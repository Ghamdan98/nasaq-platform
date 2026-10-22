import { useEffect, useState } from 'react'
import { employeeAdvanceSummary } from '../../services/api'
import { formatCurrency } from '../../utils/currency'
import { advanceStatuses, financialInput } from '../../utils/advances'

export default function AdvanceDecisionCard({ employeeId, value = {}, onChange, summary: provided, resultSummary, disabled = false }) {
  const [live, setLive] = useState(null)
  const [error, setError] = useState('')
  useEffect(() => {
    if (!employeeId || provided) return
    let active = true
    employeeAdvanceSummary(employeeId).then(data => { if (active) { setLive(data); setError('') } }).catch(error => { if (active) setError(error.message) })
    return () => { active = false }
  }, [employeeId, provided])
  if (!employeeId) return null
  const summary = provided || resultSummary || live
  const change = patch => onChange({ ...value, ...patch })
  return <section className="space-y-3 rounded-2xl border border-amber-200 bg-amber-50/60 p-4">
    <h2 className="font-bold text-amber-900">مراجعة السلف المرتبطة بالمخالصة</h2>
    {error && <p role="alert" className="text-sm text-red-700">تعذر فحص السلف: {error}. لا تعتمد المخالصة قبل اكتمال الفحص.</p>}
    {!summary ? <p className="text-sm">جاري فحص أرصدة السلف…</p> : !summary.items.length ? <p className="text-sm text-emerald-800">لا توجد سلف مصروفة ذات رصيد قائم.</p> : <>
      <p role="alert" className="text-sm font-semibold">تنبيه مالي: يوجد على الموظف عدد {summary.items.length} سلفة بإجمالي رصيد مستحق {formatCurrency(summary.total_balance)}. حدد قرار المعالجة قبل الاعتماد.</p>
      <fieldset disabled={disabled} className="space-y-3">
        <div className="grid gap-2 text-sm md:grid-cols-2">{[['full', 'خصم كامل الرصيد المتاح بحد المستحقات'], ['partial', 'خصم جزئي من سلف محددة'], ['none', 'استكمال المخالصة دون خصم السلف'], ['hold', 'تعليق المخالصة للمراجعة المالية']].map(([choice, label]) => <label key={choice} className="flex cursor-pointer items-center gap-2 rounded-xl border border-amber-200 bg-white p-3"><input type="radio" name={`advance-choice-${employeeId}`} value={choice} checked={value.choice === choice} onChange={() => change({ choice })} className="h-4 w-4 accent-blue-600" />{label}</label>)}</div>
        <div className="overflow-x-auto"><table className="w-full whitespace-nowrap text-right text-sm"><thead><tr>{['السلفة', 'الحالة', 'الرصيد القائم', 'محجوز في مخالصات أخرى', 'متاح للتخصيص', ...(value.choice === 'partial' ? ['المبلغ المختار'] : [])].map(label => <th key={label} className="p-2">{label}</th>)}</tr></thead><tbody>{summary.items.map(item => <tr key={item.id} className="border-t border-amber-200"><td className="p-2">{item.reference_number}</td><td className="p-2">{advanceStatuses[item.status]}{item.is_disputed && ' · متنازع عليها'}</td><td className="p-2">{formatCurrency(item.balance)}</td><td className="p-2">{formatCurrency(item.reserved)}</td><td className="p-2">{formatCurrency(item.available)}</td>{value.choice === 'partial' && <td className="p-2"><input aria-label={`خصم السلفة ${item.reference_number}`} type="number" min="0" max={item.available} step="0.01" className={financialInput} value={value.allocations?.find(row => row.advance_id === item.id)?.amount ?? ''} onChange={event => change({ allocations: [...(value.allocations || []).filter(row => row.advance_id !== item.id), ...(Number(event.target.value) > 0 ? [{ advance_id: item.id, amount: event.target.value }] : [])] })} /></td>}</tr>)}</tbody></table></div>
        <label className="block text-sm">سبب القرار (إلزامي للجزئي وعدم الخصم والتعليق)<textarea className={`${financialInput} mt-1`} rows="2" value={value.reason || ''} onChange={event => change({ reason: event.target.value })} /></label>
        <label className="block text-sm">قرار المديونية المتبقية إن وجدت<textarea className={`${financialInput} mt-1`} rows="2" placeholder="مثال: يبقى الرصيد قائمًا ويحصّل مستقلاً بموجب خطة السداد" value={value.remaining_debt_reason || ''} onChange={event => change({ remaining_debt_reason: event.target.value })} /></label>
      </fieldset>
      <p className="text-xs text-amber-900">الحفظ يحجز المبلغ فقط؛ لا تُسوّى السلفة إلا بعد المراجعة والاعتماد والترحيل. الرصيد غير المخصوم يبقى دينًا قائمًا ولا يُشطب.</p>
      {resultSummary && <p className="text-sm">الخصم المقترح: <b>{formatCurrency(resultSummary.proposed_deduction)}</b> · المتبقي كمديونية: <b>{formatCurrency(resultSummary.remaining_debt)}</b></p>}
    </>}
  </section>
}
