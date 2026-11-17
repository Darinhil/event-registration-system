<?php

namespace App\Http\Controllers;

use App\Models\FinanceVoucher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinanceVoucherController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        // Managers check, Event Admins verify, and Master Admins approve.
        // Admins can cover earlier stages when another user prepared the voucher.
        $queueStatuses = match ($user->role) {
            'manager' => ['prepared'],
            'event_admin' => ['prepared', 'checked'],
            'admin' => ['prepared', 'checked', 'verified', 'approved'],
            default => [],
        };
        $query = FinanceVoucher::query();
        if (! $user->isMasterAdmin()) {
            $query->where(function (Builder $records) use ($user, $queueStatuses): void {
                $records->where('user_id', $user->id);
                if ($queueStatuses !== []) {
                    $records->orWhere(fn (Builder $queue) => $queue->where('user_id', '!=', $user->id)->whereIn('payload->workflowStatus', $queueStatuses));
                }
            });
        }
        $vouchers = $query->with('user:id,name')->latest('updated_at')->limit(200)->get()
            ->map(fn (FinanceVoucher $voucher): array => $this->present($voucher, $user))
            ->filter(fn (array $voucher): bool => $user->isMasterAdmin() || $voucher['user_id'] === $user->id || $voucher['can_act'])
            ->values();

        return response()->json(['data' => $vouchers]);
    }

    public function show(Request $request, int $financeVoucher): JsonResponse
    {
        $user = $request->user();
        $voucher = FinanceVoucher::query()->findOrFail($financeVoucher);
        abort_unless($voucher->user_id === $user->id || $user->isMasterAdmin() || $this->canAct($voucher, $user), 403);

        return response()->json(['data' => $this->present($voucher, $user, true)]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $this->validatedPayload($request);
        $payload['workflowStatus'] = 'draft';
        $payload['financeWorkflow'] = ['events' => []];
        $voucher = $request->user()->financeVouchers()->create($this->summary($payload) + ['payload' => $payload]);

        return response()->json(['data' => $this->present($voucher, $request->user(), true)], 201);
    }

    public function update(Request $request, int $financeVoucher): JsonResponse
    {
        $user = $request->user();
        $voucher = FinanceVoucher::query()->where('user_id', $user->id)->findOrFail($financeVoucher);
        abort_unless(($voucher->payload['workflowStatus'] ?? 'draft') === 'draft', 422, 'This voucher is locked after it is prepared.');
        $payload = $this->validatedPayload($request);
        $payload['workflowStatus'] = 'draft';
        $payload['financeWorkflow'] = $voucher->payload['financeWorkflow'] ?? ['events' => []];
        $voucher->update($this->summary($payload) + ['payload' => $payload]);

        return response()->json(['data' => $this->present($voucher->fresh(), $user, true)]);
    }

    public function transition(Request $request, int $financeVoucher): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['action' => ['required', 'in:prepare,check,verify,approve']]);

        $voucher = DB::transaction(function () use ($user, $financeVoucher, $data): FinanceVoucher {
            $record = FinanceVoucher::query()->lockForUpdate()->findOrFail($financeVoucher);
            $from = $record->payload['workflowStatus'] ?? 'draft';
            $next = ['prepare' => ['draft' => 'prepared'], 'check' => ['prepared' => 'checked'], 'verify' => ['checked' => 'verified'], 'approve' => ['verified' => 'approved']];
            $roles = ['check' => ['manager', 'event_admin', 'admin'], 'verify' => ['event_admin', 'admin'], 'approve' => ['admin']];
            abort_unless(isset($next[$data['action']][$from]), 422, 'This workflow action is not available at the current stage.');

            if ($data['action'] === 'prepare') {
                abort_unless($record->user_id === $user->id, 403, 'Only the voucher owner can prepare it.');
            } else {
                $events = $record->payload['financeWorkflow']['events'] ?? [];
                $previousActors = collect($events)->pluck('actor_id')->map(fn ($id) => (int) $id)->all();
                abort_unless($record->user_id !== $user->id && ! in_array((int) $user->id, $previousActors, true), 403, 'A different staff member must complete each workflow stage.');
                abort_unless(in_array($user->role, $roles[$data['action']], true), 403, 'Your role cannot complete this workflow stage.');
            }

            $payload = $record->payload ?? [];
            $payload['workflowStatus'] = $next[$data['action']][$from];
            $events = $payload['financeWorkflow']['events'] ?? [];
            $events[] = [
                'action' => $data['action'],
                'status' => $payload['workflowStatus'],
                'actor_id' => $user->id,
                'actor_name' => $user->name,
                'actor_role' => $user->role,
                'acted_at' => now()->toIso8601String(),
            ];
            $payload['financeWorkflow'] = ['events' => $events];
            $record->update(['payload' => $payload]);

            return $record->fresh();
        });

        return response()->json(['data' => $this->present($voucher, $user, true)]);
    }

    private function present(FinanceVoucher $voucher, $viewer, bool $full = false): array
    {
        $payload = $voucher->payload ?? [];
        $status = $payload['workflowStatus'] ?? 'draft';
        $canEdit = $voucher->user_id === $viewer->id && $status === 'draft';
        $canAct = $this->canAct($voucher, $viewer);
        $visiblePayload = $full ? $payload : [
            'workflowStatus' => $status,
            'department' => $payload['department'] ?? null,
            'projectName' => $payload['projectName'] ?? null,
            'fiscalYear' => $payload['fiscalYear'] ?? null,
            'lines' => collect($payload['lines'] ?? [])->map(static fn (array $line): array => ['amount' => $line['amount'] ?? 0])->all(),
            'financeWorkflow' => $payload['financeWorkflow'] ?? ['events' => []],
        ];

        return [
            'id' => $voucher->id,
            'user_id' => $voucher->user_id,
            'owner_name' => $voucher->user?->name,
            'voucher_number' => $voucher->voucher_number,
            'voucher_date' => $voucher->voucher_date?->format('Y-m-d'),
            'payee_name' => $voucher->payee_name,
            'updated_at' => $voucher->updated_at?->toIso8601String(),
            'can_edit' => $canEdit,
            'can_act' => $canAct,
            'payload' => $visiblePayload,
        ];
    }

    private function canAct(FinanceVoucher $voucher, $user): bool
    {
        $payload = $voucher->payload ?? [];
        $status = $payload['workflowStatus'] ?? 'draft';
        if ($status === 'draft') return $voucher->user_id === $user->id;
        $action = ['prepared' => 'check', 'checked' => 'verify', 'verified' => 'approve'][$status] ?? null;
        if ($action === null || $voucher->user_id === $user->id) return false;
        $roles = ['check' => ['manager', 'event_admin', 'admin'], 'verify' => ['event_admin', 'admin'], 'approve' => ['admin']];
        $actors = collect($payload['financeWorkflow']['events'] ?? [])->pluck('actor_id')->map(fn ($id) => (int) $id)->all();

        return in_array($user->role, $roles[$action], true) && ! in_array((int) $user->id, $actors, true);
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
