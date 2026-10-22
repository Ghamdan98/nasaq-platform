<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmployeeAdvance;
use App\Models\HrTransaction;
use App\Services\Advances\AdvanceLedger;
use App\Services\Settlement\SettlementMath as Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DataManagementController extends Controller
{
    public function __construct(private AdvanceLedger $ledger) {}

    private function access(Request $request): void
    {
        abort_unless($request->user()->isAdmin(), 403);
    }

    public function index(Request $request)
    {
        $this->access($request);
        $data = $request->validate(['kind' => 'required|in:advances,settlements', 'search' => 'nullable|string|max:100', 'archived' => 'nullable|in:all,yes,no']);
        $model = $data['kind'] === 'advances' ? EmployeeAdvance::query() : HrTransaction::query();
        if (($data['archived'] ?? 'all') === 'yes') {
            $model->whereNotNull('archived_at');
        }
        if (($data['archived'] ?? 'all') === 'no') {
            $model->whereNull('archived_at');
        }

        $result = $model->with('employee:id,full_name,employee_number')->when($data['search'] ?? null, function ($q, $term) {
            $q->where(function ($q) use ($term) {
                $q->where('reference_number', 'like', '%'.$term.'%')->orWhereHas('employee', fn ($e) => $e->where('full_name', 'like', '%'.$term.'%')->orWhere('employee_number', 'like', '%'.$term.'%'));
            });
        })->latest('id')->paginate(app(\App\Services\SystemSettingsService::class)->pageSize());

        return response()->json($result)->header('Cache-Control', 'no-store');
    }

    private function record(string $kind, int $id)
    {
        abort_unless(in_array($kind, ['advances', 'settlements'], true), 404);

        return $kind === 'advances' ? EmployeeAdvance::findOrFail($id) : HrTransaction::findOrFail($id);
    }

    private function inspect(string $kind, $record): array
    {
        $reasons = [];
        if ($kind === 'advances') {
            $record->load('transactions', 'allocations', 'documents');
            if ($record->allocations->isNotEmpty()) {
                $reasons[] = 'السلفة مرتبطة بتصفيات؛ احذف التصفيات الملغاة المرتبطة أولًا.';
            }
            if ($record->transactions->contains(fn ($e) => $e->hr_transaction_id !== null)) {
                $reasons[] = 'توجد حركات مرتبطة بتصفية؛ احذف التصفية الملغاة أولًا.';
            }
            if ($record->disbursed_at && ($record->status !== 'cancelled' || Money::compare($this->ledger->balance($record), '0') !== 0)) {
                $reasons[] = 'يجب عكس الصرف والسداد وإلغاء السلفة قبل حذفها.';
            }
            if (! $record->disbursed_at && $record->transactions->isNotEmpty()) {
                $reasons[] = 'توجد حركات مالية؛ يلزم تصحيح حالتها قبل الحذف.';
            }
        } else {
            $record->load('items', 'deductions', 'adjustments', 'statusHistory', 'versions', 'finalSettlementDetail', 'leaveSettlementDetail', 'advanceAllocations', 'payments');
            $entries = DB::table('advance_transactions')->where('hr_transaction_id', $record->id)->get();
            if ($record->status !== 'cancelled' && (! in_array($record->status, ['draft', 'calculated', 'changes_requested'], true) || $record->approved_at || $record->posted_at || $entries->isNotEmpty() || $record->payments->isNotEmpty())) {
                $reasons[] = 'ألغِ التصفية من مسار الإلغاء المالي قبل حذفها.';
            }
            if ($record->advanceAllocations->contains(fn ($a) => $a->status === 'posted')) {
                $reasons[] = 'توجد خصومات مرحلة لم تُعكس.';
            }
            foreach ($entries->groupBy('advance_id') as $group) {
                $balance = '0';
                foreach ($group as $entry) {
                    $balance = Money::add($balance, Money::multiply((string) $entry->amount, (string) $entry->direction));
                }
                if (Money::compare($balance, '0') !== 0) {
                    $reasons[] = 'حركات السلف المرتبطة لم تُعكس بالكامل.';
                }
            }
            if ($record->payments->contains(fn ($p) => ! $p->reversal_of_id && ! $record->payments->contains(fn ($r) => $r->reversal_of_id === $p->id && Money::compare($r->amount, $p->amount) === 0))) {
                $reasons[] = 'يوجد صرف لم يسجل مستند عكسه.';
            }
        }

        return ['record' => $record, 'can_delete' => ! $reasons, 'blockers' => $reasons,
            'retained' => 'يبقى سجل التدقيق والنسخة الاحتياطية الخاصة. حركات السلف المرتبطة بتصفية محذوفة تبقى متوازنة في دفتر السلف، والمرفقات تبقى في التخزين الخاص للرجوع إليها.'];
    }

    public function preview(Request $request, string $kind, int $id)
    {
        $this->access($request);

        return response()->json($this->inspect($kind, $this->record($kind, $id)))->header('Cache-Control', 'no-store');
    }

    public function updateAdvance(Request $request, int $id)
    {
        $this->access($request);
        $data = $request->validate(['original_amount' => 'required|numeric|decimal:0,2|min:0.01|max:9999999999.99', 'notes' => 'nullable|string|max:2000', 'reason' => 'required|string|min:10|max:2000']);
        $advance = EmployeeAdvance::findOrFail($id);

        return DB::transaction(function () use ($request, $advance, $data) {
            $this->ledger->lockEmployee($advance->employee_id);
            $advance = EmployeeAdvance::lockForUpdate()->findOrFail($advance->id);
            $this->ledger->ensure(! $advance->disbursed_at && ! $advance->transactions()->exists() && ! $advance->allocations()->exists() && in_array($advance->status, ['requested', 'approved'], true), 'يمكن تعديل الطلب غير المصروف فقط.');
            $old = $advance->attributesToArray();
            $advance->update(['original_amount' => $data['original_amount'], 'notes' => $data['notes'] ?? null, 'repayment_plan' => null, 'monthly_installment' => null, 'status' => 'requested', 'approved_at' => null, 'approved_by' => null, 'approval_reference' => null]);
            $this->ledger->audit($request->user(), 'advance_request_corrected', EmployeeAdvance::class, $advance->id, ['reason' => $data['reason'], 'before' => $old, 'after' => $advance->attributesToArray()]);

            return response()->json($advance);
        });
    }

    public function archive(Request $request, string $kind, int $id)
    {
        $this->access($request);
        $data = $request->validate(['archived' => 'required|boolean', 'reason' => 'required|string|min:10|max:2000']);
        $record = $this->record($kind, $id);

        return DB::transaction(function () use ($request, $record, $data) {
            $this->ledger->lockEmployee($record->employee_id);
            $record = $record->newQuery()->lockForUpdate()->findOrFail($record->id);
            $this->ledger->ensure(! $data['archived'] || $record->status === 'cancelled', 'الأرشفة للسجلات الملغاة فقط؛ ألغِ الأثر المالي أولًا.');
            $record->forceFill(['archived_at' => $data['archived'] ? now() : null])->save();
            $this->ledger->audit($request->user(), $data['archived'] ? 'record_archived' : 'record_unarchived', get_class($record), $record->id, ['reason' => $data['reason']]);

            return response()->json(['message' => 'تم تحديث الأرشفة. استعادة العرض لا تلغي الإلغاء المالي.']);
        });
    }

    public function destroy(Request $request, string $kind, int $id)
    {
        $this->access($request);
        $data = $request->validate(['reason' => 'required|string|min:10|max:2000', 'confirmation' => 'required|string', 'incorrect_data' => 'required|accepted']);
        $record = $this->record($kind, $id);

        return DB::transaction(function () use ($request, $kind, $record, $data) {
            $this->ledger->lockEmployee($record->employee_id);
            $record = $record->newQuery()->lockForUpdate()->findOrFail($record->id);
            $inspection = $this->inspect($kind, $record);
            $this->ledger->ensure($inspection['can_delete'], implode(' ', $inspection['blockers']));
            $this->ledger->ensure($data['confirmation'] === 'DELETE-'.$record->id, 'اكتب رمز التأكيد كما هو.');
            $snapshot = $record->attributesToArray();
            foreach ($record->getRelations() as $name => $value) {
                $snapshot[$name] = $value?->toArray();
            }
            if ($kind === 'advances') {
                $snapshot['documents'] = $record->documents->map(fn ($document) => $document->getAttributes())->all();
            }
            if ($kind === 'settlements') {
                $snapshot['ledger_entries'] = DB::table('advance_transactions')->where('hr_transaction_id', $record->id)->get()->toArray();
            }
            $backup = 'data-management/'.now()->format('Ymd-His').'-'.$kind.'-'.$record->id.'-'.bin2hex(random_bytes(8)).'.encrypted';
            abort_unless(Storage::disk('local')->put($backup, Crypt::encryptString(json_encode($snapshot, JSON_THROW_ON_ERROR))), 500, 'تعذر حفظ النسخة الاحتياطية؛ لم يتم الحذف.');
            $this->ledger->audit($request->user(), 'data_permanently_deleted', get_class($record), $record->id, ['reason' => $data['reason'], 'reference' => $record->reference_number, 'employee_id' => $record->employee_id, 'backup' => $backup, 'incorrect_data' => true]);
            if ($kind === 'advances') {
                // Explicitly scoped maintenance, after zero-balance and dependency checks.
                DB::table('advance_transactions')->where('advance_id', $record->id)->whereNotNull('reversal_of_id')->delete();
                DB::table('advance_transactions')->where('advance_id', $record->id)->delete();
                $record->documents()->delete();
            } else {
                // Keep the original and reversal in the employee ledger; only detach the deleted document.
                DB::table('advance_transactions')->where('hr_transaction_id', $record->id)->update(['hr_transaction_id' => null]);
                DB::table('settlement_payments')->where('hr_transaction_id', $record->id)->whereNotNull('reversal_of_id')->delete();
                DB::table('settlement_payments')->where('hr_transaction_id', $record->id)->delete();
                $record->advanceAllocations()->delete();
            }
            $record->delete();

            return response()->json(['message' => 'تم الحذف مع حفظ نسخة احتياطية وسجل تدقيق.']);
        });
    }
}
