<?php

namespace Plugin\RedirectManager\Tests;

use App\Events\PathNotResolved;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Plugin\RedirectManager\Backend\Listeners\ResolveRedirect;
use Plugin\RedirectManager\Backend\Models\RedirectIssue;
use Plugin\RedirectManager\Backend\Models\RedirectRule;
use Plugin\RedirectManager\Backend\Services\RedirectMatcher;

require_once __DIR__ . '/autoload.php';

/**
 * What answering a 404 writes down.
 *
 * The listener is the whole plugin as far as a visitor is concerned, and two of the things it
 * records are read back as advice: the hit counter decides which rules the Overview offers up
 * as never used, and a recorded miss is a job on somebody's list. Both were being written
 * about things that had not happened.
 */
class ResolveRedirectTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        RedirectRule::query()->forceDelete();
        RedirectIssue::query()->delete();
        RedirectMatcher::forget();
    }

    protected function tearDown(): void
    {
        RedirectRule::query()->forceDelete();
        RedirectIssue::query()->delete();
        RedirectMatcher::forget();
        parent::tearDown();
    }

    private function rule(string $from, string $to): RedirectRule
    {
        $rule = RedirectRule::create([
            'match_type' => RedirectRule::MATCH_EXACT,
            'from_path'  => $from,
            'to_path'    => $to,
            'code'       => 301,
            'status'     => RedirectRule::STATUS_ACTIVE,
        ]);

        RedirectMatcher::forget();

        return $rule;
    }

    private function ask(string $path, array $query = []): PathNotResolved
    {
        $event = new PathNotResolved($path, null, $query);

        app(ResolveRedirect::class)->onPathNotResolved($event);

        return $event;
    }

    private function hits(RedirectRule $rule): int
    {
        return (int) RedirectRule::query()->whereKey($rule->id)->value('hits');
    }

    /**
     * A chain is collapsed so the visitor arrives in one hop — but every rule in it did the
     * work. Counting only the first left the middle reporting zero hits, and the Overview
     * offers those up under "Rules never used": an invitation to delete the rule that makes the
     * chain resolve.
     */
    #[Test]
    public function every_rule_a_chain_went_through_is_counted(): void
    {
        $first  = $this->rule('a-page', 'b-page');
        $second = $this->rule('b-page', 'c-page');

        $this->ask('a-page');

        $this->assertSame(1, $this->hits($first));
        $this->assertSame(1, $this->hits($second), 'The rule in the middle served this visitor too.');
    }

    #[Test]
    public function a_rule_the_walk_never_reached_is_not_counted(): void
    {
        $used   = $this->rule('a-page', 'b-page');
        $unused = $this->rule('somewhere-else', 'anywhere');

        $this->ask('a-page');

        $this->assertSame(1, $this->hits($used));
        $this->assertSame(0, $this->hits($unused));
    }

    #[Test]
    public function a_single_rule_still_counts_once(): void
    {
        $rule = $this->rule('a-page', 'b-page');

        $this->ask('a-page');
        $this->ask('a-page');

        $this->assertSame(2, $this->hits($rule));
    }

    // -----------------------------------------------------------------
    // Standing aside when somebody else has answered
    // -----------------------------------------------------------------

    /**
     * Core takes the first offer, so a second plugin answering has nothing to add. What matters
     * is that it also stops *writing*: a miss recorded here would put a path that redirects
     * perfectly well onto Broken Links as a dead end.
     */
    #[Test]
    public function a_path_another_plugin_already_answered_is_not_recorded_as_broken(): void
    {
        $event = new PathNotResolved('gone-elsewhere');
        $event->redirectTo(url('/somewhere'), 302);

        app(ResolveRedirect::class)->onPathNotResolved($event);

        $this->assertSame(0, RedirectIssue::query()->count());
        $this->assertSame(url('/somewhere'), $event->target, 'The first offer stands.');
        $this->assertSame(302, $event->code);
    }

    #[Test]
    public function a_rule_of_this_plugins_own_is_not_counted_once_somebody_else_has_answered(): void
    {
        $rule  = $this->rule('a-page', 'b-page');
        $event = new PathNotResolved('a-page');
        $event->redirectTo(url('/decided-already'), 301);

        app(ResolveRedirect::class)->onPathNotResolved($event);

        $this->assertSame(0, $this->hits($rule), 'It served nobody, so it counts nothing.');
    }

    #[Test]
    public function an_ordinary_miss_is_still_recorded(): void
    {
        $this->ask('nothing-here');

        $this->assertSame(1, RedirectIssue::query()->where('path', 'nothing-here')->count());
    }
}
