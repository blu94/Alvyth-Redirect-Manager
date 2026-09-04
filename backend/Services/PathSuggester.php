<?php

namespace Plugin\RedirectManager\Backend\Services;

use App\Http\Controllers\Seo\SitemapController;
use App\Models\Blog;
use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Plugin\RedirectManager\Backend\Models\RedirectRule;
use Plugin\RedirectManager\Backend\Models\RedirectSetting;
use Plugin\RedirectManager\Backend\Support\CacheScope;

/**
 * Given a path that leads nowhere, find the ones that do.
 *
 * Most broken links are near misses — a slug that was tidied, a section that was renamed, a
 * link somebody typed by hand. The destination is usually already on the site, and making the
 * operator go and find it is the tedious part of fixing a migration.
 *
 * **The candidate paths come from the same map the sitemap uses**: pages are bare slugs and
 * everything else sits under a prefix, mirroring `ThemeController`'s catch-all. That map is
 * `protected` on `SitemapController`, so it is restated below rather than called — if core
 * ever adds a content type with a public URL, this is the line that has to learn about it.
 */
class PathSuggester
{
    private const CACHE_KEY = 'redirect-manager:candidate-paths';

    /**
     * The counter every key built here hangs off.
     *
     * Two entries are cached now — the candidate list and the answers derived from it — and
     * the derived ones cannot be enumerated to be deleted. Naming them all after a number that
     * `forget()` moves invalidates the set in one write, which is the pattern core's
     * `SitemapCache` already uses and the only one that works on a file or array store, where
     * there are no tags.
     */
    private const VERSION_KEY = 'redirect-manager:paths-version';

    private const CACHE_TTL = 600;

    /**
     * The most paths ever compared against.
     *
     * Scoring is a `similar_text` per candidate, and on a large catalogue an uncapped list
     * would make every suggestion a walk over tens of thousands of strings. Three good
     * suggestions out of the first few thousand paths is the same answer an operator would
     * have accepted from all of them, and the cap is what keeps this affordable on the 404
     * path when an operator switches storefront suggestions on.
     */
    private const MAX_CANDIDATES = 5000;

    /**
     * The longest path ever scored against.
     *
     * `similar_text` is O(n³) in the worst case and the needle arrives from the request URL,
     * so its length is the attacker's to choose. Measured on this project's own container: a
     * normal path against 5,000 candidates costs 66ms, and a 2,100-character one costs 2.9
     * seconds — of CPU, on the cheapest request there is to generate.
     *
     * 255 because that is the width of the column a recorded path is stored in: nothing longer
     * can ever become a rule or an entry, so scoring beyond it could not produce an answer an
     * operator could act on. Same bound as `IssueRecorder`, for the same reason.
     */
    private const MAX_NEEDLE = 255;

    /** How long a scored answer is kept. Shorter than the candidate list it is derived from. */
    private const SCORE_TTL = 300;

    /**
     * Paths worth offering instead of the broken one, best first.
     *
     * @return array<int,array{path:string,label:string,score:int}>
     */
    public function suggest(string $path, int $limit = 3): array
    {
        $settings = RedirectSetting::current();

        if (! $settings->suggest_enabled) {
            return [];
        }

        return $this->score($path, $settings->suggest_threshold, $limit);
    }

    /**
     * Score without consulting the settings.
     *
     * Separate from `suggest()` so the Broken Links screen can offer a suggestion even when an
     * operator has switched the storefront ones off — those are two different decisions, and
     * conflating them would mean turning off a visitor-facing feature also removed the tool
     * the operator uses to fix it.
     *
     * @return array<int,array{path:string,label:string,score:int}>
     */
    public function score(string $path, int $threshold = 70, int $limit = 3): array
    {
        $needle = mb_substr(RedirectRule::normalisePath($path), 0, self::MAX_NEEDLE);

        if ($needle === '') {
            return [];
        }

        // Memoised per question, not per candidate list. A crawler walking a dead sitemap asks
        // the same dead URL over and over, and the answer cannot change until the candidate
        // list behind it does. The key carries the threshold and the limit because both change
        // the answer, and an md5 of the needle because a path is longer than a key should be.
        return Cache::remember(
            self::key('score:' . $threshold . ':' . $limit . ':' . md5($needle)),
            self::SCORE_TTL,
            fn () => $this->rank($needle, $threshold, $limit)
        );
    }

    /**
     * Compare one path against every candidate.
     *
     * @return array<int,array{path:string,label:string,score:int}>
     */
    private function rank(string $needle, int $threshold, int $limit): array
    {
        $scored = [];
        $length = strlen($needle);

        foreach ($this->candidates() as $candidate) {
            if ($candidate['path'] === $needle) {
                continue;
            }

            // **Skipped on length alone, before the expensive comparison.** This is an exact
            // bound rather than a heuristic, so nothing that would have been offered is lost:
            // `similar_text`'s percentage is 2 × (characters in common) ÷ (both lengths), and
            // the characters in common can never exceed the shorter string — so the best score
            // two strings of these lengths could possibly reach is fixed before they are read.
            // Below the threshold, there is no answer worth paying for.
            $other = strlen($candidate['path']);
            $best  = ($length + $other) > 0
                ? (200 * min($length, $other)) / ($length + $other)
                : 0;

            if ($best < $threshold) {
                continue;
            }

            similar_text($needle, $candidate['path'], $percent);

            if ($percent < $threshold) {
                continue;
            }

            $scored[] = $candidate + ['score' => (int) round($percent)];
        }

        // Highest score first; then the shorter path, because when two candidates score the
        // same the more specific one is usually the accident — "shop/shoes" beats
        // "shop/shoes/womens/running" as the home for a link to "shop/shoe".
        usort($scored, fn ($a, $b) => ($b['score'] <=> $a['score'])
            ?: (strlen($a['path']) <=> strlen($b['path'])));

        return array_slice($scored, 0, max(1, $limit));
    }

    /**
     * Every live storefront path, with something to call it.
     *
     * Cached because it is the same answer for every visitor and rebuilding it per 404 would
     * make a crawler walking a dead sitemap the most expensive thing the site does. The TTL is
     * short rather than event-driven: a path that appeared ten minutes ago being missing from
     * a suggestion list is a cosmetic staleness, and wiring this to every content save would
     * couple the plugin to models it does not own.
     *
     * @return array<int,array{path:string,label:string}>
     */
    private function candidates(): array
    {
        return Cache::remember(self::key('candidates'), self::CACHE_TTL, function () {
            $out = [];

            foreach ($this->sources() as $source) {
                $query = $source['model']::query()->where('status', 'active');

                if ($source['model'] === Page::class) {
                    $query->whereIn('type', SitemapController::INDEXABLE_PAGE_TYPES);
                }

                foreach ($query->get(['id', 'title', 'slug']) as $record) {
                    if (count($out) >= self::MAX_CANDIDATES) {
                        break 2;
                    }

                    $slug = $this->slug($record);

                    if ($slug === null) {
                        continue;
                    }

                    $out[] = [
                        'path'  => RedirectRule::normalisePath($source['prefix'] . $slug),
                        'label' => $this->label($record) ?: $slug,
                    ];
                }
            }

            return $out;
        });
    }

    /**
     * The content types with a public URL, and the prefix each answers on.
     *
     * Restated from `SitemapController::types()`, which is protected. Kept in the same order
     * so a tie between a page and a product resolves the way the sitemap lists them.
     *
     * @return array<int,array{model:class-string,prefix:string}>
     */
    private function sources(): array
    {
        return [
            ['model' => Page::class,     'prefix' => ''],
            ['model' => Product::class,  'prefix' => 'products/'],
            ['model' => Blog::class,     'prefix' => 'blogs/'],
            ['model' => Category::class, 'prefix' => 'collections/'],
        ];
    }

    /**
     * Slugs are translatable JSON. The current locale first, then the fallback, then whatever
     * the record has — a record with no slug in any locale has no URL and is skipped.
     */
    private function slug(mixed $record): ?string
    {
        $slug = $record->slug;

        if (is_array($slug)) {
            $slug = $slug[app()->getLocale()]
                ?? $slug[config('app.fallback_locale')]
                ?? (reset($slug) ?: null);
        }

        $slug = is_string($slug) ? trim($slug, '/') : null;

        return $slug !== '' ? $slug : null;
    }

    /** A human name for the destination, so a suggestion reads as a page and not a string. */
    private function label(mixed $record): ?string
    {
        $title = $record->title ?? null;

        if (is_array($title)) {
            $title = $title[app()->getLocale()]
                ?? $title[config('app.fallback_locale')]
                ?? (reset($title) ?: null);
        }

        return is_string($title) && trim($title) !== '' ? trim($title) : null;
    }

    /**
     * One key, named after the current version.
     *
     * Everything cached here is derived from the candidate list, so everything hangs off one
     * number and `forget()` moves all of it at once.
     */
    /**
     * One key, named after the database it belongs to and the current version.
     *
     * Candidate paths are read out of this database's own pages, products, posts and
     * collections, so a key shared with another schema offers one site's addresses as
     * suggestions on another's — see {@see CacheScope}.
     */
    private static function key(string $suffix): string
    {
        return CacheScope::key(self::CACHE_KEY) . ':v' . self::version() . ':' . $suffix;
    }

    /**
     * The version keys are built from. Starts at 1 rather than 0 so a key is never ambiguous
     * with an unset value while debugging.
     */
    private static function version(): int
    {
        return (int) (Cache::get(CacheScope::key(self::VERSION_KEY)) ?: 1);
    }

    /**
     * Drop the cached path list and every answer scored from it.
     *
     * `Cache::increment` is deliberately not used: it answers false on a missing key with some
     * drivers, which would silently never bump. Read-then-write is not atomic, but the worst a
     * lost race costs is one extra rebuild — not a stale answer, because either writer moves
     * the version off the one being read.
     */
    public static function forget(): void
    {
        Cache::forever(CacheScope::key(self::VERSION_KEY), self::version() + 1);
    }
}
