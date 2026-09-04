<?php

namespace Plugin\RedirectManager\Backend\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Plugin\RedirectManager\Backend\Models\RedirectIssue;
use Plugin\RedirectManager\Backend\Models\RedirectRule;
use Plugin\RedirectManager\Backend\Support\CacheScope;

/**
 * Write a problem down, once per path.
 *
 * The one place that knows how deduplication works, because two callers need it and they must
 * not drift: the 404 listener, and the rule editor checking whether what an operator just
 * saved points into a loop.
 *
 * Nothing here throws. Recording a problem happens while answering a 404 or saving a rule, and
 * failing to file the paperwork must never be what the visitor or the operator sees.
 */
class IssueRecorder
{
    public function __construct(
        private readonly RedirectMatcher $matcher,
    ) {
    }

    /**
     * The most open entries examined when a pattern rule is saved.
     *
     * A prefix rule's reach is expressible in SQL, so only the rows it could possibly cover
     * are read. A regular expression's is not — every open path has to be offered to it — so
     * that sweep is bounded. Five thousand is far past what a migration leaves behind and small
     * enough that saving a rule stays a form submit rather than a job.
     */
    private const MAX_SWEEP = 5000;

    /**
     * The width of the columns a recorded address is stored in.
     *
     * Applied before the write rather than discovered as an exception. Without it a path longer
     * than the column threw, the catch below reported it, and no row appeared — so the dead
     * URLs a broken link builder generates, which are exactly the long ones, were the ones
     * never recorded.
     */
    private const MAX_WIDTH = 255;

    /**
     * The most open entries recorded before new ones stop being written down.
     *
     * Deduplication bounds this table at one row per **distinct** dead path — and generating
     * distinct paths is precisely what a vulnerability scanner does, so the bound the table's
     * own migration reasons from ("hundreds after a typical migration, not millions") assumes a
     * well-behaved internet. It is also what the decision to carry no index on `hits` or
     * `last_seen_at` rests on, so if the premise fails the screen sorts an unindexed table too.
     *
     * Past the ceiling, entries already known keep counting — the question "which dead paths
     * still get traffic" goes on being answered — and no new distinct path is added. The
     * Overview says so, because a list that silently stopped growing is worse than one that
     * says why it did.
     *
     * A constant rather than a setting: the lever an operator actually has is the ignore list,
     * and a second knob meaning "let it grow bigger" would invite the wrong fix.
     */
    private const MAX_OPEN = 10000;

    /** How long the ceiling check is trusted for. One count a minute under a flood, not one a hit. */
    private const CEILING_TTL = 60;

    private const CEILING_KEY = 'redirect-manager:recording-paused';

    /**
     * Record one occurrence, incrementing the count if the path is already known.
     *
     * `update`-then-`insert` keyed on the unique `(type, path)`, rather than read-then-write:
     * the alternative races two concurrent crawlers into a duplicate-key error on exactly the
     * traffic this exists to survive. A duplicate key on the insert means another request won,
     * which is the correct outcome — the row exists either way.
     *
     * **`context` is replaced, not merged.** It describes the most recent occurrence, and a
     * merged bag would accumulate every referrer a scanner ever sent.
     *
     * **The query is part of the identity, and an empty path is a real address.** A root miss
     * — `/?p=999`, an old permalink for a post — normalises to no path at all, and this used to
     * return early on one: the single case query matching exists to serve produced no evidence
     * for the operator to act on. Recording it under `(type, path)` alone would have been no
     * better, because `/?p=123` and `/?p=456` are two different articles and would have
     * deduplicated into one row naming neither.
     *
     * Both are still bounded to the width of their columns. The path and the query are chosen
     * by whoever sends the request, and an over-long value would otherwise throw — inside a 404
     * handler, turning a missing page into a 500. The referrer beside them has been truncated
     * for exactly this reason since the beginning; these two were not.
     *
     * @param  array<string,mixed>  $context
     */
    public function record(string $type, string $path, array $context = [], string $query = ''): void
    {
        $path  = mb_substr(RedirectRule::normalisePath($path), 0, self::MAX_WIDTH);
        $query = mb_substr(ltrim(trim($query), '?'), 0, self::MAX_WIDTH);

        // Nothing to name. A bare `/` is the home page, not a missing address.
        if (($path === '' && $query === '') || ! in_array($type, RedirectIssue::TYPES, true)) {
            return;
        }

        try {
            $affected = DB::table((new RedirectIssue)->getTable())
                ->where('type', $type)
                ->where('path', $path)
                ->where('query', $query)
                ->update([
                    'hits'         => DB::raw('hits + 1'),
                    'context'      => json_encode($context),
                    'last_seen_at' => now(),
                    'updated_at'   => now(),
                ]);

            // Only a genuinely new address reaches the ceiling: an address already recorded was
            // updated above and costs nothing extra however often it is asked for.
            if ($affected === 0 && ! self::recordingPaused()) {
                RedirectIssue::create([
                    'type'         => $type,
                    'path'         => $path,
                    'query'        => $query,
                    'context'      => $context,
                    'hits'         => 1,
                    'last_seen_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Whether the open list has stopped taking new addresses.
     *
     * Cached rather than counted per miss: the count is the thing a flood would make expensive,
     * and a minute of staleness on a ceiling measured in thousands changes nothing an operator
     * would notice.
     */
    public static function recordingPaused(): bool
    {
        return (bool) Cache::remember(
            CacheScope::key(self::CEILING_KEY),
            self::CEILING_TTL,
            fn () => RedirectIssue::query()->where('resolved', false)->count() >= self::MAX_OPEN
        );
    }

    /** The ceiling, for a screen that has to explain it. */
    public static function ceiling(): int
    {
        return self::MAX_OPEN;
    }

    /**
     * Mark every open issue a rule now covers as dealt with.
     *
     * Called when a rule is written. Without it, fixing a broken link leaves the path sitting
     * on the "still open" list and the operator has to remember to tidy up after themselves —
     * which is exactly the loop this plugin exists to close.
     *
     * **The matcher decides, every time.** This used to close entries only for an *exact*
     * rule, on a straight `where path = from_path`. That is one of the three kinds of rule the
     * package sells: an operator who moved a whole section with a single prefix rule — the
     * case prefix rules exist for — watched every path under it stay open, with the detail
     * screen reporting that nothing fixed it.
     *
     * The SQL below narrows *which rows are asked about*; it never decides. A prefix rule can
     * only cover its own path and what sits under it, so those are the only rows worth
     * reading, and the index on `path` makes that cheap. A regular expression's reach cannot
     * be expressed as a `LIKE`, so every open path is offered to it, chunked and capped. In
     * both cases the answer comes from `RedirectMatcher::resolves()` — the same walk a visitor
     * gets — so the screen and the storefront cannot disagree about whether a path is fixed.
     *
     * @return int how many entries were closed
     */
    public function resolveCoveredBy(RedirectRule $rule): int
    {
        try {
            $closed = 0;

            $this->candidatesFor($rule)->chunkById(200, function ($issues) use (&$closed) {
                $fixed = [];

                foreach ($issues as $issue) {
                    if ($this->matcher->resolves($issue->path, (string) $issue->query)) {
                        $fixed[] = $issue->id;
                    }
                }

                if ($fixed !== []) {
                    RedirectIssue::query()
                        ->whereIn('id', $fixed)
                        ->update(['resolved' => true, 'updated_at' => now()]);

                    $closed += count($fixed);
                }
            });

            return $closed;
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
    }

    /**
     * The open entries worth asking the matcher about, for one rule.
     *
     * A superset filter and nothing more — it may offer rows the rule turns out not to cover,
     * and it must never withhold one that it does.
     */
    private function candidatesFor(RedirectRule $rule): Builder
    {
        $query = RedirectIssue::query()->where('resolved', false);
        $from  = RedirectRule::normalisePath($rule->from_path);

        if ($rule->match_type === RedirectRule::MATCH_EXACT && $from !== '') {
            return $query->where('path', $from);
        }

        if ($rule->match_type === RedirectRule::MATCH_PREFIX && $from !== '') {
            // The branch itself and everything under it — the same two cases `matchPrefix()`
            // accepts. Escaped because a path may legitimately contain a LIKE wildcard, and
            // an unescaped `%` or `_` would widen this well beyond the branch.
            $under = addcslashes($from, '%_' . chr(92)) . '/%';

            return $query->where(function ($q) use ($from, $under) {
                $q->where('path', $from)->orWhere('path', 'like', $under);
            });
        }

        // A pattern, or a rule matching the site root: nothing to narrow by, so every open
        // entry is offered — bounded, because this runs on a form submit.
        return $query->limit(self::MAX_SWEEP);
    }
}
