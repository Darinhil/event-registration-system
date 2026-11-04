<?php

namespace App\Http\Controllers;

use App\Models\CompliancePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminCompliancePolicyController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => CompliancePolicy::withCount('records')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $policy = CompliancePolicy::create($data + ['is_active' => true, 'sort_order' => 0]);

        return response()->json(['data' => $policy->loadCount('records')], 201);
    }

    public function update(Request $request, CompliancePolicy $policy): JsonResponse
    {
        $data = $request->validate($this->rules());
        if (
            isset($data['requirement_type'])
            && $data['requirement_type'] !== $policy->requirement_type
            && $policy->records()->exists()
        ) {
            throw ValidationException::withMessages([
                'requirement_type' => 'The requirement type cannot change after staff have submitted responses.',
            ]);
        }

        $policy->update($data);

        return response()->json(['data' => $policy->fresh()->loadCount('records')]);
    }

    public function destroy(CompliancePolicy $policy): JsonResponse
    {
        if ($policy->records()->exists()) {
            return response()->json([
                'message' => 'This policy has staff records and cannot be deleted. Deactivate it to keep its history.',
            ], 409);
        }

        $policy->delete();

        return response()->json(['message' => 'Policy deleted.']);
    }

    private function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'category' => ['required', 'string', 'max:80'],
            'description' => ['required', 'string', 'max:5000'],
            'requirement_type' => ['required', 'in:acknowledgement,declaration'],
            'statement' => ['required', 'string', 'max:5000'],
            'document_url' => ['nullable', 'url', 'max:500'],
            'validity_months' => ['nullable', 'integer', 'between:1,65535'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'between:0,65535'],
        ];
    }
}
