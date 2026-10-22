<?php

namespace App\Services\Legal;

use App\Enums\LeaveTransactionType;
use App\Services\Settlement\SettlementMath;

class LegalRuleEngine
{
    public static function ruleIsEffective(array $rule, string $relevantDate): bool
    {
        $effectiveFrom = $rule['effective_from'] ?? $rule['effective_from_date'] ?? null;
        $effectiveTo = $rule['effective_to'] ?? $rule['effective_to_date'] ?? null;

        if (! $effectiveFrom) {
            return false;
        }

        if ($relevantDate < $effectiveFrom) {
            return false;
        }

        if ($effectiveTo && $relevantDate > $effectiveTo) {
            return false;
        }

        $isActive = $rule['is_active'] ?? true;

        return (bool) $isActive;
    }

    public static function effectiveRules(array $rules, string $relevantDate): array
    {
        $selected = [];

        foreach ($rules as $rule) {
            if (self::ruleIsEffective($rule, $relevantDate)) {
                $selected[] = $rule;
            }
        }

        return $selected;
    }

    public static function evaluateFinalSettlement(array $data, array $baseResult = []): array
    {
        $warnings = $baseResult['warnings'] ?? [];
        $legalReferences = $baseResult['legal_references'] ?? [];
        $status = $baseResult['review_status'] ?? 'valid';

        if (($data['termination_type'] ?? null) === 'article_80') {
            $required = [
                'article_80_reason_code',
                'employee_given_opportunity_to_object',
                'investigation_completed',
                'warning_required',
                'warning_given',
                'supporting_documents_confirmed',
            ];

            foreach ($required as $field) {
                if (($data[$field] ?? null) === null || ($data[$field] ?? null) === '') {
                    $warnings[] = LegalReviewService::warning(
                        'ARTICLE_80_INCOMPLETE',
                        'requires_legal_review',
                        'لم يتم تأكيد متطلبات تطبيق المادة 80 بشكل كافٍ.',
                        $field,
                        [
                            ['rule_code' => 'article_80', 'article_number' => '80', 'source_name' => 'HRSD'],
                        ]
                    );
                    $status = 'requires_legal_review';
                }
            }
        }

        if (($data['termination_type'] ?? null) === 'article_81') {
            $required = ['article_81_reason_code', 'supporting_facts_confirmed', 'supporting_documents_confirmed'];
            foreach ($required as $field) {
                if (($data[$field] ?? null) === null || ($data[$field] ?? null) === '') {
                    $warnings[] = LegalReviewService::warning(
                        'ARTICLE_81_INCOMPLETE',
                        'requires_legal_review',
                        'لم يتم تأكيد متطلبات تطبيق المادة 81.',
                        $field,
                        [
                            ['rule_code' => 'article_81', 'article_number' => '81', 'source_name' => 'HRSD'],
                        ]
                    );
                    $status = 'requires_legal_review';
                }
            }
        }

        if (! empty($data['agreement_date']) && empty($data['employee_written_consent_confirmed'])) {
            $warnings[] = LegalReviewService::warning(
                'MUTUAL_AGREEMENT_CONSENT_REQUIRED',
                'requires_legal_review',
                'يجب توثيق consent الكتابي للاتفاق المشترك قبل التصفية.',
                'employee_written_consent_confirmed',
                [['rule_code' => 'mutual_agreement', 'source_name' => 'HRSD']]
            );
            $status = 'requires_legal_review';
        }

        if (($data['contract_type'] ?? null) === 'fixed' && ! empty($data['contract_end_date'])) {
            $terminationDate = $data['termination_date'] ?? $data['last_working_date'] ?? null;
            if ($terminationDate && $data['contract_end_date'] < $terminationDate) {
                $warnings[] = LegalReviewService::warning(
                    'FIXED_CONTRACT_EXPIRY_MISMATCH',
                    'warning',
                    'تاريخ إنهاء العقد لا يتوافق مع تاريخ انتهاء العقد الثابت.',
                    'contract_end_date',
                    [['rule_code' => 'contract_expiry', 'source_name' => 'HRSD']]
                );
                $status = 'warning';
            }
        }

        return [
            'warnings' => LegalReviewService::mergeWarnings($warnings),
            'legal_references' => $legalReferences,
            'review_status' => $status,
        ];
    }

    public static function evaluateLeaveSettlement(array $data, array $baseResult = []): array
    {
        $warnings = $baseResult['warnings'] ?? [];
        $legalReferences = $baseResult['legal_references'] ?? [];
        $status = $baseResult['review_status'] ?? 'valid';

        $leaveType = $data['leave_transaction_type'] ?? 'annual_leave_pay';
        $type = LeaveTransactionType::tryFrom($leaveType);

        if (($type?->value ?? $leaveType) === LeaveTransactionType::ACTIVE_SERVICE_CASH_ENCASHMENT->value) {
            $warnings[] = LegalReviewService::warning(
                'LEAVE_CASH_ENCASHMENT_DURING_SERVICE',
                'blocked',
                'لا يجوز استبدال رصيد الإجازة بمقابل نقدي أثناء الخدمة دون موافقة قانونية.',
                'leave_transaction_type',
                [['rule_code' => 'article_111', 'article_number' => '111', 'source_name' => 'HRSD']]
            );
            $status = 'blocked';
        }

        $availableDays = $data['available_days'] ?? null;
        if ($availableDays !== null && isset($data['requested_leave_days'])) {
            $requested = SettlementMath::asDecimal($data['requested_leave_days']);
            if (SettlementMath::compare($requested, SettlementMath::asDecimal($availableDays)) > 0) {
                $warnings[] = LegalReviewService::warning(
                    'LEAVE_REQUEST_EXCEEDS_AVAILABLE',
                    'requires_legal_review',
                    'عدد أيام الإجازة المطلوبة يتجاوز الرصيد المتاح.',
                    'requested_leave_days',
                    [['rule_code' => 'leave_balance', 'source_name' => 'HRSD']]
                );
                $status = 'requires_legal_review';
            }
        }

        return [
            'warnings' => LegalReviewService::mergeWarnings($warnings),
            'legal_references' => $legalReferences,
            'review_status' => $status,
        ];
    }
}
