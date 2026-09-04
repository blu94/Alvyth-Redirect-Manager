<?php

namespace Plugin\RedirectManager\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Plugin\RedirectManager\Backend\Models\RedirectRule;
use Plugin\RedirectManager\Backend\Pages\TransferPage;
use Plugin\RedirectManager\Backend\Services\RedirectMatcher;

require_once __DIR__ . '/autoload.php';

/**
 * Rules in and out as CSV.
 *
 * The most input-driven surface in the package: a migration arrives as a file somebody
 * exported from the site being replaced, so every row is somebody else's idea of what these
 * columns mean. What matters here is that a row this package cannot honour is refused and
 * *said so*, rather than stored in a shape that quietly never fires.
 */
class TransferPageTest extends TestCase
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

    private function page(): TransferPage
    {
        return app(TransferPage::class);
    }

    private function import(string $csv): array
    {
        return $this->page()->save(['import_csv' => $csv]);
    }

    private function export(): string
    {
        return $this->page()->data()['export_csv'];
    }

    // -----------------------------------------------------------------
    // Formula injection
    // -----------------------------------------------------------------

    /**
     * This screen tells the operator to save the export as a `.csv` and open it in a
     * spreadsheet, and a cell beginning `=` is a formula in all three of the ones people use.
     * The first character is not always theirs to choose: Broken Links records the path of
     * every 404 a visitor asks for and offers it as the origin of a new rule.
     */
    #[Test]
    public function a_cell_that_would_be_read_as_a_formula_is_written_as_text(): void
    {
        RedirectRule::create([
            'match_type' => RedirectRule::MATCH_EXACT,
            'from_path'  => '=HYPERLINK("https://elsewhere.example")',
            'to_path'    => 'somewhere',
            'code'       => 301,
            'status'     => RedirectRule::STATUS_ACTIVE,
        ]);

        $this->assertStringContainsString('\'=HYPERLINK', $this->export());
    }

    #[Test]
    public function the_escape_survives_its_own_round_trip(): void
    {
        RedirectRule::create([
            'match_type' => RedirectRule::MATCH_EXACT,
            'from_path'  => '@old-campaign',
            'to_path'    => 'new-campaign',
            'code'       => 301,
            'priority'   => -5,
            'status'     => RedirectRule::STATUS_ACTIVE,
        ]);

        $csv = $this->export();

        RedirectRule::query()->forceDelete();

        $this->import($csv);

        $rule = RedirectRule::query()->first();

        $this->assertNotNull($rule, 'The exported row must import back.');
        $this->assertSame('@old-campaign', $rule->from_path, 'The quote is the escape, not part of the path.');

        // The negative priority is the reason the quote is only stripped when what follows
        // needed escaping: `-5` exports as `'-5`, and a blanket strip would leave `'-5`, which
        // casts to zero.
        $this->assertSame(-5, $rule->priority);
    }

    #[Test]
    public function a_leading_quote_that_is_part_of_the_path_is_left_alone(): void
    {
        $this->import("from,to\n'quoted-path,somewhere\n");

        $this->assertSame(
            "'quoted-path",
            RedirectRule::query()->value('from_path'),
            'Only a quote shielding a formula character is an escape.'
        );
    }

    // -----------------------------------------------------------------
    // Columns that may only hold one of a fixed set
    // -----------------------------------------------------------------

    /**
     * The defect: `match_type` and `code` were validated and `status` was not, so a value
     * outside the set stored a rule that `scopeActive()` never matches. It existed, it listed,
     * it edited — and it redirected nobody, while the import reported success.
     */
    #[Test]
    public function a_status_outside_the_set_is_refused_rather_than_stored(): void
    {
        $result = $this->import("from,to,status\nold-page,new-page,archived\n");

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertStringContainsString('not a valid status', $result['errors'][0]);
        $this->assertStringContainsString('Line 2', $result['errors'][0]);

        $this->assertSame(0, RedirectRule::query()->count());
    }

    /** Refusing a whole file over another tool's word for "active" helps nobody. */
    #[Test]
    public function another_tools_word_for_active_is_understood(): void
    {
        $this->import("from,to,status\nold-page,new-page,enabled\n");

        $rule = RedirectRule::query()->first();

        $this->assertNotNull($rule);
        $this->assertSame(RedirectRule::STATUS_ACTIVE, $rule->status);
        $this->assertTrue(
            RedirectRule::query()->active()->where('from_path', 'old-page')->exists(),
            'The imported rule has to be one the matcher will actually find.'
        );
    }

    #[Test]
    public function another_tools_word_for_inactive_is_understood_too(): void
    {
        $this->import("from,to,status\nold-page,new-page,disabled\n");

        $this->assertSame(RedirectRule::STATUS_INACTIVE, RedirectRule::query()->value('status'));
    }

    #[Test]
    public function the_other_enumerated_columns_are_still_checked(): void
    {
        $result = $this->import(
            "from,to,type,code\n"
            . "a-page,b-page,fuzzy,301\n"
            . "c-page,d-page,exact,418\n"
        );

        $this->assertSame(2, $result['skipped']);
        $this->assertStringContainsString('not a valid match type', $result['errors'][0]);
        $this->assertStringContainsString('not a valid code', $result['errors'][1]);
    }

    #[Test]
    public function an_empty_enumerated_column_still_falls_back_to_its_default(): void
    {
        $this->import("from,to,status,code\nold-page,new-page,,\n");

        $rule = RedirectRule::query()->first();

        $this->assertNotNull($rule);
        $this->assertSame(RedirectRule::STATUS_ACTIVE, $rule->status);
        $this->assertSame(301, $rule->code);
        $this->assertSame(RedirectRule::MATCH_EXACT, $rule->match_type);
    }

    // -----------------------------------------------------------------
    // Saying what actually went wrong
    // -----------------------------------------------------------------

    /**
     * The header is where the whole file turns. Unrecognised, the first line is read as a rule
     * and every row after it is read one column out — and the operator was told, once per line,
     * that a rule needs somewhere to send the visitor. True, and the symptom furthest from the
     * cause.
     */
    #[Test]
    public function an_unrecognised_header_is_named_as_the_cause(): void
    {
        $result = $this->import("Origin,Endpoint\nold-page,new-page\n");

        $this->assertSame(0, $result['created']);
        $this->assertCount(1, $result['errors'], 'One sentence, not one per line.');
        $this->assertStringContainsString('first line names your columns', $result['errors'][0]);
        $this->assertStringContainsString('match_type, from_path', $result['errors'][0]);
    }

    /** Refusing a file over the word another tool chose helps nobody. */
    #[Test]
    public function another_tools_column_names_are_recognised(): void
    {
        foreach ([
            "source,target\na-page,b-page\n",
            "old_url,new_url\nc-page,d-page\n",
            "redirect_from,redirect_to\ne-page,f-page\n",
        ] as $csv) {
            $this->assertSame(1, $this->import($csv)['created'], $csv);
        }

        $this->assertSame(3, RedirectRule::query()->count());
    }

    #[Test]
    public function a_headerless_file_still_reads_by_column_order(): void
    {
        $result = $this->import("exact,old-page,,new-page,301,0,active,,\n");

        $this->assertSame(1, $result['created']);
        $this->assertSame([], $result['errors']);
    }

    // -----------------------------------------------------------------
    // Bounds
    // -----------------------------------------------------------------

    /**
     * The import runs inside one request and inside the transaction the module controller
     * opens. An unbounded paste is a request that times out halfway, with no way for the
     * operator to tell which half applied.
     */
    #[Test]
    public function a_paste_beyond_the_cap_imports_what_it_can_and_says_what_it_left(): void
    {
        $rows = "from,to" . "\n";

        for ($i = 0; $i < 5003; $i++) {
            $rows .= "old-{$i},new-{$i}\n";
        }

        $result = $this->import($rows);

        $this->assertSame(5000, $result['created']);
        $this->assertStringContainsString('5003 rules', $result['errors'][0]);
        $this->assertStringContainsString('Split the file', $result['errors'][0]);
    }

    #[Test]
    public function the_screen_says_how_much_of_the_export_it_is_showing(): void
    {
        RedirectRule::create([
            'match_type' => RedirectRule::MATCH_EXACT,
            'from_path'  => 'only-one',
            'to_path'    => 'somewhere',
            'code'       => 301,
            'status'     => RedirectRule::STATUS_ACTIVE,
        ]);

        $this->assertSame('1 rule, all of them.', $this->page()->data()['export_note']);
    }
}
