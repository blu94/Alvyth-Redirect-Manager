<?php

namespace Plugin\RedirectManager\Tests;

use App\Events\PathNotResolved;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Plugin\RedirectManager\Backend\Listeners\ResolveRedirect;
use Plugin\RedirectManager\Backend\Models\RedirectRule;
use Plugin\RedirectManager\Backend\Services\RedirectMatcher;

require_once __DIR__ . '/autoload.php';

/**
 * Following an old link must not change which language the visitor is reading.
 *
 * Core strips the locale segment before asking — a rule is written about `laman-lama`, not
 * about each locale's spelling of the URL above it — and the destination was built from the
 * stripped path alone. So a Malay visitor following a moved URL arrived on the English site,
 * as a side effect of a redirect that was otherwise correct.
 */
class LocaleTest extends TestCase
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
            'match_type' => RedirectRule::MATCH_EXACT,
            'from_path'  => 'laman-lama',
            'to_path'    => 'laman-baru',
            'code'       => 301,
            'status'     => RedirectRule::STATUS_ACTIVE,
        ], $attributes));

        RedirectMatcher::forget();

        return $rule;
    }

    /** What core does: dispatch the stripped path, with the segment it stripped beside it. */
    private function ask(string $path, ?string $locale = null): PathNotResolved
    {
        $event = new PathNotResolved($path, null, [], $locale);

        app(ResolveRedirect::class)->onPathNotResolved($event);

        return $event;
    }

    #[Test]
    public function a_visitor_reading_in_their_own_locale_stays_in_it(): void
    {
        $this->rule();

        $this->assertSame(url('/ms/laman-baru'), $this->ask('laman-lama', 'ms')->target);
    }

    #[Test]
    public function a_request_carrying_no_locale_is_unchanged(): void
    {
        $this->rule();

        $this->assertSame(url('/laman-baru'), $this->ask('laman-lama')->target);
    }

    /** Another host's locales are not ours to guess. */
    #[Test]
    public function an_off_site_destination_is_left_exactly_as_it_was_written(): void
    {
        $this->rule(['to_path' => 'https://elsewhere.example/page']);

        $this->assertSame(
            'https://elsewhere.example/page',
            $this->ask('laman-lama', 'ms')->target
        );
    }

    #[Test]
    public function the_locale_survives_a_prefix_rules_captured_remainder(): void
    {
        $this->rule([
            'match_type' => RedirectRule::MATCH_PREFIX,
            'from_path'  => 'blog',
            'to_path'    => 'berita/$1',
        ]);

        $this->assertSame(
            url('/ms/berita/pos-lama'),
            $this->ask('blog/pos-lama', 'ms')->target
        );
    }

    #[Test]
    public function a_home_page_destination_still_reaches_the_locales_home_page(): void
    {
        $this->rule(['to_path' => '/']);

        $this->assertSame(url('/ms'), $this->ask('laman-lama', 'ms')->target);
    }

    #[Test]
    public function the_url_builder_leaves_an_absolute_destination_alone(): void
    {
        $this->assertSame(
            'https://elsewhere.example/x',
            RedirectRule::toUrl('https://elsewhere.example/x', 'ms')
        );
    }
}
