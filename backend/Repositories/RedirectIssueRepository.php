<?php

namespace Plugin\RedirectManager\Backend\Repositories;

use Illuminate\Support\Carbon;
use Plugin\RedirectManager\Backend\Models\RedirectIssue;
use Plugin\RedirectManager\Backend\Models\RedirectSetting;
use Plugin\RedirectManager\Backend\Services\PathSuggester;

/**
 * Broken links — the recorded problems, and the actions that clear them.
 *
 * Read-only apart from resolving, pruning and deleting. There is no create and no edit,
 * because rows are written by visitors rather than operators: an operator inventing "a 404
 * that happened" would be fabricating evidence, and the screen's value is that everything on
 * it actually occurred.
 */
class RedirectIssueRepository
{
    public function __construct(
        private readonly PathSuggester $suggester,
    ) {
    }

    public function baseIndexQuery(array $filters = [])
    {
        $query = RedirectIssue::query();

        // `!empty`, not `isset`: DataTables sends `type=` when a filter is cleared, and
        // `isset('')` is true — which would silently filter the list down to nothing.
        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        // `resolved` is the exception, because "0" is a meaningful selection and `!empty("0")`
        // is false. The working default is "still open", so an unset filter is not the same
        // question as an explicit "show me the resolved ones".
        if (isset($filters['resolved']) && $filters['resolved'] !== '') {
            $query->where('resolved', (bool) (int) $filters['resolved']);
        }

        return $query;
    }

    /**
     * Refused rather than quietly ignored. The module declares no create action, so reaching
     * this means something is calling the endpoint directly, and answering with a fabricated
     * row would be worse than answering with an error.
     */
    public function create(array $data)
    {
        abort(422, 'Broken links are recorded when a visitor hits a missing page. They cannot be added by hand.');
    }

    /**
     * One issue, with somewhere to send it.
     *
     * Suggestions are attached here and not on the list: scoring is a string comparison
     * against every live path on the site, which is affordable once for a record an operator
     * opened and is not affordable ten times for a page of results they are only scanning.
     */
    public function find($id)
    {
        $issue = RedirectIssue::find($id);

        if ($issue === null) {
            return null;
        }

        $settings    = RedirectSetting::current();
        $suggestions = $this->suggester->score($issue->path, $settings->suggest_threshold, 5);

        $issue->setAttribute('suggestions', $suggestions);

        // The best candidate as a bare path, so the view's "Redirect to this" button can carry
        // it into the rule form through `{top_suggestion}`. A separate scalar rather than
        // reaching into the array, because the schema engine interpolates flat fields only —
        // and `ui.show_if` needs something it can test for emptiness to hide the button when
        // nothing scored well enough.
        $issue->setAttribute('top_suggestion', $suggestions[0]['path'] ?? null);

        $issue->setAttribute('suggestions_text', $suggestions === []
            ? 'Nothing on the site looks close enough to suggest.'
            : implode(' · ', array_map(
                fn ($s) => "/{$s['path']} ({$s['score']}%)" . ($s['label'] !== $s['path'] ? " — {$s['label']}" : ''),
                $suggestions
            )));

        $issue->setAttribute('existing_rule', $issue->rule()?->only(['id', 'to_path', 'code']));

        return $issue;
    }

    /**
     * Refused, for the same reason `create()` is — and because it could never do anything.
     *
     * The module is `readonly` and declares no `form` block, so `HandlesModuleSchema` derives no
     * rules from it and `validated()` is always empty. This read `$data['resolved'] ?? $issue
     * ->resolved`, which meant it wrote the value the row already had: `PUT` answered **200**,
     * having changed nothing, every time. A caller could not tell that from success.
     *
     * The action that does work is named, because a refusal that does not say what to do
     * instead is only half an answer.
     */
    public function update($id, array $data)
    {
        abort(422, 'A broken link is evidence of what happened and is not edited. Use the '
            . '"Dealt with" action to change whether it is still open.');
    }

    public function delete($id)
    {
        $issue = RedirectIssue::find($id);

        if ($issue === null) {
            return false;
        }

        // Logged before the delete, so the entry records what was destroyed rather than an id
        // with nothing behind it.
        $this->log($issue, 'deleted');

        return $issue->delete();
    }

    /**
     * Refuse an action the operator does not hold the permission for.
     *
     * **The verb the action IS, not the one its transport implies.** A plugin registers no
     * routes, so every button on this screen arrives as `POST .../page/{slug}` — and a POST
     * maps to `create`. That admitted **Prune history**, which deletes recorded 404s in bulk,
     * to anyone holding `redirect_manager.create`, while refusing it to a role granted
     * `redirect_manager.delete` and nothing else. The permission matrix read backwards.
     *
     * `module.json` now names each page's real verb and `GateModuleResource` believes it, so
     * this second check agrees with the first rather than narrowing it. It is here as well
     * because the middleware guards one route and this guards the action: a package installed
     * on a core that predates the declared verb still fails closed, and so does any future
     * caller that reaches the repository another way.
     *
     * Mirrors the middleware's own test — the `super_admin` role, or the named permission.
     */
    private function authorise(string $verb): void
    {
        $user = auth()->user();

        if ($user && ($user->hasRole('super_admin') || $user->can("redirect_manager.{$verb}"))) {
            return;
        }

        abort(403, "This needs the redirect_manager.{$verb} permission.");
    }

    /**
     * Record an operator action against one entry.
     *
     * Written by hand rather than by the `LogsSystemActivity` trait, because the trait fires on
     * model events and this table's creates come from anonymous visitors — see the note on
     * `RedirectIssue`. Only what an operator does is worth an audit row.
     */
    private function log(RedirectIssue $issue, string $event): void
    {
        activity()
            ->performedOn($issue)
            ->causedBy(auth()->user())
            ->event($event)
            ->withProperties(['attributes' => $issue->only(['type', 'path', 'hits', 'resolved'])])
            ->log($event);
    }

    public function getOptions(array $columns = [])
    {
        $out = [];

        foreach (array_intersect($columns, ['type']) as $column) {
            $out[$column] = RedirectIssue::query()->distinct()->pluck($column)->filter()->values();
        }

        return $out;
    }

    /**
     * The write endpoint for everything that is not CRUD.
     *
     * A plugin registers no routes, so `POST /admin/modules/{module}/page/{slug}` is the only
     * way for a schema-declared button to reach the server. Each slug below is one such
     * button; the schema names the fields it sends.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function savePageData(string $slug, array $data)
    {
        return match ($slug) {
            'resolve' => $this->setResolved($data, true),
            'reopen'  => $this->setResolved($data, false),
            'prune'   => $this->prune(),
            default   => ['message' => 'Unknown action.'],
        };
    }

    /** @param  array<string,mixed>  $data */
    private function setResolved(array $data, bool $resolved): array
    {
        $this->authorise('update');

        $issue = RedirectIssue::find($data['id'] ?? null);

        if ($issue === null) {
            return ['message' => 'That entry no longer exists.'];
        }

        $issue->update(['resolved' => $resolved]);

        $this->log($issue, $resolved ? 'resolved' : 'reopened');

        return ['message' => $resolved ? 'Marked as dealt with.' : 'Reopened.'];
    }

    /**
     * Delete resolved history older than the retention setting.
     *
     * **A button, not a schedule.** A plugin cannot register a scheduled task, and a retention
     * setting that silently never ran would be worse than not offering one — so the operator
     * presses it and is told what went.
     *
     * Only resolved rows are removed. An open issue is still a job to do however old it is,
     * and deleting it would quietly shorten the list without shortening the work.
     *
     * @return array<string,mixed>
     */
    private function prune(): array
    {
        $this->authorise('delete');

        $days = RedirectSetting::current()->retention_days;

        if ($days === null) {
            return ['message' => 'No retention period is set, so nothing was removed. Set one in Settings.'];
        }

        $cutoff  = Carbon::now()->subDays($days);
        $deleted = RedirectIssue::query()
            ->where('resolved', true)
            ->where('last_seen_at', '<', $cutoff)
            ->delete();

        // A bulk delete fires no model events and has no single subject, so it is logged as
        // one entry describing the sweep. Without it, history disappearing from the screen
        // would have no record of who removed it or how much went.
        if ($deleted > 0) {
            activity()
                ->causedBy(auth()->user())
                ->event('pruned')
                ->withProperties(['attributes' => ['deleted' => $deleted, 'older_than_days' => $days]])
                ->log('pruned');
        }

        return ['message' => $deleted === 0
            ? 'Nothing was old enough to remove.'
            : "Removed {$deleted} " . ($deleted === 1 ? 'entry' : 'entries') . ' you had dealt with.'];
    }
}
