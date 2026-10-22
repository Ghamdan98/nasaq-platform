import { formatDateTime } from '../utils/dates'
import { useEffect, useState } from 'react'
import { listUsers, saveUser, userAudit, userPermissions } from '../services/api'

const input = 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100'
const button = 'rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold disabled:opacity-40'
const blank = { name: '', email: '', phone: '', role: 'hr', status: 'active', password: '', password_confirmation: '', reason: '' }
const actions = { user_created: 'إنشاء الحساب', user_updated: 'تحديث الحساب والصلاحيات', user_password_reset: 'إعادة تعيين كلمة المرور' }

export default function UsersPage({ user, onSessionInvalidated }) {
  const [catalog, setCatalog] = useState(null)
  const [rows, setRows] = useState(null)
  const [filters, setFilters] = useState({ search: '', role: '', status: '', page: 1 })
  const [editor, setEditor] = useState(null)
  const [permissions, setPermissions] = useState([])
  const [history, setHistory] = useState(null)
  const [historyPage, setHistoryPage] = useState(1)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [revision, setRevision] = useState(0)

  useEffect(() => {
    if (user.role !== 'admin') return
    let active = true
    userPermissions().then(data => { if (active) setCatalog(data) }).catch(e => { if (active) setError(e.message) })
    return () => { active = false }
  }, [user.role])

  useEffect(() => {
    if (user.role !== 'admin') return
    let active = true
    const timer = setTimeout(() => listUsers(filters).then(data => { if (active) setRows(data) }).catch(e => { if (active) setError(e.message) }), 250)
    return () => { active = false; clearTimeout(timer) }
  }, [filters, revision, user.role])

  const editorId = editor?.id
  useEffect(() => {
    if (!editorId) return
    let active = true
    userAudit(editorId, historyPage).then(data => { if (active) setHistory(data) }).catch(e => { if (active) setError(e.message) })
    return () => { active = false }
  }, [editorId, historyPage, revision])

  if (user.role !== 'admin') return <p role="alert">إدارة المستخدمين متاحة لمدير النظام فقط.</p>

  const filter = (key, value) => setFilters(previous => ({ ...previous, [key]: value, page: 1 }))
  const open = record => {
    setEditor(record ? { ...blank, ...record } : { ...blank })
    setPermissions(record?.effective_permissions || catalog.defaults.hr)
    setHistory(null); setHistoryPage(1); setError(''); setMessage('')
  }
  const field = (key, label, type = 'text') => <label className="block text-sm font-medium text-slate-600" key={key}><span className="mb-2 block">{label}</span><input name={key} className={input} value={editor[key] || ''} type={type} required={['name', 'email', 'reason'].includes(key) || (!editor.id && key.startsWith('password')) || (key === 'password_confirmation' && Boolean(editor.password))} minLength={key.startsWith('password') ? 12 : key === 'reason' ? 5 : undefined} maxLength={key.startsWith('password') ? 128 : key === 'reason' ? 2000 : key === 'phone' ? 30 : 255} autoComplete={key.startsWith('password') ? 'new-password' : 'off'} onChange={e => setEditor(previous => ({ ...previous, [key]: e.target.value }))} /></label>
  const toggle = permission => setPermissions(previous => {
    const next = new Set(previous)
    if (next.has(permission)) {
      next.delete(permission)
      if (permission === 'view_employee_sensitive') next.delete('manage_employees')
    } else {
      next.add(permission)
      if (permission === 'manage_employees') next.add('view_employee_sensitive')
    }
    return [...next]
  })
  const submit = async event => {
    event.preventDefault(); setBusy(true); setError(''); setMessage('')
    try {
      const result = await saveUser({ ...editor, permissions }, editor.id)
      if (result.reauthentication_required) { onSessionInvalidated(); return }
      setEditor({ ...blank, ...result.user }); setPermissions(result.user.effective_permissions)
      setRevision(value => value + 1); setMessage(result.message)
    } catch (e) { setError(e.message) } finally { setBusy(false) }
  }

  return <main  className="space-y-5 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm md:p-6">
    <div className="flex flex-wrap items-center justify-between gap-3"><div><h1 className="text-2xl font-bold">إدارة المستخدمين والصلاحيات</h1><p className="mt-2 text-sm text-slate-500">حساب مستقل لكل مستخدم، وصلاحيات محددة لكل مهمة، وسجل موثق للتغييرات.</p></div><button type="button" className={`${button} !bg-blue-600 text-white`} disabled={!catalog || busy} onClick={() => open(null)}>إضافة مستخدم</button></div>
    <div className="grid gap-3 md:grid-cols-3"><input className={input} aria-label="البحث عن مستخدم" placeholder="الاسم أو البريد الإلكتروني" value={filters.search} onChange={e => filter('search', e.target.value)} /><select className={input} aria-label="تصفية حسب الدور" value={filters.role} onChange={e => filter('role', e.target.value)}><option value="">جميع الأدوار</option>{Object.entries(catalog?.roles || {}).map(([value, label]) => <option value={value} key={value}>{label}</option>)}</select><select className={input} aria-label="تصفية حسب الحالة" value={filters.status} onChange={e => filter('status', e.target.value)}><option value="">جميع الحالات</option><option value="active">نشط</option><option value="inactive">معطل</option></select></div>
    {message && <p role="status" className="rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800">{message}</p>}
    {error && <p role="alert" className="rounded-xl bg-red-50 p-3 text-sm text-red-700">{error}</p>}
    <div className="overflow-x-auto"><table className="w-full text-right text-sm"><thead><tr className="text-slate-500"><th className="p-3">المستخدم</th><th>البريد الإلكتروني</th><th>الدور</th><th>الحالة</th><th>الإجراء</th></tr></thead><tbody>{rows?.data.map(record => <tr key={record.id} className="border-t border-slate-100"><td className="p-3 font-semibold">{record.name}{record.id === user.id && <span className="mr-2 text-xs text-blue-600">حسابك</span>}</td><td dir="ltr" className="text-right">{record.email}</td><td>{catalog?.roles[record.role] || record.role}</td><td><span className={`rounded-full px-3 py-1 text-xs ${record.status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'}`}>{record.status === 'active' ? 'نشط' : 'معطل'}</span></td><td className="py-3"><button type="button" className={button} disabled={!catalog || busy} onClick={() => open(record)}>إدارة الحساب</button></td></tr>)}</tbody></table>{rows?.data.length === 0 && <p className="p-5 text-slate-500">لا توجد حسابات مطابقة.</p>}</div>
    <div className="flex items-center gap-3"><button className={button} disabled={filters.page <= 1} onClick={() => setFilters(previous => ({ ...previous, page: previous.page - 1 }))}>السابق</button><span className="text-sm">{filters.page} / {rows?.last_page || 1}</span><button className={button} disabled={!rows || filters.page >= rows.last_page} onClick={() => setFilters(previous => ({ ...previous, page: previous.page + 1 }))}>التالي</button></div>
    {editor && catalog && <form onSubmit={submit} className="space-y-5 rounded-2xl border border-blue-200 p-5"><fieldset disabled={busy} className="min-w-0 space-y-5">
      <div className="flex items-center justify-between"><h2 className="text-lg font-bold">{editor.id ? `إدارة حساب ${editor.name}` : 'إنشاء حساب مستخدم'}</h2><button type="button" className={button} onClick={() => setEditor(null)}>إغلاق</button></div>
      <div className="grid gap-4 md:grid-cols-2">{field('name', 'اسم المستخدم')}{field('email', 'البريد الإلكتروني', 'email')}{field('phone', 'رقم التواصل (اختياري)', 'tel')}
        <label className="text-sm text-slate-600"><span className="mb-2 block">الدور</span><select className={input} aria-label="الدور" value={editor.role} disabled={editor.id === user.id} onChange={e => { const role = e.target.value; setEditor(previous => ({ ...previous, role })); setPermissions(catalog.defaults[role]) }}>{Object.entries(catalog.roles).map(([value, label]) => <option value={value} key={value}>{label}</option>)}</select></label>
        <label className="text-sm text-slate-600"><span className="mb-2 block">حالة الحساب</span><select className={input} aria-label="حالة الحساب" value={editor.status} disabled={editor.id === user.id} onChange={e => setEditor(previous => ({ ...previous, status: e.target.value }))}><option value="active">نشط</option><option value="inactive">معطل</option></select></label>
      </div>
      <p className="rounded-xl bg-slate-50 p-3 text-xs leading-6 text-slate-600">{editor.role === 'admin' ? 'مدير النظام يملك جميع الصلاحيات، بما فيها إدارة المستخدمين والحذف النهائي. لا يمكن تعطيل آخر مدير نشط.' : editor.role === 'hr' ? 'الموارد البشرية تطلع على التصفيات التي أنشأتها. غيّر الصلاحيات أدناه حسب مهام المستخدم.' : 'المراجع يطلع على التصفيات للمراجعة، ولا يمكنه تعديل القيم المحسوبة. يمكن تخصيص صلاحياته للمهام المالية.'} تغيير الدور يعيد اختيار صلاحياته الافتراضية؛ راجعها قبل الحفظ.</p>
      <section className="space-y-3"><div className="flex flex-wrap items-center justify-between gap-2"><h3 className="font-bold">الصلاحيات الفعلية</h3><button type="button" className={button} disabled={editor.role === 'admin'} onClick={() => setPermissions(catalog.defaults[editor.role])}>استعادة صلاحيات الدور</button></div>
        {Object.entries(catalog.groups).map(([group, items]) => <fieldset key={group} className="rounded-xl border border-slate-200 p-4"><legend className="px-2 text-sm font-semibold">{group}</legend><div className="grid gap-3 md:grid-cols-2">{Object.entries(items).map(([permission, label]) => <label key={permission} className="flex items-start gap-2 text-sm"><input type="checkbox" className="mt-0.5 h-4 w-4 shrink-0 accent-blue-600" checked={permissions.includes(permission)} disabled={editor.role === 'admin' || (editor.role === 'reviewer' && permission === 'adjust_transaction_values')} onChange={() => toggle(permission)} /><span>{label}</span></label>)}</div></fieldset>)}
      </section>
      <section className="space-y-3 rounded-xl bg-slate-50 p-4"><h3 className="font-semibold">{editor.id ? 'إعادة تعيين كلمة المرور (اختياري)' : 'بيانات الدخول'}</h3>{editor.id && <p className="text-xs text-slate-500">اترك الحقلين فارغين للاحتفاظ بكلمة المرور الحالية. تغيير كلمة المرور أو البريد أو الدور أو الصلاحيات ينهي الجلسات السابقة ويستلزم تسجيل الدخول مجددًا.</p>}<div className="grid gap-4 md:grid-cols-2">{field('password', editor.id ? 'كلمة مرور جديدة' : 'كلمة المرور', 'password')}{field('password_confirmation', 'تأكيد كلمة المرور', 'password')}</div></section>
      {field('reason', 'سبب إنشاء الحساب أو التغيير')}
      <div className="flex gap-3"><button className="rounded-xl bg-blue-600 px-5 py-3 text-sm font-bold text-white disabled:opacity-40" disabled={busy}>{busy ? 'جاري الحفظ…' : editor.id ? 'حفظ الحساب والصلاحيات' : 'إنشاء الحساب'}</button><button type="button" className={button} onClick={() => setEditor(null)}>إلغاء</button></div>
    </fieldset></form>}
    {editorId && <section className="space-y-3 rounded-2xl border border-slate-200 p-5"><h2 className="font-bold">سجل تغييرات الحساب</h2>{history?.data.map(entry => <details key={entry.id} className="rounded-xl bg-slate-50 p-3 text-sm"><summary className="cursor-pointer font-semibold">{actions[entry.action] || entry.action} · {entry.user?.name || 'النظام'} · {formatDateTime(entry.created_at)}</summary><p className="mt-2">السبب: {entry.new_values_json?.reason || '—'}</p><div className="mt-2 grid gap-3 md:grid-cols-2">{[['قبل', entry.old_values_json], ['بعد', entry.new_values_json?.user]].map(([label, values]) => values && <div key={label}><b>{label}</b><p>{values.name} · {values.email} · {catalog?.roles[values.role]} · {values.status === 'active' ? 'نشط' : 'معطل'}</p><p className="mt-1 text-xs">الصلاحيات: {(values.effective_permissions || []).map(permission => Object.values(catalog?.groups || {}).find(group => group[permission])?.[permission] || permission).join('، ')}</p></div>)}</div></details>)}{history?.data.length === 0 && <p className="text-sm text-slate-500">لا توجد تغييرات مسجلة لهذا الحساب حتى الآن.</p>}<div className="flex gap-3"><button className={button} disabled={historyPage <= 1} onClick={() => setHistoryPage(value => value - 1)}>الأقدم عرضًا</button><button className={button} disabled={!history || historyPage >= history.last_page} onClick={() => setHistoryPage(value => value + 1)}>سجلات أقدم</button></div></section>}
  </main>
}
