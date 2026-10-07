<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Masters\Support\MasterCatalog;
use App\Http\Requests\StoreMasterRequest;
use App\Http\Requests\UpdateMasterRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Generic master-data CRUD (traceability §14). One controller for every
 * resource in MasterCatalog: company scope enforced on save, permission
 * keys resolved from the registry (never hardcoded per action here),
 * every mutation audited.
 */
class MasterDataController extends Controller
{
    public function __construct(protected AuditRecorder $audit) {}

    public function index(Request $request, string $type): View
    {
        $entry = $this->entry($type);

        $query = $entry['model']::query();

        if ($entry['company_scoped'] ?? true) {
            $query->where('company_id', $request->user()->company_id);
        }

        $searchable = array_filter($entry['columns'], fn (string $c) => in_array($c, ['code', 'name', 'name_bn'], true));

        if ($search = trim((string) $request->query('q'))) {
            $query->where(function ($q) use ($searchable, $search) {
                foreach ($searchable as $column) {
                    $q->orWhere($column, 'like', "%{$search}%");
                }
            });
        }

        $query->orderBy(($entry['company_scoped'] ?? true) ? 'name' : 'sort');

        return view('masters.index', [
            'type' => $type,
            'entry' => $entry,
            'records' => $query->paginate(15)->withQueryString(),
            'q' => $search,
        ]);
    }

    public function create(string $type): View
    {
        $entry = $this->entry($type);

        return view('masters.form', [
            'type' => $type,
            'entry' => $entry,
            'record' => $entry['model']::newModelInstance(),
            'mode' => 'create',
        ]);
    }

    public function store(StoreMasterRequest $request, string $type): RedirectResponse
    {
        $entry = $this->entry($type);
        $data = $request->validated();

        unset($data['company_id']);

        if ($entry['company_scoped'] ?? true) {
            $data['company_id'] = $request->user()->company_id;
        }

        /** @var Model $record */
        $record = $entry['model']::create($data);

        $this->recordAudit($request, 'record.create', $type, $record, null);

        return redirect()
            ->route("masters.{$type}.index")
            ->with('status', ucfirst($entry['label']).' row created.');
    }

    public function edit(string $record, string $type): View
    {
        $entry = $this->entry($type);

        return view('masters.form', [
            'type' => $type,
            'entry' => $entry,
            'record' => $this->resolve($entry, $record),
            'mode' => 'edit',
        ]);
    }

    public function update(UpdateMasterRequest $request, string $record, string $type): RedirectResponse
    {
        $entry = $this->entry($type);
        $model = $this->resolve($entry, $record);

        $data = $request->validated();
        unset($data['company_id']);

        $before = ['name' => $model->getAttribute('name')];
        $model->fill($data);
        $model->save();

        $this->recordAudit($request, 'record.update', $type, $model, $before);

        return redirect()
            ->route("masters.{$type}.index")
            ->with('status', ucfirst($entry['label']).' row updated.');
    }

    public function destroy(Request $request, string $record, string $type): RedirectResponse
    {
        $entry = $this->entry($type);
        $model = $this->resolve($entry, $record);

        $before = [
            'code' => $model->getAttribute('code') ?? null,
            'name' => $model->getAttribute('name'),
        ];

        $model->delete();

        $this->recordAudit($request, 'record.delete', $type, $model, $before);

        return redirect()
            ->route("masters.{$type}.index")
            ->with('status', ucfirst($entry['label']).' row deleted.');
    }

    /** @return array<string, mixed> */
    protected function entry(string $type): array
    {
        $entry = MasterCatalog::get($type);

        if ($entry === null) {
            abort(404, 'Unknown master data resource.');
        }

        return $entry;
    }

    protected function resolve(array $entry, string $record): Model
    {
        $query = $entry['model']::query();

        if ($entry['company_scoped'] ?? true) {
            $query->where('company_id', app('request')->user()?->company_id);
        }

        return $query->whereKey($record)->firstOrFail();
    }

    /** @param array<string, mixed>|null $before */
    protected function recordAudit(Request $request, string $action, string $type, Model $record, ?array $before): void
    {
        $entry = MasterCatalog::get($type) ?? [];

        $this->audit->record([
            'action' => $action,
            'entity_type' => $type,
            'entity_id' => $record->getKey(),
            'branch_id' => null,
            'actor_id' => $request->user()?->id,
            'before' => $before,
            'after' => array_intersect_key($record->toArray(), array_flip(array_keys($entry['fields'] ?? []))),
            'ip' => (string) $request->ip(),
        ]);
    }
}
