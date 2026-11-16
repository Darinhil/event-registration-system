<?php

namespace App\Http\Controllers;

use App\Models\FinanceVoucher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinanceVoucherController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $vouchers = FinanceVoucher::query()
            ->where('user_id', $request->user()->id)
            ->latest('updated_at')
            ->limit(50)
            ->get(['id', 'voucher_number', 'voucher_date', 'payee_name', 'payload', 'updated_at'])
            ->map(static function (FinanceVoucher $voucher): array {
                $payload = $voucher->payload ?? [];

                return [
                    'id' => $voucher->id,
                    'voucher_number' => $voucher->voucher_number,
                    'voucher_date' => $voucher->voucher_date?->format('Y-m-d'),
                    'payee_name' => $voucher->payee_name,
                    'updated_at' => $voucher->updated_at?->toIso8601String(),
                    'payload' => [
                        'workflowStatus' => $payload['workflowStatus'] ?? 'draft',
                        'department' => $payload['department'] ?? null,
                        'projectName' => $payload['projectName'] ?? null,
                        'fiscalYear' => $payload['fiscalYear'] ?? null,
                        'lines' => collect($payload['lines'] ?? [])->map(static fn (array $line): array => ['amount' => $line['amount'] ?? 0])->all(),
                    ],
                ];
            });

        return response()->json([
            'data' => $vouchers,
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
        abort_unless(in_array($data['workflowStatus'] ?? 'draft', ['draft', 'prepared'], true), 422, 'A new voucher must start in draft or prepared status.');
        $voucher = $request->user()->financeVouchers()->create($this->summary($data) + ['payload' => $data]);

        return response()->json(['data' => $voucher], 201);
    }

    public function update(Request $request, int $financeVoucher): JsonResponse
    {
        $data = $this->validatedPayload($request);
        $voucher = FinanceVoucher::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail($financeVoucher);
        $currentStatus = $voucher->payload['workflowStatus'] ?? 'draft';
        abort_if($currentStatus === 'approved', 422, 'Approved vouchers cannot be changed.');
        $nextStatus = ['draft' => 'prepared', 'prepared' => 'checked', 'checked' => 'verified', 'verified' => 'approved'][$currentStatus] ?? null;
        $requestedStatus = $data['workflowStatus'] ?? $currentStatus;
        abort_unless($requestedStatus === $currentStatus || $requestedStatus === $nextStatus, 422, 'This workflow action is not available at the current stage.');
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
            'department' => ['nullable', 'string', 'in:HR and Operations,Activities'],
            'projectName' => ['nullable', 'string', 'max:150'],
            'fiscalYear' => ['nullable', 'integer', 'between:2000,2200'],
            'workflowStatus' => ['nullable', 'string', 'in:draft,prepared,checked,verified,approved'],
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
            'voucher_date' => ($data['date'] ?? null) ?: null,
            'payee_name' => ($data['name'] ?? null) ?: null,
        ];
    }
}
