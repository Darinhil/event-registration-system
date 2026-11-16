<?php

namespace App\Http\Controllers;

use App\Models\FinanceBudget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FinanceBudgetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => FinanceBudget::query()
            ->where('user_id', $request->user()->id)
            ->latest('updated_at')
            ->limit(250)
            ->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $this->validatedPayload($request);
        $status = ($payload['action'] ?? 'save') === 'submit' ? 'submitted' : 'draft';
        unset($payload['action']);
        $budget = $request->user()->financeBudgets()->create($this->summary($payload, $status) + [
            'workflow_status' => $status,
            'payload' => $payload,
        ]);

        return response()->json(['data' => $budget], 201);
    }

    public function update(Request $request, int $financeBudget): JsonResponse
    {
        $budget = FinanceBudget::query()->where('user_id', $request->user()->id)->findOrFail($financeBudget);
        abort_unless(in_array($budget->workflow_status, ['draft', 'returned'], true), 422, 'Only draft or returned budgets can be edited.');
        $payload = $this->validatedPayload($request);
        $action = $payload['action'] ?? 'save';
        unset($payload['action']);
        $status = $action === 'submit' ? 'submitted' : ($budget->workflow_status === 'returned' ? 'returned' : 'draft');
        $budget->update($this->summary($payload, $status) + ['workflow_status' => $status, 'payload' => $payload]);

        return response()->json(['data' => $budget->fresh()]);
    }

    public function transition(Request $request, int $financeBudget): JsonResponse
    {
        $budget = FinanceBudget::query()->where('user_id', $request->user()->id)->findOrFail($financeBudget);
        $data = $request->validate(['action' => ['required', Rule::in(['check', 'review', 'approve', 'return'])]]);
        $next = match ([$budget->workflow_status, $data['action']]) {
            ['submitted', 'check'] => 'checked',
            ['checked', 'review'] => 'reviewed',
            ['reviewed', 'approve'] => 'approved',
            ['submitted', 'return'], ['checked', 'return'], ['reviewed', 'return'] => 'returned',
            default => null,
        };
        abort_if($next === null, 422, 'This workflow action is not available at the current stage.');
        $budget->update(['workflow_status' => $next]);

        return response()->json(['data' => $budget->fresh()]);
    }

    private function validatedPayload(Request $request): array
    {
        $submit = $request->input('action') === 'submit';

        return $request->validate([
            'budgetNumber' => ['required', 'string', 'max:80'],
            'department' => ['required', Rule::in(['HR and Operations', 'Activities'])],
            'projectName' => ['required', 'string', 'max:150'],
            'fiscalYear' => ['nullable', 'integer', 'between:2000,2200'],
            'currency' => ['required', Rule::in(['USD', 'KHR'])],
            'purpose' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.description' => [$submit ? 'required' : 'nullable', 'string', 'max:2000'],
            'lines.*.budgetCode' => ['nullable', 'string', 'max:100'],
            'lines.*.amount' => [$submit ? 'required' : 'nullable', 'numeric', 'min:0'],
            'action' => ['sometimes', Rule::in(['save', 'submit'])],
        ]);
    }

    private function summary(array $payload, string $status): array
    {
        return [
            'budget_number' => $payload['budgetNumber'],
            'department' => $payload['department'],
            'project_name' => $payload['projectName'],
            'fiscal_year' => $payload['fiscalYear'] ?? null,
            'workflow_status' => $status,
        ];
    }
}
