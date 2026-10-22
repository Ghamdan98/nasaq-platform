<?php

namespace App\Services\Advances;

use App\Models\HrTransaction;
use App\Services\Settlement\SettlementMath as Money;

class AdvanceDeductionPolicy
{
    public const SOURCES = ['article_88' => 'https://www.hrsd.gov.sa/علاقات-العمل', 'articles_92_93' => 'https://www.hrsd.gov.sa/شروط-العمل-وظروفه'];

    public function evaluate(HrTransaction $settlement, array $summary, array $review): array
    {
        $amount = Money::asDecimal($settlement->deductions()->where('code', 'employee_advances')->sum('approved_amount'));
        $capacity = Money::maxValue(Money::subtract($settlement->gross_amount, $settlement->deductions()->where('code', '!=', 'employee_advances')->sum('approved_amount')), '0');
        if (Money::compare($amount, '0') === 0) {
            return ['status' => 'clear', 'maximum' => $capacity, 'reasons' => [], 'sources' => self::SOURCES];
        }
        $reasons = [];
        foreach ($summary['items'] as $advance) {
            if ($advance['company'] !== ($settlement->employee_snapshot_json['company'] ?? $advance['company'])) {
                $reasons[] = 'السلفة تخص شركة مختلفة عن الشركة المثبتة في المخالصة؛ يلزم سند قانوني للخصم.';
            }
        }
        if (empty($review['debt_verified']) || empty($review['documents_verified']) || empty($review['protected_rights_reviewed'])) {
            $reasons[] = 'إثبات المديونية أو مستندات الخصم أو الحقوق المحمية لم تكتمل مراجعتها.';
        }
        foreach ($summary['items'] as $advance) {
            if ($advance['is_disputed'] || $advance['status'] === 'suspended' || ! $advance['approval_reference'] || ! $advance['evidence_reference']) {
                $reasons[] = 'مديونية متنازع عليها أو معلقة أو غير مكتملة الإثبات.';
            }
        }
        $source = $review['deduction_source'] ?? '';
        $isWage = $settlement->transaction_type === 'leave_settlement' && ($settlement->input_snapshot_json['leave_transaction_type'] ?? '') !== 'termination_leave_compensation';
        $maximum = $capacity;
        if ($isWage || $source === 'wages') {
            $wage = $isWage ? ($settlement->calculation_snapshot_json['gross_leave_amount'] ?? '0') : ($settlement->calculation_snapshot_json['salary_due']['net_due'] ?? '0');
            $maximum = Money::minValue($maximum, Money::minValue(Money::multiply($wage, '0.1'), Money::maxValue(Money::subtract(Money::multiply($wage, '0.5'), $settlement->deductions()->where('code', '!=', 'employee_advances')->sum('approved_amount')), '0')));
            if (! empty($review['written_consent'])) {
                $reasons[] = 'الموافقة الخطية وحدها لا تحسم تجاوز الحدود الواجبة التطبيق على الأجور.';
            }
        } elseif ($source === 'non_wage') {
            $nonWage = $settlement->items()->whereIn('code', ['end_of_service', 'leave_balance', 'notice_compensation'])->sum('approved_amount');
            if ($settlement->transaction_type === 'leave_settlement') {
                $nonWage = $settlement->calculation_snapshot_json['gross_leave_amount'] ?? '0';
            }
            $maximum = Money::minValue($maximum, (string) $nonWage);
        } else {
            $reasons[] = 'مصدر الخصم مختلط أو غير محدد ويحتاج قرارًا قانونيًا موثقًا.';
        }
        $maximum = Money::minValue($maximum, $review['deductible_limit'] ?? '0');
        if (Money::compare($amount, $maximum) > 0) {
            $reasons[] = 'المبلغ يتجاوز الحد الذي أمكن التحقق منه؛ يلزم تعديل التخصيص أو قرار قانوني موثق.';
        }

        return ['status' => $reasons ? 'requires_legal_review' : 'clear', 'maximum' => Money::roundMoney($maximum), 'reasons' => array_values(array_unique($reasons)), 'sources' => self::SOURCES];
    }
}
