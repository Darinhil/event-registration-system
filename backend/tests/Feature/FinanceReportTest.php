<?php

namespace Tests\Feature;

use App\Models\FinanceBudget;
use App\Models\FinanceVoucher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinanceReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_aggregates_only_approved_budget_and_voucher_values_by_project_and_year(): void
    {
        $owner = User::factory()->create(['role' => 'officer_1']);
        $viewer = User::factory()->create(['role' => 'manager']);

        FinanceBudget::create([
            'user_id' => $owner->id,
            'budget_number' => 'PB-USD-1',
            'department' => 'HR and Operations',
            'project_name' => 'Project A',
            'fiscal_year' => 2026,
            'workflow_status' => 'approved',
            'payload' => ['currency' => 'USD', 'fiscalYear' => 2026, 'lines' => [['amount' => 100]]],
        ]);
        FinanceBudget::create([
            'user_id' => $owner->id,
            'budget_number' => 'PB-KHR-1',
            'department' => 'HR and Operations',
            'project_name' => 'Project A',
            'fiscal_year' => 2026,
            'workflow_status' => 'approved',
            'payload' => ['currency' => 'KHR', 'fiscalYear' => 2026, 'lines' => [['amount' => 400000]]],
        ]);
        FinanceBudget::create([
            'user_id' => $owner->id,
            'budget_number' => 'PB-DRAFT-1',
            'department' => 'HR and Operations',
            'project_name' => 'Project A',
            'fiscal_year' => 2026,
            'workflow_status' => 'draft',
            'payload' => ['currency' => 'USD', 'fiscalYear' => 2026, 'lines' => [['amount' => 900]]],
        ]);
        FinanceVoucher::create([
            'user_id' => $owner->id,
            'voucher_number' => 'GV-APPROVED-1',
            'voucher_date' => '2026-04-01',
            'payee_name' => 'Vendor',
            'payload' => ['workflowStatus' => 'approved', 'department' => 'HR and Operations', 'projectName' => 'Project A', 'fiscalYear' => 2026, 'lines' => [['amount' => 60]]],
        ]);
        FinanceVoucher::create([
            'user_id' => $owner->id,
            'voucher_number' => 'GV-DRAFT-1',
            'voucher_date' => '2026-04-02',
            'payee_name' => 'Vendor',
            'payload' => ['workflowStatus' => 'prepared', 'department' => 'HR and Operations', 'projectName' => 'Project A', 'fiscalYear' => 2026, 'lines' => [['amount' => 500]]],
        ]);

        Sanctum::actingAs($viewer);
        $this->getJson('/api/admin/finance-reports')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.approved_budget_usd', 100)
            ->assertJsonPath('data.0.approved_budget_khr', 400000)
            ->assertJsonPath('data.0.approved_expenses_usd', 60)
            ->assertJsonPath('data.0.remaining_usd', 40)
            ->assertJsonPath('data.0.utilization_percent', 60);
    }
}
