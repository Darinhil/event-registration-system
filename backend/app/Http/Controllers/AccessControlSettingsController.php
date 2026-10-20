<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Models\WorkflowDefinition;
use App\Models\WorkflowStage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AccessControlSettingsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $canManageUsers = $user->hasPermission('users.manage');
        $canManageRoles = $user->hasPermission('roles.manage');
        $canManageWorkflows = $user->hasPermission('workflows.manage');

        return response()->json(['data' => [
            'roles' => ($canManageRoles || $canManageWorkflows) ? Role::query()->when($canManageRoles, fn ($query) => $query->with('permissions:id,key,name,group'))->withCount('permissions')->orderBy('id')->get() : [],
            'permissions' => $canManageRoles ? Permission::query()->orderBy('group')->orderBy('name')->get() : [],
            'staff' => $canManageUsers ? Staff::query()->with('user:id,name,email,phone,role,account_status')->orderBy('name')->get() : [],
            'users' => $canManageUsers ? User::query()->select('id', 'name', 'email', 'phone', 'role', 'account_status')->orderBy('name')->get() : [],
            'workflows' => $canManageWorkflows ? WorkflowDefinition::query()->with('stages.requiredRole:id,key,name')->orderBy('name')->get() : [],
        ]]);
    }

    public function updateRolePermissions(Request $request, Role $role): JsonResponse
    {
        $data = $request->validate([
            'permission_ids' => ['required', 'array'],
            'permission_ids.*' => ['integer', 'distinct', 'exists:permissions,id'],
        ]);

        if ($role->key === 'admin') {
            return response()->json(['message' => 'Master Admin must keep every permission.'], 422);
        }

        if (! $request->user()->isMasterAdmin()) {
            $requestedPermissions = Permission::query()->whereIn('id', $data['permission_ids'])->pluck('key');
            abort_unless($requestedPermissions->diff($request->user()->permissionKeys())->isEmpty(), 403, 'You cannot grant permissions you do not have.');
        }

        $role->permissions()->sync($data['permission_ids']);

        return response()->json(['data' => $role->load('permissions:id,key,name,group')]);
    }

    public function storeStaff(Request $request): JsonResponse
    {
        $data = $this->validateStaff($request);
        if ($data['employment_status'] === 'inactive' && ! empty($data['user_id'])) {
            abort_if((int) $data['user_id'] === (int) $request->user()->id, 422, 'You cannot deactivate your own account.');
        }
        $staff = DB::transaction(function () use ($data): Staff {
            $staff = Staff::query()->create($data);
            if ($data['employment_status'] === 'inactive' && $staff->user_id) {
                User::query()->whereKey($staff->user_id)->update(['account_status' => 'suspended']);
            }
            return $staff;
        });

        return response()->json(['data' => $staff->load('user:id,name,email,phone,role,account_status')], 201);
    }

    public function updateStaff(Request $request, Staff $staff): JsonResponse
    {
        $data = $this->validateStaff($request, $staff);
        if ($data['employment_status'] === 'inactive' && ! empty($data['user_id'])) {
            abort_if((int) $data['user_id'] === (int) $request->user()->id, 422, 'You cannot deactivate your own account.');
        }
        DB::transaction(function () use ($staff, $data): void {
            $staff->update($data);
            if ($data['employment_status'] === 'inactive' && $staff->user_id) {
                User::query()->whereKey($staff->user_id)->update(['account_status' => 'suspended']);
            }
        });

        return response()->json(['data' => $staff->fresh()->load('user:id,name,email,phone,role,account_status')]);
    }

    public function updateUserStatus(Request $request, User $user): JsonResponse
    {
        abort_if($request->user()->is($user), 422, 'You cannot suspend your own account.');
        abort_if($user->isMasterAdmin() && ! $request->user()->isMasterAdmin(), 403, 'Only a Master Admin can change a Master Admin account.');
        $data = $request->validate(['account_status' => ['required', Rule::in(['active', 'suspended'])]]);
        $user->update($data);

        return response()->json(['data' => $user->only(['id', 'name', 'email', 'phone', 'role', 'account_status'])]);
    }

    public function storeWorkflow(Request $request): JsonResponse
    {
        $data = $this->validateWorkflow($request);
        $workflow = DB::transaction(function () use ($data, $request): WorkflowDefinition {
            $workflow = WorkflowDefinition::query()->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'],
                'approval_rule' => $data['approval_rule'],
                'created_by' => $request->user()->id,
            ]);
            $this->replaceStages($workflow, $data['stages']);

            return $workflow;
        });

        return response()->json(['data' => $workflow->load('stages.requiredRole:id,key,name')], 201);
    }

    public function updateWorkflow(Request $request, WorkflowDefinition $workflow): JsonResponse
    {
        $data = $this->validateWorkflow($request);
        DB::transaction(function () use ($workflow, $data): void {
            $workflow->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'],
                'approval_rule' => $data['approval_rule'],
            ]);
            $this->replaceStages($workflow, $data['stages']);
        });

        return response()->json(['data' => $workflow->fresh()->load('stages.requiredRole:id,key,name')]);
    }

    public function destroyWorkflow(WorkflowDefinition $workflow): JsonResponse
    {
        $workflow->delete();

        return response()->json(['message' => 'Workflow deleted.']);
    }

    private function validateStaff(Request $request, ?Staff $staff = null): array
    {
        return $request->validate([
            'staff_id' => ['required', 'string', 'max:40', Rule::unique('staff', 'staff_id')->ignore($staff?->id)],
            'user_id' => ['nullable', 'integer', Rule::unique('staff', 'user_id')->ignore($staff?->id), Rule::exists('users', 'id')],
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'position' => ['nullable', 'string', 'max:120'],
            'employment_status' => ['required', Rule::in(['active', 'on_leave', 'inactive'])],
        ]);
    }

    private function validateWorkflow(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
            'approval_rule' => ['required', Rule::in(['sequential', 'parallel'])],
            'stages' => ['required', 'array', 'min:1', 'max:20'],
            'stages.*.name' => ['required', 'string', 'max:120'],
            'stages.*.required_role_id' => ['required', 'integer', 'exists:roles,id'],
            'stages.*.required_approvals' => ['required', 'integer', 'min:1', 'max:99'],
            'stages.*.approval_rule' => ['required', Rule::in(['any', 'all'])],
        ]);
    }

    private function replaceStages(WorkflowDefinition $workflow, array $stages): void
    {
        WorkflowStage::query()->where('workflow_definition_id', $workflow->id)->delete();
        foreach (array_values($stages) as $index => $stage) {
            $workflow->stages()->create([
                'stage_order' => $index + 1,
                'name' => $stage['name'],
                'required_role_id' => $stage['required_role_id'],
                'required_approvals' => $stage['required_approvals'],
                'approval_rule' => $stage['approval_rule'],
            ]);
        }
    }
}
