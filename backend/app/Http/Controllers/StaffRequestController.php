<?php

namespace App\Http\Controllers;

use App\Models\OvertimeRequest;
use App\Models\PublicHoliday;
use App\Models\StaffRequest;
use App\Models\Timesheet;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Staff requests: a person submits one to a supervisor, who approves or rejects it
 * (the same workflow as overtime). Every query is scoped to the owner or the approver.
 */
class StaffRequestController extends Controller
{
    private const TRANSPORT = ['Car', 'Motorbike', 'Bus', 'Airplane', 'Other'];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $items = StaffRequest::where('user_id', $user->id)->with(['approver:id,name', 'reviewer:id,name'])->latest()->latest('id')->limit(200)->get();

        return response()->json([
            'data' => $items->map(fn (StaffRequest $item) => $this->present($item, $user))->values(),
            'can_approve' => $this->isApprover($user),
        ]);
    }

    /** Waiting for (or already decided by) this approver. A Master Admin also receives requests with no named approver. */
    public function approvals(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->isApprover($user), 403);
        $items = StaffRequest::where(fn ($query) => $query->where('approver_id', $user->id)
            ->when($user->isMasterAdmin(), fn ($q) => $q->orWhereNull('approver_id')))
            ->where('user_id', '!=', $user->id)
            ->where('status', '!=', 'cancelled')
            ->with(['user:id,name', 'approver:id,name', 'reviewer:id,name'])
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")->latest()->limit(200)->get();

        return response()->json(['data' => $items->map(fn (StaffRequest $item) => $this->present($item, $user))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $type = $request->validate(['type' => ['required', Rule::in(array_keys(StaffRequest::TYPES))]])['type'];
        $data = $request->validate([
            'approver_id' => [$type === 'profile_change' ? 'nullable' : 'required', 'integer', Rule::notIn([$user->id]), Rule::exists('users', 'id')->where(fn ($query) => $query->where(fn ($q) => $q->whereIn('role', OvertimeRequest::APPROVER_ROLES)->orWhereJsonContains('staff_authorities', 'approver')))],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
            ...$this->rulesFor($type),
        ], [
            'approver_id.required' => 'Choose who should approve this request.',
            'approver_id.exists' => 'Choose a supervisor from the list.',
            'approver_id.not_in' => 'You can’t approve your own request. Choose another supervisor.',
            'attachment.mimes' => 'Attach a PDF or an image (JPG, PNG or WebP).',
            'attachment.max' => 'The attachment must be 5 MB or smaller.',
        ]);

        $details = $data['details'];
        if ($type === 'leave') $details['working_days'] = $this->workingDays($details['start_date'], $details['end_date']);

        $staffRequest = new StaffRequest(['user_id' => $user->id, 'type' => $type, 'approver_id' => $data['approver_id'] ?? null, 'status' => 'pending', 'details' => $details]);
        if ($request->hasFile('attachment')) {
            $staffRequest->attachment_path = $request->file('attachment')->store("staff-requests/{$user->id}", 'local');
            $staffRequest->attachment_name = mb_substr($request->file('attachment')->getClientOriginalName(), 0, 150);
        }
        $staffRequest->save();

        return response()->json(['data' => $this->present($staffRequest->load('approver:id,name'), $user)], 201);
    }

    public function cancel(Request $request, StaffRequest $staffRequest): JsonResponse
    {
        abort_unless($staffRequest->user_id === $request->user()->id, 403);
        if ($staffRequest->status !== 'pending') throw ValidationException::withMessages(['status' => "This request has already been {$staffRequest->status}, so it can’t be cancelled."]);
        $staffRequest->update(['status' => 'cancelled']);

        return response()->json(['data' => $this->present($staffRequest->load('approver:id,name'), $request->user())]);
    }

    public function decide(Request $request, StaffRequest $staffRequest): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->canApproveWorkflows() && ($staffRequest->approver_id === $user->id || $user->isMasterAdmin()), 403);
        abort_if($staffRequest->user_id === $user->id, 403, 'You can’t decide your own request.');
        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'note' => ['nullable', 'required_if:decision,rejected', 'string', 'max:1000'],
        ], ['note.required_if' => 'Give a reason so the staff member knows why it was rejected.']);
        if ($staffRequest->status !== 'pending') throw ValidationException::withMessages(['decision' => "This request has already been {$staffRequest->status}."]);

        $staffRequest->update(['status' => $data['decision'], 'reviewed_by' => $user->id, 'reviewed_at' => now(), 'review_note' => isset($data['note']) ? trim($data['note']) : null]);

        return response()->json(['data' => $this->present($staffRequest->load(['user:id,name', 'approver:id,name', 'reviewer:id,name']), $user)]);
    }

    public function attachment(Request $request, StaffRequest $staffRequest)
    {
        $user = $request->user();
        abort_unless($staffRequest->user_id === $user->id || $staffRequest->approver_id === $user->id || $user->isMasterAdmin(), 403);
        abort_unless($staffRequest->attachment_path && Storage::disk('local')->exists($staffRequest->attachment_path), 404);

        return Storage::disk('local')->download($staffRequest->attachment_path, $staffRequest->attachment_name, ['Cache-Control' => 'private, no-store']);
    }

    /** Validation for each type; everything validated under "details" is what gets stored. */
    private function rulesFor(string $type): array
    {
        return [
            'leave' => [
                'details' => ['required', 'array'],
                'details.leave_type' => ['required', Rule::in(array_keys(Timesheet::LEAVE_TYPES))],
                'details.start_date' => ['required', 'date_format:Y-m-d'],
                'details.end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:details.start_date', function (string $attribute, mixed $value, \Closure $fail): void {
                    $start = request()->input('details.start_date');
                    if (is_string($start) && strtotime($start) && $this->workingDays($start, $value) === 0) $fail('These dates have no working days. Choose at least one.');
                }],
                'details.reason' => ['nullable', 'string', 'max:500'],
            ],
            'travel' => [
                'details' => ['required', 'array'],
                'details.purpose' => ['required', 'string', 'max:1000'],
                'details.destination' => ['required', 'string', 'max:200'],
                'details.accommodation' => ['nullable', 'string', 'max:200'],
                'details.start_date' => ['required', 'date_format:Y-m-d'],
                'details.end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:details.start_date'],
                'details.transport' => ['required', Rule::in(self::TRANSPORT)],
                'details.costs' => ['nullable', 'array'],
                'details.costs.transport' => ['nullable', 'numeric', 'min:0'],
                'details.costs.accommodation' => ['nullable', 'numeric', 'min:0'],
                'details.costs.meals' => ['nullable', 'numeric', 'min:0'],
                'details.costs.other' => ['nullable', 'numeric', 'min:0'],
            ],
            'fuel' => [
                'details' => ['required', 'array'],
                'details.vehicle' => ['required', 'string', 'max:100'],
                'details.month' => ['required', 'integer', 'between:1,12'],
                'details.year' => ['required', 'integer', 'between:2000,2100'],
                'details.records' => ['required', 'array', 'min:1', 'max:62'],
                'details.records.*.date' => ['required', 'date_format:Y-m-d'],
                'details.records.*.mileage' => ['nullable', 'numeric', 'min:0'],
                'details.records.*.liters' => ['required', 'numeric', 'gt:0'],
                'details.records.*.cost' => ['required', 'numeric', 'min:0'],
            ],
            'purchase' => [
                'details' => ['required', 'array'],
                'details.purpose' => ['required', 'string', 'max:1000'],
                'details.department' => ['nullable', 'string', 'max:100'],
                'details.required_date' => ['nullable', 'date_format:Y-m-d'],
                'details.items' => ['required', 'array', 'min:1', 'max:100'],
                'details.items.*.description' => ['required', 'string', 'max:500'],
                'details.items.*.quantity' => ['required', 'numeric', 'gt:0'],
                'details.items.*.unit_cost' => ['required', 'numeric', 'min:0'],
                'details.justification' => ['nullable', 'string', 'max:2000'],
            ],
            'profile_change' => [
                'details' => ['required', 'array'],
                'details.field' => ['required', 'string', 'max:100'],
                'details.value' => ['required', 'string', 'max:255'],
                'details.reason' => ['required', 'string', 'max:500'],
            ],
        ][$type];
    }

    /** Weekdays that aren't public holidays, matching the timesheet's idea of a working day. */
    private function workingDays(string $start, string $end): int
    {
        $from = Carbon::parse($start);
        $to = Carbon::parse($end);
        if ($to->lt($from) || $from->diffInDays($to) > 366) return 0;
        $holidays = PublicHoliday::whereBetween('date', [$from->toDateString(), $to->toDateString()])->get()->map(fn ($holiday) => $holiday->date->toDateString())->all();

        return collect(CarbonPeriod::create($from, $to))->filter(fn ($day) => ! $day->isWeekend() && ! in_array($day->toDateString(), $holidays, true))->count();
    }

    private function summary(StaffRequest $item): string
    {
        $details = $item->details;
        $sum = fn (array $rows, callable $amount) => number_format(array_sum(array_map($amount, $rows)), 2);

        return match ($item->type) {
            'leave' => (Timesheet::LEAVE_TYPES[$details['leave_type']] ?? 'Leave').' leave · '.$details['working_days'].' working '.($details['working_days'] === 1 ? 'day' : 'days').' from '.Carbon::parse($details['start_date'])->format('j M Y'),
            'travel' => $details['destination'].' · '.Carbon::parse($details['start_date'])->format('j M Y'),
            'fuel' => $details['vehicle'].' · '.Carbon::create($details['year'], $details['month'], 1)->format('M Y').' · $'.$sum($details['records'], fn ($row) => (float) $row['cost']),
            'purchase' => $details['purpose'].' · $'.$sum($details['items'], fn ($row) => (float) $row['quantity'] * (float) $row['unit_cost']),
            'profile_change' => $details['field'].' → '.$details['value'],
        };
    }

    private function isApprover(User $user): bool { return $user->canApproveWorkflows(); }

    private function present(StaffRequest $item, User $viewer): array
    {
        return [
            'id' => $item->id,
            'type' => $item->type,
            'type_label' => StaffRequest::TYPES[$item->type] ?? $item->type,
            'summary' => $this->summary($item),
            'details' => $item->details,
            'status' => $item->status,
            'staff' => $item->relationLoaded('user') ? $item->user?->name : null,
            'approver' => $item->relationLoaded('approver') ? ($item->approver?->name ?? ($item->approver_id ? null : 'HR')) : null,
            'reviewed_by' => $item->relationLoaded('reviewer') ? $item->reviewer?->name : null,
            'reviewed_at' => $item->reviewed_at?->toIso8601String(),
            'review_note' => $item->review_note,
            'submitted_at' => $item->created_at?->toIso8601String(),
            'has_attachment' => (bool) $item->attachment_path,
            'attachment_name' => $item->attachment_name,
            'can_cancel' => $item->user_id === $viewer->id && $item->status === 'pending',
            'can_decide' => $item->canBeDecidedBy($viewer),
        ];
    }
}
