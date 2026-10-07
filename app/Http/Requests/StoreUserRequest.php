<?php

namespace App\Http\Requests;

use App\Domain\Security\Services\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * User creation: e-mail uniqueness, REAL configurable password policy
 * (same validator the app enforces forever), role/branch assignment
 * guards — an actor can never hand out a scope wider than their own
 * (server-side Rule 5 enforcement inside validation).
 */
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
        }

        if ($this->has('branch_scope')) {
            $this->merge(['branch_scope' => (string) $this->input('branch_scope')]);
        }
    }

    public function rules(): array
    {
        return $this->baseRules() + [
            'email' => ['required', 'email', 'max:191', 'unique:users,email'],
            'password' => ['required', 'string', 'max:200', $this->policyRule()],
        ];
    }

    /** @return array<string, mixed> */
    protected function baseRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            'phone' => ['nullable', 'string', 'max:32'],
            'status' => ['required', 'in:active,locked,disabled'],
            'branch_scope' => ['required', 'in:assigned,all'],
            'default_branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['integer', 'exists:roles,id'],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
            'warehouse_ids' => ['nullable', 'array'],
            'warehouse_ids.*' => ['integer', 'exists:warehouses,id'],
        ];
    }

    /** Callable validation rule running the real PasswordPolicy. */
    protected function policyRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            /** @var array<int, string> $errors */
            $errors = app(PasswordPolicy::class)->validate((string) $value, null, (string) $this->input('email'));

            foreach ($errors as $error) {
                $fail($error);
            }
        };
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $actor = $this->user();

            if ($actor === null) {
                return;
            }

            // Scope widening guard: only unrestricted actors may grant 'all'.
            if ($this->input('branch_scope') === 'all' && $actor->accessibleBranchIds() !== null) {
                $validator->errors()->add('branch_scope', 'You cannot grant all-branch access.');
            }

            $claimedBranches = array_map('intval', (array) $this->input('branch_ids', []));

            if ($this->filled('default_branch_id')) {
                $claimedBranches[] = (int) $this->input('default_branch_id');
            }

            foreach (array_unique($claimedBranches) as $branchId) {
                if (! $actor->hasBranchAccess($branchId)) {
                    $validator->errors()->add('default_branch_id', "Branch #{$branchId} is outside your own access.");

                    break;
                }
            }

            $actorRoleIds = $actor->roles()->pluck('roles.id')->map(fn ($id) => (int) $id)->all();

            if ($actor->isSuperAdmin()) {
                return; // super admin may grant any role
            }

            foreach ((array) $this->input('roles', []) as $roleId) {
                if (! in_array((int) $roleId, $actorRoleIds, true)) {
                    $validator->errors()->add('roles', 'You cannot assign a role you do not hold yourself.');

                    break;
                }
            }
        });
    }

    public function attributes(): array
    {
        return [
            'name' => 'name',
            'email' => 'e-mail address',
            'password' => 'password',
            'branch_scope' => 'branch scope',
            'roles' => 'roles',
        ];
    }
}
