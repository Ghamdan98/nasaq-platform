import { formatDateTime } from '../utils/dates'
import { useEffect, useState } from 'react'
import { developerAudit, developerHealth, developerSettings, exportDeveloperSettings, resetDeveloperSettings, restoreDeveloperSettings, saveDeveloperSettings, uploadBranding } from '../services/api'
import { saveDownload } from '../utils/employees'
import { useSystemSettings } from '../system/SystemSettingsContext'
import { BrandLogo } from '../system/Brand'
import { brandingAsset } from '../system/settings'

const groups = { branding: 'الهوية والعلامة التجارية', appearance: 'الألوان والمظهر', display: 'العرض واللغة', reports: 'التقارير والطباعة', documents: 'ترقيم المستندات', health: 'حالة الخدمات', audit: 'سجل التغييرات', backup: 'نسخ الإعدادات واستعادتها' }
const options = { light: 'فاتح', dark: 'داكن', comfortable: 'مريح', compact: 'مكثف', 'ar-SA': 'عربية — السعودية', 'en-GB': 'English — تنسيق إنجليزي', arab: '١٢٣', latn: '123', portrait: 'عمودي', landscape: 'أفقي' }
export default function DeveloperSettingsPage({ user }) {
  const theme = useSystemSettings()
  const [data, setData] = useState(null)
  const [draft, setDraft] = useState({})
  const [tab, setTab] = useState('branding')
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [health, setHealth] = useState(null)
  const [audit, setAudit] = useState(null)
  const [auditPage, setAuditPage] = useState(1)
  const [restore, setRestore] = useState(null)
  const [confirmation, setConfirmation] = useState('')
  const authorized = user?.role === 'developer' && user.effective_permissions?.includes('manage-system-settings')
  const { cancelPreview } = theme
  useEffect(() => {
    if (!authorized) return
    let active = true
    developerSettings().then(result => { if (active) { setData(result); setDraft(result.settings) } }).catch(e => { if (active) setError(e.message) })
    return () => { active = false }
  }, [authorized])
  useEffect(() => () => cancelPreview(), [cancelPreview])
  useEffect(() => {
    if (!authorized || tab !== 'audit') return
    let active = true
    developerAudit(auditPage).then(result => { if (active) setAudit(result) }).catch(e => { if (active) setError(e.message) })
    return () => { active = false }
  }, [authorized, tab, auditPage, data?.revision])
  if (!authorized) return <div className="system-panel" role="alert">إعدادات المطور تحتاج حساب مطور مخول؛ دور المدير وحده لا يمنح الوصول.</div>
  const run = async callback => { setBusy(true); setError(''); setMessage(''); try { await callback() } catch (e) { setError(e.message) } finally { setBusy(false) } }
  const accept = response => { setData(previous => ({ ...previous, ...response })); setDraft(response.settings); theme.accept(response.settings); setReason(''); setConfirmation(''); setRestore(null); setMessage('تم حفظ الإعدادات وتطبيقها.'); }
  const update = (key, value) => setDraft(previous => ({ ...previous, [key]: value }))
  const renderField = (key, definition) => {
    const value = draft[key]
    const asset = key.startsWith('branding.') && ['logo','sidebar_logo','favicon','print_logo'].includes(key.split('.')[1])
    return <label key={key} className="system-label"><span>{definition.label}</span>{asset ? <span className="system-asset">{value && <img src={brandingAsset(value)} className="system-logo" alt={definition.label} />}<input aria-label={`رفع ${definition.label}`} type="file" accept="image/png,image/jpeg,image/webp" disabled={busy} onChange={event => { const file = event.target.files[0]; if (file) run(async () => { const result = await uploadBranding(file); update(key,result.id); setMessage('تم رفع الصورة للمعاينة. اضغط حفظ لاعتمادها.') }); event.target.value = '' }} /><button className="system-button" type="button" onClick={() => update(key,null)}>الشعار الافتراضي</button></span> : definition.type === 'boolean' ? <input type="checkbox" checked={Boolean(value)} onChange={event => update(key,event.target.checked)} /> : definition.options.length ? <select aria-label={definition.label} className="system-input" value={value} onChange={event => update(key,event.target.value)}>{definition.options.map(option => <option key={option} value={option}>{options[option] || option}</option>)}</select> : key.match(/^appearance\.(primary|secondary|accent|background|card|text|success|warning|error)$/) ? <span className="system-color"><input aria-label={definition.label} type="color" value={value} onChange={event => update(key,event.target.value)} /><input className="system-input" aria-label={`${definition.label} HEX`} dir="ltr" value={value} maxLength={7} onChange={event => update(key,event.target.value)} /></span> : <input className="system-input" type={definition.type === 'integer' ? 'number' : 'text'} value={value ?? ''} maxLength={500} onChange={event => update(key,definition.type === 'integer' ? Number(event.target.value) : event.target.value)} />}</label>
  }
  return <main className="system-stack"><header className="system-panel"><h1 className="text-2xl font-bold">إعدادات المطور</h1><p>هوية ومظهر وتشغيل النسخة من مصدر مركزي. الإعدادات المالية والأسرار غير قابلة للتغيير هنا.</p></header>
    {error && <p className="system-alert system-error" role="alert">{error}</p>}{message && <p className="system-alert" role="status">{message}</p>}
    <nav className="system-tabs" role="tablist" aria-label="أقسام إعدادات المطور">{Object.entries(groups).map(([key,label]) => <button key={key} role="tab" aria-selected={tab===key} className="system-button" disabled={busy} onClick={() => setTab(key)}>{label}</button>)}</nav>
    {!data ? <p className="system-panel">جاري تحميل الإعدادات…</p> : <><section className="system-panel system-stack" role="tabpanel"><h2 className="text-lg font-bold">{groups[tab]}</h2>
      {['branding','appearance','display','reports','documents'].includes(tab) && <div className="system-grid">{Object.entries(data.registry).filter(([,definition]) => definition.group===tab).map(([key,definition]) => renderField(key,definition))}</div>}
      {tab==='display' && <p className="system-alert">اللغة تضبط اتجاه المستند والتنسيق. نصوص الأعمال الحالية عربية. العملة SAR للعرض، والمنازل العشرية لا تغيّر الحسابات المالية.</p>}
      {tab==='health' && <><button className="system-button" disabled={busy} onClick={() => run(async () => setHealth(await developerHealth()))}>فحص حالة الخدمات</button>{health && <dl className="system-grid">{Object.entries(health).map(([key,value]) => <div className="system-preview" key={key}><dt>{({database:'قاعدة البيانات',storage:'التخزين',cache:'ذاكرة الإعدادات',mail:'البريد'})[key]}</dt><dd>{({connected:'متصل',not_connected:'غير متصل',log_only:'تسجيل محلي فقط',configured_not_tested:'مُعدّ — لم يُختبر إرسال فعلي'})[value] || value}</dd></div>)}</dl>}<p>لا يعرض هذا الفحص بيانات الاتصال أو المفاتيح، ولا يرسل بريدًا.</p></>}
      {tab==='audit' && <><div className="system-stack">{audit?.data.map(entry => <details className="system-preview" key={entry.id}><summary>{entry.user?.name || 'النظام'} — {formatDateTime(entry.created_at)} — {entry.action}</summary><p>{entry.new_values_json?.reason}</p>{Object.entries(entry.new_values_json?.settings || {}).map(([key,value]) => <p key={key}>{data.registry[key]?.label || key}: {String(entry.old_values_json?.[key] ?? '—')} ← {String(value)}</p>)}</details>)}</div><div className="system-actions"><button className="system-button" disabled={auditPage<=1} onClick={() => setAuditPage(value=>value-1)}>الأحدث</button><button className="system-button" disabled={!audit || auditPage>=audit.last_page} onClick={() => setAuditPage(value=>value+1)}>الأقدم</button></div></>}
      {tab==='backup' && <><button className="system-button" disabled={busy} onClick={() => run(async () => saveDownload(await exportDeveloperSettings()))}>تنزيل نسخة الإعدادات الآمنة</button><label className="system-label">استيراد نسخة JSON<input type="file" accept="application/json,.json" onChange={event => { const file=event.target.files[0]; if (file) run(async () => { if (file.size>1024*1024) throw new Error('حد حجم نسخة الإعدادات 1MB.'); const parsed=JSON.parse(await file.text()); if (parsed.schema!==1 || !parsed.settings || typeof parsed.settings!=='object') throw new Error('ملف الإعدادات غير صالح.'); setRestore(parsed); setMessage('تم تحميل النسخة؛ اكتب RESTORE وأدخل السبب ثم استعدها.'); }); event.target.value=''; }} /></label><p>نسخة الإعدادات لا تحتوي أسرارًا أو بيانات موظفين. مراجع الشعارات تخص هذه النسخة؛ النسخ الكامل للنظام يشمل ملفات الشعارات.</p>{restore && <label className="system-label">اكتب RESTORE لتأكيد الاستعادة<input className="system-input" value={confirmation} onChange={event=>setConfirmation(event.target.value)} /><button className="system-button" disabled={busy || confirmation!=='RESTORE' || reason.length<5} onClick={() => run(async () => accept(await restoreDeveloperSettings({schema:1,settings:restore.settings,revision:data.revision,confirmation,reason})))}>استعادة الإعدادات الآمنة</button></label>}</>}
    </section><section className="system-panel system-stack"><label className="system-label">سبب التعديل<input className="system-input" value={reason} onChange={event=>setReason(event.target.value)} minLength={5} maxLength={1000} /></label><div className="system-actions"><button className="system-button system-button-primary" disabled={busy || reason.length<5} onClick={() => run(async () => accept(await saveDeveloperSettings({settings:draft,revision:data.revision,reason})))}>حفظ التغييرات</button><button className="system-button" disabled={busy} onClick={() => { theme.preview(draft); setMessage('المعاينة مفعلة مؤقتًا؛ لم تُحفظ تغييراتك.') }}>معاينة</button><button className="system-button" disabled={busy} onClick={() => { setDraft(data.settings); theme.cancelPreview(); setMessage('أُلغيت التغييرات غير المحفوظة.') }}>إلغاء التغييرات</button><button className="system-button" disabled={busy} onClick={() => { setConfirmation(''); setTab('reset') }}>استعادة الإعدادات الافتراضية</button></div>{tab==='reset' && <div className="system-alert system-stack"><p>سيعاد الاسم والمظهر والإعدادات الآمنة إلى الافتراضي فقط. لن تُحذف بيانات التشغيل. اكتب RESET للتأكيد.</p><input aria-label="تأكيد استعادة الافتراضي" className="system-input" value={confirmation} onChange={event=>setConfirmation(event.target.value)} /><button className="system-button" disabled={busy || confirmation!=='RESET' || reason.length<5} onClick={() => run(async () => accept(await resetDeveloperSettings({revision:data.revision,reason,confirmation})))}>تأكيد استعادة الافتراضي</button></div>}</section>
      <aside className="system-preview system-stack"><h2 className="font-bold">معاينة الهوية الحالية</h2><BrandLogo /><strong>{theme.settings['branding.name_ar']}</strong><p>{theme.settings['branding.description']}</p><div className="system-actions"><button className="system-button system-button-primary">زر أساسي</button><span>الأرقام والتواريخ والتقارير تتبع الإعدادات المحفوظة.</span></div></aside>
    </>}
  </main>
}
