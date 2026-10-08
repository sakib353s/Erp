<x-ui.page-header
    eyebrow="Business Management · Projects"
    title="Work that is bigger than one task"
    subtitle="A project is the group its tasks are read in — a name, an owner and a deadline. The counts on this page are computed from the tasks themselves rather than kept in a counter, so “8 of 12 done” and the twelve cards behind it are the same twelve."
    :pin="true">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('tasks.kanban') }}">
            <i class="bi bi-kanban" aria-hidden="true"></i> Board
        </a>
        <a class="btn btn-outline-secondary" href="{{ route('tasks.index') }}">
            <i class="bi bi-list-task" aria-hidden="true"></i> My tasks
        </a>
    </x-slot:actions>
</x-ui.page-header>

<div class="erp-kpi-grid">
    <x-ui.kpi label="Projects" :value="$projects->count()" icon="bi-folder2-open" hint="Across the company, active first" />
    <x-ui.kpi label="Open tasks in projects" :value="$projects->sum('open_tasks_count')" icon="bi-list-task" hint="Everything inside a project that is not done or cancelled" />
    <x-ui.kpi label="Loose ends" :value="$openTasks" icon="bi-bezier2" hint="Open tasks belonging to no project — they still need doing" />
</div>

@if ($canManage)
    <section class="erp-card erp-card-max mt-3">
        <header class="erp-card-head">
            <div>
                <h2 class="erp-card-title">Start a project</h2>
                <p class="erp-card-sub">A code is how people refer to it in a hurry; it has to be unique inside the company.</p>
            </div>
        </header>
        <form class="p-3" method="POST" action="{{ route('projects.store') }}">
            @csrf
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label" for="code">Code</label>
                    <input class="form-control @error('code') is-invalid @enderror" type="text" name="code" id="code" value="{{ old('code') }}" maxlength="32" required>
                    @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-5">
                    <label class="form-label" for="name">Name</label>
                    <input class="form-control @error('name') is-invalid @enderror" type="text" name="name" id="name" value="{{ old('name') }}" maxlength="191" required>
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="owner_id">Owner</label>
                    <select class="form-select @error('owner_id') is-invalid @enderror" name="owner_id" id="owner_id">
                        <option value="">Nobody yet</option>
                        @foreach ($people as $person)
                            <option value="{{ $person->id }}" @selected((int) old('owner_id') === $person->id)>{{ $person->name }}</option>
                        @endforeach
                    </select>
                    @error('owner_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="starts_on">Starts</label>
                    <input class="form-control" type="date" name="starts_on" id="starts_on" value="{{ old('starts_on') }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="due_on">Due</label>
                    <input class="form-control @error('due_on') is-invalid @enderror" type="date" name="due_on" id="due_on" value="{{ old('due_on') }}">
                    @error('due_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="description">What it is for</label>
                    <input class="form-control" type="text" name="description" id="description" value="{{ old('description') }}" maxlength="2000">
                </div>
                <div class="col-12">
                    <button class="btn btn-primary" type="submit"><i class="bi bi-plus-lg" aria-hidden="true"></i> Create project</button>
                </div>
            </div>
        </form>
    </section>
@endif

<x-ui.table-shell class="mt-3" title="Projects" :count="$projects->count().' project(s)'">
    <thead>
        <tr>
            <th>Project</th>
            <th>Owner</th>
            <th>Window</th>
            <th class="erp-th-num">Tasks</th>
            <th class="erp-th-num">Open</th>
            <th>State</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        @forelse ($projects as $project)
            <tr>
                <td>
                    <a class="erp-cell-strong" href="{{ route('projects.show', $project) }}">{{ $project->name }}</a>
                    <div class="erp-td-muted">{{ $project->code }}@if ($project->description) · {{ \Illuminate\Support\Str::limit($project->description, 70) }}@endif</div>
                </td>
                <td class="erp-td-muted">{{ $project->owner?->name ?? '—' }}</td>
                <td class="erp-td-muted">
                    {{ optional($project->starts_on)->format('d M Y') ?? '—' }}
                    → {{ optional($project->due_on)->format('d M Y') ?? 'open' }}
                </td>
                <td class="erp-td-num">{{ $project->tasks_count }}</td>
                <td class="erp-td-num">{{ $project->open_tasks_count }}</td>
                <td><x-ui.status :value="$project->status" :label="$project->statusLabel()" /></td>
                <td class="text-end">
                    <a class="btn btn-outline-secondary btn-sm" href="{{ route('projects.show', $project) }}">Open</a>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="7">
                    <x-ui.empty icon="bi-folder2-open" title="No projects yet"
                                text="Tasks work perfectly well without a project. Create one when a group of them belongs together and somebody has to answer for the whole." />
                </td>
            </tr>
        @endforelse
    </tbody>
</x-ui.table-shell>
