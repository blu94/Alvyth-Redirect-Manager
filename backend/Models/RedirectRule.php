<?php

namespace Plugin\RedirectManager\Backend\Models;

use App\Traits\LogsSystemActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * A single redirect rule, matched by the storefront after it has failed to find a page.
 *
 * Not translatable, unlike most content models: a rule is a routing instruction about one
 * literal path, not content, so there is nothing to translate. A locale-keyed `from_path`
 * would mean the same dead URL resolved differently depending on the visitor's language,
 * which is not how a moved page works.
 */
class RedirectRule extends Model
{
    /**
     * Every write here is an operator's, so all of them belong in the audit trail — a rule
     * quietly retargeted is indistinguishable from a page that moved, and the trail is the
     * only thing that tells them apart afterwards.
     *
     * The trait logs create, update and delete off the model's own events, so an import, a
     * seeder and the form are all covered without each remembering to.
     *
     * `touchHit()` deliberately escapes this: it writes through the query builder, so the hit
     * counter fires no model events. That is the point — a visitor following a moved URL is
     * not an operator action, and one audit row per redirect served would bury the real trail
     * within a day of a migration.
     */
    use LogsSystemActivity, SoftDeletes;

    protected $table = 'redirect_manager_rules';

    public const MATCH_EXACT  = 'exact';
    public const MATCH_PREFIX = 'prefix';
    public const MATCH_REGEX  = 'regex';

    public const MATCH_TYPES = [self::MATCH_EXACT, self::MATCH_PREFIX, self::MATCH_REGEX];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * The two a rule can be in.
     *
     * Named as a set because more than the form needs it: a CSV arriving from another tool
     * calls this column whatever that tool called it, and `scopeActive()` matches the literal
     * string — so a value outside this set stores a rule that lists, edits and redirects
     * nobody.
     */
    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    /**
     * 307 and 308 are here and not in the module this replaces because 301 and 302 are
     * defined to allow a client to change the method: a browser turns the redirected POST
     * into a GET and drops the body. For a moved form action or a webhook a supplier still
     * calls, that failure is invisible from the admin — the redirect "works" and the data
     * never arrives.
     */
    public const CODES = [301, 302, 307, 308];

    protected $fillable = [
        'match_type',
        'from_path',
        'query_match',
        'to_path',
        'code',
        'priority',
        'starts_at',
        'ends_at',
        'status',
    ];

    protected $casts = [
        'code'        => 'integer',
        'priority'    => 'integer',
        'hits'        => 'integer',
        'starts_at'   => 'datetime',
        'ends_at'     => 'datetime',
        'last_hit_at' => 'datetime',
    ];

    /**
     * One normalisation, used by the model, the matcher and the 404 recorder.
     *
     * Everything that touches a path goes through here, so a rule saved as "/Old-Page/" is
     * found by a request for "Old-Page". Query strings and fragments are dropped because the
     * match is on the path — a rule carrying "?utm_source" would make every campaign link a
     * separate rule. Matching *on* a query string is a separate, explicit column.
     */
    public static function normalisePath(?string $path): string
    {
        $path = (string) $path;

        // Anything from `?` or `#` onwards is not part of the path.
        $path = preg_split('/[?#]/', $path)[0] ?? '';

        return trim($path, '/');
    }

    /**
     * A destination, tidied without being damaged.
     *
     * `normalisePath` is wrong for a target: it drops everything from `?` onwards, and
     * sending a visitor to "new-page?from=old" is an ordinary thing to want. So only the
     * path portion is trimmed and any query or fragment is carried through untouched.
     *
     * An absolute URL is returned verbatim — it is not this application's path to reshape.
     */
    public static function normaliseTarget(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '' || self::isAbsoluteUrl($value)) {
            return $value;
        }

        preg_match('/^([^?#]*)(.*)$/s', $value, $parts);

        return trim($parts[1] ?? $value, '/') . ($parts[2] ?? '');
    }

    /**
     * Normalise on the way in, so a rule cannot be stored in a shape the matcher would never
     * look up. In the model rather than the FormRequest, so a CSV import and a seeder get it
     * too.
     *
     * **A saving hook, not a `setFromPathAttribute` mutator.** How `from_path` should be
     * normalised depends on `match_type` — a regex must be stored verbatim, since trimming
     * slashes off `^blog/(\d+)/$` corrupts the anchor. A mutator fires as each attribute is
     * assigned, so it would see whatever `match_type` happened to be set by then, and the
     * rule would silently depend on the key order of the incoming JSON. A saving hook runs
     * once with every attribute present.
     */
    protected static function booted(): void
    {
        static::saving(function (self $rule) {
            if ($rule->match_type !== self::MATCH_REGEX) {
                $from = self::normalisePath($rule->from_path);

                // A prefix rule means "this path and everything under it", and an operator
                // writing one reaches for the wildcard they know from every other tool —
                // "blog/*". Stored with the star still on it, the branch would only match a
                // path that literally contained one. Accepting the spelling and storing the
                // branch is the difference between the rule working and the operator being
                // told they wrote it wrong.
                if ($rule->match_type === self::MATCH_PREFIX) {
                    $from = trim(rtrim($from, '*'), '/');
                }

                $rule->attributes['from_path'] = $from;
            }

            $rule->attributes['to_path'] = self::normaliseTarget($rule->to_path);

            // Empty rather than null, so the unique index can do its job — MySQL treats each
            // NULL as distinct, which would let the same rule be entered twice. A leading "?"
            // is accepted and dropped because that is how an operator copies the value out of
            // a browser's address bar.
            $rule->attributes['query_match'] = ltrim(trim((string) $rule->query_match), '?');
        });
    }

    public static function isAbsoluteUrl(?string $value): bool
    {
        return (bool) preg_match('#^https?://#i', trim((string) $value));
    }

    public function isAbsolute(): bool
    {
        return self::isAbsoluteUrl($this->to_path);
    }

    /**
     * The destination, with the front page shown as `/` rather than as nothing.
     *
     * A destination is stored normalised, so `/` trims to `''` — which is what the matcher
     * wants, since an empty target builds `url('/')`. The cost was that the home page became
     * the *absence* of a value, and every "is this filled in?" check downstream read it as
     * missing: the form came back blank and refused the next save because the field is
     * required, the list showed a dash, the CSV exported an empty `to` that its own import then
     * skipped, and the duplicate-rule error read `sending it to ""`.
     *
     * One accessor answers all of them, because every screen reads the attribute and every
     * consumer of the value normalises before using it — `resolvedTo()` and `toUrl()` both end
     * in `normaliseTarget()`, so a `/` arriving there is trimmed straight back to `''` and
     * still builds the site root. Sorting and searching are unaffected: they run against the
     * real column in SQL and never see this.
     *
     * Reads `$value` — the raw attribute Eloquent hands in — rather than `$this->to_path`,
     * which would call this method again.
     */
    public function getToPathAttribute(?string $value): string
    {
        return (string) $value === '' ? '/' : (string) $value;
    }

    /** Rules an operator has switched on. Says nothing about their window. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Whether the rule's campaign window is open right now.
     *
     * **In PHP rather than in the query, deliberately.** The pattern rules are cached as a
     * set, and a query carrying `now()` bakes the moment of caching into the result — a rule
     * that expired thirty seconds ago would keep firing until the cache entry did, and one
     * starting today would not fire until it was evicted. Filtering on status in SQL and on
     * the window here means the cached set is time-independent and the window is exact.
     */
    public function isWithinWindow(?\DateTimeInterface $at = null): bool
    {
        $at = $at ?: now();

        if ($this->starts_at !== null && $this->starts_at->greaterThan($at)) {
            return false;
        }

        if ($this->ends_at !== null && $this->ends_at->lessThan($at)) {
            return false;
        }

        return true;
    }

    /**
     * Count a use of these rules.
     *
     * A bare UPDATE rather than a model save: this fires on every hit to a moved URL, and a
     * query-builder update is one statement with no hydration, no model events and no
     * `updated_at` churn. Failure is swallowed — a visitor's redirect must not depend on the
     * statistics behind it.
     *
     * **Every rule the walk used, not just the first.** A chain is collapsed so the visitor
     * arrives in one hop, but all of its rules did the work — and counting only the one that
     * matched the request left the rules in the middle reporting zero hits. The Overview offers
     * those up under "Rules never used", inviting the operator to delete the very rule that
     * makes the chain resolve. Still one statement, because the ids go in as a set.
     *
     * @param  int|array<int,int>  $ids
     */
    public static function touchHit(int|array $ids): void
    {
        $ids = array_values(array_unique(array_filter((array) $ids)));

        if ($ids === []) {
            return;
        }

        try {
            DB::table((new static)->getTable())
                ->whereIn('id', $ids)
                ->update([
                    'hits'        => DB::raw('hits + 1'),
                    'last_hit_at' => now(),
                ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * The destination with its capture placeholders filled in, still in stored form.
     *
     * **Kept separate from `target()` on purpose.** Whether a destination is internal decides
     * whether the matcher follows it to look for a chain, and `url()` makes every internal
     * path look absolute — so a walk that tested the browser-facing form would conclude that
     * every rule points off-site, stop at the first hop, and never find a chain or a loop.
     *
     * `$captures` are the fragments the match produced: the remainder for a prefix rule, the
     * parenthesised groups for a regex. An unfilled placeholder is replaced with nothing
     * rather than left in the path — sending a visitor to a literal "/$2" is worse than
     * sending them to the shortened path, and the pattern that produced it is the operator's
     * to fix.
     *
     * @param  array<int,string>  $captures
     */
    public function resolvedTo(array $captures = []): string
    {
        $to = (string) $this->to_path;

        if (str_contains($to, '$')) {
            $to = preg_replace_callback(
                '/\$(\d)/',
                fn (array $m) => $captures[(int) $m[1]] ?? '',
                $to
            ) ?? $to;
        }

        return self::normaliseTarget($to);
    }

    /**
     * A stored destination as something the browser can follow.
     *
     * `$locale` is the locale segment the request arrived under, and it is put back on the
     * front of an internal destination. Core strips it before asking — a rule is written about
     * `laman-lama`, not about each locale's spelling of the URL it sits under — so without this
     * a Malay visitor following an old link was moved onto the default locale as a side effect
     * of the redirect.
     *
     * An absolute destination is returned verbatim, locale or not: what another host does with
     * its own URLs is not this application's to decide.
     */
    public static function toUrl(string $destination, ?string $locale = null): string
    {
        $destination = self::normaliseTarget($destination);

        if (self::isAbsoluteUrl($destination)) {
            return $destination;
        }

        $locale = trim((string) $locale, '/');

        return url('/' . ($locale === '' ? $destination : trim($locale . '/' . $destination, '/')));
    }

    /**
     * The destination as something the browser can follow.
     *
     * @param  array<int,string>  $captures
     */
    public function target(array $captures = [], ?string $locale = null): string
    {
        return self::toUrl($this->resolvedTo($captures), $locale);
    }
}
