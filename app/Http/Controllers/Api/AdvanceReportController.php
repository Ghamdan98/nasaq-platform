<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdvanceTransaction;
use App\Models\EmployeeAdvance;
use App\Models\HrTransaction;
use App\Services\Advances\AdvanceLedger;
use App\Services\Employees\EmployeeSpreadsheet;
use App\Services\Settlement\SettlementMath;
use Illuminate\Http\Request;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class AdvanceReportController extends Controller
{
    public function __construct(private AdvanceLedger $ledger, private EmployeeSpreadsheet $sheets) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->hasPermission('view_advances'), 403);
        $request->validate(['kind' => 'required|in:outstanding,settled,terminated_debt,settlement_deductions,no_deduction,pending,statement',
            'employee_id' => 'nullable|required_if:kind,statement|integer|exists:employees,id', 'company' => 'nullable|string|max:255', 'format' => 'nullable|in:xlsx,pdf', 'page' => 'nullable|integer|min:1']);
        $kind = $request->input('kind');
        $rows = [];
        if (in_array($kind, ['settlement_deductions', 'no_deduction', 'pending'])) {
            $query = HrTransaction::with('employee:id,employee_number,full_name,company')->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', $request->input('employee_id')))->when($request->filled('company'), fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('company', $request->input('company'))));
            if ($request->user()->isHr()) {
                $query->where('created_by', $request->user()->id);
            }
            if ($kind === 'pending') {
                $query->whereIn('financial_stage', ['pending_financial_review', 'pending_legal_review', 'pending_approval']);
            } else {
                $query->where('status', '!=', 'cancelled');
            }
            abort_if($query->count() > 10000, 422, 'ضيّق نطاق التقرير إلى 10000 سجل أو أقل.');
            foreach ($query->orderBy('id')->get() as $settlement) {
                if ($kind === 'no_deduction' && ($settlement->advance_decision_json['choice'] ?? '') !== 'none') {
                    continue;
                }
                $amount = $settlement->advanceAllocations()->where('status', 'posted')->sum('allocated_amount');
                if ($kind === 'settlement_deductions' && ! $amount) {
                    continue;
                }
                $rows[] = [$settlement->reference_number, $settlement->employee?->employee_number, $settlement->employee?->full_name, $settlement->employee?->company, $settlement->financial_stage, $amount, $settlement->advance_decision_json['reason'] ?? ''];
            }
            $headers = ['المخالصة', 'كود الموظف', 'الاسم', 'الشركة', 'المرحلة المالية', 'المخصوم من السلف فعليًا', 'سبب القرار'];
        } else {
            $query = EmployeeAdvance::with('employee:id,employee_number,full_name,company,status')->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', $request->input('employee_id')))->when($request->filled('company'), fn ($q) => $q->where('company', $request->input('company')));
            if ($kind === 'terminated_debt') {
                $query->whereHas('employee', fn ($q) => $q->where('status', 'terminated')->orWhereHas('settlements')->orWhereHas('hrTransactions', fn ($t) => $t->where('transaction_type', 'final_settlement')->whereIn('financial_stage', ['posted', 'paid'])));
            }
            abort_if($query->count() > 10000, 422, 'ضيّق نطاق التقرير إلى 10000 سجل أو أقل.');
            if ($kind === 'statement') {
                $balance = '0';
                $entries = AdvanceTransaction::whereIn('advance_id', $query->pluck('id'))->orderBy('transaction_date')->orderBy('id')->get();
                abort_if($entries->count() > 10000, 422, 'كشف الحساب يتجاوز 10000 حركة.');
                foreach ($entries as $entry) {
                    $balance = SettlementMath::add($balance, SettlementMath::multiply($entry->amount, (string) $entry->direction));
                    $rows[] = [$entry->transaction_date, $entry->advance_id, $entry->reference_number, $entry->transaction_type, $entry->direction > 0 ? $entry->amount : '0', $entry->direction < 0 ? $entry->amount : '0', $balance, $entry->hr_transaction_id, $entry->notes];
                }
                $headers = ['التاريخ', 'السلفة', 'مرجع الحركة', 'النوع', 'زيادة المديونية', 'تخفيض المديونية', 'الرصيد', 'معرف المخالصة', 'ملاحظات'];
            } else {
                foreach ($query->orderBy('id')->get() as $advance) {
                    $data = $this->ledger->serialize($advance);
                    if ($kind === 'settled' ? $data['effective_status'] !== 'settled' : ! $advance->disbursed_at || (float) $data['balance'] <= 0) {
                        continue;
                    }
                    $rows[] = [$advance->reference_number, $advance->employee?->employee_number, $advance->employee?->full_name, $advance->company, $data['effective_status'], $advance->original_amount, $data['balance'], $data['reserved_balance'], $data['available_balance']];
                }
                $headers = ['مرجع السلفة', 'كود الموظف', 'الاسم', 'الشركة', 'الحالة', 'أصل السلفة', 'الرصيد القائم', 'المحجوز', 'المتاح للتخصيص'];
            }
        }
        if ($request->input('format') === 'xlsx') {
            $brand = app(\App\Services\SystemSettingsService::class)->reportBranding();
            $contents = $this->sheets->workbook($headers, array_merge([[$brand['name'], $brand['organization']]], $rows));
        } elseif ($request->input('format') === 'pdf') {
            $pdf = new Mpdf(['mode' => 'utf-8', 'format' => app(\App\Services\SystemSettingsService::class)->get('reports.paper'), 'orientation'=>'L', 'default_font' => 'dejavusans', 'autoScriptToLang' => true, 'autoLangToFont' => true, 'tempDir' => storage_path('framework/cache/mpdf')]);
            $pdf->SetDirectionality('rtl');
            $pdf->WriteHTML(view('exports.advance-report', compact('headers', 'rows', 'kind'))->render());
            $contents = $pdf->Output('', Destination::STRING_RETURN);
        } else {
            $size=app(\App\Services\SystemSettingsService::class)->pageSize();
            return response()->json(['headers' => $headers, 'rows' => array_slice($rows, ($request->integer('page', 1) - 1) * $size, $size), 'total' => count($rows), 'page' => $request->integer('page', 1), 'last_page' => max(1, (int) ceil(count($rows) / $size))])->header('Cache-Control', 'no-store');
        }
        $this->ledger->audit($request->user(), 'advance_report_exported', EmployeeAdvance::class, 0, ['kind' => $kind, 'format' => $request->input('format')]);

        return response($contents, 200, ['Content-Type' => $request->input('format') === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => 'attachment; filename="advances-'.$kind.'.'.$request->input('format').'"', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
