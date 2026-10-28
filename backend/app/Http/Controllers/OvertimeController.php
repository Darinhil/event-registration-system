<?php

namespace App\Http\Controllers;

use App\Models\OvertimeRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Overtime: staff submit requests to a supervisor, supervisors approve or reject
 * the ones addressed to them. A Master Admin can also decide any request.
 */
class OvertimeController extends Controller
{
    private const MIN_MINUTES = 15;
    private const MAX_MINUTES = 16 * 60;
    private const MAX_DAYS_BACK = 60;   // claims older than this go through HR
    private const MAX_DAYS_AHEAD = 60;  // pre-approval for planned overtime

    /** The signed-in person's own requests, newest first, with this month's approved total. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $requests = OvertimeRequest::where('user_id', $user->id)->with(['supervisor:id,name', 'reviewer:id,name'])->orderByDesc('start_at')->orderByDesc('id')->get();
        $monthStart = now()->startOfMonth();

        return response()->json([
            'data' => $requests->map(fn (OvertimeRequest $item) => $this->present($item, $user))->values(),
            'summary' => [
                'pending' => $requests->where('status', 'pending')->count(),
                'approved_this_month_minutes' => (int) $requests->where('status', 'approved')->filter(fn ($item) => $item->work_date->gte($monthStart))->sum('minutes'),
                'awaiting_my_decision' => OvertimeRequest::where('supervisor_id', $user->id)->where('status', 'pending')->count(),
            ],
            'can_approve' => $this->isApprover($user),
        ]);
    }

    /** People who can be chosen as the approving supervisor (never yourself). */
    public function supervisors(Request $request): JsonResponse
    {
        return response()->json(['data' => User::whereIn('role', OvertimeRequest::APPROVER_ROLES)->whereKeyNot($request->user()->id)->orderBy('name')->get(['id', 'name', 'role'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'work_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now()->subDays(self::MAX_DAYS_BACK)->toDateString(), 'before_or_equal:'.now()->addDays(self::MAX_DAYS_AHEAD)->toDateString()],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'overnight' => ['sometimes', 'boolean'],
            'supervisor_id' => ['required', 'integer', Rule::exists('users', 'id')->where(fn ($query) => $query->whereIn('role', OvertimeRequest::APPROVER_ROLES))],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'work_date.after_or_equal' => 'Overtime older than '.self::MAX_DAYS_BACK.' days must be claimed through HR.',
            'work_date.before_or_equal' => 'You can request overtime up to '.self::MAX_DAYS_AHEAD.' days ahead.',
            'supervisor_id.exists' => 'Choose a supervisor from the list.',
            'reason.min' => 'Describe the work in at least 10 characters.',
        ]);
        if ((int) $data['supervisor_id'] === $user->id) {
            throw ValidationException::withMessages(['supervisor_id' => 'You can’t approve your own overtime. Choose another supervisor.']);
        }

        // An end time before the start only means "the next day" when the person says so.
        $start = Carbon::parse("{$data['work_date']} {$data['start_time']}");
        $end = Carbon::parse("{$data['work_date']} {$data['end_time']}");
        $overnight = (bool) ($data['overnight'] ?? false);
        if ($overnight) $end->addDay();
        if ($end->lte($start)) {
            throw ValidationException::withMessages(['end_time' => 'The end time must be after the start time. Tick “Ends the next day” for overnight work.']);
        }
        $minutes = (int) $start->diffInMinutes($end);
        if ($minutes < self::MIN_MINUTES) throw ValidationException::withMessages(['end_time' => 'Overtime must be at least '.self::MIN_MINUTES.' minutes.']);
        if ($minutes > self::MAX_MINUTES) throw ValidationException::withMessages(['end_time' => 'A single request can’t be longer than '.(self::MAX_MINUTES / 60).' hours. Split longer work into separate days.']);

        $created = DB::transaction(function () use ($user, $data, $start, $end, $minutes) {
            $clash = OvertimeRequest::where('user_id', $user->id)->whereIn('status', OvertimeRequest::ACTIVE_STATUSES)
                ->where('start_at', '<', $end)->where('end_at', '>', $start)->lockForUpdate()->first();
            if ($clash) {
                throw ValidationException::withMessages(['start_time' => "These hours overlap your {$clash->status} request for {$clash->start_at->format('j M, H:i')}–{$clash->end_at->format('H:i')}."]);
            }
            return OvertimeRequest::create(['user_id' => $user->id, 'supervisor_id' => $data['supervisor_id'], 'work_date' => $data['work_date'], 'start_at' => $start, 'end_at' => $end, 'minutes' => $minutes, 'reason' => trim($data['reason']), 'status' => 'pending']);
        });

        return response()->json(['data' => $this->present($created->load('supervisor:id,name'), $user)], 201);
    }

    public function cancel(Request $request, OvertimeRequest $overtime): JsonResponse
    {
        abort_unless($overtime->user_id === $request->user()->id, 403);
        if ($overtime->status !== 'pending') throw ValidationException::withMessages(['status' => "This request has already been {$overtime->status}, so it can’t be cancelled."]);
        $overtime->update(['status' => 'cancelled']);

        return response()->json(['data' => $this->present($overtime->load(['supervisor:id,name']), $request->user())]);
    }

    /** Requests waiting for (or already decided by) the signed-in supervisor. */
    public function approvals(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->isApprover($user), 403);
        $requests = OvertimeRequest::where('supervisor_id', $user->id)->with(['user:id,name', 'reviewer:id,name'])
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")->orderByDesc('start_at')->limit(200)->get();

        return response()->json(['data' => $requests->map(fn (OvertimeRequest $item) => $this->present($item, $user))->values()]);
    }

    public function decide(Request $request, OvertimeRequest $overtime): JsonResponse
    {
        $user = $request->user();
        abort_unless($overtime->supervisor_id === $user->id || $user->isMasterAdmin(), 403);
        abort_if($overtime->user_id === $user->id, 403, 'You can’t decide your own overtime request.');
        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'note' => ['nullable', 'required_if:decision,rejected', 'string', 'max:1000'],
        ], ['note.required_if' => 'Give a reason so the staff member knows why it was rejected.']);
        if ($overtime->status !== 'pending') throw ValidationException::withMessages(['decision' => "This request has already been {$overtime->status}."]);

        $overtime->update(['status' => $data['decision'], 'reviewed_by' => $user->id, 'reviewed_at' => now(), 'review_note' => isset($data['note']) ? trim($data['note']) : null]);

        return response()->json(['data' => $this->present($overtime->load(['user:id,name', 'reviewer:id,name']), $user)]);
    }

    private function isApprover(User $user): bool { return in_array($user->role, OvertimeRequest::APPROVER_ROLES, true); }

    private function present(OvertimeRequest $item, User $viewer): array
    {
        return [
            'id' => $item->id,
            'staff' => $item->relationLoaded('user') ? $item->user?->name : null,
            'supervisor' => $item->relationLoaded('supervisor') ? $item->supervisor?->name : null,
            'work_date' => $item->work_date->toDateString(),
            'start_time' => $item->start_at->format('H:i'),
            'end_time' => $item->end_at->format('H:i'),
            'overnight' => ! $item->end_at->isSameDay($item->start_at),
            'minutes' => $item->minutes,
            'reason' => $item->reason,
            'status' => $item->status,
            'reviewed_by' => $item->relationLoaded('reviewer') ? $item->reviewer?->name : null,
            'reviewed_at' => $item->reviewed_at?->toIso8601String(),
            'review_note' => $item->review_note,
            'submitted_at' => $item->created_at?->toIso8601String(),
            'can_cancel' => $item->user_id === $viewer->id && $item->status === 'pending',
            'can_decide' => $item->status === 'pending' && $item->user_id !== $viewer->id && ($item->supervisor_id === $viewer->id || $viewer->isMasterAdmin()),
        ];
    }
}
