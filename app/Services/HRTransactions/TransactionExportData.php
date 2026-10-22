<?php

namespace App\Services\HRTransactions;

use App\Models\HrTransaction;
use App\Models\HrTransactionDeduction;
use App\Models\HrTransactionItem;

class TransactionExportData
{
    public function build(HrTransaction $transaction): array
    {
        $transaction->loadMissing([
            'createdBy',
            'submittedBy',
            'approvedBy',
            'reviewedBy',
            'finalSettlementDetail',
            'leaveSettlementDetail',
            'items',
            'deductions',
            'adjustments.adjustedBy',
            'statusHistory.changedBy',
            'versions.createdBy',
        ]);

        $itemsByTypeAndId = [];
        foreach ($transaction->items as $item) {
            $itemsByTypeAndId[HrTransactionItem::class.':'.$item->id] = $item;
        }
        foreach ($transaction->deductions as $deduction) {
            $itemsByTypeAndId[HrTransactionDeduction::class.':'.$deduction->id] = $deduction;
        }

        $adjustments = $transaction->adjustments->map(function ($adjustment) use ($itemsByTypeAndId) {
            $relatedItem = $itemsByTypeAndId[$adjustment->item_type.':'.$adjustment->item_id] ?? null;

            return [
                'item_name' => $relatedItem?->name_ar ?? $relatedItem?->name ?? $relatedItem?->code ?? '—',
                'calculated_amount' => $adjustment->calculated_amount,
                'old_approved_amount' => $adjustment->old_approved_amount,
                'new_approved_amount' => $adjustment->new_approved_amount,
                'difference_amount' => $adjustment->difference_amount,
                'reason' => $adjustment->reason,
                'adjusted_by' => $adjustment->adjustedBy?->name,
                'created_at' => $adjustment->created_at,
            ];
        })->all();

        $calculation = $transaction->calculation_snapshot_json ?? [];

        return [
            'branding' => app(\App\Services\SystemSettingsService::class)->reportBranding(),
            'advance_allocations' => $transaction->advanceAllocations()->with('advance:id,reference_number')->get()->toArray(),
            'settlement_payments' => $transaction->payments()->get()->toArray(),
            'transaction' => $transaction,
            'employee' => $transaction->employee_snapshot_json ?? [],
            'contract' => $transaction->contract_snapshot_json ?? [],
            'salary' => $transaction->salary_snapshot_json ?? [],
            'input' => $transaction->input_snapshot_json ?? [],
            'calculation' => $calculation,
            'items' => $transaction->items,
            'deductions' => $transaction->deductions,
            'adjustments' => $adjustments,
            'status_history' => $transaction->statusHistory,
            'versions' => $transaction->versions,
            'legal_references' => $transaction->legal_rules_snapshot_json ?? [],
            'warnings' => $transaction->warnings_snapshot_json ?? [],
            'created_by_name' => $transaction->createdBy?->name,
            'submitted_by_name' => $transaction->submittedBy?->name,
            'approved_by_name' => $transaction->approvedBy?->name,
            'reviewed_by_name' => $transaction->reviewedBy?->name,
            'leave_details' => $transaction->leaveSettlementDetail,
            'final_details' => $transaction->finalSettlementDetail,
            'calculated_totals' => [
                'gross_amount' => $calculation['gross_entitlements'] ?? $calculation['gross_leave_amount'] ?? 0,
                'total_deductions' => $calculation['total_deductions'] ?? 0,
                'net_amount' => $calculation['net_settlement'] ?? $calculation['gross_leave_amount'] ?? 0,
            ],
            'has_adjustments' => collect($transaction->items)->concat($transaction->deductions)
                ->contains(fn ($item) => $item->was_manually_adjusted),
        ];
    }
}
