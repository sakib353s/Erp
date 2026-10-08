<?php

namespace App\Domain\CashBank\Services;

use App\Domain\Accounting\Account;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\CashBank\BankStatementImport;
use App\Domain\CashBank\BankStatementLine;
use App\Domain\CashBank\Exceptions\StatementRejectedException;
use App\Domain\Foundation\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

/**
 * Reading a bank's statement file (§08-09).
 *
 * The rule this service is built around: **a statement is one document, so it
 * imports whole or not at all.** Half a statement is worse than none, because
 * the reconciliation that follows would find a difference nobody caused and
 * would be right to distrust every other figure on the page. So a file with a
 * bad row is refused with the row's number and the reason, and nothing is
 * stored — every run (preview, refusal, import) is still written down, because
 * "we tried to load the March statement and it did not load" is a fact an
 * accountant is entitled to find later.
 *
 * The bank's words are kept: a statement `debit` takes money out of the
 * account. Translation happens once, in BankStatementLine::signedAmount().
 */
class BankStatementImportService
{
    /** What a statement file is allowed to weigh. */
    public const MAX_ROWS = 5000;

    public const MAX_KILOBYTES = 2048;

    /** The columns the desk reads, and what banks in this market call them. */
    public const COLUMNS = ['value_date', 'description', 'reference', 'debit', 'credit', 'balance'];

    protected const ALIASES = [
        'value_date' => ['value_date', 'date', 'transaction_date', 'txn_date', 'posting_date', 'value date', 'transaction date', 'cheque date'],
        'description' => ['description', 'narration', 'particulars', 'details', 'remarks', 'transaction_details'],
        'reference' => ['reference', 'ref', 'ref_no', 'reference_no', 'cheque_no', 'chq_no', 'instrument_no', 'transaction_id'],
        'debit' => ['debit', 'withdrawal', 'withdrawal_amount', 'withdrawals', 'dr', 'paid_out', 'payment'],
        'credit' => ['credit', 'deposit', 'deposit_amount', 'deposits', 'cr', 'paid_in', 'receipt'],
        'balance' => ['balance', 'running_balance', 'closing_balance', 'balance_after'],
    ];

    public function __construct(
        protected AuditRecorder $audit,
    ) {}

    /** Column headings the screen offers as a download, in the order they are read. */
    public function templateHeadings(): array
    {
        return ['Date', 'Description', 'Reference', 'Debit', 'Credit', 'Balance'];
    }

    public function templateRows(): array
    {
        return [
            // The bank's brought-forward figure, kept as a labelled row the desk
            // reads and sets aside: it is not a movement, so it must not be a line.
            ['2026-04-01', 'Opening balance', '', '', '', '80000.00'],
            ['2026-04-03', 'Transfer received — Rahmania Store', 'SO-2026-00412', '', '42000.00', '122000.00'],
            ['2026-04-05', 'Cheque 004512 — rent', '004512', '26000.00', '', '96000.00'],
            ['2026-04-07', 'Bank charge', '', '250.00', '', '95750.00'],
        ];
    }

    /**
     * Read the file. Pure: nothing is written and no id is resolved here, so the
     * preview screen and the real import are looking at exactly the same rows.
     *
     * @return array{rows: array<int, array<string, mixed>>, skipped: array<int, array<string, mixed>>, errors: array<int, string>, ignored: array<int, string>, delimiter: string}
     */
    public function read(UploadedFile $file): array
    {
        $size = (int) ($file->getSize() ?? 0);

        if ($size > self::MAX_KILOBYTES * 1024) {
            throw new RuntimeException(
                'That file is larger than '.self::MAX_KILOBYTES.' KB — a statement that size is not a statement. Split it by month and import it in parts.',
            );
        }

        $path = $file->getRealPath();

        if ($path === false || ! is_readable($path)) {
            throw new RuntimeException('The uploaded file could not be read.');
        }

        $delimiter = $this->sniffDelimiter($path);
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException('The uploaded file could not be opened.');
        }

        $header = fgetcsv($handle, 0, $delimiter, '"', '\\');

        if ($header === false || $header === [null]) {
            fclose($handle);

            throw new RuntimeException('The file is empty — the first row has to be the column headings.');
        }

        $map = [];
        $ignored = [];

        foreach ($header as $index => $raw) {
            $canonical = $this->canonicalHeader((string) $raw);

            if ($canonical === null) {
                if (trim((string) $raw) !== '') {
                    $ignored[] = trim((string) $raw);
                }

                continue;
            }

            $map[$canonical] ??= $index;
        }

        if (! isset($map['value_date'])) {
            fclose($handle);

            throw new RuntimeException('The file needs a date column — without a date a line cannot be placed on a statement.');
        }

        if (! isset($map['debit']) && ! isset($map['credit'])) {
            fclose($handle);

            throw new RuntimeException('The file needs a debit and a credit column (they may also be called withdrawal and deposit).');
        }

        $rows = [];
        $skipped = [];
        $errors = [];
        $line = 1;

        while (($cells = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            $line++;

            if ($this->isBlank($cells)) {
                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                fclose($handle);

                throw new RuntimeException('That file carries more than '.number_format(self::MAX_ROWS).' rows — split it by month and import it in parts.');
            }

            try {
                $parsed = $this->row($cells, $map, $line);
            } catch (RuntimeException $error) {
                $errors[] = $error->getMessage();

                continue;
            }

            // A labelled non-movement row is not a line: it is the bank saying
            // what it brought forward or what it totalled, and neither is money
            // moving. It is kept in the read result so the desk can show it.
            if ($parsed['kind'] !== 'movement') {
                $skipped[] = $parsed;

                continue;
            }

            $rows[] = $parsed;
        }

        fclose($handle);

        if ($rows === [] && $errors === []) {
            throw new RuntimeException($skipped === []
                ? 'The file has headings but no rows.'
                : 'The file carries an opening or closing figure but no movement at all — there is nothing on it to reconcile against.');
        }

        return [
            'rows' => $rows,
            'skipped' => $skipped,
            'errors' => $errors,
            'ignored' => array_values(array_unique($ignored)),
            'delimiter' => $delimiter,
        ];
    }

    /**
     * Read a statement into the account.
     *
     * `$preview` is the same walk with nothing stored but the run itself. A file
     * with no errors lands every row; a file with any error lands none of them.
     */
    public function import(Account $account, User $actor, UploadedFile $file, bool $preview = false): BankStatementImport
    {
        $read = $this->read($file);
        $checksum = $this->checksum($file);
        $duplicate = $this->duplicate($account, $checksum);

        if ($duplicate !== null) {
            throw new RuntimeException(
                'This exact statement was already imported for '.$account->name.' on '
                .$duplicate->created_at?->toDayDateTimeString()
                .' ('.$duplicate->file_name.'). Importing it twice would double every line and reconcile to a difference nobody caused.',
            );
        }

        $rows = $read['rows'];
        // Only row failures reject a file. A column the desk does not read is a
        // warning on the preview screen — the statement is still whole without it.
        $errors = $read['errors'];

        if ($preview || $errors !== []) {
            $run = BankStatementImport::query()->create([
                'company_id' => $account->company_id,
                'branch_id' => null,
                'account_id' => $account->id,
                'file_name' => (string) ($file->getClientOriginalName() ?: 'statement.csv'),
                // A run that stored nothing carries no checksum: only a real
                // import may block a later import of the same file.
                'checksum' => null,
                'row_count' => count($rows) + count($read['errors']),
                'imported_count' => 0,
                'rejected_count' => count($read['errors']),
                'status' => $preview ? BankStatementImport::STATUS_PREVIEW : BankStatementImport::STATUS_REJECTED,
                'errors' => $errors === [] ? null : $errors,
                'imported_by' => $actor->id,
            ]);

            if (! $preview && $errors !== []) {
                $this->audit->record([
                    'action' => 'cash_bank.statement_rejected',
                    'entity_type' => 'account',
                    'entity_id' => $account->id,
                    'actor_id' => $actor->id,
                    'after' => [
                        'file' => $run->file_name,
                        'rows' => $run->row_count,
                        'rejected' => $run->rejected_count,
                        'errors' => array_slice($errors, 0, 10),
                    ],
                ]);

                throw new StatementRejectedException($run, $errors);
            }

            return $run;
        }

        $import = BankStatementImport::query()->create([
            'company_id' => $account->company_id,
            'branch_id' => null,
            'account_id' => $account->id,
            'file_name' => (string) ($file->getClientOriginalName() ?: 'statement.csv'),
            'checksum' => $checksum,
            'row_count' => count($rows),
            'imported_count' => count($rows),
            'rejected_count' => 0,
            'status' => BankStatementImport::STATUS_IMPORTED,
            'errors' => null,
            'imported_by' => $actor->id,
        ]);

        foreach ($rows as $row) {
            BankStatementLine::query()->create([
                'company_id' => $account->company_id,
                'account_id' => $account->id,
                'import_id' => $import->id,
                'value_date' => $row['value_date'],
                'description' => $row['description'],
                'reference' => $row['reference'],
                'debit' => $row['debit'],
                'credit' => $row['credit'],
                'balance_after' => $row['balance'],
                'line_no' => $row['line'],
            ]);
        }

        $this->audit->record([
            'action' => 'cash_bank.statement_imported',
            'entity_type' => 'account',
            'entity_id' => $account->id,
            'actor_id' => $actor->id,
            'after' => [
                'file' => $import->file_name,
                'rows' => count($rows),
                'from' => $rows[0]['value_date'] ?? null,
                'to' => $rows[count($rows) - 1]['value_date'] ?? null,
                'checksum' => $checksum,
            ],
        ]);

        return $import->load('lines');
    }

    /** The statement lines of one account, optionally inside a period. */
    public function lines(Account $account, ?string $from = null, ?string $to = null, bool $unmatchedOnly = false): Collection
    {
        return BankStatementLine::query()
            ->where('company_id', $account->company_id)
            ->where('account_id', $account->id)
            ->when($from !== null, fn ($query) => $query->whereDate('value_date', '>=', $from))
            ->when($to !== null, fn ($query) => $query->whereDate('value_date', '<=', $to))
            ->when($unmatchedOnly, fn ($query) => $query->whereNull('matched_journal_line_id'))
            ->orderBy('value_date')
            ->orderBy('id')
            ->get();
    }

    /** Every run against this account, newest first — previews and refusals included. */
    public function runs(Account $account, int $limit = 10): Collection
    {
        return BankStatementImport::query()
            ->where('company_id', $account->company_id)
            ->where('account_id', $account->id)
            ->with('importedBy:id,name')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /** The closing balance the file itself claims, when it carries one. */
    public function statementClosing(Account $account, ?string $from = null, ?string $to = null): ?string
    {
        $balance = BankStatementLine::query()
            ->where('company_id', $account->company_id)
            ->where('account_id', $account->id)
            ->whereNotNull('balance_after')
            ->when($from !== null, fn ($query) => $query->whereDate('value_date', '>=', $from))
            ->when($to !== null, fn ($query) => $query->whereDate('value_date', '<=', $to))
            ->orderByDesc('value_date')
            ->orderByDesc('id')
            ->value('balance_after');

        return $balance === null ? null : number_format((float) $balance, 4, '.', '');
    }

    /** The earliest and latest date any imported line carries — what the desk pre-fills. */
    public function window(Account $account): array
    {
        $row = BankStatementLine::query()
            ->where('company_id', $account->company_id)
            ->where('account_id', $account->id)
            ->selectRaw('MIN(value_date) as from_date, MAX(value_date) as to_date, COUNT(*) as lines')
            ->first();

        return [
            'from' => $row?->from_date,
            'to' => $row?->to_date,
            'lines' => (int) ($row->lines ?? 0),
        ];
    }

    /** Lines with no counterpart in the books yet — the bank's claims we have not entered. */
    public function unmatched(Account $account): Collection
    {
        return $this->lines($account, unmatchedOnly: true);
    }

    protected function duplicate(Account $account, string $checksum): ?BankStatementImport
    {
        return BankStatementImport::query()
            ->where('company_id', $account->company_id)
            ->where('account_id', $account->id)
            ->where('checksum', $checksum)
            ->orderByDesc('id')
            ->first();
    }

    public function checksum(UploadedFile $file): string
    {
        $path = $file->getRealPath();

        return $path === false ? hash('sha256', (string) $file->getClientOriginalName()) : (string) hash_file('sha256', $path);
    }

    /**
     * One row, validated on its own so its line number can be reported with it.
     *
     * @param  array<int, ?string>  $cells
     * @param  array<string, int>  $map
     * @return array<string, mixed>
     */
    protected function row(array $cells, array $map, int $line): array
    {
        $cell = fn (string $column): string => trim((string) ($cells[$map[$column] ?? -1] ?? ''));

        $date = $this->date($cell('value_date'));

        if ($date === null) {
            throw new RuntimeException(
                "Line {$line}: \"{$cell('value_date')}\" is not a date this desk reads. Use YYYY-MM-DD, or D/M/YYYY for a day-first statement.",
            );
        }

        $debit = $this->amount($cell('debit'), $line, 'debit');
        $credit = $this->amount($cell('credit'), $line, 'credit');

        if (bccomp($debit, '0.0000', 4) === 0 && bccomp($credit, '0.0000', 4) === 0) {
            // No amount, but the row says what it is: a statement opens with a
            // brought-forward figure and often closes with a total. Those are the
            // bank's own words about the account, not money moving through it —
            // so they are read, named and set aside rather than counted as lines
            // or refused as errors. Refusing them would reject the bank's real
            // exports (and this desk's own template) for being honest about
            // where the balance started.
            $kind = $this->nonMovement($cell('description'));

            if ($kind === null) {
                throw new RuntimeException("Line {$line}: a statement line has to carry either a debit or a credit. Both are empty.");
            }

            return [
                'kind' => $kind,
                'line' => $line,
                'value_date' => $date,
                'description' => $this->cut($cell('description'), 500),
                'balance' => isset($map['balance']) && $cell('balance') !== '' ? $this->amount($cell('balance'), $line, 'balance') : null,
            ];
        }

        if (bccomp($debit, '0.0000', 4) > 0 && bccomp($credit, '0.0000', 4) > 0) {
            throw new RuntimeException("Line {$line}: a statement line is one or the other — this row carries both a debit and a credit.");
        }

        return [
            'kind' => 'movement',
            'line' => $line,
            'value_date' => $date,
            'description' => $this->cut($cell('description'), 500),
            'reference' => $this->cut($cell('reference'), 80),
            'debit' => $debit,
            'credit' => $credit,
            'balance' => isset($map['balance']) && $cell('balance') !== '' ? $this->amount($cell('balance'), $line, 'balance') : null,
        ];
    }

    /**
     * What a row with no amount is, when its words give it a name: the wording is
     * matched, so the desk reads the banks' vocabulary instead of insisting they
     * write like this one does. `null` means the row carries no amount and no
     * name, which is a broken row and is reported as one.
     */
    protected function nonMovement(string $description): ?string
    {
        $text = mb_strtolower(trim($description));

        if ($text === '') {
            return null;
        }

        $opening = ['opening balance', 'opening bal', 'brought forward', 'brought fwd', 'b/f', 'b\d', 'balance b/d', 'previous balance'];

        foreach ($opening as $phrase) {
            if (str_contains($text, $phrase)) {
                return 'opening';
            }
        }

        $closing = ['closing balance', 'closing bal', 'carried forward', 'carried fwd', 'c/f', 'c\d', 'total', 'this period total'];

        foreach ($closing as $phrase) {
            if (str_contains($text, $phrase)) {
                return 'closing';
            }
        }

        return null;
    }

    /**
     * The bank's own opening figure for a period: the first line that carries a
     * running balance, less the movement that produced it. The desk's own fallback
     * (closing less the movements it holds) can only ever agree with itself, so
     * where the statement states its arithmetic the desk reads it instead.
     */
    public function statementOpening(Account $account, string $from, string $to): ?string
    {
        $line = BankStatementLine::query()
            ->where('company_id', $account->company_id)
            ->where('account_id', $account->id)
            ->whereNotNull('balance_after')
            ->whereDate('value_date', '>=', $from)
            ->whereDate('value_date', '<=', $to)
            ->orderBy('value_date')
            ->orderBy('id')
            ->first();

        return $line === null ? null : bcsub((string) $line->balance_after, $line->signedAmount(), 4);
    }

    /** Day-first unless the value is ISO: the rule is stated on the screen, not guessed per row. */
    protected function date(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'd M Y', 'j M Y', 'd/M/Y H:i', 'Y-m-d H:i:s'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $value);
            } catch (Throwable) {
                continue;
            }

            if ($parsed !== false && $parsed->format($format) === $value) {
                return $parsed->toDateString();
            }
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /** Amounts as banks write them: 1,20,000.50 · (1,500) · BDT 900 · 900.00 Dr */
    protected function amount(string $value, int $line, string $column): string
    {
        $value = trim($value);

        if ($value === '') {
            return '0.0000';
        }

        $negative = str_starts_with($value, '(') && str_ends_with($value, ')');
        $cleaned = str_ireplace(['bdt', 'tk', 'usd', '(', ')', 'dr', 'cr', ' '], '', $value);
        $cleaned = str_replace(',', '', $cleaned);

        if (! is_numeric($cleaned)) {
            throw new RuntimeException("Line {$line}: \"{$value}\" is not an amount this desk reads in the {$column} column.");
        }

        $amount = abs((float) $cleaned);

        if ($negative) {
            // A negative debit or credit is really the other column; say so
            // rather than silently flipping a sign the reader cannot see.
            throw new RuntimeException("Line {$line}: the {$column} column carries a negative amount in brackets — put it in the other column instead.");
        }

        return number_format($amount, 4, '.', '');
    }

    protected function cut(string $value, int $length): ?string
    {
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $length);
    }

    protected function canonicalHeader(string $raw): ?string
    {
        $needle = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $raw) ?? ''));

        if ($needle === '') {
            return null;
        }

        foreach (self::ALIASES as $canonical => $aliases) {
            if (in_array($needle, $aliases, true)) {
                return $canonical;
            }
        }

        return null;
    }

    /** @param array<int, ?string> $cells */
    protected function isBlank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /** Tab, semicolon or comma — sniffed from the heading row, never assumed. */
    protected function sniffDelimiter(string $path): string
    {
        $handle = fopen($path, 'r');
        $first = $handle === false ? '' : (string) fgets($handle);

        if ($handle !== false) {
            fclose($handle);
        }

        $counts = [
            "," => substr_count($first, ','),
            ";" => substr_count($first, ';'),
            "\t" => substr_count($first, "\t"),
        ];

        arsort($counts);

        $winner = array_key_first($counts);

        return $counts[$winner] > 0 ? $winner : ',';
    }
}
