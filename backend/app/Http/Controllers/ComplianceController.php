<?php

namespace App\Http\Controllers;

use App\Models\CompliancePolicy;
use App\Models\ComplianceRecord;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The signed-in staff member's own compliance requirements. Every query is scoped
 * to $request->user(), so nobody can read or complete another person's records.
 */
class ComplianceController extends Controller
{
    /** Completed items can be renewed this many days before they expire. */
    private const RENEWAL_WINDOW_DAYS = 30;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $policies = CompliancePolicy::where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
        $latest = $this->latestRecords($user, $policies->pluck('id'));
        $items = $policies->map(fn (CompliancePolicy $policy) => $this->present($policy, $latest->get($policy->id)));

        return response()->json(['data' => $items->values(), 'summary' => $this->summary($items)]);
    }

    public function complete(Request $request, CompliancePolicy $policy): JsonResponse
    {
        abort_unless($policy->is_active, 404);
        $user = $request->user();
        $current = $this->present($policy, $this->latestRecords($user, collect([$policy->id]))->get($policy->id));
        if (! $current['can_complete']) {
            throw ValidationException::withMessages(['policy' => $current['expires_at_label']
                ? "You're already up to date with this policy until {$current['expires_at_label']}."
                : "You've already completed this policy."]);
        }

        $rules = ['signature' => ['required', 'string', 'max:150']];
        $rules += $policy->requirement_type === 'declaration'
            ? ['has_conflict' => ['required', 'boolean'], 'details' => ['nullable', 'required_if_accepted:has_conflict', 'string', 'max:2000']]
            : ['acknowledged' => ['accepted']];
        $data = $request->validate($rules, [
            'acknowledged.accepted' => 'Tick the box to confirm you have read and understood this policy.',
            'has_conflict.required' => 'Choose whether you have a conflict of interest to declare.',
            'details.required_if_accepted' => 'Describe the conflict of interest so HR can review it.',
        ]);

        // The typed name is the signature, so it must be the account holder's own name.
        $normalise = fn (?string $name) => mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $name)));
        if ($normalise($data['signature']) !== $normalise($user->name)) {
            throw ValidationException::withMessages(['signature' => "Type your full name exactly as it appears on your account ({$user->name})."]);
        }

        $response = $policy->requirement_type === 'declaration'
            ? ['has_conflict' => (bool) $data['has_conflict'], 'details' => $data['has_conflict'] ? trim($data['details']) : null]
            : ['acknowledged' => true];
        $record = ComplianceRecord::create([
            'compliance_policy_id' => $policy->id,
            'user_id' => $user->id,
            'completed_at' => now(),
            'expires_at' => $policy->validity_months ? now()->addMonths($policy->validity_months) : null,
            'response' => $response,
            'signature' => trim($data['signature']),
        ]);

        return response()->json(['data' => $this->present($policy, $record)], 201);
    }

    /** @return Collection<int, ComplianceRecord> latest record per policy id */
    private function latestRecords(User $user, Collection $policyIds): Collection
    {
        return ComplianceRecord::where('user_id', $user->id)->whereIn('compliance_policy_id', $policyIds)
            ->orderByDesc('completed_at')->orderByDesc('id')->get()
            ->unique('compliance_policy_id')->keyBy('compliance_policy_id');
    }

    private function present(CompliancePolicy $policy, ?ComplianceRecord $record): array
    {
        $status = ! $record ? 'pending' : ($record->isExpired() ? 'expired' : 'completed');
        $renewSoon = $status === 'completed' && $record->expires_at && $record->expires_at->lte(now()->addDays(self::RENEWAL_WINDOW_DAYS));

        return [
            'id' => $policy->id,
            'title' => $policy->title,
            'category' => $policy->category,
            'description' => $policy->description,
            'requirement_type' => $policy->requirement_type,
            'statement' => $policy->statement,
            'document_url' => $policy->document_url,
            'validity_months' => $policy->validity_months,
            'status' => $status,
            'renew_soon' => $renewSoon,
            'can_complete' => $status !== 'completed' || $renewSoon,
            'completed_at' => $record?->completed_at?->toIso8601String(),
            'expires_at' => $record?->expires_at?->toIso8601String(),
            'expires_at_label' => $record?->expires_at?->format('j M Y'),
            'signature' => $record?->signature,
            'response' => $record?->response,
        ];
    }

    private function summary(Collection $items): array
    {
        $total = $items->count();
        $completed = $items->where('status', 'completed')->count();

        return [
            'required' => $total,
            'completed' => $completed,
            'pending' => $items->where('status', 'pending')->count(),
            'expired' => $items->where('status', 'expired')->count(),
            'renew_soon' => $items->where('renew_soon', true)->count(),
            'percent' => $total ? (int) round($completed / $total * 100) : 0,
        ];
    }
}
