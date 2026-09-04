<?php

namespace Plugin\RedirectManager\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Plugin\RedirectManager\Backend\Models\RedirectIssue;
use Plugin\RedirectManager\Backend\Models\RedirectRule;
use Plugin\RedirectManager\Backend\Repositories\RedirectRuleRepository;
use Plugin\RedirectManager\Backend\Services\RedirectMatcher;

require_once __DIR__ . '/autoload.php';

/**
 * Writing a rule takes the broken links it fixes off the open list.
 *
 * The promise the Broken Links screen makes — fix something and it leaves the list — held for
 * exact rules only. A prefix rule that moved a whole section, which is the case prefix rules
 * exist for, left every path under it open with the detail screen reporting that nothing fixed
 * it. Both halves now ask the matcher, so the screen and the storefront cannot disagree.
 */
class IssueClosingTest extends TestCase
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

    private function miss(string $path, string $query = ''): RedirectIssue
    {
        return RedirectIssue::create([
            'type'         => RedirectIssue::TYPE_NOT_FOUND,
            'path'         => $path,
            'query'        => $query,
            'hits'         => 1,
            'last_seen_at' => now(),
        ]);
    }

    private function create(array $attributes): RedirectRule
    {
        return app(RedirectRuleRepository::class)->create(array_replace([
            'match_type' => RedirectRule::MATCH_EXACT,
            'code'       => 301,
            'status'     => RedirectRule::STATUS_ACTIVE,
        ], $attributes));
    }

    private function isOpen(RedirectIssue $issue): bool
    {
        return ! $issue->fresh()->resolved;
    }

    #[Test]
    public function an_exact_rule_closes_the_path_it_names(): void
    {
        $issue = $this->miss('old-page');

        $this->create(['from_path' => 'old-page', 'to_path' => 'new-page']);

        $this->assertFalse($this->isOpen($issue));
    }

    /** The defect: one rule moves a section, and every path under it stayed open. */
    #[Test]
    public function a_prefix_rule_closes_everything_under_the_branch_it_moved(): void
    {
        $index = $this->miss('blog');
        $post  = $this->miss('blog/old-post');
        $deep  = $this->miss('blog/2019/03/a-post');
        $other = $this->miss('shop/gone');

        $this->create([
            'match_type' => RedirectRule::MATCH_PREFIX,
            'from_path'  => 'blog',
            'to_path'    => 'news/$1',
        ]);

        $this->assertFalse($this->isOpen($index), 'The branch itself moved too.');
        $this->assertFalse($this->isOpen($post));
        $this->assertFalse($this->isOpen($deep));
        $this->assertTrue($this->isOpen($other), 'A path the rule does not cover is untouched.');
    }

    #[Test]
    public function a_pattern_rule_closes_the_paths_it_actually_matches(): void
    {
        $matched = $this->miss('archive/2019/hello-world');
        $missed  = $this->miss('archive/hello-world');

        $this->create([
            'match_type' => RedirectRule::MATCH_REGEX,
            'from_path'  => '^archive/(\d{4})/(.+)$',
            'to_path'    => 'news/$2',
        ]);

        $this->assertFalse($this->isOpen($matched));
        $this->assertTrue($this->isOpen($missed), 'The pattern decides, not the prefix of it.');
    }

    /**
     * The SQL that narrows which rows are asked about is a superset filter and must never
     * withhold a row the rule covers. A path carrying a LIKE wildcard is where an unescaped
     * filter would go wrong in the other direction — matching far beyond the branch.
     */
    #[Test]
    public function a_path_containing_a_like_wildcard_does_not_widen_the_sweep(): void
    {
        $inside  = $this->miss('50%25off/details');
        $outside = $this->miss('50-off-sale/details');

        $this->create([
            'match_type' => RedirectRule::MATCH_PREFIX,
            'from_path'  => '50%25off',
            'to_path'    => 'sale/$1',
        ]);

        $this->assertFalse($this->isOpen($inside));
        $this->assertTrue($this->isOpen($outside), 'The % is part of the path, not a wildcard.');
    }

    #[Test]
    public function a_disabled_rule_closes_nothing(): void
    {
        $issue = $this->miss('old-page');

        $this->create(['from_path' => 'old-page', 'to_path' => 'new-page', 'status' => 'inactive']);

        $this->assertTrue($this->isOpen($issue), 'A rule that fires for nobody has fixed nothing.');
    }

    // -----------------------------------------------------------------
    // What the detail screen reports
    // -----------------------------------------------------------------

    #[Test]
    public function the_screen_names_the_prefix_rule_that_fixes_a_path(): void
    {
        $issue = $this->miss('blog/old-post');

        $rule = $this->create([
            'match_type' => RedirectRule::MATCH_PREFIX,
            'from_path'  => 'blog',
            'to_path'    => 'news/$1',
        ]);

        $this->assertSame($rule->id, $issue->rule()?->id);
    }

    #[Test]
    public function the_screen_names_no_rule_when_nothing_covers_the_path(): void
    {
        $issue = $this->miss('still-broken');

        $this->create(['from_path' => 'something-else', 'to_path' => 'elsewhere']);

        $this->assertNull($issue->rule());
    }

    /**
     * A loop is not a landing. The visitor never arrives, so the entry is still a job to do and
     * the screen must not report it as handled.
     */
    #[Test]
    public function a_path_whose_rules_point_in_a_circle_is_not_reported_as_fixed(): void
    {
        // The circle first, so the sweep that runs on each write has never seen this path
        // landing anywhere. Written the other way round the first rule legitimately closes the
        // entry — it did resolve at that moment — and the second one breaks it again.
        $this->create(['from_path' => 'round-a', 'to_path' => 'round-b']);
        $this->create(['from_path' => 'round-b', 'to_path' => 'round-a']);

        $issue = $this->miss('round-a');

        $this->assertNull($issue->rule(), 'A visitor never arrives, so nothing has been fixed.');
        $this->assertTrue($this->isOpen($issue));
    }

    /**
     * The address this package raised its core floor for. A rule for the site root fires only
     * for its own query, so the sweep has to hand the query over — asking about the path alone
     * would leave the one case query matching exists for open forever.
     */
    #[Test]
    public function a_root_rule_closes_the_root_miss_that_carries_its_query(): void
    {
        $mine  = $this->miss('', 'p=123');
        $other = $this->miss('', 'p=456');

        $rule = $this->create([
            'from_path'   => '',
            'query_match' => 'p=123',
            'to_path'     => 'the-article',
        ]);

        $this->assertFalse($this->isOpen($mine));
        $this->assertTrue($this->isOpen($other), 'A different article is a different problem.');
        $this->assertSame($rule->id, $mine->rule()?->id);
    }
}
