<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeImport;
use App\Services\Employees\EmployeeData;
use App\Services\Employees\EmployeeSpreadsheet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class EmployeeImportController extends Controller
{
    public function __construct(private EmployeeSpreadsheet $sheets) {}

    private function authorizeImport(Request $request, ?EmployeeImport $import = null): void
    {
        abort_unless(EmployeeData::canManage($request->user()), 403);
        if ($import) {
            abort_unless($request->user()->isAdmin() || $import->user_id === $request->user()->id, 403);
        }
    }

    public function index(Request $request)
    {
        $this->authorizeImport($request);

        return EmployeeImport::with('user:id,name')->when(! $request->user()->isAdmin(), fn ($q) => $q->where('user_id', $request->user()->id))->latest()->paginate(app(\App\Services\SystemSettingsService::class)->pageSize());
    }

    public function template(Request $request)
    {
        $this->authorizeImport($request);

        return $this->download($this->sheets->workbook(array_values(EmployeeData::HEADERS), []), 'employees-template.xlsx');
    }

    public function preview(Request $request)
    {
        $this->authorizeImport($request);
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx', 'max:10240'], 'mode' => ['required', 'in:new_only,upsert,update_only']]);
        try {
            $import = $this->sheets->preview($request->file('file'), $request->input('mode'), $request->user()->id);
        } catch (\PhpOffice\PhpSpreadsheet\Exception|\PhpOffice\PhpSpreadsheet\Reader\Exception $exception) {
            throw ValidationException::withMessages(['file' => 'تعذر قراءة ملف Excel. أعد حفظه بصيغة XLSX سليمة واستخدم القالب المعتمد.']);
        }

        return response()->json($this->summary($import, 1), 201)->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, EmployeeImport $employeeImport)
    {
        $this->authorizeImport($request, $employeeImport);
        $request->validate(['page' => 'nullable|integer|min:1']);

        return response()->json($this->summary($employeeImport, $request->integer('page', 1)))->header('Cache-Control', 'no-store');
    }

    private function summary(EmployeeImport $import, int $page): array
    {
        $rows = $import->expires_at->isPast() ? [] : ($import->payload ?? []);

        return array_merge($import->toArray(), ['rows' => array_map(fn ($row) => array_intersect_key($row, array_flip(['row', 'data', 'errors', 'action'])), array_slice($rows, ($page - 1) * 50, 50)), 'page' => $page, 'pages' => max(1, (int) ceil(count($rows) / 50))]);
    }

    public function commit(Request $request, EmployeeImport $employeeImport)
    {
        $this->authorizeImport($request, $employeeImport);
        DB::transaction(function () use ($employeeImport, $request) {
            $import = EmployeeImport::lockForUpdate()->findOrFail($employeeImport->id);
            abort_unless($import->status === 'preview' && ! $import->expires_at->isPast(), 409, 'انتهت المعاينة أو سبق تنفيذها أو تحتوي على أخطاء. أعد رفع الملف.');
            foreach (array_chunk($import->payload, 200) as $chunk) {
                foreach ($chunk as $row) {
                    if ($row['errors']) {
                        throw ValidationException::withMessages(['file' => 'لن يتم حفظ أي صف عند وجود أخطاء.']);
                    }
                    if ($row['action'] === 'skip') {
                        continue;
                    }
                    $employee = Employee::where('company', $row['data']['company'])->where('employee_number', $row['data']['employee_number'])->lockForUpdate()->first();
                    $fingerprint = $employee ? hash('sha256', json_encode($employee->getAttributes())) : null;
                    abort_unless($fingerprint === $row['fingerprint'], 409, 'تغيرت بيانات الموظفين بعد المعاينة. أعد رفع الملف.');
                    $data = Validator::make($row['data'], EmployeeData::rules($row['data'], $employee))->validate();
                    if ($employee) {
                        $employee->update($data);
                    } else {
                        Employee::create($data);
                    }
                }
            }
            $import->update(['status' => 'completed', 'payload' => null]);
            AuditLog::create(['user_id' => $request->user()->id, 'action' => 'employees_imported', 'entity_type' => EmployeeImport::class, 'entity_id' => $import->id, 'ip_address' => $request->ip()]);
        });

        return response()->json($employeeImport->fresh());
    }

    public function errors(Request $request, EmployeeImport $employeeImport)
    {
        $this->authorizeImport($request, $employeeImport);
        abort_if($employeeImport->expires_at->isPast(), 410, 'انتهت مدة الاحتفاظ بتقرير الأخطاء.');
        $rows = [];
        foreach ($employeeImport->payload ?? [] as $row) {
            if ($row['errors']) {
                $rows[] = [$row['row'], $row['data']['company'] ?? '', $row['data']['employee_number'] ?? '', implode(' | ', $row['errors'])];
            }
        }

        return $this->download($this->sheets->workbook(['رقم الصف', 'الشركة', 'كود الموظف', 'الأخطاء'], $rows), 'employee-import-errors.xlsx');
    }

    private function download(string $contents, string $filename)
    {
        return response($contents, 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => 'attachment; filename="'.$filename.'"', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
