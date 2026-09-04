<?php

namespace Plugin\RedirectManager\Backend\Pages;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Plugin\RedirectManager\Backend\Models\RedirectIssue;
use Plugin\RedirectManager\Backend\Models\RedirectRule;
use Plugin\RedirectManager\Backend\Services\IssueRecorder;

/**
 * What the Overview screen answers.
 *
 * The questions an operator actually has after a migration, in the order they ask them: is
 * anything still broken, are my rules being used, and is it getting better or worse.
 */
class OverviewPage
{
    /**
     * How many months of history the trend covers. Twelve so a seasonal pattern is visible
     * and a migration six months ago is still on the chart.
     */
    private const MONTHS = 12;

    /** @return array<string,mixed> */
    public function data(): array
    {
        return [
            'rules_total'   => RedirectRule::query()->count(),
            'rules_active'  => RedirectRule::query()->active()->count(),
            'rules_unused'  => RedirectRule::query()->active()->where('hits', 0)->count(),
            'redirects_served' => (int) RedirectRule::query()->sum('hits'),

            'issues_open'   => RedirectIssue::query()->where('resolved', false)->count(),
            'issues_loops'  => RedirectIssue::query()->where('type', RedirectIssue::TYPE_LOOP)
                ->where('resolved', false)->count(),
            'issues_chains' => RedirectIssue::query()->where('type', RedirectIssue::TYPE_CHAIN)
                ->where('resolved', false)->count(),

            // Said out loud, because a list that silently stopped growing reads as a site
            // whose links stopped breaking.
            'recording_state' => IssueRecorder::recordingPaused()
                ? 'Paused — ' . number_format(IssueRecorder::ceiling()) . ' open entries reached. '
                    . 'Addresses already listed keep counting; new ones are not being added. '
                    . 'Deal with some, or widen the ignore list in Settings.'
                : 'Recording',

            'match_type_distribution' => $this->matchTypeDistribution(),
            'top_broken'              => $this->topBroken(),
            'traffic'                 => $this->traffic(),
        ];
    }

    /**
     * Rules by kind.
     *
     * A pie must sum to its population, so every kind gets a slice even at zero — a chart that
     * silently drops empty categories tells an operator they have no pattern rules when what
     * it means is that it decided not to mention them.
     *
     * @return array{labels:array<int,string>,series:array<int,int>}
     */
    private function matchTypeDistribution(): array
    {
        $counts = RedirectRule::query()
            ->selectRaw('match_type, count(*) as total')
            ->groupBy('match_type')
            ->pluck('total', 'match_type');

        $labels = ['Exact', 'Prefix', 'Pattern'];
        $series = [
            (int) ($counts[RedirectRule::MATCH_EXACT] ?? 0),
            (int) ($counts[RedirectRule::MATCH_PREFIX] ?? 0),
            (int) ($counts[RedirectRule::MATCH_REGEX] ?? 0),
        ];

        return compact('labels', 'series');
    }

    /**
     * The dead paths still getting the most traffic.
     *
     * Ten, because this is a prompt to go and fix something rather than a report — a list long
     * enough to scroll is one nobody works through.
     *
     * @return array{labels:array<int,string>,series:array<int,array{name:string,data:array<int,int>}>}
     */
    private function topBroken(): array
    {
        $rows = RedirectIssue::query()
            ->where('type', RedirectIssue::TYPE_NOT_FOUND)
            ->where('resolved', false)
            ->orderByDesc('hits')
            ->limit(10)
            ->get(['path', 'hits']);

        return [
            'labels' => $rows->pluck('path')->map(fn ($p) => '/' . $p)->all(),
            'series' => [[
                'name' => 'Hits',
                'data' => $rows->pluck('hits')->map(fn ($h) => (int) $h)->all(),
            ]],
        ];
    }

    /**
     * Old URLs still being followed, and new dead paths found, by month.
     *
     * **Empty months are filled with zeros rather than omitted.** A chart that drops quiet
     * periods compresses a gap into nothing and makes a trend look steadier than it was —
     * which here would hide the thing the chart exists to show, a migration's tail dying down.
     *
     * **The first series counts rules, not redirects, and says so.** It used to plot
     * `sum(hits)` bucketed by `last_hit_at` under the label "Redirects served" — and `hits` is
     * a rule's running total since it was created while `last_hit_at` is a single moment. A
     * rule that had served ten thousand redirects over a year put all ten thousand into
     * whichever month it was last used, and moved all ten thousand to the next month the
     * moment it was used again. The chart rewrote its own history every month, and no bar on
     * it was the number of redirects served in that month.
     *
     * `last_hit_at` is the only date a rule carries, so the honest question it can answer is
     * *how many old URLs were still being followed* in a given month — which is the migration
     * -tail question an operator actually has. A true redirects-per-month series needs a
     * per-hit or per-day counter this plugin deliberately does not keep: the write path is one
     * bare UPDATE per redirect, and that is the property the whole feature is built around.
     *
     * @return array{labels:array<int,string>,series:array<int,array{name:string,data:array<int,int>}>}
     */
    private function traffic(): array
    {
        $start  = Carbon::now()->startOfMonth()->subMonths(self::MONTHS - 1);
        $labels = [];
        $keys   = [];

        for ($i = 0; $i < self::MONTHS; $i++) {
            $month    = $start->copy()->addMonths($i);
            $keys[]   = $month->format('Y-m');
            $labels[] = $month->format('M Y');
        }

        $used = $this->monthly(
            RedirectRule::query()->whereNotNull('last_hit_at')->where('last_hit_at', '>=', $start),
            'last_hit_at'
        );

        $found = $this->monthly(
            RedirectIssue::query()
                ->where('type', RedirectIssue::TYPE_NOT_FOUND)
                ->where('created_at', '>=', $start),
            'created_at'
        );

        return [
            'labels' => $labels,
            'series' => [
                ['name' => 'Rules last used', 'data' => array_map(fn ($k) => (int) ($used[$k] ?? 0), $keys)],
                ['name' => 'New dead paths',  'data' => array_map(fn ($k) => (int) ($found[$k] ?? 0), $keys)],
            ],
        ];
    }

    /**
     * How many rows fall in each `YYYY-MM`.
     *
     * Grouped with `DATE_FORMAT` rather than in PHP so a shop with a long history does not
     * hydrate every row to bucket it.
     *
     * **Counting only.** It could also sum a column, and that is exactly what put a rule's
     * lifetime `hits` under a monthly label — a total and a period are different questions and
     * this answers the second one. Anything that needs a sum needs a table with a row per
     * period to sum, not a column that only ever grows.
     *
     * @return array<string,int>
     */
    private function monthly($query, string $dateColumn): array
    {
        // A constant from this class, never request input — but it is still interpolated into
        // raw SQL, so anything added here has to stay that way.
        return $query
            ->selectRaw("DATE_FORMAT({$dateColumn}, '%Y-%m') as bucket, count(*) as total")
            ->groupBy('bucket')
            ->pluck('total', 'bucket')
            ->map(fn ($v) => (int) $v)
            ->all();
    }
}
