<?php

namespace Plugin\RedirectManager\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Plugin\RedirectManager\Backend\Models\RedirectSetting;

require_once __DIR__ . '/autoload.php';

/**
 * The one settings row.
 *
 * Everything about how this plugin behaves is here, and it is read on the 404 path — so the
 * question that matters is not what the columns hold but whether a read and a write reach the
 * *same row*. They did not: `firstOrCreate(['id' => 1])` could not create the row it looked
 * for, because `id` is not fillable, so it inserted wherever the auto-increment had reached
 * and missed again on the next call.
 */
class RedirectSettingTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        RedirectSetting::forget();
    }

    protected function tearDown(): void
    {
        RedirectSetting::forget();
        parent::tearDown();
    }

    private function table(): string
    {
        return (new RedirectSetting)->getTable();
    }

    #[Test]
    public function the_row_is_created_at_the_id_everything_looks_it_up_by(): void
    {
        DB::table($this->table())->delete();
        RedirectSetting::forget();

        $this->assertSame(1, RedirectSetting::current()->id);
    }

    /**
     * The failure this replaces. Once the auto-increment has moved past 1 — a deleted row, or a
     * test database where a rolled-back insert leaves the counter advanced — the old call wrote
     * a new row on **every** read, so the table grew per request and no saved setting was ever
     * read back.
     */
    #[Test]
    public function a_second_read_finds_the_row_rather_than_writing_another(): void
    {
        DB::table($this->table())->delete();

        // Move the counter off 1, which is the state the old code could not recover from.
        DB::statement('ALTER TABLE ' . $this->table() . ' AUTO_INCREMENT = 40');

        RedirectSetting::forget();
        RedirectSetting::current();

        RedirectSetting::forget();
        RedirectSetting::current();

        RedirectSetting::forget();
        RedirectSetting::current();

        $this->assertSame(1, DB::table($this->table())->count(), 'One row, however many reads.');
    }

    #[Test]
    public function what_an_operator_saves_is_what_the_next_read_sees(): void
    {
        DB::table($this->table())->delete();
        DB::statement('ALTER TABLE ' . $this->table() . ' AUTO_INCREMENT = 40');
        RedirectSetting::forget();

        RedirectSetting::current()->update(['retention_days' => 90]);
        RedirectSetting::forget();

        $this->assertSame(90, RedirectSetting::current()->retention_days);
    }

    /**
     * Every default lives on the column, so the instance handed back has to be one the database
     * has already filled in. A model built from the attributes passed to it carries `null` for
     * the threshold — and the suggester takes `int $threshold`, so that null is a `TypeError`
     * on the first missing page after a fresh install.
     */
    #[Test]
    public function the_row_it_creates_carries_the_column_defaults(): void
    {
        DB::table($this->table())->delete();
        RedirectSetting::forget();

        $settings = RedirectSetting::current();

        $this->assertTrue($settings->log_404);
        $this->assertTrue($settings->suggest_enabled);
        $this->assertSame(70, $settings->suggest_threshold);
        $this->assertFalse($settings->storefront_suggestions);
    }

    /**
     * The Settings screen already explained why an ignore list is necessary and then shipped
     * none, so every install spent its first weeks proving the point: Broken Links filled with
     * scanners probing for software the site does not serve, burying the handful of real misses
     * an operator can act on.
     */
    #[Test]
    public function a_fresh_install_starts_with_an_ignore_list(): void
    {
        DB::table($this->table())->delete();
        RedirectSetting::forget();

        $settings = RedirectSetting::current();

        $this->assertNotSame([], (array) $settings->ignore_patterns);
        $this->assertTrue($settings->ignores('.git/config'));
        $this->assertTrue($settings->ignores('wp-login.php'));
        $this->assertTrue($settings->ignores('backup.sql'));
    }

    /** Narrow on purpose: nothing seeded may silence a real page, product, post or collection. */
    #[Test]
    public function the_seeded_list_does_not_silence_an_ordinary_broken_link(): void
    {
        DB::table($this->table())->delete();
        RedirectSetting::forget();

        $settings = RedirectSetting::current();

        foreach ([
            'old-page',
            'blog/2019/a-post',
            'products/blue-widget',
            'collections/sale',
            'about-us',
        ] as $path) {
            $this->assertFalse($settings->ignores($path), $path . ' is a real address.');
        }
    }

    /**
     * The memo is per request under every runtime, not per process. A class static survives a
     * request under Octane or a queue worker, and a settings change made elsewhere is then never
     * seen.
     */
    #[Test]
    public function the_memo_is_dropped_with_the_request_rather_than_held_on_the_class(): void
    {
        $first = RedirectSetting::current();

        $this->assertSame($first, RedirectSetting::current(), 'One read per request.');

        RedirectSetting::forget();

        $this->assertNotSame(
            $first,
            RedirectSetting::current(),
            'Forgetting has to reach the memo wherever it is held.'
        );
    }
}
