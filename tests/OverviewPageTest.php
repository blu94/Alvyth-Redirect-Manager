<?php

namespace Plugin\RedirectManager\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Plugin\RedirectManager\Backend\Models\RedirectIssue;
use Plugin\RedirectManager\Backend\Models\RedirectRule;
use Plugin\RedirectManager\Backend\Pages\OverviewPage;

require_once __DIR__ . '/autoload.php';

/**
 * What the Overview screen claims.
 *
 * A chart is an assertion about the data, and the one that matters here is that a bar's label
 * describes what the bar counts. It did not: the monthly series summed each rule's *lifetime*
 * `hits` into whichever month it was last used, under the label "Redirects served".
 */
class OverviewPageTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        RedirectRule::query()->forceDelete();
        RedirectIssue::query()->delete();
    }

    protected function tearDown(): void
    {
        RedirectRule::query()->forceDelete();
        RedirectIssue::query()->delete();
        parent::tearDown();
    }

    private function rule(array $attributes = []): RedirectRule
    {
        return RedirectRule::create(array_replace([
            'match_type' => RedirectRule::MATCH_EXACT,
            'from_path'  => 'old-' . bin2hex(random_bytes(4)),
            'to_path'    => 'new-page',
            'code'       => 301,
            'status'     => RedirectRule::STATUS_ACTIVE,
        ], $attributes));
    }

    /** The bucket for the current month, from the series named. */
    private function thisMonth(string $series): int
    {
        $traffic = app(OverviewPage::class)->data()['traffic'];

        $index = array_search(now()->format('M Y'), $traffic['labels'], true);
        $this->assertNotFalse($index, 'The current month must be on the chart.');

        foreach ($traffic['series'] as $line) {
            if ($line['name'] === $series) {
                return $line['data'][$index];
            }
        }

        $this->fail("No series named '{$series}'.");
    }

    /**
     * The defect, stated as an assertion: one rule used this month is **one**, whatever its
     * lifetime total says. Under the old aggregate this bar read 10,000 — and would have read
     * 10,000 again next month the moment the rule was hit once more, while this month dropped
     * to zero.
     */
    #[Test]
    public function a_rules_lifetime_total_does_not_become_this_months_figure(): void
    {
        $rule = $this->rule();

        RedirectRule::query()->whereKey($rule->id)->update([
            'hits'        => 10000,
            'last_hit_at' => now(),
        ]);

        $this->assertSame(1, $this->thisMonth('Rules last used'));
    }

    #[Test]
    public function each_rule_used_in_a_month_counts_once(): void
    {
        foreach ([5, 900, 12] as $hits) {
            $rule = $this->rule();
            RedirectRule::query()->whereKey($rule->id)->update([
                'hits'        => $hits,
                'last_hit_at' => now(),
            ]);
        }

        $this->assertSame(3, $this->thisMonth('Rules last used'));
    }

    #[Test]
    public function a_rule_never_used_is_on_no_month_at_all(): void
    {
        $this->rule();

        $this->assertSame(0, $this->thisMonth('Rules last used'));
    }

    /** The all-time tile is a total, and a total is what `sum(hits)` honestly is. */
    #[Test]
    public function the_lifetime_total_is_still_reported_where_it_belongs(): void
    {
        $rule = $this->rule();
        RedirectRule::query()->whereKey($rule->id)->update(['hits' => 10000, 'last_hit_at' => now()]);

        $this->assertSame(10000, app(OverviewPage::class)->data()['redirects_served']);
    }

    #[Test]
    public function new_dead_paths_are_counted_in_the_month_they_were_found(): void
    {
        RedirectIssue::create([
            'type'         => RedirectIssue::TYPE_NOT_FOUND,
            'path'         => 'gone-' . bin2hex(random_bytes(3)),
            'hits'         => 44,
            'last_seen_at' => now(),
        ]);

        // One path found, whatever number of times it was asked for.
        $this->assertSame(1, $this->thisMonth('New dead paths'));
    }
}
