<x-ui.page-header
    eyebrow="Business Management · Tasks"
    title="Add work to the board"
    subtitle="A task starts in “To do” and is assigned to somebody — including yourself. The assignee is told once, by notification; what happens to it afterwards is recorded on the task's own timeline.">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('tasks.kanban') }}">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> Board
        </a>
    </x-slot:actions>
</x-ui.page-header>

<form method="POST" action="{{ route('tasks.store') }}">
    @csrf

    <section class="erp-card erp-card-max">
        <header class="erp-card-head">
            <div>
                <h2 class="erp-card-title">The task</h2>
                <p class="erp-card-sub">A title somebody can act on, and enough context that they do not have to ask.</p>
            </div>
        </header>
        <div class="p-3">
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label" for="title">Title</label>
                    <input class="form-control @error('title') is-invalid @enderror" type="text" name="title" id="title"
                           value="{{ old('title') }}" maxlength="191" required>
                    @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-12">
                    <label class="form-label" for="description">What needs doing</label>
                    <textarea class="form-control @error('description') is-invalid @enderror" name="description" id="description" rows="6">{{ old('description') }}</textarea>
                    @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="assigned_to">Assignee</label>
                    <select class="form-select @error('assigned_to') is-invalid @enderror" name="assigned_to" id="assigned_to">
                        <option value="">Nobody yet</option>
                        @foreach ($people as $person)
                            <option value="{{ $person->id }}" @selected((int) old('assigned_to') === $person->id)>{{ $person->name }}</option>
                        @endforeach
                    </select>
                    @error('assigned_to')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="priority">Priority</label>
                    <select class="form-select @error('priority') is-invalid @enderror" name="priority" id="priority">
                        @foreach ($priorities as $key => $label)
                            <option value="{{ $key }}" @selected(old('priority', 'normal') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('priority')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="due_at">Due</label>
                    <input class="form-control @error('due_at') is-invalid @enderror" type="datetime-local" name="due_at" id="due_at" value="{{ old('due_at') }}">
                    @error('due_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="project_id">Project (optional)</label>
                    <select class="form-select @error('project_id') is-invalid @enderror" name="project_id" id="project_id">
                        <option value="">No project — stands on its own</option>
                        @foreach ($projects as $project)
                            <option value="{{ $project->id }}" @selected((int) old('project_id') === $project->id)>{{ $project->code }} · {{ $project->name }}</option>
                        @endforeach
                    </select>
                    @error('project_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>
    </section>

    <div class="d-flex gap-2 mt-3">
        <button class="btn btn-primary" type="submit"><i class="bi bi-plus-lg" aria-hidden="true"></i> Create task</button>
        <a class="btn btn-outline-secondary" href="{{ route('tasks.kanban') }}">Cancel</a>
    </div>
</form>
