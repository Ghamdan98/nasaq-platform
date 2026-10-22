import { useEffect, useState } from 'react'
import { managedRecords, inspectManagedRecord, deleteManagedRecord, editManagedAdvance, archiveManagedRecord, settlementFinanceAction, advanceAction } from '../services/api'

const input = 'rounded-xl border border-slate-300 bg-white p-3'
const button = 'rounded-xl border border-slate-300 px-4 py-2 disabled:opacity-40'

export default function DataManagementPage({ user, onOpenTransaction, onOpenAdvances }) {
  const [kind, setKind] = useState('advances')
  const [search, setSearch] = useState('')
  const [archived, setArchived] = useState('all')
  const [page, setPage] = useState(1)
  const [rows, setRows] = useState(null)
  const [selected, setSelected] = useState(null)
  const [reason, setReason] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [incorrect, setIncorrect] = useState(false)
  const [amount, setAmount] = useState('')
  const [refund, setRefund] = useState('')
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [busy, setBusy] = useState(false)
  const [revision, setRevision] = useState(0)

  useEffect(() => {
    if (user.role !== 'admin') return
    let active = true
    managedRecords({ kind, search, page, archived }).then(data => { if (active) { setRows(data); setError('') } }).catch(e => { if (active) setError(e.message) })
    return () => { active = false }
  }, [kind, search, page, archived, revision, user.role])

  if (user.role !== 'admin') return <p>هذه الشاشة متاحة للمدير فقط.</p>

  async function inspect(id) {
    setBusy(true); setError(''); setMessage('')
    try {
      const data = await inspectManagedRecord(kind, id)
      setSelected(data); setReason(''); setConfirmation(''); setIncorrect(false); setRefund(''); setAmount(data.record.original_amount || '')
    } catch (e) { setError(e.message) } finally { setBusy(false) }
  }

  async function act(action) {
    setBusy(true); setError(''); setMessage('')
    try {
      if (action === 'delete') await deleteManagedRecord(kind, selected.record.id, { reason, confirmation, incorrect_data: incorrect })
      if (action === 'edit') await editManagedAdvance(selected.record.id, { reason, original_amount: amount, notes: selected.record.notes })
      if (action === 'archive') await archiveManagedRecord(kind, selected.record.id, { reason, archived: !selected.record.archived_at })
      if (action === 'cancel') {
        if (kind === 'settlements') await settlementFinanceAction(selected.record.id, 'financial-cancel', { reason, payment_reversal_reference: refund || null })
        else await advanceAction(selected.record.id, 'flags', { status: 'cancelled', reason })
      }
      setSelected(null); setRevision(v => v + 1); setMessage(action === 'delete' ? 'تم الحذف وحفظ النسخة الاحتياطية وسجل التدقيق.' : action === 'edit' ? 'تم تعديل الطلب وإعادته للاعتماد. أُزيلت خطة الأقساط السابقة لإعادة إعدادها.' : action === 'archive' ? 'تم تحديث الأرشفة. استعادة العرض لا تلغي الإلغاء المالي.' : 'تم إلغاء العملية.')
    } catch (e) { setError(e.message) } finally { setBusy(false) }
  }

  return <section className="space-y-4 rounded-3xl bg-white p-6 shadow" >
    <h1 className="text-xl font-bold">إدارة البيانات — المدير</h1>
    <p>احذف المسودات والبيانات الخاطئة فقط. العمليات المالية تستلزم الإلغاء وعكس أثرها أولًا. يبقى سجل التدقيق والنسخ الاحتياطية الخاصة.</p>
    <div className="flex flex-wrap gap-3">
      <select aria-label="نوع البيانات" className={input} value={kind} disabled={busy} onChange={e => { setKind(e.target.value); setPage(1); setRows(null); setSelected(null) }}><option value="advances">السلف</option><option value="settlements">التصفيات</option></select>
      <input aria-label="بحث" className={input} placeholder="المرجع أو اسم الموظف أو رقمه" value={search} onChange={e => { setSearch(e.target.value); setPage(1) }} />
      <select aria-label="الأرشفة" className={input} value={archived} onChange={e => { setArchived(e.target.value); setPage(1) }}><option value="all">جميع السجلات</option><option value="no">غير المؤرشفة</option><option value="yes">الأرشيف</option></select>
    </div>
    {error && <p role="alert" className="rounded-xl bg-red-50 p-3 text-red-700">{error}</p>}
    {message && <p role="status" className="rounded-xl bg-green-50 p-3">{message}</p>}
    <div className="overflow-auto"><table className="w-full text-right text-sm"><thead><tr><th>المرجع</th><th>الموظف</th><th>الحالة</th><th>المبلغ</th><th>الإجراءات</th></tr></thead><tbody>
      {rows?.data.map(row => <tr key={row.id} className="border-t"><td className="p-3">{row.reference_number}</td><td>{row.employee?.full_name}</td><td>{row.status} {row.financial_stage || ''}</td><td>{row.original_amount || row.net_amount}</td><td className="flex gap-2 p-3"><button className={button} disabled={busy} onClick={() => inspect(row.id)}>إدارة السجل</button><button className={button} onClick={() => kind === 'settlements' ? onOpenTransaction(row.id) : onOpenAdvances()}>فتح التفاصيل</button></td></tr>)}
    </tbody></table>{rows?.data.length === 0 && <p className="p-4">لا توجد سجلات.</p>}</div>
    <div className="flex items-center gap-3"><button className={button} disabled={page <= 1 || busy} onClick={() => setPage(v => v - 1)}>السابق</button><span>{page} / {rows?.last_page || 1}</span><button className={button} disabled={!rows || page >= rows.last_page || busy} onClick={() => setPage(v => v + 1)}>التالي</button></div>
    {selected && <div className="space-y-4 rounded-2xl border border-red-200 p-5">
      <h2 className="font-bold">إدارة {selected.record.reference_number} — #{selected.record.id}</h2>
      <p>عدد الحركات: {selected.record.transactions?.length || selected.record.payments?.length || 0}، ارتباطات السلف والتصفيات: {selected.record.allocations?.length || selected.record.advance_allocations?.length || 0}، المرفقات: {selected.record.documents?.length || 0}</p>
      {selected.blockers.map((text, i) => <p className="text-red-700" key={i}>{text}</p>)}
      <p className="text-sm">{selected.retained}</p>
      <label className="block">سبب الإجراء (10 أحرف على الأقل)<textarea className={`${input} block w-full`} value={reason} onChange={e => setReason(e.target.value)} maxLength={2000} /></label>
      {(selected.record.status === 'cancelled' || selected.record.archived_at) && <button className={button} disabled={busy || reason.length < 10} onClick={() => act('archive')}>{selected.record.archived_at ? 'استعادة العرض من الأرشيف' : 'أرشفة السجل الملغى'}</button>}
      {kind === 'advances' && !selected.record.disbursed_at && ['requested', 'approved'].includes(selected.record.status) && <div className="flex flex-wrap gap-2"><label>مبلغ الطلب <input type="number" min="0.01" step="0.01" className={input} value={amount} onChange={e => setAmount(e.target.value)} /></label><button className={button} disabled={busy || reason.length < 10} onClick={() => act('edit')}>حفظ التعديل وإعادة الاعتماد</button></div>}
      {kind === 'settlements' && selected.record.financial_stage === 'paid' && <label className="block">مرجع مستند عكس الصرف / الاسترداد<input className={`${input} block w-full`} value={refund} onChange={e => setRefund(e.target.value)} /></label>}
      {(kind === 'settlements' || !selected.record.disbursed_at) && selected.record.status !== 'cancelled' && <button className={button} disabled={busy || reason.length < 10} onClick={() => act('cancel')}>إلغاء العملية وحفظ سجلها</button>}
      {kind === 'advances' && selected.record.disbursed_at && <p>لعكس السلفة المصروفة افتح تفاصيل السلف واعكس حركات السداد ثم الصرف. لا يحذف هذا الإجراء دينًا قائمًا.</p>}
      <label className="flex gap-2"><input type="checkbox" checked={incorrect} onChange={e => setIncorrect(e.target.checked)} />أؤكد أن السجل تجريبي أو أُدخل بالخطأ وأريد حذفه نهائيًا.</label>
      <label className="block">اكتب <b dir="ltr">DELETE-{selected.record.id}</b> للتأكيد<input aria-label="رمز تأكيد الحذف" className={`${input} block`} dir="ltr" value={confirmation} onChange={e => setConfirmation(e.target.value)} /></label>
      <div className="flex gap-3"><button className={`${button} bg-red-700 text-white`} disabled={busy || !selected.can_delete || !incorrect || reason.length < 10 || confirmation !== `DELETE-${selected.record.id}`} onClick={() => act('delete')}>حذف نهائي مع نسخة احتياطية</button><button className={button} disabled={busy} onClick={() => setSelected(null)}>إغلاق</button></div>
    </div>}
  </section>
}
