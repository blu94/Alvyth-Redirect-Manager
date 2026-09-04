<?php

namespace Plugin\RedirectManager\Tests;

use App\Models\Page;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Plugin\RedirectManager\Backend\Services\PathSuggester;

require_once __DIR__ . '/autoload.php';

/**
 * Offering a live path in place of a dead one.
 *
 * This runs on the 404 path whenever storefront suggestions are on, which makes it the one
 * piece of the plugin whose *input length* an anonymous visitor chooses. `similar_text` is
 * O(n³) in the worst case, so the tests that matter here are as much about what is refused to
 * be compared as about what is offered.
 */
class PathSuggesterTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        PathSuggester::forget();
    }

    protected function tearDown(): void
    {
        PathSuggester::forget();
        parent::tearDown();
    }

    private function page(string $slug, string $title): Page
    {
        return Page::create([
            'title'  => ['en' => $title],
            'slug'   => ['en' => $slug],
            'type'   => 'PAGE',
            'status' => 'active',
        ]);
    }

    private function suggester(): PathSuggester
    {
        return app(PathSuggester::class);
    }

    #[Test]
    public function a_near_miss_is_offered_its_real_path(): void
    {
        $this->page('winter-sale-2026', 'Winter Sale 2026');

        $scored = $this->suggester()->score('winter-sale-2025', 70, 3);

        $this->assertNotSame([], $scored, 'A one-character miss must still be recognised.');
        $this->assertSame('winter-sale-2026', $scored[0]['path']);
        $this->assertSame('Winter Sale 2026', $scored[0]['label']);
    }

    /**
     * The length bound in `rank()` is an exact bound, not a heuristic: the best score two
     * strings of given lengths could possibly reach is fixed before either is read. This is the
     * test that says so — a candidate near the edge of the band is still compared, and still
     * offered.
     *
     * `about` against `about-us` is 6 and 8 characters: the bound allows at most
     * 200 × 6 ÷ 14 = 85%, comfortably over the threshold, so it must survive the skip.
     */
    #[Test]
    public function the_length_bound_does_not_drop_a_candidate_that_would_have_scored(): void
    {
        $this->page('about-us', 'About Us');

        $scored = $this->suggester()->score('about', 70, 3);

        $this->assertSame(['about-us'], array_column($scored, 'path'));
    }

    /**
     * A visitor chooses the length of the path they ask for. Measured on this container before
     * the bound was added: 5,000 candidates against a 2,100-character path cost 2.0 seconds of
     * CPU. The bound refuses the comparison outright, because no string that long can reach the
     * threshold against a slug.
     */
    #[Test]
    public function an_absurdly_long_path_is_answered_immediately_and_offers_nothing(): void
    {
        $this->page('contact', 'Contact');

        $needle = str_repeat('collections/category-x/slug-y/', 70);

        $started = microtime(true);
        $scored  = $this->suggester()->score($needle, 70, 3);
        $elapsed = (microtime(true) - $started) * 1000;

        $this->assertSame([], $scored);

        // Generous by two orders of magnitude against the 2,000ms it used to cost, so this
        // fails on a regression rather than on a slow machine.
        $this->assertLessThan(
            250,
            $elapsed,
            'Scoring a hostile path must not be something a visitor can make expensive.'
        );
    }

    #[Test]
    public function the_needle_is_cut_to_the_width_a_path_can_actually_be_stored_at(): void
    {
        $this->page('news', 'News');

        // Two needles differing only past 255 characters are the same question, so they must
        // reach the same cached answer rather than each paying for its own.
        $base = str_repeat('a', 255);

        $this->assertSame(
            $this->suggester()->score($base . 'xxxxx', 70, 3),
            $this->suggester()->score($base . 'yyyyy', 70, 3)
        );
    }

    /**
     * An answer is memoised per question, so a crawler asking the same dead URL a thousand
     * times pays once — and `forget()` has to reach those answers as well as the candidate list
     * they were derived from, or a new page would never be offered until the entries aged out.
     */
    #[Test]
    public function forgetting_reaches_the_scored_answers_and_not_just_the_candidate_list(): void
    {
        $this->assertSame([], $this->suggester()->score('team-page', 70, 3));

        $this->page('team-pages', 'The Team');

        // Still the old answer: nothing has been forgotten yet.
        $this->assertSame([], $this->suggester()->score('team-page', 70, 3));

        PathSuggester::forget();

        $this->assertSame(
            ['team-pages'],
            array_column($this->suggester()->score('team-page', 70, 3), 'path')
        );
    }
}
