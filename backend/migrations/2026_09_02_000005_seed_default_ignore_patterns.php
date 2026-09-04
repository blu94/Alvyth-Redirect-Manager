<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Plugin\RedirectManager\Backend\Models\RedirectSetting;

return new class extends Migration
{
    private const TABLE = 'redirect_manager_settings';

    /**
     * Give an install that has never had an ignore list the one the Settings screen argues for.
     *
     * A fresh install now gets these when the row is created. This is the other half: a site
     * already running has the row, with `ignore_patterns` still NULL, and would otherwise go on
     * recording every probe a vulnerability scanner sends until somebody typed the list by hand.
     *
     * **Only where it is NULL.** An operator who cleared the list has `[]` stored — `SettingsPage`
     * writes an empty array, never null — so "never configured" and "deliberately empty" are
     * distinguishable, and only the first is filled in. Nothing an operator chose is overwritten.
     */
    public function up(): void
    {
        DB::table(self::TABLE)
            ->whereNull('ignore_patterns')
            ->update([
                'ignore_patterns' => json_encode(RedirectSetting::DEFAULT_IGNORE_PATTERNS),
                'updated_at'      => now(),
            ]);
    }

    /**
     * Put back the NULL, but only where the list is still exactly what was seeded.
     *
     * A list an operator has since edited is theirs, and stepping a migration back is not a
     * reason to throw it away.
     */
    public function down(): void
    {
        DB::table(self::TABLE)
            ->where('ignore_patterns', json_encode(RedirectSetting::DEFAULT_IGNORE_PATTERNS))
            ->update(['ignore_patterns' => null, 'updated_at' => now()]);
    }
};
