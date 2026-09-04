<?php

namespace Plugin\RedirectManager\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Plugin\RedirectManager\Backend\Models\RedirectIssue;
use Plugin\RedirectManager\Backend\Services\IssueRecorder;

require_once __DIR__ . '/autoload.php';

/**
 * Writing a problem down, once per address.
 *
 * Rows here are written by anonymous visitors on the 404 path, so both halves of what
 * identifies one — the path and the query — are chosen by whoever sends the request. What
 * matters is that the address is recorded truthfully, that two different addresses stay two
 * rows, and that nothing a stranger can type stops a row being written at all.
 */
class IssueRecorderTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        RedirectIssue::query()->delete();
    }

    protected function tearDown(): void
    {
        RedirectIssue::query()->delete();
        parent::tearDown();
    }

    private function recorder(): IssueRecorder
    {
        return app(IssueRecorder::class);
    }

    private function record(string $path, string $query = '', array $context = []): void
    {
        $this->recorder()->record(RedirectIssue::TYPE_NOT_FOUND, $path, $context, $query);
    }

    /** The address this package raised its own core floor to serve. */
    #[Test]
    public function a_miss_on_the_site_root_carrying_a_query_is_recorded(): void
    {
        $this->record('', 'p=999');

        $issue = RedirectIssue::query()->first();

        $this->assertNotNull($issue, 'A root miss used to leave no evidence at all.');
        $this->assertSame('p=999', $issue->query);
        $this->assertSame('/', $issue->path, 'The front page reads as an address, not a blank.');
        $this->assertSame('', $issue->getRawOriginal('path'), 'Stored as the matcher wants it.');
    }

    #[Test]
    public function two_old_permalinks_sharing_the_root_stay_two_entries(): void
    {
        $this->record('', 'p=123');
        $this->record('', 'p=456');

        $this->assertSame(2, RedirectIssue::query()->count());
        $this->assertSame(
            ['p=123', 'p=456'],
            RedirectIssue::query()->orderBy('id')->pluck('query')->all()
        );
    }

    #[Test]
    public function the_same_address_twice_is_one_row_with_two_hits(): void
    {
        $this->record('gone', 'utm=x');
        $this->record('gone', 'utm=x');

        $this->assertSame(1, RedirectIssue::query()->count());
        $this->assertSame(2, RedirectIssue::query()->value('hits'));
    }

    #[Test]
    public function the_same_path_with_and_without_a_query_are_different_problems(): void
    {
        $this->record('gone');
        $this->record('gone', 'p=1');

        $this->assertSame(2, RedirectIssue::query()->count());
    }

    /** A bare `/` is the home page, not a missing address. */
    #[Test]
    public function an_address_with_neither_a_path_nor_a_query_is_not_recorded(): void
    {
        $this->record('');
        $this->record('/');

        $this->assertSame(0, RedirectIssue::query()->count());
    }

    /**
     * The defect this replaces: a path longer than its column threw, the failure was swallowed,
     * and no row appeared — so the dead URLs a broken link builder generates, which are exactly
     * the long ones, were the ones never recorded.
     */
    #[Test]
    public function an_over_long_path_is_cut_to_the_column_rather_than_dropped(): void
    {
        $this->record(str_repeat('a', 400));

        $issue = RedirectIssue::query()->first();

        $this->assertNotNull($issue, 'A long dead URL is still a dead URL.');
        $this->assertSame(255, mb_strlen($issue->getRawOriginal('path')));
    }

    #[Test]
    public function an_over_long_query_is_cut_too(): void
    {
        $this->record('gone', str_repeat('b', 400));

        $this->assertSame(255, mb_strlen(RedirectIssue::query()->value('query')));
    }

    #[Test]
    public function a_query_copied_out_of_the_address_bar_keeps_its_leading_question_mark_off(): void
    {
        $this->record('gone', '?p=7');

        $this->assertSame('p=7', RedirectIssue::query()->value('query'));
    }

    #[Test]
    public function a_kind_this_screen_does_not_show_is_refused(): void
    {
        $this->recorder()->record('SOMETHING_ELSE', 'gone');

        $this->assertSame(0, RedirectIssue::query()->count());
    }
}
