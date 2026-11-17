<?php

namespace App\Http\Controllers;

use App\Models\FinanceBudget;
use App\Models\FinanceVoucher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinanceReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $groups = [];
        $groupFor = static function (string $department, string $project, int $year) use (&$groups): array {
            $key = implode('|', [$department, $project, $year]);
            $groups[$key] ??= [
                'id' => $key,
                'department' => $department,
                'project_name' => $project,
                'fiscal_year' => $year,
                'approved_budget_usd' => 0.0,
                'approved_budget_khr' => 0.0,
                'approved_expenses_usd' => 0.0,
                'budget_count' => 0,
                'voucher_count' => 0,
            ];

            return $groups[$key];
        };

        foreach (FinanceBudget::query()->where('workflow_status', 'approved')->get() as $budget) {
            $payload = $budget->payload ?? [];
            $department = $budget->department;
            $project = $budget->project_name;
            $year = (int) ($payload['fiscalYear'] ?? $budget->fiscal_year ?? now()->year);
            if ($department === '' || $project === '') continue;
            $key = implode('|', [$department, $project, $year]);
            $currency = ($payload['currency'] ?? 'USD') === 'KHR' ? 'KHR' : 'USD';
            $amount = collect($payload['lines'] ?? [])->sum(static fn (array $line): float => (float) ($line['amount'] ?? 0));
            $groups[$key] ??= $groupFor($department, $project, $year);
            $budgetField = $currency === 'KHR' ? 'approved_budget_khr' : 'approved_budget_usd';
            $groups[$key][$budgetField] += $amount;
            $groups[$key]['budget_count']++;
        }

        foreach (FinanceVoucher::query()->where('payload->workflowStatus', 'approved')->get() as $voucher) {
            $payload = $voucher->payload ?? [];
            $department = (string) ($payload['department'] ?? '');
            $project = (string) ($payload['projectName'] ?? '');
            $year = (int) ($payload['fiscalYear'] ?? $voucher->voucher_date?->format('Y') ?? now()->year);
            if ($department === '' || $project === '') continue;
            $key = implode('|', [$department, $project, $year]);
            $groups[$key] ??= $groupFor($department, $project, $year);
            $groups[$key]['approved_expenses_usd'] += collect($payload['lines'] ?? [])->sum(static fn (array $line): float => (float) ($line['amount'] ?? 0));
            $groups[$key]['voucher_count']++;
        }

        $rows = collect($groups)->map(static function (array $row): array {
            $row['approved_budget_usd'] = round($row['approved_budget_usd'], 2);
            $row['approved_budget_khr'] = round($row['approved_budget_khr'], 2);
            $row['approved_expenses_usd'] = round($row['approved_expenses_usd'], 2);
            $row['remaining_usd'] = $row['approved_budget_usd'] > 0
                ? round($row['approved_budget_usd'] - $row['approved_expenses_usd'], 2)
                : null;
            $row['utilization_percent'] = $row['approved_budget_usd'] > 0
                ? round(($row['approved_expenses_usd'] / $row['approved_budget_usd']) * 100, 1)
                : null;

            return $row;
        })->sortBy([['department', 'asc'], ['project_name', 'asc'], ['fiscal_year', 'desc']])->values();

        return response()->json([
            'data' => $rows,
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
