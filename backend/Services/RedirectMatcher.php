<?php

namespace Plugin\RedirectManager\Backend\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Plugin\RedirectManager\Backend\Models\RedirectIssue;
use Plugin\RedirectManager\Backend\Models\RedirectRule;
use Plugin\RedirectManager\Backend\Support\CacheScope;

/**
 * Decide where an unresolved path should go.
 *
 * Runs only after the storefront has failed to find a page, so the common case — a request
 * that resolves — never reaches this class and pays nothing for its existence. That ordering
 * is the whole performance story, and it is why the exact lookup below is allowed to be a
 * query at all.
 *
 * **Nothing here may throw.** A visitor asking for a missing page must get the 404 page, not
 * a 500, even if these tables are missing or an operator has saved a pattern that will not
 * compile. Every path out of this class degrades to "no redirect".
 */
class RedirectMatcher
{
    /**
     * How far a chain is followed before it is called a chain and stopped.
     *
     * Five is generous for a legitimate configuration and small enough that a pathological
     * one costs a bounded number of queries. A visitor at hop five has already waited for
     * four round trips, so the honest thing at that point is to stop and tell the operator.
     */
    private const MAX_HOPS = 5;

    private const CACHE_KEY = 'redirect-manager:pattern-rules';

    private const CACHE_TTL = 300;

    /**
     * The key the pattern set is cached under.
     *
     * Scoped to the database it was read from — see {@see CacheScope}. A rule set means nothing
     * in another schema, and a flat key is shared by every schema pointed at one cache store.
     */
    private static function cacheKey(): string
    {
        return CacheScope::key(self::CACHE_KEY);
    }

    /**
     * Resolve a path to a destination, following and collapsing any chain.
     *
     * @param  string  $path         the requested path, in any shape
     * @param  string  $queryString  the raw query string, without the leading "?"
     */
    public function resolve(string $path, string $queryString = ''): RedirectMatch
    {
        try {
            return $this->walk(RedirectRule::normalisePath($path), $queryString);
        } catch (\Throwable $e) {
            report($e);

            return RedirectMatch::none();
        }
    }

    /**
     * Follow the rules from one path to wherever they end.
     *
     * A chain is followed rather than served hop by hop, so the visitor makes one round trip
     * instead of four and search engines see one move instead of a diluted sequence. The
     * operator is told about it separately — collapsing the chain fixes the symptom, and only
     * they can fix the rules.
     */
    private function walk(string $path, string $queryString): RedirectMatch
    {
        $first       = null;
        $destination = null;
        $used        = 0;
        $usedIds     = [];
        $walked      = [$path];
        $current     = $path;

        for ($hop = 0; $hop < self::MAX_HOPS; $hop++) {
            $matched = $this->match($current, $queryString);

            if ($matched === null) {
                break;
            }

            [$rule, $captures] = $matched;

            $first ??= $rule;
            $used++;
            $usedIds[] = (int) $rule->id;

            // The stored destination, not `target()` — see `RedirectRule::resolvedTo()`. The
            // browser-facing form is absolute for every internal path, which would make the
            // off-site test below true on the first hop and end every walk immediately.
            $destination = $rule->resolvedTo($captures);

            // An off-site destination ends the walk: what another host does with the path is
            // not knowable from here, and following it would mean fetching someone else's URL
            // on the 404 path.
            if (RedirectRule::isAbsoluteUrl($destination)) {
                return $this->result($first, $destination, $walked, $used, $usedIds);
            }

            $next = RedirectRule::normalisePath(
                (string) parse_url($destination, PHP_URL_PATH)
            );

            // A rule pointing at the path that reached it, or at any path already walked,
            // never lands anywhere. Serving it would put the browser in a redirect loop and
            // the visitor would see the browser's own error rather than the shop's 404.
            if (in_array($next, $walked, true)) {
                return new RedirectMatch(
                    rule: $first,
                    walked: array_merge($walked, [$next]),
                    problem: RedirectIssue::TYPE_LOOP,
                    usedRuleIds: $usedIds,
                );
            }

            $walked[] = $next;
            $current  = $next;

            // Only the first hop is matched against the request's query string. A later hop
            // is following a destination the operator wrote, not a URL the visitor asked
            // for, so applying their tracking parameters to it would be inventing a match.
            $queryString = '';
        }

        if ($first === null || $destination === null) {
            return RedirectMatch::none();
        }

        // Running out of hops is itself the fault worth reporting. The last path reached is
        // still the best destination available and is closer than where the visitor started,
        // so it is served rather than dropped.
        return $this->result($first, $destination, $walked, $used, $usedIds, $used >= self::MAX_HOPS);
    }

    /**
     * Assemble the answer, flagging a chain when more than one rule was involved.
     *
     * **The first rule's code wins, not the last.** The rule that matched the request is the
     * one the operator wrote for that path, and it is where they said whether the move is
     * permanent. Taking the final hop's code would let a temporary rule somewhere down the
     * chain quietly turn into a permanent one, which is not reversible in a search index.
     */
    private function result(
        RedirectRule $first,
        string $destination,
        array $walked,
        int $used,
        array $usedIds = [],
        bool $exhausted = false,
    ): RedirectMatch {
        return new RedirectMatch(
            rule: $first,
            target: RedirectRule::toUrl($destination),
            code: $first->code,
            walked: $walked,
            problem: ($used > 1 || $exhausted) ? RedirectIssue::TYPE_CHAIN : null,
            destination: $destination,
            usedRuleIds: $usedIds,
        );
    }

    /**
     * The rule for one path, or null.
     *
     * Exact rules are tried first and separately, as one equality lookup on the unique index.
     * That is what keeps the ordinary case — a renamed page — cheap, and it means a site with
     * a thousand exact rules and no patterns never loads a rule set into memory at all.
     *
     * @return array{0:RedirectRule,1:array<int,string>}|null
     */
    private function match(string $path, string $queryString): ?array
    {
        // **An empty path is matchable, not skipped.** It is the site root, and a rule for it is
        // the case this package advertises query matching for: `/?p=123` is an old permalink for
        // a post, and its path normalises to nothing at all.
        //
        // The guard that used to sit here returned null before any rule was consulted, so no
        // root rule could ever fire whatever its query said. It was never load-bearing — the
        // root is protected upstream instead, where core asks about `/` only when the request
        // carries a query string, so a bare `/` never reaches a rule and the home page cannot be
        // redirected away from by a pattern that happens to match nothing.

        $exact = $this->pickExact($path, $queryString);

        if ($exact !== null) {
            return [$exact, []];
        }

        foreach ($this->patternRules() as $rule) {
            if (! $rule->isWithinWindow() || ! $this->queryMatches($rule, $queryString)) {
                continue;
            }

            $captures = $rule->match_type === RedirectRule::MATCH_PREFIX
                ? $this->matchPrefix($rule, $path)
                : $this->matchRegex($rule, $path);

            if ($captures !== null) {
                return [$rule, $captures];
            }
        }

        return null;
    }

    /**
     * The best exact rule for a path.
     *
     * More than one can apply once the query string is part of the match — that is the point
     * of the column — so the choice is made here rather than left to whatever the database
     * returned first. **A rule naming a query string beats one that does not**, because it is
     * the more specific statement: "/?p=123 goes here, and everything else at / goes there"
     * is only expressible if the narrower rule wins.
     */
    private function pickExact(string $path, string $queryString): ?RedirectRule
    {
        return RedirectRule::query()
            ->active()
            ->where('match_type', RedirectRule::MATCH_EXACT)
            ->where('from_path', $path)
            ->get()
            ->filter(fn (RedirectRule $r) => $r->isWithinWindow() && $this->queryMatches($r, $queryString))
            // One comparator rather than a list of sort keys: `sortBy`'s handling of an array
            // of closures has not been the same across Laravel majors, and this ordering is
            // load-bearing — it decides which of two applicable rules a visitor gets.
            ->sort(fn (RedirectRule $a, RedirectRule $b) =>
                // A rule naming a query string is the more specific statement, so it wins.
                (($b->query_match !== '') <=> ($a->query_match !== ''))
                    // Then whichever the operator ranked higher.
                    ?: ($b->priority <=> $a->priority)
                    // Then the oldest, so the answer does not change when a rule is edited.
                    ?: ($a->id <=> $b->id))
            ->first();
    }

    /**
     * Whether the request's query string satisfies the rule's requirement.
     *
     * **Subset, not equality.** A rule asking for `p=123` matches a request for
     * `?p=123&utm_source=newsletter`, because the parameters that identify the old page and
     * the parameters a campaign bolted on are different things and only the first is the
     * operator's business. Requiring the whole string to match would mean such a rule worked
     * from a bookmark and failed from every link anybody shared.
     *
     * An empty requirement matches anything, which is how a redirect normally behaves.
     */
    private function queryMatches(RedirectRule $rule, string $queryString): bool
    {
        $required = (string) $rule->query_match;

        if ($required === '') {
            return true;
        }

        parse_str($required, $want);
        parse_str($queryString, $have);

        foreach ($want as $key => $value) {
            if (! array_key_exists($key, $have) || (string) $have[$key] !== (string) $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * "This branch and everything under it."
     *
     * The remainder becomes `$1`, so `blog` → `news/$1` moves an entire section with one rule
     * and every article keeps its own slug. The branch itself matches too — moving
     * `blog/*` while leaving `blog` behind would strand the index page.
     *
     * @return array<int,string>|null
     */
    private function matchPrefix(RedirectRule $rule, string $path): ?array
    {
        $from = (string) $rule->from_path;

        if ($from === '') {
            return null;
        }

        if ($path === $from) {
            return [1 => ''];
        }

        if (! str_starts_with($path, $from . '/')) {
            return null;
        }

        return [1 => substr($path, strlen($from) + 1)];
    }

    /**
     * A full pattern, with its parenthesised groups available as `$1`..`$9`.
     *
     * The operator writes the pattern without delimiters — those are this class's problem,
     * not theirs, and asking for them invites a package that works until someone types a
     * slash.
     *
     * A pattern that fails to compile, or that hits PCRE's backtrack limit on a hostile path,
     * makes `preg_match` return false. Both are treated as "did not match" and logged rather
     * than raised: this runs while answering a 404, and the safe direction to fail is the one
     * where the visitor still gets a page.
     *
     * @return array<int,string>|null
     */
    private function matchRegex(RedirectRule $rule, string $path): ?array
    {
        $pattern = '#' . str_replace('#', '\#', (string) $rule->from_path) . '#u';

        $result = @preg_match($pattern, $path, $matches);

        if ($result === false) {
            Log::warning('Redirect Manager skipped a rule whose pattern could not be evaluated', [
                'rule'  => $rule->id,
                'error' => preg_last_error_msg(),
            ]);

            return null;
        }

        return $result === 1 ? array_values($matches) : null;
    }

    /**
     * Every active pattern rule, in the order they should be tried.
     *
     * Cached as a set because they cannot be looked up by equality — the alternative is
     * loading them on every 404, which is exactly the traffic a dead sitemap generates.
     * Status is filtered in SQL and the campaign window in PHP, so nothing time-dependent is
     * baked into the cached value; see `RedirectRule::isWithinWindow()`.
     *
     * **Raw attribute rows are cached, never the models.** A serialised Eloquent model has to
     * resolve its class when it is read back, and a plugin's classes come from a runtime
     * autoloader that is only registered while the plugin is runnable. Cache an object and the
     * write succeeds, the first read succeeds because it never touched the cache, and every
     * read afterwards comes back as `__PHP_Incomplete_Class` — a `TypeError` that this class's
     * own catch turns into "no redirect". Every pattern rule would silently stop working a few
     * milliseconds after being saved, and the only symptom would be a log line.
     *
     * Hydrating here instead means the class is resolved by plugin code that is, by
     * definition, already loaded.
     *
     * Higher priority first, then oldest first, so two rules that could both match resolve
     * the same way on every request and after every edit.
     *
     * @return Collection<int,RedirectRule>
     */
    private function patternRules(): Collection
    {
        $rows = Cache::remember(
            self::cacheKey(),
            self::CACHE_TTL,
            fn () => RedirectRule::query()
                ->active()
                ->whereIn('match_type', [RedirectRule::MATCH_PREFIX, RedirectRule::MATCH_REGEX])
                ->orderByDesc('priority')
                ->orderBy('id')
                ->get()
                ->map(fn (RedirectRule $rule) => $rule->getAttributes())
                ->all()
        );

        return RedirectRule::hydrate(is_array($rows) ? $rows : []);
    }

    /**
     * Whether a path now lands somewhere.
     *
     * The question the Broken Links screen asks about every entry on it, and the reason it is
     * asked *here*: the screen used to answer it with a lookup of its own — an active exact
     * rule whose `from_path` equalled the path — which is true of one of the three kinds of
     * rule this class knows about. A prefix rule that moved a whole branch, or a pattern that
     * caught a family of old URLs, fixed the visitor's experience and left every entry it
     * covered sitting on the open list saying nothing fixes it.
     *
     * A loop is deliberately not a landing: `hasTarget()` is false for one, and an entry whose
     * rules point in a circle is still a job to do.
     */
    public function resolves(string $path, string $queryString = ''): bool
    {
        return $this->resolve($path, $queryString)->hasTarget();
    }

    /** Drop the cached pattern set. Called whenever a rule is written. */
    public static function forget(): void
    {
        Cache::forget(self::cacheKey());
    }
}
