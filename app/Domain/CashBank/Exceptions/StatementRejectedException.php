<?php

namespace App\Domain\CashBank\Exceptions;

use App\Domain\CashBank\BankStatementImport;
use RuntimeException;

/**
 * A statement file that did not load (§08-09).
 *
 * Carries the run that was written down and the per-row reasons, so the screen
 * can show the operator the lines that stopped it instead of a sentence that
 * says "invalid file" and leaves them to guess which of 400 rows it meant.
 */
class StatementRejectedException extends RuntimeException
{
    /**
     * @param  array<int, string>  $reasons
     */
    public function __construct(
        public readonly BankStatementImport $run,
        public readonly array $reasons,
    ) {
        parent::__construct(
            'Nothing was imported: a statement is one document, so it loads whole or not at all. '
            .count($reasons).' row(s) could not be read.',
        );
    }
}
