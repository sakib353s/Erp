<?php

namespace App\Console\Commands;

use App\Domain\Business\Meeting;
use App\Domain\Business\MeetingAttendee;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Notification\Services\NotificationCenter;
use Illuminate\Console\Command;

/**
 * §12-11 — the bell before the meeting.
 *
 * Runs every fifteen minutes and tells the people on the list about a meeting
 * that starts inside the next hour. Once per meeting per person, ever: the dedupe
 * key carries no date, so the 10:00 run of a meeting at 10:45 reminds you once and
 * the 10:15 run does not remind you again. A reminder that repeats is a reminder
 * people turn off, and then the meeting is missed anyway.
 *
 * A meeting that has been held, cancelled or moved out of the window is not
 * reminded about — the move sends its own message with the new time.
 */
class MeetingReminderCommand extends Command
{
    protected $signature = 'erp:business:meeting-reminders
        {--company= : Company id (defaults to THE company)}
        {--minutes= : How far ahead to look, in minutes}';

    protected $description = 'Remind the people on a meeting’s list that it is starting soon';

    public function handle(
        NotificationCenter $notifications,
        TenantContext $context,
    ): int {
        $company = $this->option('company')
            ? Company::query()->find((int) $this->option('company'))
            : Company::current();

        if ($company === null) {
            $this->error('No company found — nothing to remind anybody about.');

            return self::FAILURE;
        }

        $context->setCompany($company);

        $window = (int) ($this->option('minutes') ?: Meeting::REMINDER_MINUTES);
        $window = max(1, $window);

        $from = now();
        $to = now()->copy()->addMinutes($window);

        $meetings = Meeting::query()
            ->where('company_id', $company->id)
            ->where('status', Meeting::STATUS_SCHEDULED)
            ->whereBetween('starts_at', [$from, $to])
            ->with(['attendees.user'])
            ->get();

        $delivered = 0;

        foreach ($meetings as $meeting) {
            foreach ($meeting->attendees as $attendee) {
                /** @var MeetingAttendee $attendee */
                if ($attendee->user === null || $attendee->response === 'declined') {
                    continue; // somebody who said they cannot come has said so
                }

                $minutesAway = max(1, (int) round(now()->diffInMinutes($meeting->starts_at, false)));

                $sent = $notifications->notify(
                    $attendee->user,
                    'meeting.reminder',
                    'Starting in '.$minutesAway.' minute(s): '.$meeting->title,
                    sprintf(
                        '%s–%s%s%s',
                        $meeting->starts_at->format('H:i'),
                        $meeting->endsAt()->format('H:i'),
                        $meeting->location ? ' at '.$meeting->location : '',
                        $meeting->agenda ? ' — '.\Illuminate\Support\Str::limit($meeting->agenda, 120) : '',
                    ),
                    [
                        'priority' => 'high',
                        'action_url' => route('meetings.show', $meeting, false),
                        'data' => ['meeting_id' => $meeting->id],
                        // Once per meeting per person, forever: a reminder that
                        // rings again at 10:15 is a reminder people silence.
                        'dedupe_key' => 'meeting:'.$meeting->id.':reminder:'.$attendee->user_id,
                    ],
                );

                if ($sent !== null) {
                    $delivered++;
                }
            }
        }

        $this->info($meetings->isEmpty()
            ? "No meeting starts in the next {$window} minute(s)."
            : $delivered.' reminder(s) delivered for '.$meetings->count().' meeting(s).');

        return self::SUCCESS;
    }
}
