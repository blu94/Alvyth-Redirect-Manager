<?php

namespace Plugin\RedirectManager\Backend\Listeners;

use App\Events\PathNotResolved;
use Illuminate\Support\Facades\Log;
use Plugin\RedirectManager\Backend\Models\RedirectIssue;
use Plugin\RedirectManager\Backend\Models\RedirectRule;
use Plugin\RedirectManager\Backend\Models\RedirectSetting;
use Plugin\RedirectManager\Backend\Services\IssueRecorder;
use Plugin\RedirectManager\Backend\Services\RedirectMatcher;

/**
 * The 404 seam: send the visitor somewhere, or write down that we could not.
 *
 * This is the whole plugin as far as a visitor is concerned. Everything else — the screens,
 * the charts, the import — exists to decide what this class does.
 *
 * **Not `ShouldQueue`.** Core's listeners defer because they send email, which is slow and may
 * fail. This one has to answer *this* request: the event carries the response, so deferring it
 * would mean the visitor gets a 404 and the redirect is decided some time afterwards, for
 * nobody. It must also stay cheap, which is why the ordinary case is one indexed lookup.
 *
 * **Nothing propagates.** Core dispatches quietly, so a throw here is swallowed and logged
 * rather than shown — which means an unhandled failure would silently stop every redirect on
 * the site with no symptom but a log line. So failures are caught and named here instead.
 */
class ResolveRedirect
{
    public function __construct(
        private readonly RedirectMatcher $matcher,
        private readonly IssueRecorder $recorder,
    ) {
    }

    public function onPathNotResolved(PathNotResolved $event): void
    {
        try {
            $this->handle($event);
        } catch (\Throwable $e) {
            Log::error('Redirect Manager could not resolve a missing path', [
                'path'  => $event->path,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function handle(PathNotResolved $event): void
    {
        // Another plugin has already offered a destination, and core takes the first offer. So
        // there is no answer left to give — and, more to the point, no miss to record: writing
        // one here would put a path that redirects perfectly well on the Broken Links screen as
        // a dead end, which is evidence of something that did not happen.
        if ($event->handled()) {
            return;
        }

        $queryString = http_build_query($event->query);
        $match       = $this->matcher->resolve($event->path, $queryString);

        // A loop is not a destination. Serving it would hand the browser its own redirect
        // error instead of the shop's 404 page, so the fault is recorded and the request
        // falls through to the 404 exactly as if no rule existed.
        if ($match->problem === RedirectIssue::TYPE_LOOP) {
            $this->recorder->record(RedirectIssue::TYPE_LOOP, $event->path, [
                'walked' => $match->walked,
                'rule'   => $match->rule?->id,
            ]);

            return;
        }

        if ($match->hasTarget()) {
            // Put the visitor back on their own locale. Core strips the segment before asking
            // — a rule is written about `laman-lama`, not about each locale's spelling of the
            // URL above it — so without this a Malay visitor following an old link lands on the
            // English site as a side effect of the redirect. An off-site destination is left
            // exactly as the operator wrote it: another host's locales are not ours to guess.
            $target = $match->isOffsite()
                ? $match->target
                : RedirectRule::toUrl((string) $match->destination, $event->locale);

            $event->redirectTo($target, $match->code);

            RedirectRule::touchHit($match->usedRuleIds ?: [$match->rule->id]);

            // A chain still redirects — the visitor is sent to the end of it in one hop — but
            // the operator is told, because only they can collapse the rules that caused it.
            if ($match->problem === RedirectIssue::TYPE_CHAIN) {
                $this->recorder->record(RedirectIssue::TYPE_CHAIN, $event->path, [
                    'walked' => $match->walked,
                    'rule'   => $match->rule->id,
                ]);
            }

            return;
        }

        $this->recordMiss($event, $queryString);
    }

    /**
     * Write down a path that resolved to nothing.
     *
     * Filtered at write time rather than read time. A vulnerability scanner walking its list of
     * admin paths and dotfiles would otherwise dominate the Broken Links screen, and the handful
     * of real content misses an operator can act on would be buried under it — so the rows are
     * not written at all rather than hidden later.
     */
    private function recordMiss(PathNotResolved $event, string $queryString): void
    {
        $settings = RedirectSetting::current();

        if (! $settings->log_404) {
            return;
        }

        $path = RedirectRule::normalisePath($event->path);

        if ($settings->ignores($path)) {
            return;
        }

        // **An empty path is not a reason to drop this.** It is the site root, and core only
        // asks about the root when the request carries a query — which is precisely the
        // address this package raised its core floor to serve, `/?p=999`. The guard that used
        // to sit here meant the one case query matching exists for produced no evidence at all.
        // The recorder refuses a genuinely empty address, path and query both.
        //
        // The query travels as a column rather than inside `context`, because it is half of
        // what identifies the problem: `/?p=123` and `/?p=456` are two different articles.
        $this->recorder->record(
            RedirectIssue::TYPE_NOT_FOUND,
            $path,
            // Truncated because it is attacker-controlled input heading for a `string`
            // column. An over-long value would otherwise throw and, in a 404 handler, turn a
            // missing page into a 500.
            ['referrer' => $this->trim($event->referrer)],
            $queryString
        );
    }

    private function trim(?string $referrer): ?string
    {
        $referrer = trim((string) $referrer);

        return $referrer === '' ? null : mb_substr($referrer, 0, 255);
    }
}
