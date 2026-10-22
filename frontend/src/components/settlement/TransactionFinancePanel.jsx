import { formatDateTime, todayForInput } from '../../utils/dates'
import { useEffect, useState } from 'react'
import { settlementFinance, settlementFinanceAction } from '../../services/api'
import { advancePermission, financialButton as button, financialInput as input, financialStages } from '../../utils/advances'
import { formatCurrency } from '../../utils/currency'
import AdvanceDecisionCard from './AdvanceDecisionCard'

export default function TransactionFinancePanel({ transaction, user, onChanged }) {
  const [data, setData] = useState(null)
  const [decision, setDecision] = useState({})
  const [review, setReview] = useState({ deduction_source: '', deductible_limit: '', debt_verified: false, documents_verified: false, protected_rights_reviewed: false, written_consent: false, reason: '' })
  const [legal, setLegal] = useState({ approved_limit: '', authority_reference: '', reason: '' })
  const [payment, setPayment] = useState(() => ({ reference_number: '', payment_date: todayForInput(), method: 'bank', notes: '' }))
  const [cancellation, setCancellation] = useState({ reason: '', payment_reversal_reference: '' })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [postingKey] = useState(() => crypto.randomUUID())
  const can = permission => advancePermission(user, permission)
  useEffect(() => {
    let active = true
    settlementFinance(transaction.id).then(result => { if (active) { setData(result); setDecision(result.settlement.advance_decision_json || {}) } }).catch(error => { if (active) setError(error.message) })
    return () => { active = false }
  }, [transaction])
  const action = async (name, body) => {
    setBusy(true); setError('')
    try { await settlementFinanceAction(transaction.id, name, body); setData(await settlementFinance(transaction.id)); await onChanged() } catch (error) { setError(error.message) } finally { setBusy(false) }
  }
  const field = (key, label, state, setter, type = 'text') => <label key={key} className="block text-sm"><span className="mb-1 block">{label}</span><input aria-label={label} type={type} {...(type === 'number' ? { min: 0, step: '0.01' } : {})} required className={input} value={state[key]} onChange={event => setter({ ...state, [key]: event.target.value })} /></label>
  if (!data) return <section className="rounded-2xl border p-4 text-sm">{error || 'جاري فحص إجراءات السلف والترحيل…'}</section>
  const stage = data.settlement.financial_stage
  const editable = ['calculated', 'changes_requested', 'pending_review'].includes(data.settlement.status)
  const policy = data.settlement.financial_review_json?.policy
  return <section className="space-y-4 rounded-2xl border border-blue-200 bg-white p-5">
    <div className="flex flex-wrap justify-between gap-3"><h2 className="text-xl font-bold">المراجعة والتسوية المالية</h2><span className="rounded-full bg-blue-50 px-3 py-1 text-sm text-blue-800">{financialStages[stage] || stage}</span></div>
    <p className="text-xs leading-6 text-slate-500">الاعتماد لا يعني الدفع. حجز السلف لا يسددها؛ تخفيض الرصيد يحدث مرة واحدة عند الترحيل. توثيق الصرف يسجل مستند الدفع ولا ينفذ تحويلًا بنكيًا.</p>
    {error && <p role="alert" className="rounded-xl bg-red-50 p-3 text-sm text-red-700">{error}</p>}
    <AdvanceDecisionCard employeeId={transaction.employee_id} value={decision} onChange={setDecision} summary={data.advances} resultSummary={transaction.calculation_snapshot_json?.advance_summary} disabled={!editable || busy} />
    {editable && (can('review_financial_settlements') || can('edit_transactions')) && <button className={button} disabled={busy} onClick={() => action('advance-decision', { advance_decision: decision })}>حفظ قرار السلف وإعادة المخالصة للإرسال والمراجعة</button>}
    {data.allocations.length > 0 && <div className="overflow-x-auto"><table className="w-full text-right text-sm"><thead><tr><th className="p-2">السلفة</th><th className="p-2">المبلغ المخصص</th><th className="p-2">حالة التخصيص</th></tr></thead><tbody>{data.allocations.map(row => <tr key={row.id} className="border-t"><td className="p-2">{row.advance?.reference_number || row.advance_id}</td><td className="p-2">{formatCurrency(row.allocated_amount)}</td><td className="p-2">{{ reserved: 'محجوز ولم يسدد', posted: 'سداد مرحل', reversed: 'عُكس محاسبيًا', released: 'أُلغي الحجز' }[row.status]}</td></tr>)}</tbody></table></div>}
    {can('review_financial_settlements') && transaction.status === 'pending_review' && <form className="space-y-3 rounded-xl border p-4" onSubmit={event => { event.preventDefault(); action('financial-review', review) }}><h3 className="font-bold">المراجعة المالية</h3><div className="grid gap-3 md:grid-cols-2"><label className="text-sm">مصدر الخصم<select required aria-label="مصدر خصم السلف" className={input} value={review.deduction_source} onChange={event => setReview({ ...review, deduction_source: event.target.value })}><option value="">حدد نوع المستحق</option><option value="non_wage">حقوق نهاية العلاقة غير الأجرية</option><option value="wages">أجور أو تصفية إجازة أثناء الخدمة</option><option value="mixed">مختلط أو يحتاج تفسيرًا</option></select></label>{field('deductible_limit', 'الحد القابل للحسم بعد المراجعة', review, setReview, 'number')}{field('reason', 'تفسير المراجعة المالية', review, setReview)}</div><div className="grid gap-2 text-sm md:grid-cols-2">{[['debt_verified', 'تحققت من صحة المديونية وطبيعتها'], ['documents_verified', 'راجعت المستندات والموافقات'], ['protected_rights_reviewed', 'راجعت الحدود والحقوق المحمية ومصدر الخصم'], ['written_consent', 'توجد موافقة خطية موثقة']].map(([key, label]) => <label key={key} className="flex items-center gap-2"><input type="checkbox" className="h-4 w-4 accent-blue-600" checked={review[key]} onChange={event => setReview({ ...review, [key]: event.target.checked })} />{label}</label>)}</div><p className="text-xs text-slate-500">لا يُطبق حد 10% على إجمالي مخالصة نهاية الخدمة. خصومات الأجور والحالات المختلطة أو غير مكتملة الإثبات تُراجع بحسب طبيعتها، وقد تتطلب قرارًا قانونيًا.</p><button className={button} disabled={busy}>تسجيل المراجعة المالية</button></form>}
    {policy && <div className="rounded-xl bg-slate-50 p-3 text-sm"><b>{policy.status === 'clear' ? 'اكتملت ضوابط المراجعة المالية' : 'يتطلب مراجعة قانونية'}</b><p>الحد الذي أمكن التحقق منه: {formatCurrency(policy.maximum)}</p>{policy.reasons.map(reason => <p key={reason}>{reason}</p>)}</div>}
    {can('review_advance_legal') && stage === 'pending_legal_review' && <form className="space-y-3 rounded-xl border border-rose-200 p-4" onSubmit={event => { event.preventDefault(); action('advance-legal-review', legal) }}><h3 className="font-bold">قرار المراجعة القانونية</h3><p className="text-xs text-slate-600">وثق سند القرار وحد الحسم. إذا لم يُجز الخصم، حدّث قرار السلف إلى جزئي أو عدم الخصم بدل اعتماد مبلغ غير مشروع.</p><div className="grid gap-3 md:grid-cols-2">{field('approved_limit', 'الحد القانوني المعتمد', legal, setLegal, 'number')}{field('authority_reference', 'مرجع السند أو الحكم أو الرأي القانوني', legal, setLegal)}{field('reason', 'تفسير القرار القانوني المفصل', legal, setLegal)}</div><button className={button} disabled={busy}>توثيق القرار القانوني</button></form>}
    {data.settlement.financial_reviewed_at && <p className="text-xs text-slate-500">المراجع المالي: {data.settlement.financial_reviewed_by} · {formatDateTime(data.settlement.financial_reviewed_at)}</p>}
    {can('post_settlements') && stage === 'approved' && <button className="rounded-xl bg-blue-600 px-5 py-3 font-bold text-white disabled:opacity-40" disabled={busy} onClick={() => action('post', { idempotency_key: postingKey })}>ترحيل التسوية المالية وتخفيض السلف المخصصة</button>}
    {can('pay_settlements') && stage === 'posted' && <form className="space-y-3 rounded-xl border p-4" onSubmit={event => { event.preventDefault(); action('pay', payment) }}><h3 className="font-bold">توثيق صرف الصافي: {formatCurrency(data.settlement.net_amount)}</h3><div className="grid gap-3 md:grid-cols-2">{field('reference_number', 'مرجع مستند الدفع', payment, setPayment)}{field('payment_date', 'تاريخ الصرف الفعلي', payment, setPayment, 'date')}<select className={input} aria-label="طريقة دفع المخالصة" value={payment.method} onChange={event => setPayment({ ...payment, method: event.target.value })}><option value="bank">تحويل بنكي</option><option value="cash">نقدًا</option><option value="no_cash">صافي صفر دون صرف نقدي</option></select></div><button className={button} disabled={busy}>تسجيل مستند الصرف</button></form>}
    {data.payments.length > 0 && <div className="space-y-2 text-sm"><h3 className="font-bold">مستندات الدفع والعكس</h3>{data.payments.map(row => <p key={row.id}>{row.payment_date} · {row.reference_number} · {formatCurrency(row.amount)} · {row.reversal_of_id ? 'مستند عكسي' : 'صرف'}</p>)}</div>}
    {can('reverse_settlements') && stage !== 'cancelled' && <details className="rounded-xl border border-rose-200 p-4"><summary className="cursor-pointer text-sm font-bold text-rose-700">إلغاء أو عكس مالي موثق</summary><form className="mt-3 space-y-3" onSubmit={event => { event.preventDefault(); action('financial-cancel', cancellation) }}>{field('reason', 'سبب الإلغاء أو العكس', cancellation, setCancellation)}{stage === 'paid' && field('payment_reversal_reference', 'مرجع عكس الصرف أو الاسترداد', cancellation, setCancellation)}<p className="text-xs text-slate-500">يُلغى الحجز غير المرحل، وتُعاد المديونية المرحلة بمستند عكسي. لا يُحذف السجل الأصلي.</p><button className={button} disabled={busy}>تنفيذ الإلغاء المالي الموثق</button></form></details>}
  </section>
}
