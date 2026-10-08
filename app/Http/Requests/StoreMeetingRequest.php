<?php

namespace App\Http\Requests;

use App\Domain\Business\Meeting;
use App\Domain\Foundation\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * §12-11 — calling a meeting.
 *
 * The rules that are not here are the interesting ones: whether the slot is
 * already taken is decided by {@see \App\Domain\Business\Services\MeetingService}
 * against the diary rather than against the input, and “schedule anyway” is a
 * field precisely because recording a deliberate overlap has to be possible.
 */
class StoreMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('business.meetings.manage');
    }

    public function rules(): array
    {
        $companyId = $this->user()?->company_id;

        return [
            'title' => ['required', 'string', 'max:191'],
            'agenda' => ['nullable', 'string', 'max:4000'],
            'location' => ['nullable', 'string', 'max:160'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'branch_id' => [
                'nullable', 'integer',
                Rule::exists('branches', 'id')->where('company_id', $companyId),
            ],
            'chaired_by' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->where('company_id', $companyId),
            ],
            'attendees' => ['nullable', 'array'],
            'attendees.*' => ['integer', Rule::exists('users', 'id')->where('company_id', $companyId)],
            'secretary' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->where('company_id', $companyId),
            ],
            'allow_clash' => ['nullable', 'boolean'],
        ];
    }

    /**
     * The list of people on the meeting, in the shape the service wants. The chair
     * and the minute-taker are on it too — they are in the room like anybody else.
     *
     * @return array<int, array{user_id: int, role: string}>
     */
    public function attendeeRows(): array
    {
        $rows = [];

        foreach ((array) $this->input('attendees', []) as $id) {
            $rows[(int) $id] = ['user_id' => (int) $id, 'role' => 'attendee'];
        }

        if ($chair = $this->input('chaired_by')) {
            $rows[(int) $chair] = ['user_id' => (int) $chair, 'role' => 'chair'];
        }

        if ($secretary = $this->input('secretary')) {
            $rows[(int) $secretary] = ['user_id' => (int) $secretary, 'role' => 'secretary'];
        }

        return array_values($rows);
    }

    /** What the service needs, without the fields it derives itself. */
    public function meetingData(): array
    {
        return [
            'title' => $this->validated('title'),
            'agenda' => $this->validated('agenda'),
            'location' => $this->validated('location'),
            'starts_at' => $this->validated('starts_at'),
            'ends_at' => $this->validated('ends_at'),
            'branch_id' => $this->validated('branch_id'),
            'chaired_by' => $this->validated('chaired_by') ?? $this->user()->id,
            'allow_clash' => (bool) $this->validated('allow_clash'),
        ];
    }

    public function attributes(): array
    {
        return [
            'starts_at' => 'start',
            'ends_at' => 'end',
            'chaired_by' => 'chair',
            'secretary' => 'minute-taker',
            'attendees' => 'people',
        ];
    }

    public function messages(): array
    {
        return [
            'ends_at.after' => 'A meeting cannot end before it starts.',
            'attendees.*.exists' => 'Everybody on the list has to be a user of this company.',
        ];
    }
}
