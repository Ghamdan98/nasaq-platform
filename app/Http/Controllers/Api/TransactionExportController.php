<?php

namespace App\Http\Controllers\Api;

use App\Exports\Transactions\HrTransactionExport;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\HrTransaction;
use App\Models\User;
use App\Services\HRTransactions\TransactionExportData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Facades\Excel;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class TransactionExportController extends Controller
{
    public function __construct(private readonly TransactionExportData $exportData)
    {
    }

    public function excel(Request $request, HrTransaction $transaction)
    {
        $this->authorizeExport($request->user(), $transaction);
        $data = $this->exportData->build($transaction);
        $contents = Excel::raw(new HrTransactionExport($data), \Maatwebsite\Excel\Excel::XLSX);
        $filename = $this->filename($transaction, 'xlsx');

        $this->audit($request, $transaction, 'transaction_exported_excel');

        return response($contents, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function pdf(Request $request, HrTransaction $transaction)
    {
        $this->authorizeExport($request->user(), $transaction);
        $data = $this->exportData->build($transaction);
        $html = view('exports.hr-transaction-pdf', compact('data'))->render();
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => app(\App\Services\SystemSettingsService::class)->get('reports.paper'),
            'orientation' => app(\App\Services\SystemSettingsService::class)->get('reports.orientation') === 'landscape' ? 'L' : 'P',
            'default_font' => 'dejavusans',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'useOTL' => 0xFF,
            'useKashida' => 75,
            'tempDir' => storage_path('framework/cache/mpdf'),
        ]);
        $mpdf->SetDirectionality('rtl');
        $mpdf->SetTitle($transaction->transaction_type === 'leave_settlement' ? 'تصفية إجازة' : 'مخالصة نهاية خدمة');
        $mpdf->WriteHTML($html);
        $contents = $mpdf->Output('', Destination::STRING_RETURN);
        $filename = $this->filename($transaction, 'pdf');

        $this->audit($request, $transaction, 'transaction_exported_pdf');

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function printData(Request $request, HrTransaction $transaction)
    {
        $this->authorizeExport($request->user(), $transaction);

        $data=$this->exportData->build($transaction);
        unset($data['branding']['logo_path']);
        return response()->json($data);
    }

    public function printed(Request $request, HrTransaction $transaction)
    {
        $this->authorizeExport($request->user(), $transaction);
        $this->audit($request, $transaction, 'transaction_printed');

        return response()->json(['message' => 'Print action recorded.']);
    }

    private function authorizeExport(User $user, HrTransaction $transaction): void
    {
        Gate::forUser($user)->authorize('export_transactions');
        if ($user->role === 'hr' && (int) $transaction->created_by !== (int) $user->id) {
            abort(403);
        }
    }

    private function filename(HrTransaction $transaction, string $extension): string
    {
        $reference = preg_replace('/[^A-Za-z0-9-]/', '-', $transaction->reference_number ?: 'transaction-'.$transaction->id);
        $type = $transaction->transaction_type === 'leave_settlement' ? 'leave-settlement' : 'final-settlement';

        return $reference.'-'.$type.'.'.$extension;
    }

    private function audit(Request $request, HrTransaction $transaction, string $action): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'entity_type' => HrTransaction::class,
            'entity_id' => $transaction->id,
            'old_values_json' => null,
            'new_values_json' => ['version' => $transaction->version_number],
            'ip_address' => $request->ip(),
        ]);
    }
}
