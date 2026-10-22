import { useCallback, useEffect, useRef, useState } from 'react'
import { getEmployee, listEmployees } from '../../services/api'
import { employeeMoney } from '../../utils/employees'
import { formatCurrency } from '../../utils/currency'

export default function EmployeePicker({ form, setForm, error, initialEmployeeId }) {
  const mounted = useRef(true)
  useEffect(() => { mounted.current = true; return () => { mounted.current = false } }, [])
  const [search, setSearch] = useState('')
  const [results, setResults] = useState([])
  const [open, setOpen] = useState(false)
  const [loading, setLoading] = useState(Boolean(initialEmployeeId))
  const [message, setMessage] = useState('')
  const employee = form.employee_profile
  const applyEmployee = useCallback(data => setForm(previous => ({ ...previous,
    last_return_from_leave_date: '', last_settlement_date: data.last_leave_settlement_date || '',
    previously_settled_days: 0, used_days: 0, used_leave_days: 0, opening_balance: 0,
    advance_decision: {}, employee_loan: 0, advance: 0,
    accrued_days: 0, adjustment_days: 0, adjusted_leave_days: 0,
    ...data.settlement_input, hire_date: data.hire_date,
    employee_id: String(data.id), contract_id: '', employee_profile: data,
    employee_profile_version: data.employee_profile_version,
  })), [setForm])

  useEffect(() => {
    if (!open) return
    let active = true
    const timer = setTimeout(() => { setLoading(true); listEmployees({ search, per_page: 20 }).then(data => { if (active) setResults(data.data || []) }).catch(error => { if (active) setMessage(error.message) }).finally(() => { if (active) setLoading(false) }) }, 250)
    return () => { active = false; clearTimeout(timer) }
  }, [search, open])

  useEffect(() => {
    if (!initialEmployeeId) return
    let active = true
    getEmployee(initialEmployeeId).then(data => { if (active) applyEmployee(data) }).catch(error => { if (active) setMessage(error.message) }).finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [initialEmployeeId, applyEmployee])

  const select = async id => {
    setLoading(true); setMessage('')
    try {
      const data = await getEmployee(id)
      if (!mounted.current) return
      applyEmployee(data)
      setOpen(false); setSearch('')
    } catch (error) { setMessage(error.message) } finally { setLoading(false) }
  }

  return <div className="relative space-y-3 md:col-span-2 xl:col-span-4">
    <label className="block text-sm font-medium text-slate-600"><span className="mb-2 block">البحث عن الموظف</span><input aria-label="البحث عن الموظف" role="combobox" aria-expanded={open} aria-controls="employee-search-results" autoComplete="off" className="w-full rounded-2xl border border-slate-200 bg-white px-3 py-2.5 text-sm" placeholder="كود الموظف أو الاسم أو رقم الهوية" value={search} onFocus={() => setOpen(true)} onChange={event => { setSearch(event.target.value); setOpen(true) }} onKeyDown={event => { if (event.key === 'Escape') setOpen(false) }} /></label>
    {open && <div id="employee-search-results" role="listbox" aria-label="نتائج البحث عن الموظفين" className="absolute inset-x-0 top-20 z-20 max-h-72 overflow-auto rounded-xl border bg-white p-2 shadow-xl">
      <button type="button" className="w-full rounded-lg px-3 py-2 text-right text-sm text-slate-500" onClick={() => setOpen(false)}>إغلاق النتائج</button>
      {loading ? <p className="p-3 text-sm">جاري البحث…</p> : results.length ? results.map(item => <button key={item.id} type="button" role="option" data-employee-id={item.id} aria-selected={String(item.id) === String(form.employee_id)} className="block w-full rounded-lg p-3 text-right text-sm hover:bg-blue-50" onClick={() => select(item.id)}>{item.employee_number} — {item.full_name}<span className="mr-2 text-xs text-slate-500">{item.company}</span></button>) : <p className="p-3 text-sm text-slate-500">لا توجد نتائج مطابقة.</p>}
    </div>}
    {employee && <div className="rounded-2xl border border-blue-100 bg-blue-50 p-4 text-sm">
      <div className="flex flex-wrap items-center justify-between gap-2"><b>{employee.employee_number} — {employee.full_name}</b><button type="button" className="text-xs text-blue-700 underline" onClick={() => { setForm(previous => ({ ...previous, employee_id: '', contract_id: '', employee_profile: null, employee_profile_version: null, salary_components: [], last_settlement_date: '', basic_salary: 0, housing_allowance: 0, transport_allowance: 0, fixed_allowances: 0, commissions: 0, other_allowances: 0 })); setSearch('') }}>إلغاء اختيار الموظف</button></div>
      <p className="mt-2">{employee.company} · {employee.department || '—'} · {employee.job_title || '—'} · {employee.nationality || '—'}</p>
      {employee.national_id_or_iqama && <p className="mt-1">رقم الهوية: {employee.national_id_or_iqama}</p>}
      {employee.basic_salary != null && <><div className="mt-3 grid gap-2 md:grid-cols-3">{Object.entries(employeeMoney).map(([key, label]) => <p key={key}>{label}: <b>{formatCurrency(employee[key])}</b></p>)}</div><p className="mt-3 font-bold">إجمالي الراتب: {formatCurrency(employee.total_salary)}</p>
        <p className="mt-2 text-xs">الراتب وتاريخ المباشرة وآخر تصفية معتمدة من ملف الموظف. لتعديلها افتح <a className="font-semibold underline" href="#/employees">إدارة الموظفين</a> ثم أعد اختيار الموظف. أسس المستحقات تعتمد على قواعد إدراج البدلات المحفوظة.</p>
      </>}
    </div>}
    {message && <p role="alert" className="text-sm text-red-600">{message}</p>}
    {error && <p role="alert" className="text-xs text-red-600">{error.join(' ')}</p>}
  </div>
}
