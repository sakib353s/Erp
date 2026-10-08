<?php

namespace App\Http\Controllers;

use App\Domain\Operations\MaintenanceRun;
use App\Domain\Operations\Services\LogReader;
use App\Domain\Operations\Services\MaintenanceService;
use App\Domain\Operations\Services\SystemReport;
use App\Search\SearchIndex;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * §15-23…§15-33 — the maintenance desk.
 *
 * The controller is deliberately thin and does exactly two things per action:
 * ask the service, and turn the answer into words the person who pressed the
 * button can act on. Every rule about *what may be done* lives in
 * {@see MaintenanceService} — the allow-list of paths, the ordering that makes a
 * repair stand on a check, the figures each operation reports — because a rule
 * that lives in a controller is a rule that disappears the day a second caller
 * appears.
 *
 * What the controller owns is the honesty of the answer. An operation that
 * refused says so as a warning; one that failed says so as an error; one that
 * worked reports its figures. None of the three is allowed to masquerade as
 * another, which is why the service returns a status with every result instead
 * of a boolean.
 */
class MaintenanceController extends Controller
{
    public function __construct(
        protected MaintenanceService $maintenance,
        protected SystemReport $system,
        protected LogReader $logs,
    ) {}

    /** The desk: what this installation is, and what may be done to it. */
    public function index(Request $request): View
    {
        $driver = config('database.default');

        return view('maintenance.index', [
            'report' => $this->system->build($request->user()),
            'sweep' => $this->maintenance->sweepTargets(),
            'history' => $this->maintenance->history(),
            'neverAutomatic' => MaintenanceService::NEVER_AUTOMATIC,
            'protectedPaths' => MaintenanceService::PROTECTED,
            'lastCheck' => $this->maintenance->lastRun('db.integrity'),
            'lastHeal' => $this->maintenance->lastRun('self.heal'),
            'searchRows' => SearchIndex::query()->count(),
            'databaseHost' => (string) (config("database.connections.{$driver}.host") ?? 'local'),
            'tempMinAge' => MaintenanceService::TEMP_MIN_AGE_HOURS,
            'confirmRepair' => MaintenanceService::CONFIRM_REPAIR,
            'confirmReset' => MaintenanceService::CONFIRM_RESET,
        ]);
    }

    /** §15-23 — clear derived state: cache and compiled templates. */
    public function clearCache(Request $request): RedirectResponse
    {
        return $this->answer($this->maintenance->clearCache($request->user()));
    }

    /** §15-24 — end other sessions, keeping the operator's own. */
    public function clearSessions(Request $request): RedirectResponse
    {
        return $this->answer($this->maintenance->clearSessions(
            $request->user(),
            $request->session()->getId(),
        ));
    }

    /** §15-25 — sweep the temporary directories, and only those. */
    public function clearTemp(Request $request): RedirectResponse
    {
        return $this->answer($this->maintenance->clearTemp($request->user()));
    }

    /** §15-26 — reclaim the space free inside the tables. */
    public function optimize(Request $request): RedirectResponse
    {
        return $this->answer($this->maintenance->optimizeDatabase($request->user()));
    }

    /** §15-27 — the read-only check a repair has to stand on. */
    public function integrity(Request $request): RedirectResponse
    {
        return $this->answer($this->maintenance->integrityCheck($request->user()));
    }

    /**
     * §15-27 — repair, which is refused unless the word is typed, a check is on
     * file and the table was one the check named. The refusals come back as
     * warnings with the reason, not as a silent no-op.
     */
    public function repair(Request $request): RedirectResponse
    {
        $tables = array_values(array_filter(array_map(
            fn ($table): string => (string) $table,
            (array) $request->input('tables', []),
        )));

        return $this->answer($this->maintenance->repairDatabase(
            $request->user(),
            (string) $request->input('confirm', ''),
            $tables,
        ));
    }

    /** §15-28 — the search index, rebuilt from source tables only. */
    public function rebuildIndex(Request $request): RedirectResponse
    {
        return $this->answer($this->maintenance->rebuildSearchIndex($request->user()));
    }

    /** §15-32 — run the operations that are safe to run without a person. */
    public function selfHeal(Request $request): RedirectResponse
    {
        return $this->answer($this->maintenance->selfHeal(
            $request->user(),
            $request->session()->getId(),
        ));
    }

    /** §15-33 — settings back to their declared defaults; data untouched. */
    public function resetSettings(Request $request): RedirectResponse
    {
        return $this->answer($this->maintenance->resetSettings(
            $request->user(),
            (string) $request->input('confirm', ''),
        ));
    }

    /** §15-31 — the log viewer, redacted before it is rendered. */
    public function logs(Request $request): View
    {
        $file = $request->query('file');
        $file = is_string($file) && $file !== '' ? $file : null;

        $level = $request->query('level');
        $level = is_string($level) && in_array(strtolower($level), LogReader::LEVELS, true) ? strtolower($level) : null;

        $search = $request->query('q');
        $search = is_string($search) && $search !== '' ? $search : null;

        $limit = (int) $request->query('limit', 100);

        return view('maintenance.logs', [
            'files' => $this->logs->files(),
            'file' => $file,
            'level' => $level,
            'search' => $search,
            'limit' => max(10, min($limit, 500)),
            'view' => $this->logs->entries($file, max(10, min($limit, 500)), $level, $search),
            'summary' => $this->logs->summary($file),
            'levels' => LogReader::LEVELS,
        ]);
    }

    /* -------------------------------------------------------------- internals */

    /**
     * One answer, in the right voice. `status` for work done, `warning` for a
     * refusal (the operation declined, and the person needs the reason), `error`
     * for an action that threw — which the service records before re-throwing.
     *
     * @param  array<string, mixed>  $result
     */
    protected function answer(array $result): RedirectResponse
    {
        $summary = (string) ($result['summary'] ?? 'The operation finished without a summary.');

        $key = match ($result['status'] ?? MaintenanceRun::STATUS_OK) {
            MaintenanceRun::STATUS_REFUSED => 'warning',
            MaintenanceRun::STATUS_FAILED => 'error',
            default => 'status',
        };

        return back()->with($key, $summary);
    }
}
