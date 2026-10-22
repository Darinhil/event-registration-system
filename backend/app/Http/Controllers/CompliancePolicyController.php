<?php

namespace App\Http\Controllers;

use App\Models\CompliancePolicy;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CompliancePolicyController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => CompliancePolicy::query()->orderBy('title')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'category' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:2000'],
            'icon' => ['required', Rule::in(['shield', 'alert', 'drop'])],
        ]);

        $baseKey = Str::slug($data['title'], '_');
        $key = $baseKey;
        $suffix = 2;
        while (CompliancePolicy::query()->where('key', $key)->exists()) {
            $key = $baseKey . '_' . $suffix++;
        }

        $policy = CompliancePolicy::query()->create([...$data, 'key' => $key]);

        return response()->json(['data' => $policy], 201);
    }

    public function update(Request $request, CompliancePolicy $compliancePolicy): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'category' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:2000'],
            'icon' => ['required', Rule::in(['shield', 'alert', 'drop'])],
        ]);

        DB::transaction(function () use ($compliancePolicy, $data): void {
            $compliancePolicy->update($data);
            $this->clearAcknowledgements($compliancePolicy->key);
        });

        return response()->json(['data' => $compliancePolicy->fresh()]);
    }

    public function destroy(CompliancePolicy $compliancePolicy): JsonResponse
    {
        $key = $compliancePolicy->key;
        DB::transaction(function () use ($compliancePolicy, $key): void {
            $compliancePolicy->delete();
            $this->clearAcknowledgements($key);
        });

        return response()->json(['message' => 'Compliance policy deleted.']);
    }

    private function clearAcknowledgements(string $key): void
    {
        foreach (User::query()->whereNotNull('compliance_acknowledgements')->get() as $user) {
            $acknowledgements = $user->compliance_acknowledgements ?? [];
            if (! array_key_exists($key, $acknowledgements)) {
                continue;
            }

            unset($acknowledgements[$key]);
            $user->compliance_acknowledgements = $acknowledgements ?: null;
            $user->save();
        }
    }
}
