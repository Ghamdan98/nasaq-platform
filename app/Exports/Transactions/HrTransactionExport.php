<?php

namespace App\Exports\Transactions;

use App\Models\HrTransactionDeduction;
use App\Models\HrTransactionItem;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class HrTransactionExport implements WithMultipleSheets
{
    public function __construct(private readonly array $data)
    {
    }

    public function sheets(): array
    {
        $transaction = $this->data['transaction'];
        $calculation = $this->data['calculation'];
        $employee = $this->data['employee'];
        $contract = $this->data['contract'];
        $leave = $this->data['leave_details'];
        $final = $this->data['final_details'];

        $sheets = [
            new TransactionArraySheet('ملخص المعاملة', [
                ['البيان', 'القيمة'],
                ['النظام', $this->data['branding']['name'] ?? ''],
                ['الجهة', $this->data['branding']['organization'] ?? ''],
                ['رقم المعاملة', $transaction->reference_number],
                ['الإصدار', $transaction->version_number],
                ['نوع المعاملة', $transaction->transaction_type],
                ['حالة المعاملة', $transaction->status],
                ['حالة المراجعة النظامية', $transaction->review_status],
                ['تاريخ المعاملة', $transaction->transaction_date?->format('Y-m-d')],
                ['اسم الموظف', $employee['full_name'] ?? null],
                ['رقم الموظف', $employee['employee_number'] ?? null],
                ['المسمى الوظيفي', $employee['job_title'] ?? null],
                ['الإدارة', $employee['department'] ?? null],
                ['نوع العقد', $contract['contract_type'] ?? null],
                ['تاريخ المباشرة', $contract['start_date'] ?? $employee['hire_date'] ?? null],
                ['تاريخ نهاية العقد', $contract['end_date'] ?? null],
                ['آخر يوم عمل', $final?->last_working_date?->format('Y-m-d') ?? ($this->data['input']['last_working_date'] ?? null)],
                ['مدة الخدمة', isset($calculation['service_period']) ? $this->servicePeriod($calculation['service_period']) : null],
                ['سبب انتهاء العلاقة', $final?->termination_type ?? ($calculation['termination']['type'] ?? null)],
                ['إجمالي المستحقات', (float) $transaction->gross_amount],
                ['إجمالي الخصومات', (float) $transaction->total_deductions],
                ['صافي المستحق', (float) $transaction->net_amount],
                ['المرحلة المالية', $transaction->financial_stage],
                ['قرار السلف', $transaction->advance_decision_json['choice'] ?? null],
                ['سبب قرار السلف', $transaction->advance_decision_json['reason'] ?? null],
                ['قرار المديونية المتبقية', $transaction->advance_decision_json['remaining_debt_reason'] ?? null],
                ['تاريخ الترحيل', $transaction->posted_at?->format('Y-m-d H:i:s')],
                ['تاريخ توثيق الدفع', $transaction->paid_at?->format('Y-m-d H:i:s')],
                ['عدد تخصيصات السلف', count($this->data['advance_allocations'] ?? [])],
                ['عدد مستندات الدفع', count($this->data['settlement_payments'] ?? [])],
                ['أنشأها', $this->data['created_by_name']],
                ['أرسلها للمراجعة', $this->data['submitted_by_name']],
                ['اعتمدها', $this->data['approved_by_name']],
                ['تاريخ الاعتماد', $transaction->approved_at?->format('Y-m-d H:i:s')],
                ['نوع معاملة الإجازة', $leave?->leave_transaction_type],
                ['الرصيد الافتتاحي', $leave?->opening_balance],
                ['الرصيد المستحق', $leave?->accrued_days],
                ['الرصيد المستخدم', $leave?->used_days],
                ['التعديلات', $leave?->adjustment_days],
                ['الرصيد المتاح', $leave?->available_days],
                ['الأيام المصروفة / المستحقة', $leave?->days_to_compensate],
                ['الرصيد المتبقي', $leave?->remaining_balance],
                ['مصدر الرصيد', $leave?->balance_source],
                ['الأجر المستخدم', $leave?->calculation_wage],
                ['قيمة اليوم', $leave?->daily_rate],
                ['مبلغ التصفية', $leave?->gross_leave_amount],
            ], ['B']),
            new TransactionArraySheet('المستحقات', $this->itemRows($this->data['items']), ['D', 'F', 'G', 'H']),
            new TransactionArraySheet('الخصومات', $this->deductionRows($this->data['deductions']), ['D', 'E', 'F', 'G']),
            new TransactionArraySheet('تفاصيل الحساب', $this->calculationRows($calculation)),
            new TransactionArraySheet('السند النظامي', $this->legalRows($this->data['legal_references'])),
            new TransactionArraySheet('سجل التعديلات', $this->adjustmentRows($this->data['adjustments']), ['B', 'C', 'D', 'E']),
            new TransactionArraySheet('سجل سير المعاملة', $this->workflowRows($this->data['status_history']), []),
        ];
        if (! empty($this->data['advance_allocations'])) {
            $rows = [['السلفة', 'المبلغ', 'الحالة', 'معتمد التخصيص', 'تاريخ الترحيل']];
            foreach ($this->data['advance_allocations'] as $allocation) $rows[] = [$allocation['advance']['reference_number'] ?? $allocation['advance_id'], $allocation['allocated_amount'], $allocation['status'], $allocation['approved_by'], $allocation['posted_at']];
            $sheets[] = new TransactionArraySheet('تخصيصات السلف', $rows, ['B']);
        }
        if (! empty($this->data['settlement_payments'])) {
            $rows = [['المرجع', 'المبلغ', 'التاريخ', 'الطريقة', 'عكس مستند', 'سجله']];
            foreach ($this->data['settlement_payments'] as $payment) $rows[] = [$payment['reference_number'], $payment['amount'], $payment['payment_date'], $payment['method'], $payment['reversal_of_id'], $payment['created_by']];
            $sheets[] = new TransactionArraySheet('مستندات الصرف', $rows, ['B']);
        }
        return $sheets;
    }

    private function itemRows(Collection $items): array
    {
        $rows = [['#', 'البند', 'الكمية', 'المعدل', 'طريقة الحساب', 'القيمة المحسوبة', 'القيمة المعتمدة', 'الفرق', 'معدل يدوياً؟', 'سبب التعديل', 'الحالة']];
        foreach ($items as $index => $item) {
            $rows[] = [
                $index + 1,
                $item->name_ar ?? $item->name ?? $item->code,
                $item->quantity,
                $item->rate,
                json_encode($item->calculation_details_json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                (float) $item->calculated_amount,
                (float) $item->approved_amount,
                (float) $item->difference_amount,
                $item->was_manually_adjusted ? 'نعم' : 'لا',
                $item->adjustment_reason,
                $item->type,
            ];
        }

        $rows[] = ['', 'إجمالي المستحقات المعتمدة', '', '', '', '', (float) $this->data['transaction']->gross_amount, '', '', '', ''];

        return $rows;
    }

    private function deductionRows(Collection $deductions): array
    {
        $rows = [['#', 'البند', 'الكمية', 'المعدل', 'القيمة المحسوبة', 'القيمة المعتمدة', 'الفرق', 'معدل يدوياً؟', 'سبب التعديل', 'سبب البند']];
        foreach ($deductions as $index => $item) {
            $rows[] = [
                $index + 1,
                $item->name_ar ?? $item->name ?? $item->code,
                $item->quantity,
                $item->rate,
                (float) $item->calculated_amount,
                (float) $item->approved_amount,
                (float) $item->difference_amount,
                $item->was_manually_adjusted ? 'نعم' : 'لا',
                $item->adjustment_reason,
                $item->calculation_details_json['reason'] ?? null,
            ];
        }
        $rows[] = ['', 'إجمالي الخصومات المعتمدة', '', '', '', (float) $this->data['transaction']->total_deductions, '', '', ''];

        return $rows;
    }

    private function calculationRows(array $calculation): array
    {
        $rows = [['البيان', 'القيمة']];
        foreach (['service_period' => 'مدة الخدمة', 'salary_base' => 'الأجر المستخدم', 'termination' => 'بيانات انتهاء العلاقة', 'leave_transaction_type' => 'نوع معاملة الإجازة', 'available_days' => 'الرصيد المتاح', 'days_to_compensate' => 'الأيام المصروفة', 'daily_rate' => 'قيمة اليوم', 'gross_leave_amount' => 'مبلغ تصفية الإجازة'] as $key => $label) {
            if (array_key_exists($key, $calculation)) {
                $value = is_array($calculation[$key])
                    ? json_encode($calculation[$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : $calculation[$key];
                $rows[] = [$label, $value];
            }
        }
        foreach ($calculation['entitlements'] ?? [] as $entitlement) {
            $rows[] = [
                $entitlement['name_ar'] ?? $entitlement['code'] ?? 'تفصيل',
                json_encode($entitlement['calculation'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }
        foreach ($this->data['items'] as $item) {
            $details = $item->calculation_details_json;
            if (is_string($details)) {
                $decodedDetails = json_decode($details, true);
                $details = json_last_error() === JSON_ERROR_NONE && is_array($decodedDetails)
                    ? $decodedDetails
                    : ['تفاصيل' => $details];
            }
            if (! is_array($details)) {
                $details = ['تفاصيل' => $details];
            }
            foreach ($details as $key => $value) {
                $rows[] = [
                    ($item->name_ar ?? $item->name ?? $item->code).' - '.$key,
                    is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE),
                ];
            }
        }

        return $rows;
    }

    private function legalRows(array $references): array
    {
        $rows = [['رقم المادة', 'العنوان', 'شرح مختصر', 'المصدر', 'تاريخ السريان', 'تاريخ نهاية السريان', 'حالة التحقق']];
        foreach ($references as $reference) {
            if (! is_array($reference)) {
                continue;
            }
            $rows[] = [
                $reference['article_number'] ?? $reference['article'] ?? null,
                $reference['title'] ?? $reference['rule_code'] ?? null,
                $reference['summary'] ?? $reference['description'] ?? null,
                $reference['source_name'] ?? $reference['source'] ?? null,
                $reference['effective_from'] ?? $reference['effective_date'] ?? null,
                $reference['effective_to'] ?? null,
                $reference['verification_status'] ?? $reference['status'] ?? null,
            ];
        }

        return $rows;
    }

    private function adjustmentRows(array $adjustments): array
    {
        $rows = [['البند', 'القيمة المحسوبة', 'القيمة المعتمدة السابقة', 'القيمة المعتمدة الجديدة', 'الفرق', 'سبب التعديل', 'عدل بواسطة', 'تاريخ التعديل']];
        foreach ($adjustments as $adjustment) {
            $rows[] = [
                $adjustment['item_name'],
                (float) $adjustment['calculated_amount'],
                (float) $adjustment['old_approved_amount'],
                (float) $adjustment['new_approved_amount'],
                (float) $adjustment['difference_amount'],
                $adjustment['reason'],
                $adjustment['adjusted_by'],
                $adjustment['created_at']?->format('Y-m-d H:i:s'),
            ];
        }

        return $rows;
    }

    private function workflowRows(Collection $history): array
    {
        $rows = [['الحالة السابقة', 'الحالة الجديدة', 'السبب', 'نفذ بواسطة', 'التاريخ']];
        foreach ($history as $entry) {
            $rows[] = [
                $entry->from_status,
                $entry->to_status,
                $entry->reason,
                $entry->changedBy?->name,
                $entry->created_at?->format('Y-m-d H:i:s'),
            ];
        }

        return $rows;
    }

    private function servicePeriod(array $period): string
    {
        return sprintf(
            '%s سنة، %s شهر، %s يوم',
            $period['years'] ?? 0,
            $period['months'] ?? 0,
            $period['days'] ?? 0,
        );
    }
}
