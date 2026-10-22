import { FlaskConical, Users } from 'lucide-react'

const modes = [
  { value: 'employee', title: 'موظف في النظام', description: 'اختر الموظف لاستخدام بياناته المعتمدة وإنشاء تصفية قابلة للحفظ والمراجعة.', icon: Users },
  { value: 'estimate', title: 'موظف غير مسجل — تصفية تقديرية', description: 'أدخل الراتب والتواريخ يدويًا لمعرفة المستحقات المتوقعة دون تسجيل موظف جديد.', icon: FlaskConical },
]

export default function CalculationModeCard({ value, onChange }) {
  return <section className="rounded-3xl border border-slate-200 bg-slate-50 p-5">
    <h3 className="mb-1 text-lg font-bold text-slate-900">لمن تريد احتساب التصفية؟</h3>
    <p className="mb-4 text-sm text-slate-500">اختر مصدر البيانات قبل البدء. يمكنك التبديل بين الخيارين مع الاحتفاظ بإدخالات كل خيار خلال هذه الجلسة.</p>
    <div role="radiogroup" aria-label="مصدر بيانات التصفية" className="grid gap-3 md:grid-cols-2">
      {modes.map(({ value: mode, title, description, icon: Icon }) => <label key={mode} className={`flex cursor-pointer items-start gap-3 rounded-2xl border p-4 transition ${value === mode ? 'border-blue-500 bg-white shadow-sm ring-2 ring-blue-100' : 'border-slate-200 bg-white hover:border-blue-300'}`}>
        <input type="radio" name="calculation-mode" value={mode} checked={value === mode} onChange={() => onChange(mode)} className="mt-1 h-4 w-4 shrink-0 accent-blue-600" />
        <Icon aria-hidden="true" className={`mt-0.5 h-5 w-5 shrink-0 ${value === mode ? 'text-blue-600' : 'text-slate-400'}`} />
        <span><span className="block text-sm font-bold text-slate-900">{title}</span><span className="mt-1 block text-xs leading-6 text-slate-500">{description}</span></span>
      </label>)}
    </div>
    {value === 'estimate' && <p className="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">وضع تقديري: النتائج للمعاينة فقط، ولا تُنشئ معاملة مالية أو تؤثر على أرصدة الموظفين والسلف.</p>}
  </section>
}

export function EstimateIdentity({ form, setForm }) {
  return <div className="grid gap-4 md:col-span-2 md:grid-cols-2 xl:col-span-4">
    {[
      ['estimate_name', 'اسم الموظف أو الحالة (اختياري)', 'مثال: تقدير مستحقات موظف جديد'],
      ['estimate_company', 'اسم الشركة (اختياري)', 'للتوضيح في النتيجة التقديرية'],
    ].map(([key, label, placeholder]) => <label key={key} className="text-sm font-medium text-slate-600"><span className="mb-2 block">{label}</span><input maxLength={150} value={form[key] || ''} onChange={event => setForm(previous => ({ ...previous, [key]: event.target.value }))} placeholder={placeholder} className="w-full rounded-2xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:border-blue-400 focus:ring-4 focus:ring-blue-100" /></label>)}
  </div>
}
