<?php

namespace Plugin\RedirectManager\Backend\Models;

use App\Traits\LogsSystemActivity;
use Illuminate\Database\Eloquent\Model;

/**
 * How the plugin behaves — one row, id 1.
 *
 * Read on the 404 path, so `current()` is memoised for the life of the request: a single page
 * miss can consult these several times (once to decide whether to log, once per suggestion
 * pass) and none of them should be a separate query.
 */
class RedirectSetting extends Model
{
    /**
     * Switching off 404 logging, or widening the ignore list, makes evidence stop appearing —
     * which looks exactly like nothing being broken. The audit trail is what distinguishes the
     * two months later.
     */
    use LogsSystemActivity;

    protected $table = 'redirect_manager_settings';

    protected $fillable = [
        'log_404',
        'ignore_patterns',
        'suggest_enabled',
        'suggest_threshold',
        'storefront_suggestions',
        'retention_days',
    ];

    protected $casts = [
        'log_404'                => 'boolean',
        'ignore_patterns'        => 'array',
        'suggest_enabled'        => 'boolean',
        'suggest_threshold'      => 'integer',
        'storefront_suggestions' => 'boolean',
        'retention_days'         => 'integer',
    ];

    /**
     * The ignore list a fresh install starts with.
     *
     * The Settings screen already explains why these are necessary — *"without a few of these,
     * Broken Links fills up with automated scanners probing for files and software your site
     * does not serve, and the handful of real broken links your visitors actually hit gets
     * buried"* — and then shipped none of them, so every install spent its first weeks proving
     * the point. Recording is filtered at write time, so a pattern here is a row never written
     * rather than a row hidden later.
     *
     * Deliberately narrow: software this site does not serve, and files it must never serve.
     * Nothing here can match a page, product, post or collection URL, so no real broken link is
     * silenced by a default an operator did not choose. They are ordinary rows on the screen —
     * visible, editable, and removable.
     */
    public const DEFAULT_IGNORE_PATTERNS = [
        '.git/*',
        '.env',
        '*.sql',
        '*.php',
        '*.asp',
        '*.aspx',
        'wp-*',
        'wordpress/*',
        'vendor/*',
        'cgi-bin/*',
        '.well-known/*',
    ];

    /**
     * The container key the per-request memo is held under.
     *
     * **Not a class static.** Under php-fpm a static is per-request by accident of the process
     * model, and this plugin does not control the process model: under Octane, Swoole or a
     * long-running queue worker a static outlives the request that set it, so a settings change
     * made anywhere else is never seen and the model instance outlives its connection. A
     * container binding is per-request under every runtime and behaves identically under this
     * one.
     */
    private const MEMO = 'redirect-manager.settings';

    /**
     * The settings row, created with the column defaults if it is not there yet.
     *
     * Created on demand rather than seeded: the row has to exist the first time anything reads
     * it, and a plugin's migrations run on enable while its first 404 could arrive a
     * millisecond later.
     *
     * **Not `firstOrCreate(['id' => 1])`, which cannot create the row it looks for.** `id` is
     * not fillable, so the create dropped it and inserted at whatever the auto-increment had
     * reached; the next miss looked for id 1 again, still did not find it, and inserted
     * another. On a fresh install the counter happens to be at 1 and it works by luck. Once
     * anything has moved the counter past 1 — a row deleted, or a test database where a
     * rolled-back insert leaves the counter advanced — every call that misses the memo writes
     * a *new* row, so the table grows per request and nothing an operator saves in Settings is
     * ever read back. Observed: two rows at id 5 and 6, neither reachable, retention set and
     * gone on the next read.
     *
     * `insertOrIgnore` states the id and is safe against the race the old call also had: two
     * requests arriving together on a fresh install both created a row, and one lost to the
     * primary key. Whoever wins, the row exists; the read below finds it either way.
     *
     * Read back rather than constructed, because every default lives on the column: a model
     * built from the values passed in carries `null` for the threshold, the log switch and
     * everything else. That mattered — the suggester takes `int $threshold`, and a null there
     * is a `TypeError` the listener's own catch turns into a log line and no redirect. Silent,
     * once, on a fresh install: the hardest shape of bug to be told about.
     */
    public static function current(): self
    {
        if (app()->bound(self::MEMO)) {
            return app(self::MEMO);
        }

        $settings = self::query()->find(1);

        if ($settings === null) {
            self::query()->insertOrIgnore([
                'id'              => 1,
                'ignore_patterns' => json_encode(self::DEFAULT_IGNORE_PATTERNS),
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            $settings = self::query()->findOrFail(1);
        }

        app()->instance(self::MEMO, $settings);

        return $settings;
    }

    /** Drop the memo. Called after a save so the next read sees what was just written. */
    public static function forget(): void
    {
        app()->forgetInstance(self::MEMO);
    }

    /**
     * Whether a path should be left unrecorded.
     *
     * Globs rather than regular expressions: an operator writing an ignore list is thinking
     * "anything under .git", and `fnmatch` says that as `.git/*` without a chance of writing a
     * pattern that backtracks catastrophically on the 404 path.
     *
     * `FNM_CASEFOLD` because these are noise filters, not routing — someone excluding
     * `.git/*` means it whichever case the scanner used.
     */
    public function ignores(string $path): bool
    {
        foreach ((array) $this->ignore_patterns as $pattern) {
            $pattern = trim((string) $pattern, "/ \t\n\r\0\x0B");

            if ($pattern === '') {
                continue;
            }

            if (fnmatch($pattern, $path, FNM_CASEFOLD)) {
                return true;
            }
        }

        return false;
    }
}
