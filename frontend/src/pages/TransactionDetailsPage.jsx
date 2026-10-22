import { useEffect, useState } from 'react'
import {
  adjustTransactionItem,
  approveTransaction,
  cancelTransaction,
  downloadTransactionExcel,
  downloadTransactionPdf,
  getHrTransaction,
  rejectTransaction,
  requestTransactionChanges,
  reopenApprovedTransaction,
  revertTransactionAdjustment,
  submitTransactionForReview,
} from '../services/api'
import { formatCurrency, formatNumber } from '../utils/currency'
import { transactionLabels, workflowActionLabels, workflowStatusLabels } from '../utils/labels'
import TransactionFinancePanel from '../components/settlement/TransactionFinancePanel'

const inputClass = 'w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100'

function AdjustmentDialog({ item, onClose, onSave, onRevert, saving }) {
  const [amount, setAmount] = useState(item.approved_amount)
  const [reason, setReason] = useState('')

  if (!item) return null
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4" role="presentation">
      <section role="dialog" aria-modal="true" aria-labelledby="adjust-title" className="w-full max-w-lg space-y-4 rounded-3xl bg-white p-6 text-right shadow-2xl">
        <h2 id="adjust-title" className="text-xl font-black">تعديل القيمة المعتمدة</h2>
        <div className="rounded-xl bg-slate-50 p-3 text-sm"><span className="font-bold">البند: </span>{item.name_ar || item.name || item.code}</div>
        <div className="grid grid-cols-2 gap-3 text-sm">
          <div className="rounded-xl bg-slate-50 p-3"><span className="block text-slate-500">القيمة المحسوبة</span>{formatCurrency(item.calculated_amount)}</div>
          <div className="rounded-xl bg-slate-50 p-3"><span className="block text-slate-500">القيمة المعتمدة الحالية</span>{formatCurrency(item.approved_amount)}</div>
        </div>
        <label className="block text-sm font-semibold">القيمة الجديدة<input autoFocus type="number" min="0" step="0.01" className={`${inputClass} mt-1`} value={amount} onChange={(event) => setAmount(event.target.value)} /></label>
        <label className="block text-sm font-semibold">سبب التعديل<textarea required minLength="3" className={`${inputClass} mt-1`} rows="3" value={reason} onChange={(event) => setReason(event.target.value)} /></label>
        <div className="flex flex-wrap justify-end gap-2">
          <button type="button" disabled={saving} onClick={() => onRevert(item)} className="rounded-xl border border-amber-300 px-3 py-2 text-sm font-bold text-amber-800">إعادة للقيمة المحسوبة</button>
          <button type="button" onClick={onClose} className="rounded-xl border border-slate-200 px-4 py-2 text-sm font-bold">إلغاء</button>
          <button type="button" disabled={saving || reason.trim().length < 3 || amount === ''} onClick={() => onSave(item, amount, reason)} className="rounded-xl bg-blue-600 px-4 py-2 text-sm font-bold text-white disabled:opacity-50">
            {saving ? 'جاري الحفظ...' : 'حفظ التعديل'}
          </button>
        </div>
      </section>
    </div>
  )
}

function WorkflowReasonDialog({ action, onClose, onSubmit, saving }) {
  const [reason, setReason] = useState('')
  if (!action) return null

  const labels = {
    reject: ['رفض المعاملة', 'سبب الرفض'],
    requestChanges: ['طلب تعديل', 'سبب طلب التعديل'],
    cancel: ['إلغاء المعاملة', 'سبب الإلغاء'],
    reopen: ['إعادة فتح المعاملة', 'سبب إعادة الفتح'],
  }
  const [title, label] = labels[action]

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4">
      <section role="dialog" aria-modal="true" className="w-full max-w-lg space-y-4 rounded-3xl bg-white p-6 text-right shadow-2xl">
        <h2 className="text-xl font-black">{title}</h2>
        <label className="block text-sm font-semibold">{label}
          <textarea autoFocus required minLength="3" className={`${inputClass} mt-1`} rows="4" value={reason} onChange={(event) => setReason(event.target.value)} />
        </label>
        <div className="flex justify-end gap-2">
          <button type="button" onClick={onClose} className="rounded-xl border border-slate-200 px-4 py-2 text-sm font-bold">إلغاء</button>
          <button type="button" disabled={saving || reason.trim().length < 3} onClick={() => onSubmit(action, reason)} className="rounded-xl bg-blue-600 px-4 py-2 text-sm font-bold text-white disabled:opacity-50">
            {saving ? 'جاري التنفيذ...' : title}
          </button>
        </div>
      </section>
    </div>
  )
}

function FinancialTable({ title, rows, itemType, canAdjust, onAdjust }) {
  return (
    <section className="space-y-3 rounded-2xl border border-slate-200 p-4">
      <h2 className="text-xl font-black">{title}</h2>
      <div className="overflow-x-auto">
        <table className="w-full min-w-[760px] text-right text-sm">
          <thead className="bg-slate-50 text-slate-600"><tr>
            {['البند', 'القيمة المحسوبة', 'القيمة المعتمدة', 'الفرق', ''].map((label) => <th key={label} className="px-3 py-2">{label}</th>)}
          </tr></thead>
          <tbody className="divide-y divide-slate-100">
            {rows.map((item) => (
              <tr key={item.id}>
                <td className="px-3 py-3 font-semibold">
                  {item.name_ar || item.name || item.code}
                  {item.calculation_details_json?.reason && <div className="mt-1 text-xs font-normal text-slate-500">سبب البند: {item.calculation_details_json.reason}</div>}
                  {item.was_manually_adjusted && <span className="mr-2 rounded-full bg-amber-100 px-2 py-1 text-[11px] font-bold text-amber-800">معدل يدوياً</span>}
                  {item.was_manually_adjusted && <div className="mt-1 text-xs font-normal text-slate-500">سبب التعديل: {item.adjustment_reason}</div>}
                </td>
                <td className="px-3 py-3">{formatCurrency(item.calculated_amount)}</td>
                <td className="px-3 py-3 font-bold">{formatCurrency(item.approved_amount)}</td>
                <td className="px-3 py-3">{formatCurrency(item.difference_amount)}</td>
                <td className="px-3 py-3">{canAdjust && item.code !== 'employee_advances' && <button type="button" onClick={() => onAdjust({ ...item, item_type: itemType })} className="rounded-lg bg-blue-50 px-3 py-1.5 font-bold text-blue-700">تعديل</button>}</td>
              </tr>
            ))}
            {rows.length === 0 && <tr><td colSpan="5" className="px-3 py-5 text-center text-slate-500">لا توجد بنود.</td></tr>}
          </tbody>
        </table>
      </div>
    </section>
  )
}

function LeaveAccrualSummary({ transaction }) {
  const calculation = transaction.calculation_snapshot_json || {}
  const balance = calculation.accrual_details || calculation.leave_calculation?.accrual_details
  const period = balance?.accrual_details
  if (!period) return null

  const yearBasis = period.year_basis || (period.year_days ? `${period.month_days || 30}/${period.year_days}` : '—')

  return (
    <section className="space-y-2 rounded-2xl border border-blue-200 bg-blue-50/70 p-4 text-sm">
      <h2 className="font-black">احتساب رصيد الإجازة</h2>
      <p>أساس الحساب: {yearBasis} {period.day_count_convention ? `(${period.day_count_convention})` : ''}</p>
      <p>الفترة: {period.period_from} إلى {period.period_to} — أيام الاستحقاق المحاسبية: {formatNumber(period.service_days)}</p>
      <p>الرصيد المكتسب: {formatNumber(balance.accrued_days)} يوم — الرصيد النهائي: {formatNumber(balance.available_days)} يوم</p>
      {(period.segments || []).map((segment, index) => (
        <p key={index}>فترة {formatNumber(segment.annual_entitlement)} يوم سنوياً، {segment.from} إلى {segment.to}: {formatNumber(segment.service_days)} ÷ {segment.year_days} × {formatNumber(segment.annual_entitlement)} = {formatNumber(segment.accrued_days)} يوم</p>
      ))}
    </section>
  )
}

export default function TransactionDetailsPage({ transactionId, permissions, onBack, onPrint, user }) {
  const [details, setDetails] = useState(null)
  const [selectedItem, setSelectedItem] = useState(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [workflowDialog, setWorkflowDialog] = useState('')
  const [exporting, setExporting] = useState('')

  const refresh = async () => {
    setLoading(true)
    setError('')
    try {
      setDetails(await getHrTransaction(transactionId))
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { refresh() }, [transactionId])

  const updateItem = async (item, approvedAmount, reason) => {
    setSaving(true)
    setError('')
    try {
      await adjustTransactionItem(transactionId, item.id, { approved_amount: approvedAmount, reason, item_type: item.item_type })
      setSelectedItem(null)
      await refresh()
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setSaving(false)
    }
  }

  const revertItem = async (item) => {
    setSaving(true)
    setError('')
    try {
      await revertTransactionAdjustment(transactionId, item.id, item.item_type)
      setSelectedItem(null)
      await refresh()
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setSaving(false)
    }
  }

  const runWorkflowAction = async (action, reason = null) => {
    setSaving(true)
    setError('')
    setNotice('')
    try {
      if (action === 'submit') {
        if (!window.confirm('هل تريد إرسال المعاملة للمراجعة؟\nلن تكون بعض التعديلات متاحة أثناء المراجعة.')) return
        await submitTransactionForReview(transactionId)
      } else if (action === 'approve') {
        await approveTransaction(transactionId)
      } else if (action === 'reject') {
        await rejectTransaction(transactionId, reason)
      } else if (action === 'requestChanges') {
        await requestTransactionChanges(transactionId, reason)
      } else if (action === 'cancel') {
        await cancelTransaction(transactionId, reason)
      } else if (action === 'reopen') {
        await reopenApprovedTransaction(transactionId, reason)
      }
      setWorkflowDialog('')
      setNotice('تم تنفيذ الإجراء بنجاح.')
      await refresh()
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setSaving(false)
    }
  }

  const downloadExport = async (kind) => {
    setExporting(kind)
    setError('')
    setNotice('')
    try {
      const downloadFile = kind === 'excel'
        ? await downloadTransactionExcel(transactionId)
        : await downloadTransactionPdf(transactionId)
      const url = URL.createObjectURL(downloadFile.blob)
      const link = document.createElement('a')
      link.href = url
      link.download = downloadFile.filename
      document.body.appendChild(link)
      link.click()
      link.remove()
      URL.revokeObjectURL(url)
      setNotice('تم تجهيز الملف للتنزيل.')
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setExporting('')
    }
  }

  if (loading && !details) return <main className="rounded-3xl bg-white p-8 text-center">جاري تحميل المعاملة...</main>
  if (!details) return <main className="space-y-4 rounded-3xl bg-white p-8 text-right"><p role="alert" className="text-red-700">{error || 'تعذر تحميل المعاملة.'}</p><button type="button" onClick={onBack} className="rounded-xl border px-4 py-2">العودة إلى سجل المعاملات</button></main>

  const transaction = details.transaction
  const employee = details.employee_snapshot || {}
  const contract = details.contract_snapshot || {}
  const adjustments = details.manual_adjustments || []
  const status = transaction.status
  const latestAction = details.audit_timeline?.[details.audit_timeline.length - 1]
  const canAdjust = permissions.adjust && ['draft', 'calculated', 'changes_requested'].includes(status)
  const canSubmit = permissions.submit && ['calculated', 'changes_requested'].includes(status) && transaction.review_status !== 'blocked'
  const canReview = status === 'pending_review'
  const financeRequired = transaction.input_snapshot_json?.financial_process_required || transaction.advance_decision_json?.choice || transaction.calculation_snapshot_json?.advance_summary?.decision_required
  const canApprove = permissions.approve && canReview && transaction.review_status !== 'blocked' && (!financeRequired || transaction.financial_stage === 'pending_approval')
    && (transaction.review_status !== 'requires_legal_review' || permissions.approveLegal)

  return (
    <main className="space-y-5 rounded-[30px] border border-slate-200 bg-white p-5 text-right shadow-sm md:p-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div><p className="text-xs font-bold text-blue-600">تفاصيل المعاملة المحفوظة</p><h1 className="mt-1 text-3xl font-black">{transaction.reference_number}</h1></div>
        <div className="no-print flex flex-wrap gap-2">
          {permissions.export && <>
            <button type="button" disabled={Boolean(exporting)} onClick={() => downloadExport('excel')} className="rounded-xl border border-emerald-300 px-4 py-2 font-bold text-emerald-800 disabled:opacity-50">{exporting === 'excel' ? 'جاري تجهيز Excel...' : 'Excel'}</button>
            <button type="button" disabled={Boolean(exporting)} onClick={() => downloadExport('pdf')} className="rounded-xl border border-rose-300 px-4 py-2 font-bold text-rose-800 disabled:opacity-50">{exporting === 'pdf' ? 'جاري تجهيز PDF...' : 'PDF'}</button>
            <button type="button" onClick={onPrint} className="rounded-xl border border-slate-300 px-4 py-2 font-bold">طباعة</button>
          </>}
          <button type="button" onClick={onBack} className="rounded-xl border border-slate-200 px-4 py-2 font-bold">سجل المعاملات</button>
        </div>
      </div>
      {error && <p role="alert" className="rounded-xl bg-red-50 p-3 text-sm text-red-700">{error}</p>}
      {notice && <p role="status" className="rounded-xl bg-emerald-50 p-3 text-sm text-emerald-700">{notice}</p>}
      <section className="space-y-4 rounded-2xl border border-blue-200 bg-blue-50/70 p-4">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 className="text-lg font-black">حالة المعاملة</h2>
            <p className="mt-1 text-sm">الحالة الحالية: <strong>{workflowStatusLabels[status] || status}</strong></p>
            <p className="text-sm">حالة المراجعة النظامية: <strong>{transaction.review_status}</strong></p>
            <p className="text-sm">الإصدار الحالي: <strong>{transaction.version_number || 1}</strong></p>
            <p className="text-sm">آخر إجراء: <strong>{latestAction ? workflowActionLabels[latestAction.action] || latestAction.action : '—'}</strong></p>
            {transaction.rejection_reason && <p className="mt-1 text-sm text-red-700">سبب الرفض: {transaction.rejection_reason}</p>}
            {transaction.requested_changes_reason && <p className="mt-1 text-sm text-amber-800">سبب طلب التعديل: {transaction.requested_changes_reason}</p>}
          </div>
          <div className="flex flex-wrap gap-2">
            {canSubmit && <button type="button" disabled={saving} onClick={() => runWorkflowAction('submit')} className="rounded-xl bg-blue-600 px-3 py-2 text-sm font-bold text-white disabled:opacity-50">إرسال للمراجعة</button>}
            {canReview && canApprove && <button type="button" disabled={saving} onClick={() => runWorkflowAction('approve')} className="rounded-xl bg-emerald-600 px-3 py-2 text-sm font-bold text-white disabled:opacity-50">اعتماد</button>}
            {canReview && permissions.reject && <button type="button" onClick={() => setWorkflowDialog('reject')} className="rounded-xl bg-red-600 px-3 py-2 text-sm font-bold text-white">رفض</button>}
            {canReview && permissions.requestChanges && <button type="button" onClick={() => setWorkflowDialog('requestChanges')} className="rounded-xl border border-amber-300 bg-white px-3 py-2 text-sm font-bold text-amber-800">طلب تعديل</button>}
            {permissions.cancel && ['draft', 'calculated', 'changes_requested'].includes(status) && <button type="button" onClick={() => setWorkflowDialog('cancel')} className="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-bold">إلغاء المعاملة</button>}
            {permissions.reopen && status === 'approved' && <button type="button" onClick={() => setWorkflowDialog('reopen')} className="rounded-xl border border-blue-300 bg-white px-3 py-2 text-sm font-bold text-blue-800">إعادة فتح</button>}
          </div>
        </div>
        {transaction.review_status === 'requires_legal_review' && (
          <p className="rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm font-bold text-amber-900">
            تحتاج هذه المعاملة إلى صلاحية اعتماد نظامية خاصة.
            {!permissions.approveLegal && <span className="mr-1 font-normal">لا تملك الصلاحية اللازمة للاعتماد.</span>}
          </p>
        )}
        {transaction.review_status === 'blocked' && <p className="rounded-xl border border-red-300 bg-red-50 p-3 text-sm font-bold text-red-800">لا يمكن اعتماد هذه المعاملة حالياً. {details.warnings?.map((warning) => warning.message_ar || warning.message || warning.description).filter(Boolean).join(' ')}</p>}
        {status === 'pending_review' && (
          <div className="rounded-xl bg-white p-3">
            <h3 className="font-bold">لوحة المراجعة</h3>
            <p className="mt-1 text-sm text-slate-600">راجع الملخص المالي والقيم المعدلة والتحذيرات والمراجع النظامية أدناه قبل اتخاذ القرار.</p>
          </div>
        )}
      </section>
      <section className="grid gap-3 rounded-2xl bg-slate-50 p-4 md:grid-cols-2 xl:grid-cols-4">
        <div><span className="block text-xs text-slate-500">الموظف</span>{employee.full_name || transaction.employee?.full_name || '—'} ({employee.employee_number || '—'})</div>
        <div><span className="block text-xs text-slate-500">بيانات العقد</span>{contract.contract_type || transaction.contract?.contract_type || '—'} — الراتب الأساسي: {contract.basic_salary ?? '—'} — البداية: {contract.start_date || '—'}</div>
        <div><span className="block text-xs text-slate-500">نوع المعاملة</span>{transactionLabels[transaction.transaction_type] || transaction.transaction_type}</div>
        <div><span className="block text-xs text-slate-500">التاريخ والحالة</span>{transaction.transaction_date} — {transaction.status}</div>
      </section>

      <LeaveAccrualSummary transaction={transaction} />
      <FinancialTable title="المستحقات" rows={details.financial_items || []} itemType="entitlement" canAdjust={canAdjust} onAdjust={setSelectedItem} />
      <TransactionFinancePanel transaction={transaction} user={user} onChanged={refresh} />
      <FinancialTable title="الخصومات" rows={details.deductions || []} itemType="deduction" canAdjust={canAdjust} onAdjust={setSelectedItem} />

      <section className="grid gap-3 rounded-2xl bg-blue-50 p-4 text-sm md:grid-cols-2">
        <div>
          <h2 className="mb-2 font-black">الإجماليات المحسوبة</h2>
          <div>المستحقات <strong>{formatCurrency(details.calculated_totals?.gross_amount)}</strong></div>
          <div>الخصومات <strong>{formatCurrency(details.calculated_totals?.total_deductions)}</strong></div>
          <div>الصافي <strong>{formatCurrency(details.calculated_totals?.net_amount)}</strong></div>
        </div>
        <div>
          <h2 className="mb-2 font-black">الإجماليات المعتمدة</h2>
          <div>المستحقات <strong>{formatCurrency(transaction.gross_amount)}</strong></div>
          <div>الخصومات <strong>{formatCurrency(transaction.total_deductions)}</strong></div>
          <div>الصافي <strong className="text-lg">{formatCurrency(transaction.net_amount)}</strong></div>
        </div>
      </section>

      <section className="grid gap-4 md:grid-cols-2">
        <div className="rounded-2xl border border-slate-200 p-4">
          <h2 className="font-black">المراجعة النظامية</h2>
          <p className="mt-2">{transaction.review_status}</p>
          <ul className="mt-2 list-inside list-disc text-sm text-slate-600">{(details.warnings || []).map((warning, index) => <li key={index}>{warning.message_ar || warning.message || warning.description || JSON.stringify(warning)}</li>)}</ul>
        </div>
        <div className="rounded-2xl border border-slate-200 p-4">
          <h2 className="font-black">المراجع النظامية</h2>
          <ul className="mt-2 space-y-1 text-sm text-slate-600">{(details.legal_references || []).map((reference, index) => <li key={index}>{reference.article_number ? `المادة ${reference.article_number}` : reference.rule_code} — {reference.source_name}</li>)}</ul>
        </div>
      </section>

      <section className="rounded-2xl border border-slate-200 p-4">
        <h2 className="font-black">سجل الإصدارات</h2>
        <p className="mt-2 text-sm font-semibold">الإصدار الحالي: {transaction.version_number || 1}</p>
        <div className="mt-3 space-y-2">
          {(details.versions || []).map((version) => <div key={version.id} className="rounded-xl bg-slate-50 p-3 text-sm">
            الإصدار {version.version_number} — {workflowStatusLabels[version.status] || version.status} — {version.created_at} — {version.created_by?.name || 'المستخدم'} — {version.reason}
          </div>)}
          {(!details.versions || details.versions.length === 0) && <p className="text-sm text-slate-500">لا توجد إصدارات سابقة.</p>}
        </div>
      </section>

      <section className="rounded-2xl border border-slate-200 p-4">
        <h2 className="font-black">سجل الإجراءات</h2>
        <ol className="mt-3 space-y-3 border-r-2 border-blue-100 pr-4">
          {(details.audit_timeline || []).map((entry) => <li key={entry.id} className="relative rounded-xl bg-slate-50 p-3 text-sm">
            <span className="absolute -right-[1.45rem] top-4 h-3 w-3 rounded-full bg-blue-500 ring-4 ring-white" />
            <p className="font-bold">{workflowActionLabels[entry.action] || entry.action}</p>
            <p className="mt-1 text-xs text-slate-500">{entry.created_at} — بواسطة: {entry.user?.name || 'النظام'}</p>
            {entry.new_values_json?.reason && <p className="mt-1 text-slate-600">السبب: {entry.new_values_json.reason}</p>}
          </li>)}
          {(!details.audit_timeline || details.audit_timeline.length === 0) && <li className="text-sm text-slate-500">لا توجد إجراءات مسجلة.</li>}
        </ol>
      </section>

      <section className="rounded-2xl border border-slate-200 p-4">
        <h2 className="font-black">سجل التعديلات</h2>
        <div className="mt-3 space-y-2">
          {adjustments.map((adjustment) => <div key={adjustment.id} className="rounded-xl bg-slate-50 p-3 text-sm">
            {adjustment.created_at} — {adjustment.reason || 'إعادة للقيمة المحسوبة'} — {formatCurrency(adjustment.old_approved_amount)} ← {formatCurrency(adjustment.new_approved_amount)}
          </div>)}
          {adjustments.length === 0 && <p className="text-sm text-slate-500">لا توجد تعديلات على هذه المعاملة.</p>}
        </div>
      </section>

      {selectedItem && <AdjustmentDialog item={selectedItem} onClose={() => setSelectedItem(null)} onSave={updateItem} onRevert={revertItem} saving={saving} />}
      {workflowDialog && <WorkflowReasonDialog action={workflowDialog} onClose={() => setWorkflowDialog('')} onSubmit={runWorkflowAction} saving={saving} />}
    </main>
  )
}
