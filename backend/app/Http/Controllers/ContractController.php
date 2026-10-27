<?php

namespace App\Http\Controllers;

use App\Models\StaffContract;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Staff read and download their own contracts (/me/contracts). HR — Master Admins,
 * who already manage staff accounts — add, edit and remove contracts for anyone
 * (/admin/contracts). Signed files live on the private disk and are only served here.
 */
class ContractController extends Controller
{
    private const ENDING_SOON_DAYS = 30;

    public function mine(Request $request): JsonResponse
    {
        return $this->listFor($request->user());
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer']]);
        return $this->listFor($this->staffMember($data['user_id']));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $owner = $this->staffMember($data['user_id']);
        $this->assertNoOverlap($owner, $data['start_date'], $data['end_date'] ?? null);

        $contract = new StaffContract([...collect($data)->except('file')->all(), 'created_by' => $request->user()->id]);
        $this->attachFile($contract, $request);
        $contract->save();

        return response()->json(['data' => $this->present($contract)], 201);
    }

    /** POST (not PUT) so the browser can send a replacement file as multipart form data. */
    public function update(Request $request, StaffContract $contract): JsonResponse
    {
        $data = $this->validated($request, $contract);
        $this->assertNoOverlap($contract->user, $data['start_date'], $data['end_date'] ?? null, $contract->id);

        $contract->fill(collect($data)->except(['file', 'user_id', 'remove_file'])->all());
        if ($request->hasFile('file')) $this->attachFile($contract, $request);
        elseif ($request->boolean('remove_file')) $this->deleteFile($contract);
        $contract->save();

        return response()->json(['data' => $this->present($contract)]);
    }

    public function destroy(StaffContract $contract): JsonResponse
    {
        $this->deleteFile($contract);
        $contract->delete();
        return response()->json(['message' => 'Contract deleted.']);
    }

    public function file(Request $request, StaffContract $contract)
    {
        $user = $request->user();
        abort_unless($user->id === $contract->user_id || $user->isMasterAdmin(), 403);
        abort_unless($contract->file_path && Storage::disk('local')->exists($contract->file_path), 404, 'No signed copy has been uploaded for this contract.');

        return Storage::disk('local')->download($contract->file_path, $contract->file_name ?: "contract-{$contract->id}.pdf", ['Cache-Control' => 'private, no-store']);
    }

    private function listFor(User $user): JsonResponse
    {
        $contracts = StaffContract::where('user_id', $user->id)->orderByDesc('start_date')->orderByDesc('id')->get();
        return response()->json([
            'data' => $contracts->map(fn (StaffContract $contract) => $this->present($contract))->values(),
            'staff' => ['id' => $user->id, 'name' => $user->name],
        ]);
    }

    /** Contracts belong to staff accounts only (the people the personnel portal is for). */
    private function staffMember(int $id): User
    {
        $user = User::find($id);
        if (! $user || ! $user->isStaff()) throw ValidationException::withMessages(['user_id' => 'Choose a staff member.']);
        return $user;
    }

    private function validated(Request $request, ?StaffContract $contract = null): array
    {
        return $request->validate([
            'user_id' => [$contract ? 'sometimes' : 'required', 'integer'],
            'contract_type' => ['required', Rule::in(array_keys(StaffContract::TYPES))],
            'position' => ['required', 'string', 'max:150'],
            'department' => ['nullable', 'string', 'max:100'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'salary' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'currency' => ['required', Rule::in(StaffContract::CURRENCIES)],
            'file' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
            'remove_file' => ['sometimes', 'boolean'],
        ], [
            'end_date.after_or_equal' => 'The end date can’t be before the start date.',
            'file.mimes' => 'Upload the signed contract as a PDF.',
            'file.max' => 'The file can be at most 10 MB.',
        ]);
    }

    /** A person can only be on one contract at a time, so date ranges must not overlap. */
    private function assertNoOverlap(User $user, string $start, ?string $end, ?int $ignoreId = null): void
    {
        $clash = StaffContract::where('user_id', $user->id)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->when($end, fn ($query) => $query->whereDate('start_date', '<=', $end))
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $start))
            ->orderBy('start_date')->first();
        if ($clash) {
            $period = $clash->start_date->format('j M Y').' – '.($clash->end_date?->format('j M Y') ?? 'open-ended');
            throw ValidationException::withMessages(['start_date' => "These dates overlap {$user->name}’s {$this->typeLabel($clash)} contract ({$period}). End that contract first or adjust the dates."]);
        }
    }

    private function attachFile(StaffContract $contract, Request $request): void
    {
        if (! $request->hasFile('file')) return;
        $this->deleteFile($contract);
        $contract->file_path = $request->file('file')->store('contracts', 'local');
        $contract->file_name = $request->file('file')->getClientOriginalName();
    }

    private function deleteFile(StaffContract $contract): void
    {
        if ($contract->file_path) Storage::disk('local')->delete($contract->file_path);
        $contract->file_path = null;
        $contract->file_name = null;
    }

    private function typeLabel(StaffContract $contract): string { return mb_strtolower(StaffContract::TYPES[$contract->contract_type] ?? $contract->contract_type); }

    private function present(StaffContract $contract): array
    {
        $status = $contract->status();
        return [
            'id' => $contract->id,
            'user_id' => $contract->user_id,
            'contract_type' => $contract->contract_type,
            'type_label' => StaffContract::TYPES[$contract->contract_type] ?? $contract->contract_type,
            'position' => $contract->position,
            'department' => $contract->department,
            'start_date' => $contract->start_date->toDateString(),
            'end_date' => $contract->end_date?->toDateString(),
            'salary' => $contract->salary,
            'currency' => $contract->currency,
            'status' => $status,
            'ending_soon' => $status === 'active' && $contract->end_date !== null && $contract->end_date->lte(now()->addDays(self::ENDING_SOON_DAYS)),
            'has_file' => (bool) $contract->file_path,
            'file_name' => $contract->file_name,
        ];
    }
}
