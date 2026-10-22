import { formatDateTime } from '../utils/dates'
import { useEffect, useState } from 'react'
import { hasUserPermission } from '../utils/permissions'
import { employeeCompanies, listEmployees, getEmployee, saveEmployee, employeeImports, employeeImportPreview, getEmployeeImport, commitEmployeeImport, employeeTemplate, employeeImportErrors } from '../services/api'
import { employeeMoney, employeeStatuses, importStatuses, saveDownload } from '../utils/employees'
import { formatCurrency } from '../utils/currency'

const inputClass = 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:border-blue-400'
const buttonClass = 'rounded-xl border border-blue-200 bg-blue-50 px-4 py-2 text-sm font-semibold text-blue-700 disabled:opacity-40'
const blank = () => ({ employee_number: '', full_name: '', company: 'الشركة الرئيسية', department: '', job_title: '', nationality: '', national_id_or_iqama: '', hire_date: '', last_leave_settlement_date: '', status: 'active', ...Object.fromEntries(Object.keys(employeeMoney).map(key => [key, 0])), salary_inclusion: Object.fromEntries(Object.keys(employeeMoney).map(key => [key, { eos: true, leave: true, notice: true }])) })

function Pager({ page, last, onChange }) {
  return <div className="flex items-center justify-center gap-4 p-4 text-sm"><button className={buttonClass} disabled={page <= 1} onClick={() => onChange(page - 1)}>السابق</button><span>{page} / {last || 1}</span><button className={buttonClass} disabled={page >= (last || 1)} onClick={() => onChange(page + 1)}>التالي</button></div>
}

export default function EmployeesPage({ user, onCalculate }) {
  const [filters, setFilters] = useState({ search: '', company: '', status: '', page: 1 })
  const [result, setResult] = useState({ data: [], last_page: 1 })
  const [companies, setCompanies] = useState([])
  const [loading, setLoading] = useState(false)
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState('')
  const [errors, setErrors] = useState({})
  const [refresh, setRefresh] = useState(0)
  const [editor, setEditor] = useState(null)
  const [readOnly, setReadOnly] = useState(false)
  const [tab, setTab] = useState('employees')
  const [mode, setMode] = useState('new_only')
  const [file, setFile] = useState(null)
  const [preview, setPreview] = useState(null)
  const [history, setHistory] = useState({ data: [] })
  const [historyPage, setHistoryPage] = useState(1)
  const [openedAt] = useState(() => Date.now())
  const manage = hasUserPermission(user, 'manage_employees')
  const sensitive = manage || hasUserPermission(user, 'view_employee_sensitive')

  useEffect(() => {
    let active = true
    const timer = setTimeout(() => { setLoading(true); listEmployees(filters).then(data => { if (active) setResult(data) }).catch(error => { if (active) setMessage(error.message) }).finally(() => { if (active) setLoading(false) }) }, 250)
    return () => { active = false; clearTimeout(timer) }
  }, [filters, refresh])
  useEffect(() => { employeeCompanies().then(setCompanies).catch(error => setMessage(error.message)) }, [refresh])
  useEffect(() => {
    if (tab !== 'imports' || !manage) return
    let active = true
    employeeImports(historyPage).then(data => { if (active) setHistory(data) }).catch(error => { if (active) setMessage(error.message) })
    return () => { active = false }
  }, [tab, historyPage, refresh, manage])

  const run = async action => { setBusy(true); setMessage(''); try { await action() } catch (error) { setMessage(error.message); setErrors(error.errors || {}) } finally { setBusy(false) } }
  const edit = async (id, viewing) => run(async () => { const data = await getEmployee(id); setEditor({ ...blank(), ...data, salary_inclusion: { ...blank().salary_inclusion, ...(data.salary_inclusion || {}) } }); setReadOnly(viewing); setErrors({}) })
  const changeFilter = (key, value) => setFilters(current => ({ ...current, [key]: value, page: 1 }))
  const field = (key, label, type = 'text', required = false) => <label key={key} className="block text-sm text-slate-600"><span className="mb-2 block">{label}{required && ' *'}</span><input aria-label={label} className={inputClass} type={type} required={required} value={editor[key] ?? ''} {...(type === 'number' ? { min: 0, step: '0.01' } : {})} onChange={event => setEditor({ ...editor, [key]: event.target.value })} />{errors[key] && <span className="text-xs text-red-600">{errors[key].join(' ')}</span>}</label>

  return <main className="space-y-5">
    <header className="flex flex-wrap items-center justify-between gap-3 rounded-3xl border border-slate-200 bg-white p-6">
      <div><h1 className="text-2xl font-bold">إدارة الموظفين</h1><p className="mt-1 text-sm text-slate-500">ملفات الموظفين والرواتب والاستيراد من Excel</p></div>
      <div className="flex flex-wrap gap-2"><button className={buttonClass} onClick={() => setTab('employees')}>الموظفون</button>{manage && <><button className={buttonClass} onClick={() => setTab('imports')}>الاستيراد وسجله</button><button className="rounded-xl bg-blue-600 px-4 py-2 text-sm font-bold text-white" onClick={() => { setEditor(blank()); setReadOnly(false); setErrors({}) }}>إضافة موظف</button></>}</div>
    </header>
    {message && <div role="status" className="rounded-xl border border-blue-100 bg-blue-50 p-4 text-sm">{message}</div>}
    {tab === 'employees' ? <section className="overflow-hidden rounded-3xl border border-slate-200 bg-white">
      <div className="grid gap-3 p-5 md:grid-cols-3"><input aria-label="البحث عن موظف" placeholder={sensitive ? 'الكود أو الاسم أو رقم الهوية' : 'الكود أو الاسم'} className={inputClass} value={filters.search} onChange={event => changeFilter('search', event.target.value)} /><select aria-label="فلترة الشركة" className={inputClass} value={filters.company} onChange={event => changeFilter('company', event.target.value)}><option value="">جميع الشركات</option>{companies.map(company => <option key={company}>{company}</option>)}</select><select aria-label="فلترة الحالة" className={inputClass} value={filters.status} onChange={event => changeFilter('status', event.target.value)}><option value="">جميع الحالات</option>{Object.entries(employeeStatuses).map(([value, name]) => <option key={value} value={value}>{name}</option>)}</select></div>
      {loading ? <p className="p-6 text-slate-500">جاري تحميل الموظفين…</p> : <div className="overflow-x-auto"><table className="w-full whitespace-nowrap text-right text-sm"><thead className="bg-slate-50 text-slate-500"><tr>{['الكود', 'الموظف', 'الشركة', 'الوحدة التنظيمية', 'الحالة', 'الإجراءات'].map(label => <th key={label} className="p-4">{label}</th>)}</tr></thead><tbody>{result.data.map(employee => <tr className="border-t border-slate-100" key={employee.id}><td className="p-4">{employee.employee_number}</td><td className="p-4 font-semibold">{employee.full_name}</td><td className="p-4">{employee.company}</td><td className="p-4">{employee.department || '—'}</td><td className="p-4">{employeeStatuses[employee.status]}</td><td className="flex gap-2 p-4"><button className={buttonClass} onClick={() => edit(employee.id, true)}>عرض</button>{manage && <button className={buttonClass} onClick={() => edit(employee.id, false)}>تعديل</button>}{sensitive && <button className={buttonClass} onClick={() => onCalculate(employee.id)}>إعداد مخالصة</button>}</td></tr>)}</tbody></table>{!result.data.length && <p className="p-8 text-center text-slate-500">لا توجد نتائج مطابقة.</p>}</div>}
      <Pager page={filters.page} last={result.last_page} onChange={page => setFilters({ ...filters, page })} />
    </section> : manage && <>
      <section className="space-y-4 rounded-3xl border border-slate-200 bg-white p-5">
        <div className="flex flex-wrap items-center justify-between gap-3"><h2 className="text-lg font-bold">استيراد الموظفين</h2><button disabled={busy} className={buttonClass} onClick={() => run(async () => saveDownload(await employeeTemplate()))}>تحميل قالب Excel</button></div>
        <p className="text-sm leading-7 text-slate-600">استخدم عناوين القالب العربي. الكود والهوية نص للحفاظ على الأصفار. التواريخ بصيغة YYYY-MM-DD أو DD/MM/YYYY أو تاريخ Excel. الإجمالي يُحسب من مكونات الراتب. الخلايا الفارغة تحافظ على بيانات الموظف الموجود؛ وللموظف الجديد تكون البدلات الاختيارية صفرًا. لا تُحذف سجلات بسبب غيابها من الملف.</p>
        <p className="text-sm text-blue-700">تُحتسب المستحقات افتراضيًا على إجمالي الراتب، شاملًا الأساسي وجميع البدلات. يمكن تخصيص إدراج البدلات من ملف الموظف. الاستيراد يحافظ على قواعد الإدراج الموجودة عند تحديث الموظفين.</p>
        <div className="flex flex-wrap gap-3"><input aria-label="ملف الموظفين" type="file" accept=".xlsx" onChange={event => { setFile(event.target.files[0]); setPreview(null) }} /><select aria-label="طريقة الاستيراد" className="rounded-xl border px-3 py-2 text-sm" value={mode} onChange={event => { setMode(event.target.value); setPreview(null) }}><option value="new_only">إضافة الجدد فقط</option><option value="upsert">إضافة الجدد وتحديث الموجودين</option><option value="update_only">تحديث الموجودين فقط</option></select><button disabled={!file || busy} className={buttonClass} onClick={() => run(async () => { setPreview(await employeeImportPreview(file, mode)); setRefresh(x => x + 1) })}>{busy ? 'جاري المعالجة…' : 'معاينة قبل الحفظ'}</button></div>
        {preview && <div className="space-y-3 rounded-xl border p-4"><div className="flex flex-wrap gap-4 text-sm"><b>{importStatuses[preview.status]}</b><span>جديد: {preview.new_count}</span><span>تحديث: {preview.updated_count}</span><span>مرفوض: {preview.rejected_count}</span><span>متجاهل: {preview.skipped_count}</span></div>
          <div className="overflow-x-auto"><table className="w-full text-right text-sm"><thead><tr>{['الصف', 'الكود', 'الاسم', 'الإجراء', 'الأخطاء'].map(label => <th key={label} className="p-2">{label}</th>)}</tr></thead><tbody>{preview.rows?.map(row => <tr key={row.row} className="border-t"><td className="p-2">{row.row}</td><td className="p-2">{row.data.employee_number}</td><td className="p-2">{row.data.full_name}</td><td className="p-2">{{ create: 'إضافة', update: 'تحديث', skip: 'تجاهل' }[row.action]}</td><td className="p-2 text-red-600">{row.errors.join('، ')}</td></tr>)}</tbody></table></div>
          <Pager page={preview.page || 1} last={preview.pages} onChange={page => run(async () => setPreview(await getEmployeeImport(preview.id, page)))} />
          {preview.rejected_count > 0 && <><p className="text-sm text-red-600">لن يُحفظ أي صف. صحّح الأخطاء ثم أعد رفع الملف.</p><button className={buttonClass} onClick={() => run(async () => saveDownload(await employeeImportErrors(preview.id)))}>تنزيل تقرير الأخطاء</button></>}
          <button disabled={busy || preview.status !== 'preview' || preview.rejected_count > 0} className="rounded-xl bg-blue-600 px-5 py-2 text-sm font-bold text-white disabled:opacity-40" onClick={() => run(async () => { await commitEmployeeImport(preview.id); setPreview(null); setMessage('تم استيراد الموظفين بنجاح.'); setRefresh(x => x + 1) })}>تأكيد حفظ الاستيراد كاملًا</button>
        </div>}
      </section>
      <section className="overflow-hidden rounded-3xl border border-slate-200 bg-white p-5"><h2 className="mb-4 text-lg font-bold">سجل عمليات الاستيراد</h2><p className="mb-3 text-xs text-slate-500">المعاينة وتقرير الأخطاء متاحان لمدة 24 ساعة. يُحفظ سجل النتائج بعد التنفيذ.</p><div className="overflow-x-auto"><table className="w-full whitespace-nowrap text-right text-sm"><thead><tr>{['الوقت', 'المستخدم', 'الملف', 'الحالة', 'جديد / تحديث / مرفوض / متجاهل', ''].map(label => <th key={label} className="p-2">{label}</th>)}</tr></thead><tbody>{history.data.map(item => <tr key={item.id} className="border-t"><td className="p-2">{formatDateTime(item.created_at)}</td><td className="p-2">{item.user?.name || item.user_id}</td><td className="p-2">{item.filename}</td><td className="p-2">{importStatuses[item.status]}</td><td className="p-2">{item.new_count} / {item.updated_count} / {item.rejected_count} / {item.skipped_count}</td><td className="p-2"><button className={buttonClass} disabled={busy || item.status === 'completed' || new Date(item.expires_at).getTime() < openedAt} onClick={() => run(async () => setPreview(await getEmployeeImport(item.id)))}>عرض المعاينة</button></td></tr>)}</tbody></table></div><Pager page={historyPage} last={history.last_page} onChange={setHistoryPage} /></section>
    </>}
    {editor && <div className="fixed inset-0 z-50 overflow-y-auto bg-slate-950/50 p-3 md:p-8" role="dialog" aria-modal="true" aria-label="ملف الموظف"><form className="mx-auto max-w-4xl space-y-5 rounded-3xl bg-white p-5 shadow-xl" onSubmit={event => { event.preventDefault(); run(async () => { await saveEmployee(editor, editor.id); setEditor(null); setRefresh(x => x + 1); setMessage('تم حفظ بيانات الموظف. المخالصات السابقة تحتفظ بنسختها التاريخية.') }) }}>
      <div className="flex items-center justify-between"><h2 className="text-xl font-bold">{editor.id ? 'ملف الموظف' : 'إضافة موظف'}</h2><button type="button" className={buttonClass} onClick={() => setEditor(null)}>إغلاق</button></div>
      <fieldset disabled={readOnly || busy} className="space-y-5">
        <section><h3 className="mb-3 font-bold text-blue-900">البيانات الشخصية</h3><div className="grid gap-4 md:grid-cols-2">{field('full_name', 'اسم الموظف', 'text', true)}{field('nationality', 'الجنسية')}{sensitive && field('national_id_or_iqama', 'رقم الهوية')}</div></section>
        <section><h3 className="mb-3 font-bold text-blue-900">البيانات الوظيفية</h3><div className="grid gap-4 md:grid-cols-2">{field('employee_number', 'كود الموظف', 'text', true)}{field('company', 'الشركة', 'text', true)}{field('department', 'الوحدة التنظيمية')}{field('job_title', 'المهنة')}{field('hire_date', 'تاريخ المباشرة', 'date', true)}<label className="text-sm text-slate-600"><span className="mb-2 block">الحالة الوظيفية</span><select className={inputClass} value={editor.status} onChange={event => setEditor({ ...editor, status: event.target.value })}>{Object.entries(employeeStatuses).map(([value, name]) => <option key={value} value={value}>{name}</option>)}</select></label></div></section>
        {sensitive && <section><h3 className="mb-3 font-bold text-blue-900">تفاصيل الراتب والبدلات</h3><p className="mb-3 text-xs text-slate-500">كل المكونات تدخل في إجمالي الراتب. حدّد إدراج كل مكون في أساس نهاية الخدمة والإجازة والإشعار حسب القواعد المعتمدة.</p><div className="overflow-x-auto"><table className="w-full text-sm"><thead><tr><th className="p-2">المكون والمبلغ</th>{['نهاية الخدمة', 'الإجازة', 'الإشعار'].map(name => <th key={name} className="p-2">{name}</th>)}</tr></thead><tbody>{Object.entries(employeeMoney).map(([key, label]) => <tr key={key} className="border-t"><td className="p-2">{field(key, label, 'number', key === 'basic_salary')}</td>{['eos', 'leave', 'notice'].map(basis => <td key={basis} className="p-2 text-center"><input aria-label={`${label} ${basis}`} type="checkbox" className="h-5 w-5 accent-blue-600" checked={editor.salary_inclusion?.[key]?.[basis] ?? true} onChange={event => setEditor({ ...editor, salary_inclusion: { ...editor.salary_inclusion, [key]: { ...editor.salary_inclusion?.[key], [basis]: event.target.checked } } })} /></td>)}</tr>)}</tbody></table></div><p className="mt-3 rounded-xl bg-blue-50 p-3 font-bold">إجمالي الراتب: {formatCurrency(Object.keys(employeeMoney).reduce((sum, key) => sum + Number(editor[key] || 0), 0))}</p></section>}
        <section><h3 className="mb-3 font-bold text-blue-900">بيانات الإجازات والتصفية</h3>{field('last_leave_settlement_date', 'تاريخ آخر تصفية إجازة', 'date')}</section>
      </fieldset>
      {Object.keys(errors).length > 0 && <p role="alert" className="text-sm text-red-600">{Object.values(errors).flat().join('، ')}</p>}
      {!readOnly && <button disabled={busy} className="rounded-xl bg-blue-600 px-5 py-3 font-bold text-white disabled:opacity-40">{busy ? 'جاري الحفظ…' : 'حفظ الموظف'}</button>}
    </form></div>}
  </main>
}
