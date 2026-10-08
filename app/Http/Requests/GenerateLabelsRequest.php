<?php

namespace App\Http\Requests;

use App\Domain\Inventory\Services\LabelService;
use App\Domain\Inventory\Services\QrService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * §04-52/04-53 — generating a label sheet.
 *
 * The request validates the *shape* of the selection (which kind of thing, how
 * many, on which paper, what to print beside the bars) and the template keys
 * against the registry the renderer actually uses. Whether the chosen ids are
 * ours is settled in the controller, because that check depends on which kind
 * of subject was chosen and has to answer in a sentence rather than a field
 * error — "that batch belongs to another company" is a refusal, not a typo.
 */
class GenerateLabelsRequest extends FormRequest
{
    /*
     * Orders and invoices are two kinds, not one "document" kind, because their
     * ids come from two tables: order #7 and invoice #7 are different things, and
     * a single shared list of ids could not tell them apart.
     */
    public const SUBJECT_TYPES = ['product', 'batch', 'order', 'invoice'];

    /** How many *rows* one run may tick, before copies multiply them. */
    public const MAX_ROWS = 200;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject_type' => ['required', Rule::in(self::SUBJECT_TYPES)],
            // One array per family rather than one shared array of ids: a product
            // and a batch can be #7 at the same time, so a single list would make
            // the selection ambiguous the moment both tables are ticked.
            'product_ids' => ['nullable', 'array', 'max:'.self::MAX_ROWS],
            'product_ids.*' => ['integer', 'min:1'],
            'batch_ids' => ['nullable', 'array', 'max:'.self::MAX_ROWS],
            'batch_ids.*' => ['integer', 'min:1'],
            'order_ids' => ['nullable', 'array', 'max:'.self::MAX_ROWS],
            'order_ids.*' => ['integer', 'min:1'],
            'invoice_ids' => ['nullable', 'array', 'max:'.self::MAX_ROWS],
            'invoice_ids.*' => ['integer', 'min:1'],
            'template' => ['nullable', Rule::in(array_keys(LabelService::TEMPLATES))],
            'copies' => ['nullable', 'integer', 'min:1', 'max:'.LabelService::MAX_COPIES],
            'show_price' => ['nullable', 'boolean'],
            'show_company' => ['nullable', 'boolean'],
            'show_code_text' => ['nullable', 'boolean'],
            'qr' => ['nullable', 'boolean'],
            'qr_level' => ['nullable', Rule::in(QrService::LEVELS)],
        ];
    }

    public function attributes(): array
    {
        return [
            'subject_type' => 'kind of item',
            'product_ids' => 'selected products',
            'batch_ids' => 'selected batches',
            'order_ids' => 'selected orders',
            'invoice_ids' => 'selected invoices',
            'copies' => 'copies of each label',
            'qr_level' => 'QR error-correction level',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $rows = count($this->subjectIds());

            if ($rows === 0) {
                // Say *which* table to tick, rather than "the selection is invalid".
                $validator->errors()->add($this->inputKey(), match ((string) $this->input('subject_type')) {
                    'product' => 'Tick at least one product to label.',
                    'batch' => 'Tick at least one batch to label.',
                    'order' => 'Tick at least one order to label.',
                    default => 'Tick at least one invoice to label.',
                });

                return;
            }

            $copies = max(1, (int) ($this->input('copies') ?: 1));

            if ($rows * $copies > LabelService::MAX_LABELS) {
                $validator->errors()->add($this->inputKey(), sprintf(
                    '%d row(s) × %d copies is %d labels; one run may produce %d. Generate the run in parts.',
                    $rows,
                    $copies,
                    $rows * $copies,
                    LabelService::MAX_LABELS,
                ));
            }
        });
    }

    /** The field the ticked rows are submitted under, for a chosen kind of subject. */
    public function inputKey(): string
    {
        return match ((string) $this->input('subject_type')) {
            'product' => 'product_ids',
            'batch' => 'batch_ids',
            'order' => 'order_ids',
            default => 'invoice_ids',
        };
    }

    /** The ticked ids of the chosen kind, de-duplicated — the same row twice is one label. */
    public function subjectIds(): array
    {
        $ids = array_map('intval', (array) $this->input($this->inputKey(), []));

        return array_values(array_unique(array_filter($ids, fn (int $id) => $id > 0)));
    }

    /** @return array<string, mixed> */
    public function sheetOptions(string $companyName): array
    {
        return [
            'template' => $this->validated('template'),
            'copies' => (int) ($this->validated('copies') ?? 1),
            'show_price' => $this->has('show_price') ? $this->boolean('show_price') : null,
            'show_company' => $this->has('show_company') ? $this->boolean('show_company') : null,
            'show_code_text' => $this->has('show_code_text') ? $this->boolean('show_code_text') : null,
            'qr' => $this->has('qr') ? $this->boolean('qr') : null,
            'qr_level' => $this->validated('qr_level'),
            'company' => $companyName,
        ];
    }
}
