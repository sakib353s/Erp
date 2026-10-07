<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Masters\Support\MasterCatalog;
use App\Http\Requests\StoreMasterRequest;
use App\Http\Requests\UpdateMasterRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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

        $query = $this->scoped($entry);

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
            'tree' => $this->treeMaps($entry),
        ]);
    }

    public function create(string $type): View
    {
        $entry = $this->withOptions($this->entry($type));

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

        $data = $this->blankSelectsToNull($entry, $data);
        $this->assertParent($entry, $data, null);

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
        $model = $this->resolve($entry, $record);
        $entry = $this->withOptions($entry, $model);

        return view('masters.form', [
            'type' => $type,
            'entry' => $entry,
            'record' => $model,
            'mode' => 'edit',
        ]);
    }

    public function update(UpdateMasterRequest $request, string $record, string $type): RedirectResponse
    {
        $entry = $this->entry($type);
        $model = $this->resolve($entry, $record);

        $data = $request->validated();
        unset($data['company_id']);

        $data = $this->blankSelectsToNull($entry, $data);
        $this->assertParent($entry, $data, $model);

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

        $this->assertNoChildren($model);

        $model->delete();

        $this->recordAudit($request, 'record.delete', $type, $model, $before);

        return redirect()
            ->route("masters.{$type}.index")
            ->with('status', ucfirst($entry['label']).' row deleted.');
    }

    /**
     * The company-scoped base query for one catalog entry. Every read and every
     * parent lookup goes through here, so a row of another company is not merely
     * invisible — it cannot be referenced either.
     *
     * @param  array<string, mixed>  $entry
     * @return Builder<Model>
     */
    protected function scoped(array $entry): Builder
    {
        $query = $entry['model']::query();

        if ($entry['company_scoped'] ?? true) {
            $query->where('company_id', app('request')->user()?->company_id);
        }

        return $query;
    }

    /**
     * Fill the options of selects whose choices live in the database. On edit the
     * record itself and its descendants are withdrawn from the list, because a
     * row that is its own ancestor is not a taxonomy — it is a loop.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    protected function withOptions(array $entry, ?Model $record = null): array
    {
        foreach ($entry['fields'] ?? [] as $field => $meta) {
            if (($meta['type'] ?? 'text') !== 'select' || ! isset($meta['source'])) {
                continue;
            }

            $entry['fields'][$field]['options'] = $this->optionList($meta['source'], $record);
        }

        return $entry;
    }

    /** @return array<int, string> id → label */
    protected function optionList(string $sourceSlug, ?Model $record): array
    {
        $source = MasterCatalog::get($sourceSlug);

        if ($source === null) {
            return [];
        }

        $query = $this->scoped($source);

        if ($record !== null && $record->exists) {
            $query->whereNotIn('id', array_merge([$record->getKey()], $this->descendantIds($source, $record)));
        }

        return $query->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * A blank select means "no parent", not the empty string. Written out here so
     * the column stores NULL and the tree knows where the root is.
     *
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function blankSelectsToNull(array $entry, array $data): array
    {
        foreach ($entry['fields'] ?? [] as $field => $meta) {
            if (($meta['type'] ?? 'text') === 'select' && array_key_exists($field, $data) && $data[$field] === '') {
                $data[$field] = null;
            }
        }

        return $data;
    }

    /**
     * §04-05: a hierarchy is only a hierarchy if it cannot become a loop. The
     * parent has to belong to this company, cannot be the row itself, and cannot
     * be one of the row's own descendants.
     *
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $data
     */
    protected function assertParent(array $entry, array $data, ?Model $record): void
    {
        if (! ($entry['hierarchy'] ?? false)) {
            return;
        }

        $parentId = $data['parent_id'] ?? null;

        if ($parentId === null || $parentId === '') {
            return;
        }

        $parentId = (int) $parentId;

        if (! $this->scoped($entry)->whereKey($parentId)->exists()) {
            throw ValidationException::withMessages([
                'parent_id' => 'Pick a parent row from this company.',
            ]);
        }

        if ($record === null || ! $record->exists) {
            return;
        }

        if ($parentId === (int) $record->getKey()) {
            throw ValidationException::withMessages([
                'parent_id' => 'A row cannot be its own parent.',
            ]);
        }

        if (in_array($parentId, $this->descendantIds($entry, $record), true)) {
            throw ValidationException::withMessages([
                'parent_id' => 'That row sits underneath this one — the parent cannot be its own descendant.',
            ]);
        }
    }

    /** Refuse to orphan a branch: children are moved or deleted first. */
    protected function assertNoChildren(Model $record): void
    {
        if (! method_exists($record, 'children')) {
            return;
        }

        $children = $record->children()->count();

        if ($children > 0) {
            throw ValidationException::withMessages([
                'record' => "{$children} child row(s) still point at this one — move or delete them first.",
            ]);
        }
    }

    /**
     * id → descendants, built from the company's own rows. Bounded by construction
     * (a row appears once), so a pre-existing loop cannot spin forever.
     *
     * @param  array<string, mixed>  $entry
     * @return array<int, int>
     */
    protected function descendantIds(array $entry, Model $record): array
    {
        $childrenOf = [];

        foreach ($this->scoped($entry)->get(['id', 'parent_id']) as $row) {
            $childrenOf[(int) ($row->parent_id ?? 0)][] = (int) $row->getKey();
        }

        $found = [];
        $stack = [(int) $record->getKey()];

        while ($stack !== []) {
            $current = array_pop($stack);

            foreach ($childrenOf[$current] ?? [] as $child) {
                if (isset($found[$child])) {
                    continue;
                }

                $found[$child] = $child;
                $stack[] = $child;
            }
        }

        return array_values($found);
    }

    /**
     * Parent name and depth per row, so a flat table can show the tree it holds
     * (§04-05: the categories list is where a hierarchy is useful).
     *
     * @param  array<string, mixed>  $entry
     * @return array{names: array<int, string>, parent_of: array<int, int|null>, depth: array<int, int>}
     */
    protected function treeMaps(array $entry): array
    {
        if (! ($entry['hierarchy'] ?? false)) {
            return ['names' => [], 'parent_of' => [], 'depth' => []];
        }

        $rows = $this->scoped($entry)->orderBy('name')->get(['id', 'parent_id', 'name']);

        $parentOf = [];
        $names = [];
        $depth = [];

        foreach ($rows as $row) {
            $parentOf[(int) $row->getKey()] = $row->parent_id === null ? null : (int) $row->parent_id;
            $names[(int) $row->getKey()] = (string) $row->name;
        }

        foreach ($rows as $row) {
            $level = 0;
            $cursor = $parentOf[(int) $row->getKey()];
            $guard = 0;

            while ($cursor !== null && $guard++ < 20) {
                $level++;
                $cursor = $parentOf[$cursor] ?? null;
            }

            $depth[(int) $row->getKey()] = $level;
        }

        return ['names' => $names, 'parent_of' => $parentOf, 'depth' => $depth];
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
        return $this->scoped($entry)->whereKey($record)->firstOrFail();
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
