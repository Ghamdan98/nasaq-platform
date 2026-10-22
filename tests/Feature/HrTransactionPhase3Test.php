<?php

namespace Tests\Feature;

use App\Services\HRTransactions\HRTransactionEngine;
use App\Services\Settlement\LeaveSettlementEngine;
use App\Services\Legal\LegalRuleEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HrTransactionPhase3Test extends TestCase
{
    use RefreshDatabase;

    public function test_hr_transaction_engine_routes_final_settlement_correctly(): void
    {
        $result = HRTransactionEngine::calculate([
            'transaction_type' => 'final_settlement',
            'hire_date' => '2020-01-01',
            'last_working_date' => '2026-01-01',
            'termination_type' => 'termination',
            'basic_salary' => 6000,
            'housing_allowance' => 0,
            'transport_allowance' => 0,
            'fixed_allowances' => 0,
            'commissions' => 0,
            'other_allowances' => 0,
        ]);

        $this->assertSame('final_settlement', $result['transaction_type']);
        $this->assertSame('21000', $result['entitlements'][2]['amount']);
    }

    public function test_hr_transaction_engine_routes_leave_settlement_correctly(): void
    {
        $result = HRTransactionEngine::calculate([
            'transaction_type' => 'leave_settlement',
            'leave_transaction_type' => 'annual_leave_pay',
            'basic_salary' => 6000,
            'opening_balance' => 12,
            'accrued_days' => 8,
            'used_days' => 2,
            'adjustment_days' => 1,
            'requested_leave_days' => 5,
            'balance_source' => 'calculated',
        ]);

        $this->assertSame('leave_settlement', $result['transaction_type']);
        $this->assertSame('annual_leave_pay', $result['leave_transaction_type']);
        $this->assertSame('valid', $result['review_status']);
    }

    public function test_leave_settlement_does_not_require_termination_type(): void
    {
        $result = LeaveSettlementEngine::calculate([
            'leave_transaction_type' => 'annual_leave_pay',
            'basic_salary' => 6000,
            'opening_balance' => 10,
            'accrued_days' => 5,
            'used_days' => 2,
            'adjustment_days' => 1,
            'requested_leave_days' => 5,
            'balance_source' => 'hr_system',
        ]);

        $this->assertArrayHasKey('review_status', $result);
        $this->assertNotSame('requires_legal_review', $result['review_status']);
    }

    public function test_manual_balance_is_tagged_manual(): void
    {
        $result = LeaveSettlementEngine::calculate([
            'leave_transaction_type' => 'annual_leave_pay',
            'basic_salary' => 6000,
            'opening_balance' => 10,
            'accrued_days' => 5,
            'used_days' => 2,
            'adjustment_days' => 1,
            'requested_leave_days' => 5,
            'balance_source' => 'manual',
        ]);

        $this->assertSame('manual', $result['balance_source']);
    }

    public function test_active_service_cash_encashment_is_blocked(): void
    {
        $result = LeaveSettlementEngine::calculate([
            'leave_transaction_type' => 'active_service_cash_encashment',
            'basic_salary' => 6000,
            'opening_balance' => 10,
            'accrued_days' => 5,
            'used_days' => 0,
            'adjustment_days' => 0,
            'requested_leave_days' => 5,
            'balance_source' => 'calculated',
        ]);

        $this->assertSame('blocked', $result['review_status']);
        $this->assertSame('blocked', $result['status']);
    }

    public function test_requested_leave_exceeds_available_balance_is_blocked(): void
    {
        $result = LeaveSettlementEngine::calculate([
            'leave_transaction_type' => 'annual_leave_pay',
            'basic_salary' => 6000,
            'opening_balance' => 5,
            'accrued_days' => 0,
            'used_days' => 0,
            'adjustment_days' => 0,
            'requested_leave_days' => 10,
            'balance_source' => 'calculated',
        ]);

        $this->assertSame('requires_legal_review', $result['review_status']);
    }

    public function test_article_80_unverified_requires_legal_review(): void
    {
        $result = LegalRuleEngine::evaluateFinalSettlement([
            'termination_type' => 'article_80',
            'article_80_reason_code' => null,
        ], [
            'warnings' => [],
            'legal_references' => [],
            'review_status' => 'valid',
        ]);

        $this->assertSame('requires_legal_review', $result['review_status']);
    }
}
