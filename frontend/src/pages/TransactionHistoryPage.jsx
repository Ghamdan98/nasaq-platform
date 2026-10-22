import { useEffect, useState } from 'react'
import { listHrTransactions } from '../services/api'
import { formatCurrency } from '../utils/currency'
import { reviewLabels, transactionLabels, workflowStatusLabels } from '../utils/labels'
import { useSystemSettings } from '../system/SystemSettingsContext'
import { todayForInput } from '../utils/dates'

const fieldClass = 'rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100'

export default function TransactionHistoryPage({ onOpen, onNew }) {
  const { settings } = useSystemSettings()
  const [referenceYear] = useState(() => todayForInput().slice(0,4))
  const [filters, setFilters] = useState({
    transaction_type: '',
    status: '',
    review_status: '',
    reference_number: '',
    employee: '',
    date_from: '',
    date_to: '',
  })
  const [result, setResult] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [page, setPage] = useState(1)

  const load = async (query = filters, requestedPage = page) => {
    setLoading(true)
    setError('')
    try {
      setResult(await listHrTransactions({ ...query, page: requestedPage }))
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    let active=true
    listHrTransactions({page:1}).then(data=>{ if(active) setResult(data) }).catch(requestError=>{ if(active) setError(requestError.message) }).finally(()=>{ if(active) setLoading(false) })
    return ()=>{ active=false }
  }, [])

  return (
    <main className="space-y-5 rounded-[30px] border border-slate-200 bg-white p-5 shadow-sm md:p-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <p className="text-xs font-bold text-blue-600">PHASE 5B</p>
          <h1 className="mt-1 text-3xl font-black text-slate-900">سجل المعاملات</h1>
        </div>
        <button type="button" onClick={onNew} className="rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-bold text-white">معاملة جديدة</button>
      </div>

      <form
        className="flex flex-wrap items-end gap-3 rounded-2xl bg-slate-50 p-4"
        onSubmit={(event) => { event.preventDefault(); setPage(1); load(filters, 1) }}
      >
        <label className="grid gap-1 text-sm text-slate-600">
          نوع المعاملة
          <select className={fieldClass} value={filters.transaction_type} onChange={(event) => setFilters({ ...filters, transaction_type: event.target.value })}>
            <option value="">الكل</option>
            {Object.entries(transactionLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
          </select>
        </label>
        <label className="grid gap-1 text-sm text-slate-600">
          الحالة
          <select className={fieldClass} value={filters.status} onChange={(event) => setFilters({ ...filters, status: event.target.value })}>
            <option value="">الكل</option>
            {Object.entries(workflowStatusLabels).map(([status, label]) => <option key={status} value={status}>{label}</option>)}
          </select>
        </label>
        <label className="grid gap-1 text-sm text-slate-600">
          المراجعة النظامية
          <select className={fieldClass} value={filters.review_status} onChange={(event) => setFilters({ ...filters, review_status: event.target.value })}>
            <option value="">الكل</option>
            {Object.entries(reviewLabels).map(([status, label]) => <option key={status} value={status}>{label}</option>)}
          </select>
        </label>
        <label className="grid gap-1 text-sm text-slate-600">
          رقم المعاملة
          <input className={fieldClass} value={filters.reference_number} onChange={(event) => setFilters({ ...filters, reference_number: event.target.value })} placeholder={`${settings['documents.settlement_prefix']}-${referenceYear}-000001`} />
        </label>
        <label className="grid gap-1 text-sm text-slate-600">
          الموظف
          <input className={fieldClass} value={filters.employee} onChange={(event) => setFilters({ ...filters, employee: event.target.value })} placeholder="الاسم أو الرقم" />
        </label>
        <label className="grid gap-1 text-sm text-slate-600">
          من تاريخ
          <input type="date" className={fieldClass} value={filters.date_from} onChange={(event) => setFilters({ ...filters, date_from: event.target.value })} />
        </label>
        <label className="grid gap-1 text-sm text-slate-600">
          إلى تاريخ
          <input type="date" className={fieldClass} value={filters.date_to} onChange={(event) => setFilters({ ...filters, date_to: event.target.value })} />
        </label>
        <button type="submit" disabled={loading} className="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold disabled:opacity-50">
          {loading ? 'جاري التحميل...' : 'تطبيق الفلاتر'}
        </button>
      </form>

      {error && <p role="alert" className="rounded-xl bg-red-50 p-3 text-sm text-red-700">{error}</p>}

      <div className="overflow-x-auto rounded-2xl border border-slate-200">
        <table className="w-full min-w-[900px] text-right text-sm">
          <thead className="bg-slate-50 text-slate-600">
            <tr>
              {['رقم المعاملة', 'الموظف', 'نوع المعاملة', 'التاريخ', 'إجمالي المستحقات', 'إجمالي الخصومات', 'الصافي', 'الحالة', ''].map((title) => <th key={title} className="px-4 py-3 font-bold">{title}</th>)}
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {(result?.data || []).map((transaction) => (
              <tr key={transaction.id} className="text-slate-700">
                <td className="px-4 py-3 font-bold text-slate-900">{transaction.reference_number}</td>
                <td className="px-4 py-3">{transaction.employee_snapshot_json?.full_name || transaction.employee?.full_name || '—'}</td>
                <td className="px-4 py-3">{transactionLabels[transaction.transaction_type] || transaction.transaction_type}</td>
                <td className="px-4 py-3">{transaction.transaction_date}</td>
                <td className="px-4 py-3">{formatCurrency(transaction.gross_amount)}</td>
                <td className="px-4 py-3">{formatCurrency(transaction.total_deductions)}</td>
                <td className="px-4 py-3 font-bold">{formatCurrency(transaction.net_amount)}</td>
                <td className="px-4 py-3">
                  <span className={`rounded-full px-2.5 py-1 text-xs font-bold ${
                    transaction.status === 'approved' ? 'bg-emerald-100 text-emerald-800' :
                    transaction.status === 'rejected' || transaction.status === 'cancelled' ? 'bg-red-100 text-red-800' :
                    transaction.status === 'pending_review' ? 'bg-blue-100 text-blue-800' :
                    transaction.status === 'changes_requested' ? 'bg-amber-100 text-amber-800' :
                    'bg-slate-100 text-slate-700'
                  }`}>{workflowStatusLabels[transaction.status] || transaction.status}</span>
                  {transaction.review_status !== 'valid' && <span className="mt-1 block text-[11px] text-amber-700">{reviewLabels[transaction.review_status] || transaction.review_status}</span>}
                </td>
                <td className="px-4 py-3">
                  <button type="button" onClick={() => onOpen(transaction.id)} className="rounded-lg bg-blue-50 px-3 py-1.5 font-bold text-blue-700">عرض</button>
                </td>
              </tr>
            ))}
            {!loading && (result?.data || []).length === 0 && (
              <tr><td colSpan="9" className="px-4 py-8 text-center text-slate-500">لا توجد معاملات محفوظة.</td></tr>
            )}
          </tbody>
        </table>
      </div>
      {result && result.last_page > 1 && (
        <div className="flex items-center justify-between">
          <button type="button" disabled={loading || result.current_page <= 1} onClick={() => { const nextPage = page - 1; setPage(nextPage); load(filters, nextPage) }} className="rounded-xl border px-4 py-2 text-sm font-bold disabled:opacity-40">السابق</button>
          <span className="text-sm text-slate-500">صفحة {result.current_page} من {result.last_page}</span>
          <button type="button" disabled={loading || result.current_page >= result.last_page} onClick={() => { const nextPage = page + 1; setPage(nextPage); load(filters, nextPage) }} className="rounded-xl border px-4 py-2 text-sm font-bold disabled:opacity-40">التالي</button>
        </div>
      )}
    </main>
  )
}
