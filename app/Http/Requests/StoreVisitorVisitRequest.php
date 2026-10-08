<?php

namespace App\Http\Requests;

use App\Domain\Business\Visitor;
use App\Domain\Business\VisitorVisit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * §12-16 — booking a visit, or checking somebody in at the gate.
 *
 * One request for both because it is one question with two answers: either the
 * visitor is already on file (`visitor_id`) or the gate is meeting them for the
 * first time (`name`, and a phone if there is one). Everything after that is the
 * same — who they are here to see, what the visit is for, and when.
 *
 * `scheduled_for` is present for a booking and absent for a walk-in, and the
 * service reads the difference rather than a hidden field.
 */
class StoreVisitorVisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('business.visitors.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = (int) ($this->user()?->company_id ?? 0);

        return [
            'visitor_id' => ['nullable', 'integer', Rule::exists('visitors', 'id')->where('company_id', $companyId)],
            'name' => ['nullable', 'required_without:visitor_id', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:160'],
            'organisation' => ['nullable', 'string', 'max:160'],
            'id_type' => ['nullable', Rule::in(array_keys(Visitor::ID_TYPES))],
            'id_number' => ['nullable', 'string', 'max:64'],

            'host_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('company_id', $companyId)],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'purpose' => ['required', Rule::in(array_keys(\App\Domain\Business\VisitorRegistry::PURPOSES))],
            'scheduled_for' => ['nullable', 'date', 'before_or_equal:'.now()->addDays(VisitorVisit::BOOKING_HORIZON_DAYS)->toDateString()],
            'meet_at' => ['nullable', 'string', 'max:120'],
            'items_carried' => ['nullable', 'string', 'max:500'],
            'vehicle_no' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required_without' => 'Who is coming in? Pick somebody already on file or write the name.',
            'purpose.required' => 'Say what the visit is for — the gate logs why somebody is inside the building.',
            'scheduled_for.before_or_equal' => 'The gate keeps a diary, not a calendar: bookings are taken up to '.VisitorVisit::BOOKING_HORIZON_DAYS.' days ahead.',
            'host_user_id.exists' => 'The person being visited has to be somebody in this company.',
            'branch_id.exists' => 'That gate is not one of this company\'s branches.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'visitor_id' => $this->input('visitor_id') === '' ? null : $this->input('visitor_id'),
            'host_user_id' => $this->input('host_user_id') === '' ? null : $this->input('host_user_id'),
            'branch_id' => $this->input('branch_id') === '' ? null : $this->input('branch_id'),
            'scheduled_for' => $this->input('scheduled_for') === '' ? null : $this->input('scheduled_for'),
        ]);
    }
}
