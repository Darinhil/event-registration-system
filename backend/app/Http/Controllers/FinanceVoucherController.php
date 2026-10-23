<?php

namespace App\Http\Controllers;

use App\Models\FinanceVoucher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinanceVoucherController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => FinanceVoucher::query()
                ->where('user_id', $request->user()->id)
                ->latest('updated_at')
                ->limit(50)
                ->get(['id', 'voucher_number', 'voucher_date', 'payee_name', 'updated_at']),
        ]);
    }

    public function show(Request $request, int $financeVoucher): JsonResponse
    {
        return response()->json([
            'data' => FinanceVoucher::query()
                ->where('user_id', $request->user()->id)
                ->findOrFail($financeVoucher),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedPayload($request);
        $voucher = $request->user()->financeVouchers()->create($this->summary($data) + ['payload' => $data]);

        return response()->json(['data' => $voucher], 201);
    }

    public function update(Request $request, int $financeVoucher): JsonResponse
    {
        $data = $this->validatedPayload($request);
        $voucher = FinanceVoucher::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail($financeVoucher);
        $voucher->update($this->summary($data) + ['payload' => $data]);

        return response()->json(['data' => $voucher->fresh()]);
    }

    private function validatedPayload(Request $request): array
    {
        return $request->validate([
            'number' => ['required', 'string', 'max:80'],
            'date' => ['nullable', 'date'],
            'advanceNumber' => ['nullable', 'string', 'max:80'],
            'name' => ['nullable', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
            'purpose' => ['nullable', 'string', 'max:5000'],
            'signatureDate' => ['nullable', 'date'],
            'voucherType' => ['nullable', 'string', 'max:80'],
            'paymentMode' => ['nullable', 'string', 'max:80'],
            'chequeNumber' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:10000'],
            'lines' => ['required', 'array', 'max:200'],
            'lines.*.id' => ['nullable', 'integer'],
            'lines.*.description' => ['nullable', 'string', 'max:2000'],
            'lines.*.fundingSource' => ['nullable', 'string', 'max:150'],
            'lines.*.budgetCode' => ['nullable', 'string', 'max:150'],
            'lines.*.amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.liquidatedAmount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.accountCode' => ['nullable', 'string', 'max:150'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'approvals' => ['sometimes', 'array'],
            'approvals.*.name' => ['nullable', 'string', 'max:255'],
            'approvals.*.position' => ['nullable', 'string', 'max:255'],
            'approvals.*.date' => ['nullable', 'string', 'max:30'],
            'approvals.*.signature' => ['nullable', 'string', 'max:1000000'],
        ]);
    }

    private function summary(array $data): array
    {
        return [
            'voucher_number' => $data['number'],
            'voucher_date' => $data['date'] ?: null,
            'payee_name' => $data['name'] ?: null,
        ];
    }
}
