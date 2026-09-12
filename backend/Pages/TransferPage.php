<?php

namespace Plugin\RedirectManager\Backend\Pages;

use Plugin\RedirectManager\Backend\Models\RedirectRule;
use Plugin\RedirectManager\Backend\Services\RedirectMatcher;

/**
 * Import and export rules as CSV.
 *
 * A migration does not arrive as a hundred forms filled in by hand. It arrives as a file
 * somebody exported from the site being replaced — Redirection, Yoast, or a spreadsheet a
 * developer assembled — and the first thing an operator wants is to paste it in.
 *
 * **Text areas rather than a file picker, and this is a platform limit rather than a choice.**
 * A plugin registers no routes, so everything it does goes through the nine generic module
 * endpoints, and the one that serves a custom page always wraps its return value in
 * `response()->json()`. There is no way for a package to stream a file back, and there is no
 * `file` field type to send one up. Pasting works, costs nothing, and is honest about what it
 * is; the alternative was a download button that produced a `.csv` full of JSON.
 *
 * Alvyth's own import pipeline is not an option either: `ImporterRegistry::DRIVERS` is a
 * private constant, so a plugin cannot add a resource to it.
 */
class TransferPage
{
    /**
     * Columns, in the order they are written and the order assumed when a file has no header.
     */
    private const COLUMNS = [
        'match_type', 'from_path', 'query_match', 'to_path',
        'code', 'priority', 'status', 'starts_at', 'ends_at',
    ];

    /** Errors reported back. Beyond this the list stops being read and starts being scrolled. */
    private const MAX_ERRORS = 20;

    /**
     * The most rules read from one paste, and the most written into one export.
     *
     * Both directions run inside a single request, and the import runs inside the transaction
     * `GenericModuleController::savePageData()` opens — so an unbounded file is a request that
     * times out halfway with no way for the operator to tell which half applied. The export had
     * the same shape from the other end: the whole table was assembled into one string and
     * returned inside a JSON response every time the screen was opened, wanted or not.
     *
     * Five thousand is far past a migration's worth of rules and small enough to stay a form
     * submit. A bound stated on the screen is a bound an operator can work with; a timeout is
     * not.
     */
    private const MAX_ROWS = 5000;

    /**
     * What another tool calls a column.
     *
     * A migration arrives as somebody else's export, and the origin column is where the whole
     * file turns on: if it is not recognised the first line is read as data, every row comes out
     * without a destination, and the operator is told once per line that a rule needs somewhere
     * to send the visitor — which names the symptom furthest from the cause.
     */
    private const COLUMN_NAMES = [
        'from'         => 'from_path',
        'source'       => 'from_path',
        'old'          => 'from_path',
        'old_url'      => 'from_path',
        'url'          => 'from_path',
        'redirect_from' => 'from_path',
        'to'           => 'to_path',
        'target'       => 'to_path',
        'destination'  => 'to_path',
        'new'          => 'to_path',
        'new_url'      => 'to_path',
        'redirect_to'  => 'to_path',
        'type'         => 'match_type',
        'match'        => 'match_type',
        'query'        => 'query_match',
    ];

    /**
     * Every column that may only hold one of a fixed set, and the set.
     *
     * One list rather than a check per column, because the asymmetry is what let a defect
     * through: `match_type` and `code` were both validated and `status` was not, so a file
     * calling the column `enabled` — which is what Redirection and several WordPress exporters
     * call it — imported cleanly, reported "1 created", and stored a rule that `scopeActive()`
     * never matches. It existed, it listed, it edited, and it redirected nobody.
     *
     * A fourth enumerated column cannot now be added without its check.
     */
    private const ENUMERATED = [
        'match_type' => RedirectRule::MATCH_TYPES,
        'status'     => RedirectRule::STATUSES,
        'code'       => RedirectRule::CODES,
    ];

    /**
     * What another tool's word for a value means here.
     *
     * The same courtesy `header()` extends to column names, extended to the values in them: a
     * migration arrives as somebody else's export, and refusing a whole file over the word
     * "enabled" helps nobody.
     */
    private const SYNONYMS = [
        'status' => [
            'enabled'  => RedirectRule::STATUS_ACTIVE,
            'on'       => RedirectRule::STATUS_ACTIVE,
            'yes'      => RedirectRule::STATUS_ACTIVE,
            '1'        => RedirectRule::STATUS_ACTIVE,
            'true'     => RedirectRule::STATUS_ACTIVE,
            'disabled' => RedirectRule::STATUS_INACTIVE,
            'off'      => RedirectRule::STATUS_INACTIVE,
            'no'       => RedirectRule::STATUS_INACTIVE,
            '0'        => RedirectRule::STATUS_INACTIVE,
            'false'    => RedirectRule::STATUS_INACTIVE,
        ],
    ];

    /**
     * The column reference shown under the paste box.
     *
     * On the screen rather than in a manual, because the moment an operator needs it is the
     * moment they are looking at a spreadsheet wondering which columns to keep. Written as
     * prose rather than a table: a `display` field renders one value, and a list of nine
     * columns nobody reads beats a sentence that says which two actually matter.
     */
    private const COLUMN_HELP =
        'Only "from" and "to" have to be filled in. A row missing either is skipped and told to '
        . 'you by line number. Everything else falls back to a sensible default: the match becomes '
        . 'an exact one, the code becomes 301, priority becomes 0, status becomes active, and both '
        . 'dates stay empty, which means the rule always applies. The header row is optional — '
        . 'leave it off and the columns are read in the order shown in the box above. A rule you '
        . 'already have is updated rather than added a second time, so if something goes wrong you '
        . 'can correct your file and paste the whole thing again. Up to '
        . self::MAX_ROWS . ' rules in one paste; split a larger file and paste it in parts.';

    /** @return array<string,mixed> */
    public function data(): array
    {
        $total = RedirectRule::query()->count();

        return [
            'export_csv'  => $this->export(),
            'import_csv'  => '',
            'import_help' => self::COLUMN_HELP,
            'rules_total' => $total,
            'export_note' => $total > self::MAX_ROWS
                ? 'Showing the first ' . self::MAX_ROWS . ' of ' . $total . ' rules. This box is '
                    . 'built and sent every time the screen opens, so it is capped — the rest are '
                    . 'still there and still working.'
                : $total . ' ' . ($total === 1 ? 'rule' : 'rules') . ', all of them.',
        ];
    }

    /**
     * Every rule as CSV, header first.
     *
     * Written through `fputcsv` to a memory stream rather than assembled with commas: a
     * destination containing a comma or a quote is ordinary, and hand-joining is how an export
     * that looks right in testing corrupts somebody's real data.
     */
    private function export(): string
    {
        $handle = fopen('php://temp', 'r+');

        // The escape character is passed explicitly on every CSV call here. PHP 8.4 deprecates
        // relying on the default because the default is changing, and an empty string is both
        // the future behaviour and the correct one: backslash escaping is a PHP quirk that no
        // spreadsheet writes and no other reader expects.
        fputcsv($handle, self::COLUMNS, ',', '"', '');

        RedirectRule::query()->orderBy('id')->limit(self::MAX_ROWS)->chunk(500, function ($rules) use ($handle) {
            foreach ($rules as $rule) {
                fputcsv($handle, array_map([self::class, 'escapeCell'], [
                    $rule->match_type,
                    $rule->from_path,
                    $rule->query_match,
                    // The home page is stored as an empty destination, and `validate()` refuses
                    // an empty `to` — so exporting it verbatim produced a file whose own import
                    // silently skipped that row. Written as `/`, which normalises straight back
                    // to empty on the way in.
                    // Reads `/` for the front page, via the model's accessor — an empty `to` is
                    // refused by `validate()`, so exporting it verbatim produced a file whose
                    // own import silently skipped that row.
                    $rule->to_path,
                    $rule->code,
                    $rule->priority,
                    $rule->status,
                    $rule->starts_at?->toDateTimeString(),
                    $rule->ends_at?->toDateTimeString(),
                ]), ',', '"', '');
            }
        });

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * The characters a spreadsheet reads as the start of a formula rather than as text.
     *
     * Tab and carriage return are in the set because Excel strips leading whitespace before
     * deciding, so a cell beginning with one of them and then `=` is a formula too.
     */
    private const FORMULA_LEADERS = ['=', '+', '-', '@', "	", "
"];

    /**
     * Make one cell safe to open in a spreadsheet.
     *
     * This screen tells the operator to paste the export into a text editor and save it with a
     * `.csv` ending, which is an instruction to open it in Excel, LibreOffice or Sheets — and
     * all three execute a cell beginning `=`, `+`, `-` or `@` as a formula. A rule's `from_path`
     * is not always something the operator typed: Broken Links records the path of every 404 a
     * visitor asks for and offers it as the origin of a new rule, so a stranger can choose the
     * first character of a cell that later lands in somebody's spreadsheet.
     *
     * A single quote is the escape every spreadsheet understands: it is consumed on open and
     * the rest is shown as text. `unescapeCell()` takes it back off, so the file this package
     * writes is still the file this package reads.
     */
    private static function escapeCell(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], self::FORMULA_LEADERS, true)
            ? "'" . $value
            : $value;
    }

    /**
     * Undo `escapeCell()`, and nothing else.
     *
     * **Only when what follows is a character that needed escaping.** A leading quote is
     * otherwise left alone, because a path may legitimately start with one and stripping it
     * would corrupt a value on every round trip. The narrow rule is what keeps `-5` — an
     * ordinary negative priority, exported as `'-5` — importing back as the number it was.
     */
    private static function unescapeCell(string $value): string
    {
        return strlen($value) > 1
            && $value[0] === "'"
            && in_array($value[1], self::FORMULA_LEADERS, true)
                ? substr($value, 1)
                : $value;
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function save(array $data): array
    {
        $csv = trim((string) ($data['import_csv'] ?? ''));

        if ($csv === '') {
            return ['message' => 'Nothing to import — paste some CSV first.'];
        }

        $result = $this->import($csv);

        RedirectMatcher::forget();

        return ['message' => $this->summarise($result)] + $result;
    }

    /**
     * Parse and upsert.
     *
     * **Upserted on the rule's identity, not appended.** Re-importing a corrected file is the
     * normal way anyone fixes a bad migration, and an import that only ever inserted would
     * fail on the unique index the second time — leaving the operator with a half-applied
     * file and no way to tell which half.
     *
     * @return array<string,mixed>
     */
    private function import(string $csv): array
    {
        $rows = $this->rows($csv);

        if ($rows === []) {
            return ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => ['No readable rows.']];
        }

        $header = $this->header($rows[0]);

        if ($header !== null) {
            array_shift($rows);
        }

        $overflow = [];

        if (count($rows) > self::MAX_ROWS) {
            $overflow = [
                'This paste holds ' . count($rows) . ' rules and ' . self::MAX_ROWS
                . ' is the most that can be imported at once, so the rest were not read. '
                . 'Split the file and paste it in parts — a rule you already have is updated '
                . 'rather than added again, so an overlap between the parts is harmless.',
            ];

            $rows = array_slice($rows, 0, self::MAX_ROWS);
        }

        $created = $updated = $skipped = 0;
        $errors  = [];

        foreach ($rows as $index => $row) {
            $line   = $index + ($header !== null ? 2 : 1);
            $values = $this->map($row, $header ?? self::COLUMNS);

            $error = $this->validate($values);

            if ($error !== null) {
                $skipped++;

                if (count($errors) < self::MAX_ERRORS) {
                    $errors[] = "Line {$line}: {$error}";
                }

                continue;
            }

            try {
                $rule = RedirectRule::withTrashed()
                    ->firstOrNew([
                        'match_type'  => $values['match_type'],
                        'from_path'   => RedirectRule::normalisePath($values['from_path']),
                        'query_match' => ltrim($values['query_match'], '?'),
                    ]);

                $existed = $rule->exists;

                // A rule that was deleted and is being imported again is being asked for, so
                // it comes back rather than colliding with a soft-deleted row the operator
                // cannot see.
                $rule->deleted_at = null;

                $rule->fill([
                    'to_path'   => $values['to_path'],
                    'code'      => (int) ($values['code'] ?: 301),
                    'priority'  => (int) ($values['priority'] ?: 0),
                    'status'    => $values['status'] ?: RedirectRule::STATUS_ACTIVE,
                    'starts_at' => $values['starts_at'] ?: null,
                    'ends_at'   => $values['ends_at'] ?: null,
                ])->save();

                $existed ? $updated++ : $created++;
            } catch (\Throwable $e) {
                $skipped++;

                if (count($errors) < self::MAX_ERRORS) {
                    $errors[] = "Line {$line}: {$e->getMessage()}";
                }
            }
        }

        // Nothing landed and no header was recognised, so the first line was read as data and
        // every row after it was read one column out. Saying that once beats saying "a rule
        // needs somewhere to send the visitor" on every line — which is true, and is the
        // symptom furthest from the cause.
        if ($created === 0 && $updated === 0 && $skipped > 0 && $header === null) {
            $errors = [
                'None of these lines could be read. If the first line names your columns, '
                . 'rename the one holding the old address to "from" so it is recognised as a '
                . 'header — it was read as a rule. If your file has no header, put the columns '
                . 'in this order: ' . implode(', ', self::COLUMNS) . '.',
            ];
        }

        // The overflow notice first: it explains the shape of everything under it.
        $errors = array_merge($overflow, $errors);

        return compact('created', 'updated', 'skipped', 'errors');
    }

    /**
     * Split the pasted text into rows.
     *
     * Line endings are normalised first because the file came off somebody else's machine, and
     * a CRLF export read as LF leaves a stray carriage return on the last column of every row —
     * which then becomes part of a stored path and matches nothing.
     *
     * @return array<int,array<int,string>>
     */
    private function rows(string $csv): array
    {
        $csv    = str_replace(["\r\n", "\r"], "\n", $csv);
        $handle = fopen('php://temp', 'r+');

        fwrite($handle, $csv);
        rewind($handle);

        $rows = [];

        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            // fgetcsv gives [null] for a blank line.
            if ($row === [null] || $row === []) {
                continue;
            }

            $rows[] = array_map(
                fn ($v) => self::unescapeCell(trim((string) $v)),
                $row
            );
        }

        fclose($handle);

        return $rows;
    }

    /**
     * The header row, if the first row is one.
     *
     * Detected rather than required, because a file exported from another tool and trimmed by
     * hand may have lost it — and a header row silently imported as a rule creates a redirect
     * from "from_path" to "to_path" that an operator would never think to look for.
     *
     * @param  array<int,string>  $row
     * @return array<int,string>|null
     */
    private function header(array $row): ?array
    {
        $normalised = array_map(fn ($v) => strtolower(str_replace([' ', '-'], '_', $v)), $row);
        $named      = array_map(fn ($v) => self::COLUMN_NAMES[$v] ?? $v, $normalised);

        if (! in_array('from_path', $named, true)) {
            return null;
        }

        return array_map(fn ($v) => self::COLUMN_NAMES[$v] ?? $v, $normalised);
    }

    /**
     * @param  array<int,string>  $row
     * @param  array<int,string>  $header
     * @return array<string,string>
     */
    private function map(array $row, array $header): array
    {
        $values = array_fill_keys(self::COLUMNS, '');

        foreach ($header as $index => $column) {
            if (array_key_exists($column, $values)) {
                $values[$column] = $row[$index] ?? '';
            }
        }

        $values['match_type'] = strtolower($values['match_type']) ?: RedirectRule::MATCH_EXACT;
        $values['status']     = strtolower($values['status']);

        foreach (self::SYNONYMS as $column => $words) {
            if (isset($words[$values[$column]])) {
                $values[$column] = $words[$values[$column]];
            }
        }

        return $values;
    }

    /**
     * @param  array<string,string>  $values
     */
    private function validate(array $values): ?string
    {
        if ($values['to_path'] === '') {
            return 'a rule needs somewhere to send the visitor.';
        }

        // An empty path is allowed when a query is named — that pair is the site's front page
        // asked for a particular way, which is what an exported permalink such as `/?p=123`
        // becomes. Empty on both is refused for the same reason the form is: it would match the
        // home page however it was reached.
        if ($values['from_path'] === '' && $values['query_match'] === '') {
            return 'a rule needs a path, or a query to match on when the path is your front page.';
        }

        foreach (self::ENUMERATED as $column => $allowed) {
            // Empty means "use the default", which every one of these columns has.
            if ($values[$column] === '') {
                continue;
            }

            // Compared as strings so one loop can serve a column of words and a column of
            // numbers without either being cast into the other's shape.
            if (! in_array($values[$column], array_map('strval', $allowed), true)) {
                return "\"{$values[$column]}\" is not a valid " . str_replace('_', ' ', $column)
                    . '. Use ' . implode(', ', $allowed) . '.';
            }
        }

        if ($values['match_type'] === RedirectRule::MATCH_REGEX
            && @preg_match('#' . str_replace('#', '\#', $values['from_path']) . '#u', '') === false) {
            return 'that pattern is not a valid regular expression.';
        }

        return null;
    }

    /** @param  array<string,mixed>  $result */
    private function summarise(array $result): string
    {
        $parts = [];

        if ($result['created']) {
            $parts[] = $result['created'] . ' created';
        }

        if ($result['updated']) {
            $parts[] = $result['updated'] . ' updated';
        }

        if ($result['skipped']) {
            $parts[] = $result['skipped'] . ' skipped';
        }

        return $parts === [] ? 'Nothing changed.' : 'Import finished: ' . implode(', ', $parts) . '.';
    }
}
