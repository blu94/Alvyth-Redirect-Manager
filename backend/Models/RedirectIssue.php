<?php

namespace Plugin\RedirectManager\Backend\Models;

use Illuminate\Database\Eloquent\Model;
use Plugin\RedirectManager\Backend\Services\RedirectMatcher;

/**
 * A recurring, path-scoped problem an operator should fix.
 *
 * Three kinds, all about one path, all recurring, all fixed by editing rules — which is the
 * admission test the table's migration writes down. They live in one table and on one screen
 * because to an operator they are one list: things that are wrong with the site's links.
 *
 * **No typed subclass and no global scope**, unlike the core model this replaces. Core needed
 * that pattern because its table was shared with problem kinds belonging to other features
 * that had not been built; here every kind belongs to this plugin and the screen deliberately
 * shows them together, so a scope would exist only to be disabled.
 *
 * **Deliberately no `LogsSystemActivity`**, unlike the rules. Rows here are written by
 * anonymous visitors — one per 404, on traffic a crawler can generate by the thousand — and a
 * model-event trait would put every one of them in the audit trail, burying the operator
 * actions the trail exists to record. The operator actions this table *does* have (marking an
 * entry dealt with, deleting one, pruning history) are logged explicitly in
 * `RedirectIssueRepository`, which is the only place they can happen.
 */
class RedirectIssue extends Model
{
    protected $table = 'redirect_manager_issues';

    /** A path that resolved to nothing. */
    public const TYPE_NOT_FOUND = 'NOT_FOUND';

    /** Rules that point in a circle, so a visitor never lands. */
    public const TYPE_LOOP = 'REDIRECT_LOOP';

    /** A rule pointing at a path another rule redirects away from. */
    public const TYPE_CHAIN = 'REDIRECT_CHAIN';

    public const TYPES = [self::TYPE_NOT_FOUND, self::TYPE_LOOP, self::TYPE_CHAIN];

    protected $fillable = [
        'type',
        'path',
        'query',
        'context',
        'hits',
        'last_seen_at',
        'resolved',
    ];

    protected $casts = [
        'context'      => 'array',
        'hits'         => 'integer',
        'resolved'     => 'boolean',
        'last_seen_at' => 'datetime',
    ];

    /**
     * Computed columns the list needs.
     *
     * Appended rather than added at the controller, because a plugin's list is served by
     * `GenericModuleController`, which builds its own DataTables instance and gives a
     * repository no place to hang an `addColumn`. What a plugin controls is the model, so
     * anything the table shows beyond a real column has to arrive through `toArray()`.
     */
    protected $appends = ['referrer', 'state', 'kind'];

    /**
     * Where the visitor came from on the most recent hit.
     *
     * In `context` rather than a column because only a 404 has one — a loop or a chain is
     * found by walking the rules, not by someone arriving from somewhere.
     */
    public function getReferrerAttribute(): ?string
    {
        return $this->context['referrer'] ?? null;
    }

    /**
     * The path, with the front page shown as `/` rather than as nothing.
     *
     * A root miss is stored with an empty path and its query beside it — `/?p=999` is a
     * WordPress permalink for a post, not the home page — and empty is the shape the matcher
     * wants. The cost was a blank cell on a screen whose whole job is to name addresses.
     *
     * Safe as an accessor for the same reason `RedirectRule::getToPathAttribute()` is: every
     * consumer normalises before using the value, and `RedirectRule::normalisePath('/')` trims
     * straight back to `''`. Sorting and searching are unaffected — both run against the real
     * column in SQL and never see this — and nothing writes through it.
     */
    public function getPathAttribute(?string $value): string
    {
        return (string) $value === '' ? '/' : (string) $value;
    }

    /**
     * The list renders a status chip and "resolved" is a boolean. Mapping it to a word here
     * keeps the schema's `type: status` cell generic instead of teaching it about booleans.
     */
    public function getStateAttribute(): string
    {
        // "Dealt with", not "resolved", because that is the word on the button that sets it and
        // in the filter that finds it again. The two disagreed: an operator pressed **Dealt
        // with** and the row then reported **resolved**, with Prune talking about resolved
        // entries — three names for one thing on one screen.
        //
        // Only the shown word changes. The column, the filter key and the API value stay
        // `resolved`; "resolve" is also what this plugin calls a path finding a page
        // (`PathNotResolved`, `RedirectMatcher::resolve()`), which is why the operator-facing
        // half reads better as the plainer phrase.
        return $this->resolved ? 'dealt with' : 'open';
    }

    /** The type as something worth reading in a table cell. */
    public function getKindAttribute(): string
    {
        return match ($this->type) {
            self::TYPE_LOOP  => 'Loop',
            self::TYPE_CHAIN => 'Chain',
            default          => 'Not found',
        };
    }

    /**
     * The rule that fixes this path, if one does.
     *
     * Not a relationship: a rule can be created or removed independently of the 404 that
     * suggested it, and a foreign key would either block that or leave a dangling reference.
     *
     * **Answered by the matcher, not by a lookup of this screen's own.** It used to find an
     * active *exact* rule whose `from_path` equalled the path, which is right about one of the
     * three kinds of rule the package sells and silently wrong about the other two: a prefix
     * rule that moved a whole branch, or a pattern that caught a family of old permalinks,
     * fixed the visitor's experience while this screen went on reporting that nothing fixed it.
     *
     * The narrowing was itself a fix — an unfiltered `where('from_path', ...)` matched a prefix
     * rule that covers everything *under* a path but not the path itself, a disabled rule that
     * fires for nobody, and a regex whose `from_path` is stored verbatim. All three were wrong
     * answers. Asking the matcher answers all of them correctly, because it is the same walk
     * the visitor gets.
     */
    public function rule(): ?RedirectRule
    {
        // The query is handed over too. A rule for the site root only fires for its own query,
        // so asking about the path alone would report that nothing fixes the one address this
        // package raised its core floor to support.
        $match = app(RedirectMatcher::class)->resolve($this->path, (string) $this->query);

        return $match->hasTarget() ? $match->rule : null;
    }
}
