<?php

namespace App\Http\Controllers;

use App\Domain\Business\Project;
use App\Domain\Business\Services\TaskService;
use App\Domain\Business\Task;
use App\Domain\Foundation\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * §12-13 — projects: a container, its owner and its deadline.
 *
 * Kept deliberately thin. A project is not a second kind of task; it is the
 * group the tasks are read in, with counts computed from the tasks themselves
 * (never a stored counter that can drift), so “8 of 12 done” on this screen and
 * the twelve cards behind it are the same twelve.
 */
class ProjectController extends Controller
{
    public function __construct(protected TaskService $tasks) {}

    public function index(Request $request): View
    {
        $companyId = (int) $request->user()->company_id;

        return view('business.projects.index', [
            'projects' => $this->tasks->projects($companyId),
            'people' => $request->user()->can('tasks.manage')
                ? User::query()->where('company_id', $companyId)->where('status', 'active')->orderBy('name')->get()
                : collect(),
            'statuses' => Project::STATUSES,
            'canManage' => (bool) $request->user()->can('tasks.manage'),
            'openTasks' => Task::query()->where('company_id', $companyId)->open()->doesntHave('project')->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = (int) $request->user()->company_id;

        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', Rule::unique('projects', 'code')->where('company_id', $companyId)],
            'name' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:2000'],
            'owner_id' => ['nullable', Rule::exists('users', 'id')->where('company_id', $companyId)],
            'starts_on' => ['nullable', 'date'],
            'due_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ]);

        $project = Project::create($data + [
            'company_id' => $companyId,
            'status' => 'active',
        ]);

        return redirect()->route('projects.show', $project)->with('status', 'Project created.');
    }

    public function show(Request $request, Project $project): View
    {
        abort_if((int) $project->company_id !== (int) $request->user()->company_id, 404);

        $person = $request->user();
        $seesEverybody = $person->isSuperAdmin() || $person->can('tasks.view_all');

        $tasks = Task::query()
            ->where('company_id', $project->company_id)
            ->where('project_id', $project->id)
            ->when(! $seesEverybody, fn ($query) => $query->where(fn ($q) => $q->where('assigned_to', $person->id)->orWhere('created_by', $person->id)))
            ->with(['assignee'])
            ->orderBy('position')
            ->get();

        return view('business.projects.show', [
            'project' => $project->load('owner'),
            'tasks' => $tasks,
            'statuses' => Task::STATUSES,
            'priorities' => Task::PRIORITIES,
            'canManage' => (bool) $person->can('tasks.manage'),
        ]);
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        abort_if((int) $project->company_id !== (int) $request->user()->company_id, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(array_keys(Project::STATUSES))],
            'owner_id' => ['nullable', Rule::exists('users', 'id')->where('company_id', $project->company_id)],
            'due_on' => ['nullable', 'date'],
        ]);

        $project->update($data);

        return back()->with('status', 'Project updated.');
    }
}
