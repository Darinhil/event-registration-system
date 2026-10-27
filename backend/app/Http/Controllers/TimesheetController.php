<?php

namespace App\Http\Controllers;

use App\Models\LeaveEntitlement;
use App\Models\PublicHoliday;
use App\Models\StaffContract;
use App\Models\Timesheet;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Monthly timesheets: staff enter daily hours and leave, save drafts, then submit to an
 * approver who approves it or returns it with comments. HR (Master Admin) marks approved
 * timesheets as processed. Every query is scoped to the owner or the assigned approver.
 */
class TimesheetController extends Controller
{
    /* ===================== Staff: their own timesheet ===================== */

    public function show(Request $request, int $year, int $month): JsonResponse
    {
        $this->assertPeriod($year, $month);
        $user = $request->user();
        $timesheet = Timesheet::firstOrNew(['user_id' => $user->id, 'year' => $year, 'month' => $month], ['status' => 'draft', 'entries' => []]);

        return response()->json(['data' => $this->payload($timesheet, $user, $user)]);
    }

    public function save(Request $request, int $year, int $month): JsonResponse
    {
        $this->assertPeriod($year, $month);
        $user = $request->user();
        $timesheet = Timesheet::firstOrNew(['user_id' => $user->id, 'year' => $year, 'month' => $month], ['status' => 'draft']);
        $this->assertEditable($timesheet);
        $data = $request->validate($this->metaRules($user));

        $timesheet->entries = $this->validatedEntries($request->input('entries', []), $year, $month, $user);
        $timesheet->approver_id = $data['approver_id'] ?? $timesheet->approver_id;
        $timesheet->comment = isset($data['comment']) ? trim($data['comment']) : $timesheet->comment;
        $timesheet->save();

        return response()->json(['data' => $this->payload($timesheet->fresh(), $user, $user)]);
    }

    public function submit(Request $request, int $year, int $month): JsonResponse
    {
        $this->assertPeriod($year, $month);
        $user = $request->user();
        $timesheet = Timesheet::firstOrNew(['user_id' => $user->id, 'year' => $year, 'month' => $month], ['status' => 'draft']);
        $this->assertEditable($timesheet);
        $data = $request->validate([...$this->metaRules($user), 'approver_id' => ['required', ...$this->metaRules($user)['approver_id']], 'certify' => ['accepted']], [
            'certify.accepted' => 'Confirm that these hours are accurate.',
            'approver_id.required' => 'Choose who should approve your timesheet.',
        ]);

        $entries = $this->validatedEntries($request->input('entries', []), $year, $month, $user);
        $errors = $this->blockingIssues($entries, $year, $month, $user);
        if ($errors) throw ValidationException::withMessages($errors);

        $timesheet->fill(['entries' => $entries, 'approver_id' => $data['approver_id'], 'comment' => isset($data['comment']) ? trim($data['comment']) : null, 'status' => 'submitted', 'submitted_at' => now(), 'decided_by' => null, 'decided_at' => null]);
        $timesheet->addHistory('submitted', $user, $timesheet->comment);
        $timesheet->save();

        return response()->json(['data' => $this->payload($timesheet->fresh(), $user, $user)]);
    }

    /** Take a submitted timesheet back to draft while the approver hasn't acted on it. */
    public function recall(Request $request, int $year, int $month): JsonResponse
    {
        $user = $request->user();
        $timesheet = Timesheet::where(['user_id' => $user->id, 'year' => $year, 'month' => $month])->firstOrFail();
        if ($timesheet->status !== 'submitted') throw ValidationException::withMessages(['status' => 'Only a submitted timesheet that hasn’t been reviewed yet can be recalled.']);
        $timesheet->status = 'draft';
        $timesheet->addHistory('recalled', $user);
        $timesheet->save();

        return response()->json(['data' => $this->payload($timesheet->fresh(), $user, $user)]);
    }

    public function uploadAttachment(Request $request, int $year, int $month): JsonResponse
    {
        $this->assertPeriod($year, $month);
        $request->validate(['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120']], ['file.mimes' => 'Attach a PDF, JPG or PNG file.', 'file.max' => 'The file can be at most 5 MB.']);
        $user = $request->user();
        $path = $request->file('file')->store("timesheet-attachments/{$user->id}", 'local');

        return response()->json(['data' => ['path' => $path, 'name' => Str::limit($request->file('file')->getClientOriginalName(), 150, '')]], 201);
    }

    /** The owner, the assigned approver or a Master Admin can open an attachment. */
    public function attachment(Request $request, Timesheet $timesheet, int $day)
    {
        $this->assertCanView($request->user(), $timesheet);
        $file = $timesheet->entries[(string) $day]['attachment'] ?? null;
        abort_unless($file && Storage::disk('local')->exists($file['path']), 404);

        return Storage::disk('local')->download($file['path'], $file['name'], ['Cache-Control' => 'private, no-store']);
    }

    /* ===================== Approvers ===================== */

    public function approvals(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->isApprover($user), 403);
        // Supervisors see what was sent to them; a Master Admin (HR) also sees every approved
        // timesheet still waiting to be processed.
        $items = Timesheet::where(fn ($query) => $query->where('approver_id', $user->id)->whereIn('status', ['submitted', 'approved', 'returned'])
            ->when($user->isMasterAdmin(), fn ($q) => $q->orWhere(fn ($hr) => $hr->where('status', 'approved')->whereNull('processed_at'))))
            ->with('user:id,name')
            ->orderByRaw("CASE WHEN status = 'submitted' THEN 0 ELSE 1 END")->orderByDesc('submitted_at')->limit(100)->get();

        return response()->json(['data' => $items->map(fn (Timesheet $item) => [
            'id' => $item->id, 'staff' => $item->user?->name, 'year' => $item->year, 'month' => $item->month, 'status' => $item->status,
            'hours' => round(collect($item->entries ?? [])->sum(fn ($entry) => (float) ($entry['hours'] ?? 0)), 2),
            'leave_days' => array_sum($item->leaveDays()), 'submitted_at' => $item->submitted_at?->toIso8601String(), 'processed' => (bool) $item->processed_at,
        ])->values()]);
    }

    public function review(Request $request, Timesheet $timesheet): JsonResponse
    {
        $viewer = $request->user();
        $this->assertCanView($viewer, $timesheet);
        return response()->json(['data' => $this->payload($timesheet, $timesheet->user, $viewer)]);
    }

    public function decide(Request $request, Timesheet $timesheet): JsonResponse
    {
        $viewer = $request->user();
        abort_unless($timesheet->approver_id === $viewer->id || $viewer->isMasterAdmin(), 403);
        abort_if($timesheet->user_id === $viewer->id, 403, 'You can’t approve your own timesheet.');
        $data = $request->validate([
            'decision' => ['required', 'in:approved,returned'],
            'comment' => ['nullable', 'required_if:decision,returned', 'string', 'max:2000'],
            'day_comments' => ['nullable', 'array'],
            'day_comments.*' => ['nullable', 'string', 'max:500'],
        ], ['comment.required_if' => 'Tell the staff member what needs correcting.']);
        if ($timesheet->status !== 'submitted') throw ValidationException::withMessages(['decision' => 'This timesheet isn’t waiting for a decision.']);

        $dayComments = $data['decision'] === 'returned'
            ? collect($data['day_comments'] ?? [])->filter(fn ($comment, $day) => filled($comment) && ctype_digit((string) $day))->map(fn ($comment) => trim($comment))->all()
            : [];
        $timesheet->fill(['status' => $data['decision'], 'decided_by' => $viewer->id, 'decided_at' => now(), 'return_comment' => $data['decision'] === 'returned' ? trim($data['comment']) : null, 'day_comments' => $dayComments]);
        $timesheet->addHistory($data['decision'], $viewer, $data['comment'] ?? null);
        $timesheet->save();

        return response()->json(['data' => $this->payload($timesheet->fresh(), $timesheet->user, $viewer)]);
    }

    /** HR records that an approved timesheet has been processed (payroll / leave records). */
    public function process(Request $request, Timesheet $timesheet): JsonResponse
    {
        $viewer = $request->user();
        abort_unless($viewer->isMasterAdmin(), 403);
        if ($timesheet->status !== 'approved' || $timesheet->processed_at) throw ValidationException::withMessages(['status' => 'Only an approved timesheet that hasn’t been processed can be marked as processed.']);
        $timesheet->processed_at = now();
        $timesheet->addHistory('processed', $viewer);
        $timesheet->save();

        return response()->json(['data' => $this->payload($timesheet->fresh(), $timesheet->user, $viewer)]);
    }

    /* ===================== Rules ===================== */

    private function metaRules(User $user): array
    {
        return [
            'approver_id' => ['nullable', 'integer', Rule::notIn([$user->id]), Rule::exists('users', 'id')->where(fn ($query) => $query->whereIn('role', Timesheet::APPROVER_ROLES))],
            'comment' => ['nullable', 'string', 'max:2000'],
            'entries' => ['present', 'array'],
        ];
    }

    /** Checks each day's entry and returns the cleaned set. Throws on anything that can't be saved. */
    private function validatedEntries(mixed $raw, int $year, int $month, User $user): array
    {
        if (! is_array($raw)) throw ValidationException::withMessages(['entries' => 'Timesheet entries are missing.']);
        $calendar = $this->calendar($year, $month);
        $clean = [];
        foreach ($raw as $day => $entry) {
            $key = (string) $day;
            if (! isset($calendar[$key]) || ! is_array($entry)) throw ValidationException::withMessages(["entries.$key" => "Day $key isn’t in this month."]);
            $info = $calendar[$key];
            $hours = $entry['hours'] ?? null;
            $hours = $hours === null || $hours === '' ? null : $hours;
            $leave = $entry['leave_type'] ?? null ?: null;
            $part = $leave ? ($entry['leave_part'] ?? 'full') : null;
            $note = trim((string) ($entry['note'] ?? ''));
            $attachment = $entry['attachment'] ?? null;
            $label = Carbon::create($year, $month, (int) $key)->format('j M');

            if ($hours !== null) {
                if (! is_numeric($hours) || $hours < 0 || $hours > 24) throw ValidationException::withMessages(["entries.$key.hours" => "$label: hours must be between 0 and 24."]);
                if (fmod((float) $hours * 100, Timesheet::HOURS_STEP * 100) != 0.0) throw ValidationException::withMessages(["entries.$key.hours" => "$label: enter hours in steps of 15 minutes (0.25)."]);
                $hours = round((float) $hours, 2);
            }
            if (! $info['working'] && ($hours ?? 0) > 0) throw ValidationException::withMessages(["entries.$key.hours" => "$label is a ".($info['holiday'] ? 'public holiday' : 'weekend').'. Request overtime for work on non-working days.']);
            if ($leave !== null) {
                if (! array_key_exists($leave, Timesheet::LEAVE_TYPES)) throw ValidationException::withMessages(["entries.$key.leave_type" => "$label: choose a valid leave type."]);
                if (! $info['working']) throw ValidationException::withMessages(["entries.$key.leave_type" => "$label isn’t a working day, so it needs no leave."]);
                if (! in_array($part, ['full', 'am', 'pm'], true)) throw ValidationException::withMessages(["entries.$key.leave_part" => "$label: choose full day, morning or afternoon."]);
                if ($part === 'full' && ($hours ?? 0) > 0) throw ValidationException::withMessages(["entries.$key.hours" => "$label is a full day of leave, so it can’t have working hours."]);
                if ($part !== 'full' && ($hours ?? 0) > Timesheet::HALF_DAY_MAX_HOURS) throw ValidationException::withMessages(["entries.$key.hours" => "$label is a half day of leave, so hours can’t exceed ".Timesheet::HALF_DAY_MAX_HOURS.'.']);
            }
            if (mb_strlen($note) > 500) throw ValidationException::withMessages(["entries.$key.note" => "$label: keep the note under 500 characters."]);
            if ($attachment !== null) {
                // Only files this person uploaded can be attached.
                if (! is_array($attachment) || ! str_starts_with((string) ($attachment['path'] ?? ''), "timesheet-attachments/{$user->id}/") || ! Storage::disk('local')->exists($attachment['path'])) {
                    throw ValidationException::withMessages(["entries.$key.attachment" => "$label: the attachment couldn’t be found. Upload it again."]);
                }
                $attachment = ['path' => $attachment['path'], 'name' => Str::limit((string) ($attachment['name'] ?? 'attachment'), 150, '')];
            }
            if ($hours === null && $leave === null && $note === '' && $attachment === null) continue;
            $clean[$key] = ['hours' => $hours, 'leave_type' => $leave, 'leave_part' => $part, 'note' => $note, 'attachment' => $attachment];
        }
        ksort($clean, SORT_NUMERIC);
        return $clean;
    }

    /** Problems that stop a timesheet from being submitted. */
    private function blockingIssues(array $entries, int $year, int $month, User $user): array
    {
        $errors = [];
        $missing = collect($this->calendar($year, $month))->filter(function ($info, $day) use ($entries) {
            if (! $info['working']) return false;
            $entry = $entries[$day] ?? [];
            return ($entry['leave_part'] ?? null) !== 'full' && (float) ($entry['hours'] ?? 0) <= 0;
        })->keys()->map(fn ($day) => Carbon::create($year, $month, (int) $day)->format('j M'));
        if ($missing->isNotEmpty()) $errors['entries'] = 'Enter hours or leave for every working day. Missing: '.$missing->implode(', ').'.';

        $draft = new Timesheet(['entries' => $entries]);
        foreach ($this->balances($user, $year, $month, $draft) as $balance) {
            if ($balance['remaining'] < 0) $errors["leave.{$balance['type']}"] = "You’ve used more {$balance['label']} leave than you have left ({$balance['remaining']} days).";
        }
        return $errors;
    }

    /* ===================== Building blocks ===================== */

    /** Every day of the month: weekday, whether it's a working day, and any public holiday. */
    private function calendar(int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1);
        $holidays = PublicHoliday::whereBetween('date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])->get()->keyBy(fn ($holiday) => $holiday->date->day);
        $days = [];
        foreach (CarbonPeriod::create($start, $start->copy()->endOfMonth()) as $date) {
            $holiday = $holidays->get($date->day)?->name;
            $days[(string) $date->day] = ['day' => $date->day, 'weekday' => $date->dayOfWeekIso, 'weekend' => $date->isWeekend(), 'holiday' => $holiday, 'working' => ! $date->isWeekend() && ! $holiday];
        }
        return $days;
    }

    /**
     * Leave balances for the year. Remaining = entitlement + carried over − used before
     * (approved timesheets for other months) − pending (submitted, not yet approved) − this month.
     */
    private function balances(User $user, int $year, int $month, Timesheet $current): array
    {
        $rows = LeaveEntitlement::where('user_id', $user->id)->where('year', $year)->get()->keyBy('leave_type');
        $others = Timesheet::where('user_id', $user->id)->where('year', $year)->where('month', '!=', $month)->whereIn('status', ['submitted', 'approved'])->get();
        $sum = fn ($items) => $items->reduce(function (array $carry, Timesheet $item) { foreach ($item->leaveDays() as $type => $days) $carry[$type] = ($carry[$type] ?? 0) + $days; return $carry; }, []);
        $usedBefore = $sum($others->where('status', 'approved'));
        $pending = $sum($others->where('status', 'submitted'));
        $thisMonth = $current->leaveDays();

        return collect(Timesheet::LEAVE_TYPES)->map(function ($label, $type) use ($rows, $usedBefore, $pending, $thisMonth) {
            $entitled = (float) ($rows->get($type)?->entitled_days ?? Timesheet::DEFAULT_ENTITLEMENTS[$type] ?? 0);
            $carried = (float) ($rows->get($type)?->carried_over_days ?? 0);
            $before = (float) ($usedBefore[$type] ?? 0);
            $pend = (float) ($pending[$type] ?? 0);
            $now = (float) ($thisMonth[$type] ?? 0);
            return ['type' => $type, 'label' => $label, 'entitlement' => $entitled, 'carried_over' => $carried, 'used_before' => $before, 'pending' => $pend, 'used_this_month' => $now, 'remaining' => $entitled + $carried - $before - $pend - $now];
        })->values()->all();
    }

    private function payload(Timesheet $timesheet, User $owner, User $viewer): array
    {
        $contract = StaffContract::where('user_id', $owner->id)->orderByDesc('start_date')->get()->first(fn (StaffContract $item) => $item->status() === 'active');
        $approver = $timesheet->approver_id ? User::find($timesheet->approver_id, ['id', 'name']) : null;
        $editable = $owner->id === $viewer->id && in_array($timesheet->status, Timesheet::EDITABLE_STATUSES, true);

        return [
            'id' => $timesheet->id,
            'year' => $timesheet->year,
            'month' => $timesheet->month,
            'status' => $timesheet->status,
            'processed' => (bool) $timesheet->processed_at,
            'editable' => $editable,
            'entries' => (object) ($timesheet->entries ?? []),
            'comment' => $timesheet->comment,
            'approver' => $approver ? ['id' => $approver->id, 'name' => $approver->name] : null,
            'submitted_at' => $timesheet->submitted_at?->toIso8601String(),
            'return_comment' => $timesheet->return_comment,
            'day_comments' => (object) ($timesheet->day_comments ?? []),
            'history' => $timesheet->history ?? [],
            'saved_at' => $timesheet->updated_at?->toIso8601String(),
            'days' => array_values($this->calendar($timesheet->year, $timesheet->month)),
            'balances' => $this->balances($owner, $timesheet->year, $timesheet->month, $timesheet),
            'employee' => [
                'name' => $owner->name,
                'staff_id' => 'ST-'.str_pad((string) $owner->id, 5, '0', STR_PAD_LEFT),
                'position' => $contract?->position,
                'department' => $contract?->department,
            ],
            'approvers' => $owner->id === $viewer->id ? User::whereIn('role', Timesheet::APPROVER_ROLES)->whereKeyNot($owner->id)->orderBy('name')->get(['id', 'name', 'role']) : [],
            'can_decide' => $timesheet->status === 'submitted' && $timesheet->user_id !== $viewer->id && ($timesheet->approver_id === $viewer->id || $viewer->isMasterAdmin()),
            'can_process' => $timesheet->status === 'approved' && ! $timesheet->processed_at && $viewer->isMasterAdmin(),
            'rules' => ['standard_hours' => Timesheet::STANDARD_DAY_HOURS, 'half_day_max' => Timesheet::HALF_DAY_MAX_HOURS, 'step' => Timesheet::HOURS_STEP, 'leave_types' => Timesheet::LEAVE_TYPES],
        ];
    }

    private function assertPeriod(int $year, int $month): void
    {
        abort_unless($year >= 2020 && $year <= 2100 && $month >= 1 && $month <= 12, 404);
    }

    private function assertEditable(Timesheet $timesheet): void
    {
        if (! in_array($timesheet->status, Timesheet::EDITABLE_STATUSES, true)) {
            throw ValidationException::withMessages(['status' => $timesheet->status === 'submitted' ? 'This timesheet has been submitted. Recall it to make changes.' : 'This timesheet has been approved and can’t be changed.']);
        }
    }

    private function assertCanView(User $viewer, Timesheet $timesheet): void
    {
        abort_unless($timesheet->user_id === $viewer->id || $timesheet->approver_id === $viewer->id || $viewer->isMasterAdmin(), 403);
    }

    private function isApprover(User $user): bool { return in_array($user->role, Timesheet::APPROVER_ROLES, true); }
}
