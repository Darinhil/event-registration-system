<?php

namespace App\Http\Controllers;

use App\Models\StaffContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StaffContractController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => StaffContract::query()
                ->where('user_id', $request->user()->id)
                ->orderByDesc('start_date')
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'contract_type' => ['required', Rule::in([
                'Probationary',
                'Full-Time Contract',
                'Part-Time Contract',
                'Intermittent Contract',
                'Volunteering Contract',
                'Internship Contract',
            ])],
            'position' => ['required', 'string', 'max:150'],
            'department' => ['nullable', 'string', 'max:150'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'salary' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'status' => ['required', Rule::in(['Active', 'Completed', 'Terminated'])],
        ]);

        $contract = $request->user()->staffContracts()->create($data);

        return response()->json(['data' => $contract], 201);
    }

    public function destroy(Request $request, int $contract): JsonResponse
    {
        $record = StaffContract::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail($contract);
        $record->delete();

        return response()->json(['message' => 'Contract record deleted.']);
    }
}
