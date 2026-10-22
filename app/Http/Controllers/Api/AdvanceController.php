<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdvanceDocument;
use App\Models\AdvanceTransaction;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Services\Advances\AdvanceLedger;
use App\Services\Settlement\SettlementMath;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AdvanceController extends Controller
{
    public function __construct(private AdvanceLedger $ledger) {}

    private function access(Request $request, string $permission = 'view_advances'): void
    {
        abort_unless($request->user()->hasPermission($permission), 403);
    }

    public function index(Request $request)
    {
        $this->access($request);
        $request->validate(['employee_id' => 'nullable|integer|exists:employees,id', 'company' => 'nullable|string|max:255', 'status' => 'nullable|in:requested,approved,active,suspended,cancelled', 'search' => 'nullable|string|max:150', 'per_page' => 'nullable|integer|min:1|max:100']);
        $query = EmployeeAdvance::with('employee:id,employee_number,full_name,company')->whereNull('archived_at')->latest();
        foreach (['employee_id', 'company', 'status'] as $key) {
            if ($request->filled($key)) {
                $query->where($key, $request->input($key));
            }
        }
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('reference_number', 'like', '%'.$request->input('search').'%')->orWhereHas('employee', fn ($e) => $e->where('full_name', 'like', '%'.$request->input('search').'%')->orWhere('employee_number', 'like', '%'.$request->input('search').'%'));
            });
        }
        $result = $query->paginate(app(\App\Services\SystemSettingsService::class)->pageSize());
        $result->setCollection($result->getCollection()->map(fn ($advance) => $this->ledger->serialize($advance)));

        return response()->json($result)->header('Cache-Control', 'no-store');
    }

    public function store(Request $request)
    {
        $this->access($request, 'manage_advances');
        $company = Employee::find($request->input('employee_id'))?->company;
        $data = $request->validate(['employee_id' => 'required|integer|exists:employees,id', 'company' => ['nullable', Rule::in([$company])],
            'reference_number' => ['nullable', 'string', 'max:100', Rule::unique('employee_advances')->where('company', $company)],
            'original_amount' => 'required|numeric|decimal:0,2|min:0.01|max:9999999999.99', 'monthly_installment' => 'nullable|numeric|decimal:0,2|min:0.01|lte:original_amount',
            'planned_disbursement_date' => 'nullable|date_format:Y-m-d', 'payment_method' => 'nullable|in:bank,cash,other', 'notes' => 'nullable|string|max:2000',
            'repayment_plan' => 'nullable|array', 'repayment_plan.installments_count' => 'nullable|integer|min:1|max:600',
            'repayment_plan.schedule' => 'nullable|array|max:600', 'repayment_plan.schedule.*.date' => 'required|date_format:Y-m-d',
            'repayment_plan.schedule.*.amount' => 'required|numeric|decimal:0,2|min:0.01']);
        if (! empty($data['repayment_plan']['schedule'])) {
            $total = SettlementMath::add(...array_column($data['repayment_plan']['schedule'], 'amount'));
            $this->ledger->ensure(SettlementMath::compare($total, $data['original_amount']) === 0, 'إجمالي جدول السداد يجب أن يطابق مبلغ السلفة.');
            $this->ledger->ensure(empty($data['repayment_plan']['installments_count']) || count($data['repayment_plan']['schedule']) === $data['repayment_plan']['installments_count'], 'عدد الأقساط لا يطابق الجدول.');
        }

        return DB::transaction(function () use ($data, $company, $request) {
            $this->ledger->lockEmployee($data['employee_id']);
            if (empty($data['reference_number'])) $data['reference_number'] = app(\App\Services\SystemSettingsService::class)->get('documents.advance_prefix').'-'.now()->format('Y').'-'.strtoupper(bin2hex(random_bytes(6)));
            $advance = EmployeeAdvance::create(array_merge($data, ['company' => $company, 'created_by' => $request->user()->id, 'status' => 'requested']));
            $this->ledger->audit($request->user(), 'advance_requested', EmployeeAdvance::class, $advance->id, ['amount' => $advance->original_amount]);

            return response()->json($this->ledger->serialize($advance), 201);
        });
    }

    public function show(Request $request, EmployeeAdvance $advance)
    {
        $this->access($request);
        $data = $this->ledger->serialize($advance->load(['employee:id,employee_number,full_name,company', 'transactions', 'documents', 'allocations']));
        $data['audit_history'] = AuditLog::with('user:id,name')->where('entity_type', EmployeeAdvance::class)->where('entity_id', $advance->id)->orderBy('id')->get();

        return response()->json($data)->header('Cache-Control', 'no-store');
    }

    public function employeeSummary(Request $request, Employee $employee)
    {
        $this->access($request);

        return response()->json($this->ledger->summary($employee->id))->header('Cache-Control', 'no-store');
    }

    public function approve(Request $request, EmployeeAdvance $advance)
    {
        $data = $request->validate(['approval_reference' => 'required|string|max:150']);

        return response()->json($this->ledger->serialize($this->ledger->approve($advance, $request->user(), $data['approval_reference'])));
    }

    public function movement(Request $request, EmployeeAdvance $advance)
    {
        $data = $request->validate(['type' => 'required|in:disbursement,payroll_repayment,external_repayment', 'amount' => 'exclude_if:type,disbursement|required_unless:type,disbursement|numeric|decimal:0,2|min:0.01|max:9999999999.99',
            'transaction_date' => 'required|date_format:Y-m-d|before_or_equal:today', 'reference_number' => 'required|string|max:150', 'idempotency_key' => 'required|string|max:150', 'method' => 'required|in:bank,cash,payroll,other', 'notes' => 'nullable|string|max:2000']);

        return response()->json($this->ledger->movement($advance, $request->user(), $data['type'], $data), 201);
    }

    public function reverse(Request $request, EmployeeAdvance $advance, AdvanceTransaction $entry)
    {
        $data = $request->validate(['transaction_date' => 'required|date_format:Y-m-d|before_or_equal:today', 'reference_number' => 'required|string|max:150', 'notes' => 'required|string|min:3|max:2000']);

        return response()->json($this->ledger->reverse($advance, $entry, $request->user(), $data), 201);
    }

    public function flags(Request $request, EmployeeAdvance $advance)
    {
        $this->access($request, 'manage_advances');
        $data = $request->validate(['status' => 'nullable|in:suspended,active,cancelled', 'is_disputed' => 'sometimes|boolean', 'reason' => 'required|string|min:3|max:2000']);

        return DB::transaction(function () use ($advance, $request, $data) {
            $this->ledger->lockEmployee($advance->employee_id);
            $advance = EmployeeAdvance::lockForUpdate()->findOrFail($advance->id);
            if (isset($data['status'])) {
                if ($data['status'] === 'cancelled') {
                    $this->ledger->ensure(! $advance->disbursed_at, 'لا تلغِ سلفة مصروفة. علّقها أو استخدم العكس المالي.');
                } else {
                    $this->ledger->ensure($advance->disbursed_at && in_array($advance->status, ['active', 'suspended']), 'التعليق والاستئناف للسلف المصروفة فقط.');
                }
            }
            $advance->update(array_intersect_key($data, array_flip(['status', 'is_disputed'])));
            $this->ledger->audit($request->user(), 'advance_flags_changed', EmployeeAdvance::class, $advance->id, $data);

            return response()->json($this->ledger->serialize($advance));
        });
    }

    public function document(Request $request, EmployeeAdvance $advance)
    {
        $this->access($request, 'manage_advances');
        $request->validate(['file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240', 'category' => 'required|in:approval,disbursement,support']);
        $path = $request->file('file')->store('advances/'.$advance->id, 'local');
        try {
            $document = AdvanceDocument::create(['advance_id' => $advance->id, 'path' => $path, 'name' => basename($request->file('file')->getClientOriginalName()), 'mime' => $request->file('file')->getMimeType(), 'category' => $request->input('category'), 'created_by' => $request->user()->id]);
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }
        $this->ledger->audit($request->user(), 'advance_document_uploaded', EmployeeAdvance::class, $advance->id, ['document_id' => $document->id]);

        return response()->json($document, 201);
    }

    public function downloadDocument(Request $request, EmployeeAdvance $advance, AdvanceDocument $document)
    {
        $this->access($request);
        abort_unless($document->advance_id === $advance->id, 404);

        return Storage::disk('local')->download($document->path, $document->name, ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
