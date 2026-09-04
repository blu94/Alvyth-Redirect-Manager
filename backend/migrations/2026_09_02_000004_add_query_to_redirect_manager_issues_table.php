<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'redirect_manager_issues';

    /**
     * Give a recorded problem the query string it arrived with, and make it part of its
     * identity.
     *
     * **The address this package raised its own core floor for could not be recorded.** A root
     * miss — `/?p=999`, a WordPress permalink for a post that no longer exists — normalises to
     * an empty path, and both the recorder and the 404 listener returned early on one. So the
     * one case query matching exists to serve produced no evidence at all: the operator had
     * nothing on Broken Links to write the rule from.
     *
     * Lifting that guard alone would not have been enough. The identity of a row was
     * `(type, path)` with the query kept in the `context` JSON, so `/?p=123` and `/?p=456` —
     * two different articles — deduplicated into a single row that counted both and named
     * neither. The query has to be part of the key, exactly as it is on a rule.
     *
     * A separate migration rather than a fold into the create, because this package is
     * distributed: an install already running 1.1.0 has these tables, and `enable()` re-runs
     * `migrate --path` on update, so a new file is the only thing that reaches it.
     *
     * Key length: `type`(32) + `path`(255) + `query`(255) at 4 bytes a character is 2,168, a
     * long way inside InnoDB's 3,072-byte limit.
     */
    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table) {
            // NOT NULL with an empty default, for the same reason `redirect_manager_rules`
            // does it: MySQL treats every NULL in a unique index as distinct, so a nullable
            // column here would let the same problem be recorded twice and make the key below
            // decorative.
            $table->string('query', 255)->default('')->after('path');
        });

        // A second statement, because the index has to be rebuilt against a column that
        // already exists.
        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->dropUnique('redirect_manager_issues_type_path_unique');
            $table->unique(['type', 'path', 'query'], 'redirect_manager_issues_identity_unique');
        });
    }

    public function down(): void
    {
        // Before the column goes, so the rows the narrower key cannot hold are gone before it
        // is applied — two entries differing only by query would collide on `(type, path)`.
        // Losing evidence recorded in a shape the old schema cannot express is the honest cost
        // of stepping back, and `down()` is for a developer doing exactly that.
        DB::table(self::TABLE)->where('query', '<>', '')->delete();

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->dropUnique('redirect_manager_issues_identity_unique');
            $table->dropColumn('query');
        });

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->unique(['type', 'path'], 'redirect_manager_issues_type_path_unique');
        });
    }
};
