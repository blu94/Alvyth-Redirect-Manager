<?php

namespace Plugin\RedirectManager\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Plugin\RedirectManager\Backend\Models\RedirectRule;
use Plugin\RedirectManager\Backend\Services\RedirectMatcher;

require_once __DIR__ . '/autoload.php';

/**
 * Resolving a dead path to somewhere real.
 *
 * This runs on a 404 — after the storefront has already failed to find a page — so it is the
 * one place in the plugin a visitor waits on. Everything here is about what a browser is
 * finally told to do.
 *
 * Run against a container with the plugin installed into the test database:
 *
 *   docker exec -e DB_DATABASE=alvyth_test alvyth_app \
 *     php artisan plugin:import /var/www/storage/app/plugin-src-tmp/redirect-manager --enable
 *   docker exec alvyth_app php vendor/bin/phpunit \
 *     storage/app/plugins/redirect-manager/tests --no-coverage
 */
class RedirectMatcherTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        RedirectRule::query()->forceDelete();
        RedirectMatcher::forget();
    }

    protected function tearDown(): void
    {
        RedirectRule::query()->forceDelete();
        RedirectMatcher::forget();
        parent::tearDown();
    }

    private function rule(array $attributes = []): RedirectRule
    {
        $rule = RedirectRule::create(array_replace([
            'match_type'  => RedirectRule::MATCH_EXACT,
            'from_path'   => 'old-page',
            'to_path'     => 'new-page',
            'code'        => 301,
            'status'      => RedirectRule::STATUS_ACTIVE,
        ], $attributes));

        RedirectMatcher::forget();

        return $rule;
    }

    private function resolve(string $path, string $query = '')
    {
        return app(RedirectMatcher::class)->resolve($path, $query);
    }

    #[Test]
    public function an_exact_rule_sends_the_visitor_on(): void
    {
        $this->rule();

        $match = $this->resolve('old-page');

        $this->assertTrue($match->hasTarget());
        $this->assertStringEndsWith('/new-page', $match->target);
        $this->assertSame(301, $match->code);
    }

    #[Test]
    public function a_path_with_no_rule_matches_nothing(): void
    {
        $this->assertFalse($this->resolve('never-existed')->hasTarget());
    }

    #[Test]
    public function surrounding_slashes_do_not_change_the_answer(): void
    {
        // Normalisation is shared by the model, the matcher and the recorder precisely so a
        // rule saved one way is found by a request written another.
        $this->rule(['from_path' => '/Old-Page/']);

        $this->assertTrue($this->resolve('Old-Page')->hasTarget());
    }

    #[Test]
    public function a_disabled_rule_never_fires(): void
    {
        $this->rule(['status' => 'inactive']);

        $this->assertFalse($this->resolve('old-page')->hasTarget());
    }

    #[Test]
    public function a_campaign_window_opens_and_closes(): void
    {
        $this->rule(['starts_at' => now()->addDay()]);
        $this->assertFalse($this->resolve('old-page')->hasTarget(), 'A rule that has not started must not fire.');

        RedirectRule::query()->forceDelete();
        $this->rule(['ends_at' => now()->subDay()]);
        $this->assertFalse($this->resolve('old-page')->hasTarget(), 'An expired rule must not fire.');

        RedirectRule::query()->forceDelete();
        $this->rule(['starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
        $this->assertTrue($this->resolve('old-page')->hasTarget());
    }

    #[Test]
    public function a_prefix_rule_carries_the_remainder_through(): void
    {
        $this->rule([
            'match_type' => RedirectRule::MATCH_PREFIX,
            'from_path'  => 'blog',
            'to_path'    => 'news/$1',
        ]);

        $match = $this->resolve('blog/2019/hello');

        $this->assertTrue($match->hasTarget());
        $this->assertStringEndsWith('/news/2019/hello', $match->target);
    }

    #[Test]
    public function a_pattern_rule_substitutes_what_it_captured(): void
    {
        $this->rule([
            'match_type' => RedirectRule::MATCH_REGEX,
            'from_path'  => '^products/(\d+)$',
            'to_path'    => 'shop/item/$1',
        ]);

        $this->assertStringEndsWith('/shop/item/42', $this->resolve('products/42')->target);
    }

    #[Test]
    public function a_pattern_that_cannot_compile_matches_nothing_rather_than_erroring(): void
    {
        // A visitor who mistyped a URL must never see a 500 because an operator saved a bad
        // pattern. The repository refuses these on save; this is the second line.
        RedirectRule::withoutEvents(fn () => RedirectRule::create([
            'match_type' => RedirectRule::MATCH_REGEX,
            'from_path'  => '^products/((((',
            'to_path'    => 'shop',
            'code'       => 301,
            'status'     => RedirectRule::STATUS_ACTIVE,
        ]));
        RedirectMatcher::forget();

        $this->assertFalse($this->resolve('products/42')->hasTarget());
    }

    #[Test]
    public function a_query_rule_only_fires_for_its_own_query(): void
    {
        // Two articles sharing one path, told apart by the query — the reason `query_match`
        // is a column rather than part of `from_path`.
        $this->rule(['from_path' => 'index.php', 'query_match' => 'p=123', 'to_path' => 'hello-world']);

        $this->assertTrue($this->resolve('index.php', 'p=123')->hasTarget());
        $this->assertFalse($this->resolve('index.php', 'p=999')->hasTarget());
    }

    #[Test]
    public function the_method_preserving_codes_survive_to_the_browser(): void
    {
        // 307/308 exist so a moved POST endpoint keeps its body. A rule storing 308 that
        // answered 301 would silently drop it.
        $this->rule(['code' => 308]);

        $this->assertSame(308, $this->resolve('old-page')->code);
    }

    #[Test]
    public function an_absolute_destination_is_left_alone(): void
    {
        $this->rule(['to_path' => 'https://example.com/elsewhere']);

        $this->assertSame('https://example.com/elsewhere', $this->resolve('old-page')->target);
    }
    /**
     * The site root is a matchable path, and this is the case the package advertises query
     * matching for: `/?p=123` is an old permalink for a post, not the front page.
     *
     * Its path normalises to nothing at all, so a rule for it has an empty `from_path` — which
     * a guard in `match()` used to reject before any rule was consulted. Nothing about the
     * query logic was wrong; the request simply never got that far.
     *
     * The bare front page is protected upstream instead: core asks about `/` only when the
     * request carries a query string, so a rule like this cannot swallow `/`.
     */
    #[Test]
    public function a_rule_on_the_site_root_fires_for_its_own_query(): void
    {
        $this->rule(['from_path' => '', 'query_match' => 'p=123', 'to_path' => 'hello-world']);

        $match = $this->resolve('', 'p=123');

        $this->assertTrue($match->hasTarget(), 'A root rule must match its own query.');
        $this->assertSame(url('/hello-world'), $match->target);

        // A different query is a different old post, and the bare root is the front page.
        $this->assertFalse($this->resolve('', 'p=999')->hasTarget());
        $this->assertFalse($this->resolve('')->hasTarget());
    }
}
