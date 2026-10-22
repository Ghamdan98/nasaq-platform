import { useCallback, useEffect, useRef, useState } from 'react'
import {
  AlertTriangle,
  BadgeCheck,
  CalendarDays,
  CircleDollarSign,
  FileCheck2,
  FileText,
  Info,
  ShieldAlert,
  ShieldCheck,
  Sparkles,
  Stethoscope,
} from 'lucide-react'
import TransactionTypeSelector from '../components/settlement/TransactionTypeSelector'
import EmployeePicker from '../components/settlement/EmployeePicker'
import CalculationModeCard, { EstimateIdentity } from '../components/settlement/CalculationModeCard'
import AdvanceDecisionCard from '../components/settlement/AdvanceDecisionCard'
import { calculateSettlement, listEmployees, saveHrTransaction } from '../services/api'
import { hasUserPermission } from '../utils/permissions'
import { useSystemSettings } from '../system/SystemSettingsContext'
import { formatCurrency, formatNumber } from '../utils/currency'
import { formatDate } from '../utils/dates'
import { leaveTransactionLabels, reviewLabels, statusBadgeClasses, terminationLabels, transactionLabels } from '../utils/labels'

const defaultForm = {
  calculation_mode: 'employee',
  financial_process_required: true,
  transaction_type: 'final_settlement',
  transaction_date: new Date().toISOString().slice(0, 10),
  employee_id: '',
  contract_id: '',
  termination_type: 'termination',
  hire_date: '2020-01-01',
  last_working_date: '2026-01-01',
  basic_salary: 12000,
  housing_allowance: 1500,
  transport_allowance: 800,
  fixed_allowances: 500,
  commissions: 0,
  other_allowances: 0,
  contract_type: 'indefinite',
  actual_notice_days: 30,
  unpaid_days: 0,
  salary_due_monthly_wage: '',
  remaining_leave_days: 0,
  leave_balance_method: 'date_accrual',
  deduct_settled_days_from_accrual_period: true,
  last_return_from_leave_date: '',
  last_settlement_date: '',
  contractual_annual_leave_days: '',
  employee_loan: 0,
  advance: 0,
  leave_transaction_type: 'termination_leave_compensation',
  opening_balance: 0,
  accrued_days: 0,
  used_days: 0,
  adjustment_days: 0,
  requested_leave_days: '',
  balance_source: 'calculated',
  additional_entitlements: [],
  additional_deductions: [],
}

function fieldClass(hasError) {
  return `w-full rounded-2xl border bg-white px-3 py-2.5 text-sm text-slate-700 outline-none transition ${
    hasError ? 'border-red-300 bg-red-50 ring-4 ring-red-100' : 'border-slate-200 focus:border-blue-400 focus:ring-4 focus:ring-blue-100'
  }`
}

function SectionCard({ title, subtitle, icon: Icon, children, action }) {
  return (
    <section className="rounded-[24px] border border-slate-200 bg-white p-5 shadow-sm">
      <div className="mb-5 flex items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <div className="flex h-10 w-10 items-center justify-center rounded-2xl bg-blue-50 text-blue-600">
            <Icon className="h-4 w-4" />
          </div>
          <div className="text-right">
            <h3 className="text-lg font-bold text-slate-900">{title}</h3>
            {subtitle ? <p className="text-xs text-slate-500">{subtitle}</p> : null}
          </div>
        </div>
        {action}
      </div>
      {children}
    </section>
  )
}

function StatPill({ label, value, tone = 'slate' }) {
  const toneMap = {
    slate: 'bg-slate-100 text-slate-700',
    blue: 'bg-blue-50 text-blue-700',
    emerald: 'bg-emerald-50 text-emerald-700',
    amber: 'bg-amber-50 text-amber-700',
    red: 'bg-red-50 text-red-700',
  }

  return (
    <div className={`rounded-full px-3 py-1.5 text-xs font-semibold ring-1 ring-inset ${toneMap[tone] || toneMap.slate}`}>
      <span className="ml-2 text-slate-500">{label}</span>
      {value}
    </div>
  )
}

function EmployeeSelect({ form, setForm, error }) {
  if (form.calculation_mode === 'estimate') return <EstimateIdentity form={form} setForm={setForm} />
  return <EmployeePicker form={form} setForm={setForm} error={error} initialEmployeeId={form.initial_employee_id} />
}

function EmployeeCard({ form, setForm, employees, loadingEmployees, errors = {} }) {
  return (
    <SectionCard title="بيانات الموظف" subtitle="هوية الموظف الأساسية" icon={FileText}>
      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <EmployeeSelect form={form} setForm={setForm} employees={employees} loadingEmployees={loadingEmployees} error={errors.employee_id} />


</div>
    </SectionCard>
  )
}

function ContractCard({ form, setForm, employees, errors = {} }) {
  const contracts = form.employee_profile?.contracts || employees.find(employee => String(employee.id) === String(form.employee_id))?.contracts || []
  return (
    <SectionCard title="بيانات العقد" subtitle="معلومات التوظيف الأساسية" icon={BadgeCheck}>
      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        {form.calculation_mode !== 'estimate' && <label className="text-right text-sm font-medium text-slate-600">
          <span className="mb-2 block">العقد</span>
          <select aria-label="العقد" value={form.contract_id} disabled={!form.employee_id} onChange={event => setForm({ ...form, contract_id: event.target.value })} className={fieldClass(false)}>
            <option value="">دون ربط بعقد</option>
            {contracts.map(contract => <option key={contract.id} value={contract.id}>{contract.id} — {contract.start_date} — {contract.contract_type}</option>)}
          </select>
        </label>}

        <label className="text-right text-sm font-medium text-slate-600">
          <span className="mb-2 block">نوع العقد</span>
          <select value={form.contract_type} onChange={(event) => setForm({ ...form, contract_type: event.target.value })} className={fieldClass(false)}>
            <option value="indefinite">غير محدد المدة</option>
            <option value="fixed">محدد المدة</option>
          </select>
        </label>

        <label className="text-right text-sm font-medium text-slate-600">
          <span className="mb-2 block">تاريخ المباشرة</span>
          <input aria-invalid={Boolean(errors.hire_date)} disabled={form.employee_profile?.basic_salary != null} type="date" value={form.hire_date} onChange={(event) => setForm({ ...form, hire_date: event.target.value })} className={fieldClass(Boolean(errors.hire_date))} />
          {errors.hire_date && <span role="alert" className="mt-1 block text-xs text-red-700">{errors.hire_date.join(' ')}</span>}
        </label>

</div>
    </SectionCard>
  )
}

function ServicePeriodCard({ result }) {
  const service = result?.service_period || {}

  return (
    <SectionCard title="مدة الخدمة" subtitle="المخرجات الرسمية من Backend" icon={Sparkles}>
      <div className="flex flex-wrap items-center gap-3">
        <StatPill label="سنوات" value={service.years ?? 0} tone="blue" />
        <StatPill label="أشهر" value={service.months ?? 0} tone="slate" />
        <StatPill label="أيام" value={service.days ?? 0} tone="amber" />
      </div>
      <div className="mt-4 rounded-2xl bg-slate-50 p-4 text-right text-sm text-slate-600">
        <div className="font-semibold text-slate-900">
          {service.years ?? 0} سنوات | {service.months ?? 0} أشهر | {service.days ?? 0} يوماً
        </div>
        <div className="mt-1">القيم تُسترجع مباشرة من Backend ولا تُعاد حسابها في الواجهة.</div>
      </div>
    </SectionCard>
  )
}

function LeaveAccrualFields({ form, setForm, result, isLeave = false, errors = {} }) {
  const balance = result?.leave_calculation?.accrual_details || result?.accrual_details
  const period = balance?.accrual_details
  const automatic = form.leave_balance_method === 'date_accrual'
  return (
    <SectionCard title="احتساب رصيد الإجازة" subtitle="الاستحقاق من آخر مباشرة بعد الإجازة السابقة" icon={CalendarDays}>
      <label className="block text-sm font-medium text-slate-600">
        طريقة حساب رصيد الإجازة
        <select aria-label="طريقة حساب رصيد الإجازة" value={form.leave_balance_method} onChange={event => setForm({ ...form, leave_balance_method: event.target.value, last_return_from_leave_date: '', last_settlement_date: '' })} className={fieldClass(false)}>
          <option value="date_accrual">تلقائي حسب التواريخ</option>
          <option value="manual">رصيد مسجل مسبقاً</option>
        </select>
      </label>
      {isLeave && !automatic && (errors.last_working_date || form.last_return_from_leave_date || form.last_settlement_date) && <label className="mt-4 block text-sm font-medium text-slate-600"><span className="mb-2 block">تاريخ آخر يوم عمل</span><input aria-label="تاريخ آخر يوم عمل" aria-invalid={Boolean(errors.last_working_date)} required type="date" value={form.last_working_date} onChange={event => setForm({ ...form, last_working_date: event.target.value })} className={fieldClass(Boolean(errors.last_working_date))} />{errors.last_working_date && <span role="alert" className="mt-1 block text-xs text-red-700">{errors.last_working_date.join(' ')}</span>}</label>}
      {automatic && <>
        <div className="mt-4 grid gap-4 md:grid-cols-2">
          {(isLeave ? [['hire_date', 'تاريخ المباشرة'], ['last_return_from_leave_date', 'تاريخ آخر مباشرة بعد الإجازة السابقة (اختياري)'], ['last_settlement_date', 'آخر يوم تمت تسويته (اختياري)'], ['last_working_date', 'تاريخ آخر يوم عمل']] : [['last_return_from_leave_date', 'تاريخ آخر مباشرة بعد الإجازة السابقة (اختياري)'], ['last_settlement_date', 'آخر يوم تمت تسويته (اختياري)']]).map(([key, label]) => (
            <label key={key} className="text-sm font-medium text-slate-600"><span className="mb-2 block">{label}{(key === 'hire_date' || key === 'last_working_date') && <span className="mr-1 text-red-600" aria-hidden="true">*</span>}</span><input aria-label={label} aria-invalid={Boolean(errors[key])} required={key === 'hire_date' || key === 'last_working_date'} disabled={form.employee_profile?.basic_salary != null && (key === 'hire_date' || key === 'last_settlement_date')} type="date" value={form[key]} onChange={event => setForm({ ...form, [key]: event.target.value })} className={fieldClass(Boolean(errors[key]))} />{errors[key] && <span role="alert" className="mt-1 block text-xs text-red-700">{errors[key].join(' ')}</span>}</label>
          ))}
          <label className="text-sm font-medium text-slate-600"><span className="mb-2 block">الاستحقاق السنوي بالعقد إن كان أعلى (اختياري)</span><input aria-label="الاستحقاق السنوي بالعقد" type="number" min="21" max="365" step="0.01" value={form.contractual_annual_leave_days} onChange={event => setForm({ ...form, contractual_annual_leave_days: event.target.value })} className={fieldClass(false)} /></label>
        </div>
        <p className="mt-3 text-xs text-slate-600">21 يوماً سنوياً قبل إكمال خمس سنوات خدمة متصلة، و30 يوماً بعدها، أو الاستحقاق التعاقدي الأعلى. أساس الاستحقاق 360 يوماً (30/360). عند إدخال آخر يوم تمت تسويته يبدأ الاستحقاق من اليوم الميلادي التالي؛ وإلا يبدأ من تاريخ آخر مباشرة بعد الإجازة، أو من تاريخ المباشرة إن تُرك فارغاً. لا يُضاف يوم البداية إلى فرق التاريخين.</p>
        <label className="mt-4 block text-sm font-medium text-slate-600">
          <span className="mb-2 block">أيام سبق تسويتها (تُخصم من الرصيد وبدل الإجازة)</span>
          <input type="number" min="0" step="0.01" aria-label="أيام سبق تسويتها" value={form.previously_settled_days ?? 0} onChange={(event) => setForm({ ...form, previously_settled_days: Number(event.target.value) })} className={fieldClass(false)} />
        </label>
        <label className="mt-3 flex cursor-pointer items-center gap-3 rounded-xl border border-blue-200 bg-blue-50 p-3 text-sm font-medium text-slate-700">
          <input type="checkbox" className="h-5 w-5 shrink-0 cursor-pointer accent-blue-600" checked={form.deduct_settled_days_from_accrual_period ?? false} onChange={event => setForm({ ...form, deduct_settled_days_from_accrual_period: event.target.checked })} />
          خصم أيام سبق تسويتها من مدة احتساب استحقاق الإجازة أيضًا
        </label>
        <p className="mt-2 text-xs text-slate-500">عند التفعيل: (360 − يومين) ÷ 360 × 21 − يومين = 18.8833 يومًا. عند الإلغاء: 21 − يومين = 19 يومًا. يؤثر الخيار على استحقاق الإجازة فقط. إذا اختلف الاستحقاق السنوي خلال الفترة، يوزّع خصم المدة بالتناسب بين الفترات. لا تُكرر أيامًا خُصمت مسبقًا.</p>
        {balance && <div className="mt-4 rounded-xl bg-blue-50 p-3 text-sm">
          <p>أساس الحساب: <strong>{period?.year_days ?? 360} يوماً ({period?.day_count_convention ?? '30E/360 ISDA'})</strong></p>
          <p>الفترة المحسوبة: {period?.period_from} إلى {period?.period_to}</p>
          <p>أيام الاستحقاق المحاسبية: <strong>{formatNumber(period?.service_days)}</strong></p>
          <p>الأيام المستبعدة من مدة الاستحقاق: <strong>{formatNumber(period?.excluded_service_days ?? 0)}</strong>؛ المدة المحتسبة بعد الخصم: <strong>{formatNumber(period?.eligible_service_days ?? period?.service_days)}</strong> يوم</p>
          <p>الرصيد المكتسب قبل الخصومات: <strong>{formatNumber(balance.accrued_days)}</strong> يوم</p>
          <p>الرصيد النهائي بعد الترحيل والاستخدام والتسوية: <strong>{formatNumber(balance.available_days)}</strong> يوم</p>
          {period?.segments?.map((segment, index) => <p key={index}>فترة {segment.annual_entitlement} يوم سنوياً، من {segment.from} إلى {segment.to}: {formatNumber(segment.eligible_service_days ?? segment.service_days)} ÷ {segment.year_days} × {formatNumber(segment.annual_entitlement)} = {formatNumber(segment.accrued_days)} يوم</p>)}
        </div>}
      </>}
    </SectionCard>
  )
}

function TerminationDetailsCard({ form, setForm, isLeave, errors = {} }) {
  if (isLeave) return null

  return (
    <SectionCard title="تفاصيل انتهاء العلاقة" subtitle="معلومات سبب الفصل أو الاستقالة" icon={FileCheck2}>
      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <label className="text-right text-sm font-medium text-slate-600">
          <span className="mb-2 block">سبب انتهاء العلاقة</span>
          <select value={form.termination_type} onChange={(event) => setForm({ ...form, termination_type: event.target.value })} className={fieldClass(false)}>
            {Object.entries(terminationLabels).map(([value, label]) => (
              <option key={value} value={value}>{label}</option>
            ))}
          </select>
        </label>

        <label className="text-right text-sm font-medium text-slate-600">
          <span className="mb-2 block">تاريخ آخر يوم عمل</span>
          <input aria-invalid={Boolean(errors.last_working_date)} required type="date" value={form.last_working_date} onChange={(event) => setForm({ ...form, last_working_date: event.target.value })} className={fieldClass(Boolean(errors.last_working_date))} />
          {errors.last_working_date && <span role="alert" className="mt-1 block text-xs text-red-700">{errors.last_working_date.join(' ')}</span>}
        </label>

        <label className="text-right text-sm font-medium text-slate-600">
          <span className="mb-2 block">مدة الإشعار</span>
          <input type="number" value={form.actual_notice_days} onChange={(event) => setForm({ ...form, actual_notice_days: Number(event.target.value) })} className={fieldClass(false)} />
        </label>

        <div className="rounded-2xl bg-slate-50 p-3 text-right text-sm text-slate-600">
          <div className="font-semibold text-slate-800">الحالة الحالية</div>
          <div className="mt-2">{form.termination_type === 'resignation' ? 'تستند الاستقالة إلى معاملتها القانونية' : 'تتطلب المعلومة مراجعة نظامية عند الضرورة'}</div>
        </div>
      </div>
    </SectionCard>
  )
}

function SalaryCard({ form, setForm, result }) {
  const salaryAmounts = Object.fromEntries(
    ['basic_salary', 'housing_allowance', 'transport_allowance', 'fixed_allowances', 'commissions', 'other_allowances']
      .map(key => [key, Number(form[key] || 0)]),
  )
  for (const component of form.salary_components || []) {
    salaryAmounts[component.code || component.name || 'custom_component'] = Number(component.amount || 0)
  }
  const grossSalary = result?.salary_base?.gross_monthly_wage
    ?? form.employee_profile?.total_salary
    ?? Object.values(salaryAmounts).reduce((total, amount) => total + amount, 0)
  return (
    <SectionCard title="الراتب" subtitle="الأجر المعتمد للحساب" icon={CircleDollarSign}>
      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
        <label className="text-right text-sm font-medium text-slate-600">
          <span className="mb-2 block">الراتب الأساسي</span>
          <input disabled={form.employee_profile?.basic_salary != null} type="number" step="0.01" value={form.basic_salary} onChange={(event) => setForm({ ...form, basic_salary: Number(event.target.value) })} className={fieldClass(false)} />
        </label>
        <label className="text-right text-sm font-medium text-slate-600">
          <span className="mb-2 block">بدل السكن</span>
          <input disabled={form.employee_profile?.basic_salary != null} type="number" step="0.01" value={form.housing_allowance} onChange={(event) => setForm({ ...form, housing_allowance: Number(event.target.value) })} className={fieldClass(false)} />
        </label>
        <label className="text-right text-sm font-medium text-slate-600">
          <span className="mb-2 block">بدل النقل</span>
          <input disabled={form.employee_profile?.basic_salary != null} type="number" step="0.01" value={form.transport_allowance} onChange={(event) => setForm({ ...form, transport_allowance: Number(event.target.value) })} className={fieldClass(false)} />
        </label>
        <label className="text-right text-sm font-medium text-slate-600">
          <span className="mb-2 block">بدلات أخرى</span>
          <input disabled={form.employee_profile?.basic_salary != null} type="number" step="0.01" value={form.fixed_allowances} onChange={(event) => setForm({ ...form, fixed_allowances: Number(event.target.value) })} className={fieldClass(false)} />
        </label>
        <label className="text-right text-sm font-medium text-slate-600">
          <span className="mb-2 block">عمولات</span>
          <input disabled={form.employee_profile?.basic_salary != null} type="number" step="0.01" value={form.commissions} onChange={(event) => setForm({ ...form, commissions: Number(event.target.value) })} className={fieldClass(false)} />
        </label>
      </div>

      <div className="mt-5 rounded-2xl border border-blue-100 bg-gradient-to-r from-blue-50 to-indigo-50 p-4">
        <div className="flex items-center justify-between gap-3">
          <div className="text-right">
            <p className="text-xs font-semibold text-blue-600">إجمالي الراتب شاملًا البدلات</p>
            <h4 className="mt-1 text-2xl font-black text-slate-900">{formatCurrency(grossSalary)}</h4>
          </div>
          <div className="rounded-2xl bg-white px-3 py-2 text-right shadow-sm">
            <div className="text-[10px] font-semibold tracking-[0.18em] text-slate-500">الأجر المعتمد</div>
            <div className="mt-1 text-lg font-bold text-slate-900">{formatCurrency(grossSalary)}</div>
          </div>
        </div>
      </div>
      <div className="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        {[
          ['unpaid_days', 'أيام الراتب غير المدفوع'],
          ...(form.leave_balance_method === 'manual' ? [['remaining_leave_days', 'رصيد الإجازة عند الإنهاء']] : []),
          ['employee_loan', 'قرض الموظف'],
          ['advance', 'السلفة'],
        ].filter(([key]) => !form.employee_id || !['employee_loan', 'advance'].includes(key)).map(([key, label]) => (
          <label key={key} className="text-sm font-medium text-slate-600">
            <span className="mb-2 block">{label}</span>
            <input type="number" min="0" step={key === 'unpaid_days' ? '1' : '0.01'} value={form[key] ?? 0} onChange={event => setForm({ ...form, [key]: Number(event.target.value) })} className={fieldClass(false)} />
          </label>
        ))}
      </div>
    </SectionCard>
  )
}

function AdditionalFinancialItems({ form, setForm, errors }) {
  const sections = [
    ['additional_entitlements', 'مكافآت ومستحقات إضافية', 'مستحق', 'إضافة مكافأة / مستحق'],
    ['additional_deductions', 'خصومات إضافية', 'خصم', 'إضافة خصم'],
  ]
  return (
    <SectionCard title="مكافآت وخصومات أخرى" subtitle="بنود مستقلة تضاف إلى التصفية أو تخصم منها" icon={CircleDollarSign}>
      <p className="mb-4 text-sm text-slate-600">أدخل اسم كل بند ومبلغه وسببه. هذه البنود لا تغيّر أساس الراتب المستخدم لمكافأة نهاية الخدمة أو الإجازة. لا تكرر القرض أو السلفة هنا إذا أدخلتهما في خاناتهما.</p>
      <div className="space-y-5">
        {sections.map(([key, title, singular, buttonLabel]) => (
          <section key={key} className="space-y-3 rounded-xl border border-slate-200 p-4">
            <h3 className="font-bold">{title}</h3>
            {(form[key] || []).map((item, index) => {
              const change = (field, value) => setForm({ ...form, [key]: form[key].map((row, rowIndex) => rowIndex === index ? { ...row, [field]: value } : row) })
              return <div key={index} className="grid gap-3 rounded-xl bg-slate-50 p-3 md:grid-cols-3">
                {[
                  ['name', `اسم ${singular} ${index + 1}`, 'text'],
                  ['amount', `مبلغ ${singular} ${index + 1}`, 'number'],
                  ['reason', `سبب ${singular} ${index + 1}`, 'text'],
                ].map(([field, label, type]) => (
                  <label key={field} className="text-sm text-slate-600"><span className="mb-1 block">{label}</span>
                    <input aria-label={label} required type={type} {...(type === 'number' ? { min: '0.01', step: '0.01', max: '99999999.99' } : { maxLength: field === 'name' ? 200 : 1000 })} value={item[field]} onChange={event => change(field, event.target.value)} className={fieldClass(Boolean(errors[`${key}.${index}.${field}`]))} />
                    {errors[`${key}.${index}.${field}`] && <span role="alert" className="block text-xs text-red-700">{errors[`${key}.${index}.${field}`].join(' ')}</span>}
                  </label>
                ))}
                <button type="button" aria-label={`حذف ${singular} ${index + 1}`} onClick={() => setForm({ ...form, [key]: form[key].filter((_, rowIndex) => rowIndex !== index) })} className="justify-self-start rounded-lg border border-red-200 px-3 py-2 text-sm text-red-700">حذف البند</button>
              </div>
            })}
            <button type="button" disabled={(form[key] || []).length >= 50} onClick={() => setForm({ ...form, [key]: [...(form[key] || []), { name: '', amount: '', reason: '' }] })} className="rounded-xl border border-blue-200 bg-blue-50 px-4 py-2 text-sm font-bold text-blue-700">{buttonLabel}</button>
          </section>
        ))}
      </div>
    </SectionCard>
  )
}

function EntitlementsTable({ result = { entitlements: [] }, onWhyClick }) {
  const additionalRows = Array.isArray(result.entitlements) ? result.entitlements : []
  const rows = result.gross_leave_amount != null ? [{
    code: 'leave_pay', name_ar: 'أجر الإجازة', amount: result.gross_leave_amount,
    quantity: result.payable_days ?? result.requested_leave_days ?? result.days_to_compensate,
    rate: result.daily_rate, status: result.status, legal_references: result.legal_references,
  }, ...additionalRows] : additionalRows

  return (
    <SectionCard title="المستحقات" subtitle="جدول احترافي للاستحقاقات" icon={FileText}>
      <div className="overflow-x-auto">
        <table className="min-w-full text-right text-sm">
          <thead>
            <tr className="border-b border-slate-200 text-slate-500">
              <th className="px-3 py-3 font-semibold">#</th>
              <th className="px-3 py-3 font-semibold">البند</th>
              <th className="px-3 py-3 font-semibold">الكمية</th>
              <th className="px-3 py-3 font-semibold">طريقة الحساب</th>
              <th className="px-3 py-3 font-semibold">المبلغ</th>
              <th className="px-3 py-3 font-semibold">الحالة</th>
              <th className="px-3 py-3 font-semibold">السبب</th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 ? (
              <tr>
                <td colSpan={7} className="px-3 py-8 text-center text-slate-500">لا توجد مستحقات حتى الآن.</td>
              </tr>
            ) : (
              rows.map((row, index) => (
                <tr key={`${row.code || index}-${index}`} className="border-b border-slate-100 align-top">
                  <td className="px-3 py-3 text-slate-500">{index + 1}</td>
                  <td className="px-3 py-3 font-medium text-slate-800">{row.name_ar || row.title || row.label || 'مستحق'}</td>
                  <td className="px-3 py-3 text-slate-600">{row.quantity ?? '-'}</td>
                  <td className="px-3 py-3 text-slate-600">{row.method || row.calculation || row.type || 'محسوب من Backend'}</td>
                  <td className="px-3 py-3 font-semibold text-slate-900">{formatCurrency(row.amount ?? 0)}</td>
                  <td className="px-3 py-3">
                    <span className={`inline-flex rounded-full px-2 py-1 text-[11px] font-bold ring-1 ring-inset ${statusBadgeClasses[row.status] || 'bg-slate-100 text-slate-700 ring-slate-200'}`}>
                      {['valid', 'entitled'].includes(row.status) ? 'مستحق' : ['warning', 'requires_legal_review'].includes(row.status) ? 'تحتاج مراجعة' : row.status === 'blocked' ? 'غير مستحق' : 'لا ينطبق'}
                    </span>
                  </td>
                  <td className="px-3 py-3">
                    <button type="button" onClick={() => onWhyClick(row)} className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700 transition hover:bg-slate-200">
                      لماذا؟
                    </button>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </SectionCard>
  )
}

function DeductionsTable({ result = { deductions: [] } }) {
  const rows = Array.isArray(result.deductions) ? result.deductions : []

  return (
    <SectionCard title="الخصومات" subtitle="الإجمالي المخصوم من المستحقات" icon={ShieldAlert}>
      <div className="overflow-x-auto">
        <table className="min-w-full text-right text-sm">
          <thead>
            <tr className="border-b border-slate-200 text-slate-500">
              <th className="px-3 py-3 font-semibold">#</th>
              <th className="px-3 py-3 font-semibold">البند</th>
              <th className="px-3 py-3 font-semibold">الكمية</th>
              <th className="px-3 py-3 font-semibold">طريقة الحساب</th>
              <th className="px-3 py-3 font-semibold">المبلغ</th>
              <th className="px-3 py-3 font-semibold">الحالة</th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 ? (
              <tr>
                <td colSpan={6} className="px-3 py-8 text-center text-slate-500">لا توجد خصومات.</td>
              </tr>
            ) : (
              rows.map((row, index) => (
                <tr key={`${row.code || 'deduction'}-${index}`} className="border-b border-slate-100">
                  <td className="px-3 py-3 text-slate-500">{index + 1}</td>
                  <td className="px-3 py-3 font-medium text-slate-800">{row.name_ar || row.description || row.title || row.label || 'خصم'}</td>
                  <td className="px-3 py-3 text-slate-600">{row.quantity ?? '-'}</td>
                  <td className="px-3 py-3 text-slate-600">{row.reason || row.method || row.calculation || 'محسوب من Backend'}</td>
                  <td className="px-3 py-3 font-semibold text-slate-900">{formatCurrency(row.amount ?? 0)}</td>
                  <td className="px-3 py-3">
                    <span className={`inline-flex rounded-full px-2 py-1 text-[11px] font-bold ring-1 ring-inset ${statusBadgeClasses[row.status] || 'bg-slate-100 text-slate-700 ring-slate-200'}`}>
                      {row.status === 'valid' ? 'مستحق' : row.status === 'warning' ? 'تحتاج مراجعة' : 'لا ينطبق'}
                    </span>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
      <div className="mt-5 flex items-center justify-between rounded-2xl bg-slate-100 px-4 py-3 text-sm font-bold text-slate-700">
        <span>إجمالي الخصومات</span>
        <span>{formatCurrency(result.total_deductions ?? 0)}</span>
      </div>
    </SectionCard>
  )
}

function SettlementSummary({ result }) {
  return (
    <SectionCard title="نتيجة المخالصة" subtitle="ملخص مالي نهائي" icon={CircleDollarSign}>
      <div className="grid gap-4 lg:grid-cols-3">
        <div className="rounded-2xl bg-slate-50 p-4">
          <div className="text-xs font-semibold tracking-[0.18em] text-slate-500">إجمالي المستحقات</div>
          <div className="mt-3 text-2xl font-black text-slate-800">{formatCurrency(result?.gross_entitlements ?? result?.gross_leave_amount ?? 0)}</div>
        </div>
        <div className="rounded-2xl bg-slate-50 p-4">
          <div className="text-xs font-semibold tracking-[0.18em] text-slate-500">إجمالي الخصومات</div>
          <div className="mt-3 text-2xl font-black text-slate-800">{formatCurrency(result?.total_deductions ?? 0)}</div>
        </div>
        <div className="rounded-2xl bg-gradient-to-br from-blue-600 to-indigo-700 p-4 text-white shadow-lg shadow-blue-700/20">
          <div className="text-xs font-semibold tracking-[0.18em] text-blue-100">صافي مستحق الموظف</div>
          <div className="mt-3 text-3xl font-black">{formatCurrency(result?.net_settlement ?? result?.gross_leave_amount ?? 0)}</div>
        </div>
      </div>
    </SectionCard>
  )
}

function LegalReviewCard({ result }) {
  const reviewStatus = result?.review_status || 'valid'
  const statusClass = statusBadgeClasses[reviewStatus] || 'bg-slate-100 text-slate-700 ring-slate-200'

  return (
    <SectionCard title="التقييم النظامي" subtitle="حالة المعاملة حسب القواعد" icon={Stethoscope}>
      <div className="flex flex-wrap items-center gap-3">
        <span className={`inline-flex rounded-full px-3 py-1.5 text-sm font-bold ring-1 ring-inset ${statusClass}`}>
          {reviewLabels[reviewStatus] || reviewStatus}
        </span>
        <span className="text-sm text-slate-500">{result?.status || 'valid'}</span>
      </div>

      {Array.isArray(result?.warnings) && result.warnings.length > 0 ? (
        <ul className="mt-5 space-y-3">
          {result.warnings.map((warning, index) => (
            <li key={`${warning.code || 'warning'}-${index}`} className="flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-3 text-right text-sm text-amber-800">
              <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
              <div>
                <div className="font-bold">{warning.code || 'تحذير'}</div>
                <div>{warning.message_ar || warning.message || 'توجد ملاحظة تحتاج مراجعة.'}</div>
              </div>
            </li>
          ))}
        </ul>
      ) : (
        <div className="mt-5 flex items-center gap-2 rounded-2xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">
          <ShieldCheck className="h-4 w-4" />
          لا توجد ملاحظات قانونية نشطة في هذه المعاملة.
        </div>
      )}
    </SectionCard>
  )
}

function LegalReferencesCard({ result }) {
  const refs = Array.isArray(result?.legal_references) ? result.legal_references.filter(Boolean) : []

  return (
    <SectionCard title="السند النظامي" subtitle="المواد القانونية المرتبطة بالمعاملة" icon={ShieldCheck}>
      <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        {refs.map((ref, index) => (
          <button
            key={`${ref.article || 'ref'}-${index}`}
            type="button"
            className="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-right transition hover:border-blue-200 hover:bg-blue-50"
          >
            <div className="text-xs font-bold tracking-[0.2em] text-blue-600">{ref.article_number || ref.article || ref.number || ref.rule_code || '—'}</div>
            <div className="mt-2 text-base font-bold text-slate-900">{ref.title || ref.label || 'مادة نظامية'}</div>
            <div className="mt-2 text-sm text-slate-600">{ref.explanation_ar || ref.summary || ref.description || 'لا يوجد وصف متوفر'}</div>
            <div className="mt-3 text-[11px] text-slate-500">{ref.effective_date ? `تاريخ السريان: ${formatDate(ref.effective_date)}` : 'تاريخ السريان: غير محدد'}</div>
            <div className="mt-1 text-[11px] text-slate-500">{ref.source_name || ref.source || '—'}</div>
          </button>
        ))}
      </div>
    </SectionCard>
  )
}

function SalaryDueForm({ form, setForm, result, errors }) {
  return (
    <SectionCard title="راتب أيام شهر التصفية" subtitle="أدخل الأيام التي لم يصرف راتبها لإضافتها إلى التصفية" icon={CircleDollarSign}>
      <div className="grid gap-4 md:grid-cols-3">
        <label className="text-sm font-medium text-slate-600"><span className="mb-2 block">أيام الراتب المستحقة</span>
          <input aria-label="أيام الراتب المستحقة" type="number" min="0" max="31" step="1" value={form.unpaid_days} onChange={event => setForm({ ...form, unpaid_days: event.target.value })} className={fieldClass(Boolean(errors.unpaid_days))} />
          {errors.unpaid_days && <span role="alert" className="block text-xs text-red-700">{errors.unpaid_days.join(' ')}</span>}
        </label>
        <label className="text-sm font-medium text-slate-600"><span className="mb-2 block">الراتب الشهري لحساب الأيام (اختياري)</span>
          <input aria-label="الراتب الشهري لحساب الأيام" type="number" min="0" step="0.01" value={form.salary_due_monthly_wage} onChange={event => setForm({ ...form, salary_due_monthly_wage: event.target.value })} className={fieldClass(Boolean(errors.salary_due_monthly_wage))} />
          <span className="mt-1 block text-xs">إذا ترك فارغاً يستخدم الأجر المدخل لتصفية الإجازة.</span>
        </label>
        <div className="rounded-xl bg-blue-50 p-3 text-sm"><span className="block">قيمة الراتب المستحق</span><strong className="mt-2 block text-lg">{result?.salary_due ? formatCurrency(result.salary_due.net_due) : '—'}</strong></div>
      </div>
      <p className="mt-3 text-xs text-slate-600">تُعبّأ الأيام تلقائيًا عند تغيير تاريخ آخر يوم عمل: يوم 15 يملأ 15 يومًا. يمكنك تعديل العدد يدويًا؛ وإذا غيّرت التاريخ مجددًا تُعبّأ الأيام حسب التاريخ الجديد. إذا صُرف راتب الشهر بالكامل، اجعل الأيام صفرًا. لا تكرر راتب هذه الأيام في المستحقات الإضافية.</p>
    </SectionCard>
  )
}

function LeaveSettlementForm({ form, setForm, result, employees, loadingEmployees, errors = {} }) {
  const fullCompensation = form.leave_transaction_type === 'termination_leave_compensation' && form.leave_balance_method === 'date_accrual'
  const hasEmployeeSalary = form.employee_profile?.basic_salary != null
  const employeeLeaveWage = form.salary_components?.length
    ? form.salary_components.reduce((total, component) => total + (component.include_in_leave === false ? 0 : Number(component.amount || 0)), 0)
    : form.employee_profile?.total_salary
  const estimate = form.calculation_mode === 'estimate'
  const manualGrossWage = ['basic_salary', 'housing_allowance', 'transport_allowance', 'fixed_allowances', 'commissions', 'other_allowances'].reduce((total, key) => total + Number(form[key] || 0), 0)
  const displayedWage = result?.calculation_wage ?? (hasEmployeeSalary ? employeeLeaveWage : estimate ? manualGrossWage : form.basic_salary)
  return (
    <>
      <SectionCard title="بيانات الموظف" subtitle="معلومات الموظف الأساسية" icon={FileText}>
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
          <EmployeeSelect form={form} setForm={setForm} employees={employees} loadingEmployees={loadingEmployees} error={errors.employee_id} />
          <label className="text-right text-sm font-medium text-slate-600">
            <span className="mb-2 block">نوع المعاملة</span>
            <select value={form.leave_transaction_type} onChange={(event) => setForm({ ...form, leave_transaction_type: event.target.value })} className={fieldClass(false)}>
              {Object.entries(leaveTransactionLabels).map(([value, label]) => (
                <option key={value} value={value}>{label}</option>
              ))}
            </select>
          </label>
        </div>
      </SectionCard>

      {estimate && <SalaryCard form={form} setForm={setForm} result={result} />}
      <SalaryDueForm form={form} setForm={setForm} result={result} errors={errors} />
      <LeaveAccrualFields form={form} setForm={setForm} result={result} isLeave errors={errors} />
      {fullCompensation && <p className="rounded-xl bg-blue-50 p-4 text-sm text-blue-800">تعويض كامل الرصيد المتاح عند انتهاء الخدمة. لا تُخصم خمسة أيام تلقائياً؛ تُحتسب قيمة كامل الرصيد ويصبح المتبقي صفراً بعد التعويض.</p>}
      <SectionCard title="بيانات الرصيد" subtitle="إدارة إجازة الموظف" icon={CalendarDays}>
        <label className="mb-4 block text-sm font-medium text-slate-600">
          الرصيد المتبقي
          <input readOnly type="number" value={result?.remaining_balance ?? ''} className={fieldClass(false)} />
        </label>
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
          <label className="text-right text-sm font-medium text-slate-600">
            <span className="mb-2 block">الرصيد الافتتاحي</span>
            <input type="number" step="0.01" value={form.opening_balance} onChange={(event) => setForm({ ...form, opening_balance: Number(event.target.value) })} className={fieldClass(false)} />
          </label>
          <label className="text-right text-sm font-medium text-slate-600">
            <span className="mb-2 block">الرصيد المستحق</span>
            <input type={form.leave_balance_method === 'date_accrual' ? 'text' : 'number'} step="any" readOnly={form.leave_balance_method === 'date_accrual'} value={form.leave_balance_method === 'date_accrual' ? (result?.accrual_details?.accrued_days != null ? formatNumber(result.accrual_details.accrued_days) : '') : form.accrued_days} onChange={(event) => setForm({ ...form, accrued_days: Number(event.target.value) })} className={fieldClass(false)} />
          </label>
          <label className="text-right text-sm font-medium text-slate-600">
            <span className="mb-2 block">المستخدم</span>
            <input type="number" step="0.01" value={form.used_days} onChange={(event) => setForm({ ...form, used_days: Number(event.target.value) })} className={fieldClass(false)} />
          </label>
          <label className="text-right text-sm font-medium text-slate-600">
            <span className="mb-2 block">التعديلات</span>
            <input type="number" step="0.01" value={form.adjustment_days} onChange={(event) => setForm({ ...form, adjustment_days: Number(event.target.value) })} className={fieldClass(false)} />
          </label>
          <label className="text-right text-sm font-medium text-slate-600">
            <span className="mb-2 block">الرصيد المتاح</span>
            <input type="text" value={result?.available_days != null ? formatNumber(result.available_days) : ''} readOnly className={fieldClass(false)} />
          </label>
        </div>

        <div className="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
          {!fullCompensation && <label className="text-right text-sm font-medium text-slate-600">
            <span className="mb-2 block">أيام الإجازة</span>
            <input aria-label="أيام الإجازة" type="number" step="0.01" value={form.requested_leave_days} onChange={(event) => setForm({ ...form, requested_leave_days: Number(event.target.value) })} className={fieldClass(false)} />
            <span className="mt-1 block text-xs">يُصرف أجر الأيام المدخلة فقط. لتعويض كامل الرصيد عند انتهاء الخدمة اختر نوع المعاملة المخصص لذلك.</span>
          </label>}
          <label className="text-right text-sm font-medium text-slate-600">
            <span className="mb-2 block">الأجر المستخدم</span>
            <input disabled={hasEmployeeSalary || estimate} type="number" step="0.01" value={displayedWage ?? ''} onChange={(event) => setForm({ ...form, basic_salary: Number(event.target.value) })} className={fieldClass(false)} />
            {estimate && <span className="mt-1 block text-xs text-slate-500">إجمالي الأساسي والبدلات المدخلة في قسم الراتب.</span>}
            {hasEmployeeSalary && <span className="mt-1 block text-xs text-slate-500">يشمل الأساسي والبدلات المدرجة في حساب الإجازة من ملف الموظف.</span>}
          </label>
          <label className="text-right text-sm font-medium text-slate-600">
            <span className="mb-2 block">قيمة اليوم</span>
            <input type="number" step="0.01" value={result?.daily_rate ?? ''} readOnly className={fieldClass(false)} />
          </label>
          <label className="text-right text-sm font-medium text-slate-600">
            <span className="mb-2 block">مصدر الرصيد</span>
            <select value={form.balance_source} onChange={(event) => setForm({ ...form, balance_source: event.target.value })} className={fieldClass(false)}>
              <option value="calculated">محسوب</option>
              <option value="hr_system">نظام الموارد البشرية</option>
              <option value="manual">يدوي</option>
            </select>
          </label>
        </div>
      </SectionCard>
    </>
  )
}

function WhyDrawer({ item, onClose }) {
  if (!item) return null

  return (
    <div className="fixed inset-0 z-50 flex justify-end bg-slate-950/25">
      <aside className="h-full w-full max-w-lg overflow-y-auto border-l border-slate-200 bg-white p-6 text-right shadow-2xl">
        <div className="flex items-center justify-between gap-3 border-b border-slate-200 pb-4">
          <button type="button" onClick={onClose} className="rounded-full bg-slate-100 px-3 py-2 text-sm font-semibold text-slate-700">إغلاق</button>
          <div>
            <p className="text-[10px] font-bold tracking-[0.2em] text-blue-600">WHY</p>
            <h3 className="text-xl font-bold text-slate-900">{item.title || item.label || 'البند'}</h3>
          </div>
        </div>

        <div className="mt-5 space-y-4 text-sm text-slate-700">
          <div className="rounded-2xl bg-slate-50 p-4">
            <div className="font-bold text-slate-900">المصادر المستخدمة</div>
            <div className="mt-2">{item.method || item.calculation || 'محسوب من Backend'}</div>
          </div>
          <div className="rounded-2xl bg-slate-50 p-4">
            <div className="font-bold text-slate-900">الصيغة</div>
            <div className="mt-2">{item.formula || item.method || 'الكمية × المعدل × المدة'}</div>
          </div>
          <div className="rounded-2xl bg-slate-50 p-4">
            <div className="font-bold text-slate-900">المبلغ</div>
            <div className="mt-2 text-lg font-black text-slate-900">{formatCurrency(item.amount ?? 0)}</div>
          </div>
          <div className="rounded-2xl bg-slate-50 p-4">
            <div className="font-bold text-slate-900">المادة النظامية</div>
            <div className="mt-2">{item.article || 'المادة 84'}</div>
          </div>
          <div className="rounded-2xl bg-slate-50 p-4">
            <div className="font-bold text-slate-900">تنبيهات الحساب</div>
            <div className="mt-2">{item.warning || 'لا توجد تنبيهات إضافية.'}</div>
          </div>
        </div>
      </aside>
    </div>
  )
}

export default function SettlementCalculatorPage({ user, onOpenTransactions, initialEmployeeId }) {
  const { settings } = useSystemSettings()
  const [form, updateForm] = useState(() => ({ ...defaultForm, initial_employee_id: initialEmployeeId }))
  const modeDrafts = useRef({})
  const formRevision = useRef(0)
  const [result, setResult] = useState(null)
  const [savedReference, setSavedReference] = useState('')
  const [calculatedForm, setCalculatedForm] = useState(null)
  const setForm = useCallback((next) => {
    formRevision.current += 1
    updateForm(previous => {
      const updated = typeof next === 'function' ? next(previous) : next
      if (updated.last_working_date !== previous.last_working_date) {
        const day = /^\d{4}-\d{2}-(\d{2})$/.exec(updated.last_working_date || '')
        return {
          ...updated,
          unpaid_days: day ? Number(day[1]) : 0,
          salary_days_source: 'manual',
        }
      }
      return updated
    })
    setResult(null)
    setCalculatedForm(null)
    setSavedReference('')
  }, [])
  const [loading, setLoading] = useState(false)
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState('')
  const [drawerItem, setDrawerItem] = useState(null)
  const [fieldErrors, setFieldErrors] = useState({})
  useEffect(() => {
    if (!Object.keys(fieldErrors).length || loading) return
    const field = document.querySelector('main input[aria-invalid="true"]:not(:disabled)')
    field?.focus({ preventScroll: true })
    field?.scrollIntoView({ behavior: 'smooth', block: 'center' })
  }, [fieldErrors, loading])
  const [employees, setEmployees] = useState([])
  const [loadingEmployees, setLoadingEmployees] = useState(true)

  useEffect(() => {
    let active = true
    listEmployees().then(records => { if (active) setEmployees(records.data || []) })
      .catch(error => { if (active) setMessage(error.message) })
      .finally(() => { if (active) setLoadingEmployees(false) })
    return () => { active = false }
  }, [])

  const isLeaveTransaction = form.transaction_type === 'leave_settlement'
  const isEstimate = form.calculation_mode === 'estimate'
  const canSave = hasUserPermission(user, 'create_transactions')

  const changeMode = mode => {
    if (mode === form.calculation_mode) return
    modeDrafts.current[form.calculation_mode] = form
    const blank = { ...defaultForm, calculation_mode: mode, initial_employee_id: null, employee_profile: null, employee_profile_version: null, salary_components: [], advance_decision: {}, estimate_name: '', estimate_company: '', hire_date: '', last_working_date: '', basic_salary: '', housing_allowance: 0, transport_allowance: 0, fixed_allowances: 0, other_allowances: 0 }
    const restored = modeDrafts.current[mode] || blank
    setForm({ ...restored, initial_employee_id: restored.employee_profile ? null : restored.initial_employee_id, transaction_type: form.transaction_type })
    setFieldErrors({}); setMessage(''); setDrawerItem(null)
  }

  const handleCalculate = async () => {
    if (!isEstimate && !form.employee_id) {
      setFieldErrors({ employee_id: ['اختر موظفًا من النظام أو انتقل إلى التصفية التقديرية لموظف غير مسجل.'] })
      setMessage('حدد الموظف قبل الاحتساب، أو اختر وضع التصفية التقديرية.')
      return
    }
    setLoading(true)
    setMessage('')
    setFieldErrors({})
    const revision = formRevision.current

    try {
      const payload = await calculateSettlement(form)
      if (revision !== formRevision.current) return
      setResult(payload)
      setCalculatedForm({ ...form })
      setSavedReference('')
      setMessage('تمت عملية الحساب بنجاح.')
    } catch (error) {
      if (revision !== formRevision.current) return
      setMessage(error.message)
      setFieldErrors(error.errors || {})
      setResult(null)
    } finally {
      setLoading(false)
    }
  }

  const handleSave = async () => {
    if (!calculatedForm || !result || isEstimate || !canSave) return
    setSaving(true)
    setMessage('')
    try {
      const saved = await saveHrTransaction(calculatedForm)
      setSavedReference(saved.reference_number)
      setMessage('تم حفظ المعاملة بنجاح')
    } catch (error) {
      setMessage(error.message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <>
          <main className="rounded-[30px] border border-slate-200 bg-white p-5 shadow-sm md:p-6">
            <div className="mb-6 flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
              <div>
                <div className="text-xs font-bold text-blue-600">{isEstimate ? 'تقدير المستحقات' : 'تصفية موظف مسجل'}</div>
                <h2 className="mt-2 text-3xl font-black text-slate-900">{transactionLabels[form.transaction_type] || 'حاسبة المخالصة'}</h2>
                <p className="mt-2 text-sm text-slate-500">{settings['branding.description']}</p>
              </div>

              <div className="w-full xl:max-w-[540px]">
                <TransactionTypeSelector
                  disabled={loading || saving}
                  value={form.transaction_type}
                  onChange={(transaction_type) => setForm((prev) => ({ ...prev, transaction_type }))}
                />
              </div>
            </div>

            <fieldset disabled={loading || saving} className="min-w-0 space-y-5">
              <CalculationModeCard value={form.calculation_mode} onChange={changeMode} />
              {!isEstimate && <AdvanceDecisionCard key={form.employee_id} employeeId={form.employee_id} value={form.advance_decision || {}} onChange={advance_decision => setForm({ ...form, advance_decision, employee_loan: 0, advance: 0 })} resultSummary={result?.advance_summary} />}
              {isLeaveTransaction ? (
                <LeaveSettlementForm form={form} setForm={setForm} result={result} employees={employees} loadingEmployees={loadingEmployees} errors={fieldErrors} />
              ) : (
                <>
                  <EmployeeCard form={form} setForm={setForm} employees={employees} loadingEmployees={loadingEmployees} errors={fieldErrors} />
                  <ContractCard form={form} setForm={setForm} employees={employees} errors={fieldErrors} />
                  <ServicePeriodCard result={result} />
                  <TerminationDetailsCard form={form} setForm={setForm} isLeave={false} errors={fieldErrors} />
                  <LeaveAccrualFields form={form} setForm={setForm} result={result} errors={fieldErrors} />
                  <SalaryCard form={form} setForm={setForm} result={result} />
                </>
              )}

              <AdditionalFinancialItems form={form} setForm={setForm} errors={fieldErrors} />
              <div className="rounded-[24px] border border-slate-200 bg-slate-50 p-4">
                <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                  <div className="text-right">
                    <div className="text-[10px] font-bold tracking-[0.18em] text-slate-500">ACTION</div>
                    <div className="mt-1 text-lg font-bold text-slate-900">احسب المخالصة</div>
                  </div>

                  <div className="flex flex-wrap items-center gap-2">
                    {result && !isEstimate && canSave && !savedReference && <button type="button" onClick={handleSave} disabled={saving || !calculatedForm?.employee_id} className="rounded-2xl bg-emerald-600 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-50">{saving ? 'جاري الحفظ...' : 'حفظ المعاملة'}</button>}
                    <button type="button" onClick={onOpenTransactions} className="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-100">سجل المعاملات</button>
                    <button
                      type="button"
                      onClick={handleCalculate}
                      disabled={loading}
                      className="rounded-2xl bg-blue-600 px-5 py-2.5 text-sm font-bold text-white shadow-lg shadow-blue-600/20 transition hover:bg-blue-500 disabled:cursor-not-allowed disabled:bg-blue-300"
                    >
                      {loading ? 'جاري الحساب...' : 'احسب المخالصة'}
                    </button>
                  </div>
                </div>
              </div>

              {message ? (
                <div className="rounded-2xl border border-blue-100 bg-blue-50 px-4 py-3 text-sm font-medium text-blue-700">
                  {message}
                  {savedReference && <div className="mt-1 font-bold">رقم المعاملة: {savedReference}</div>}
                </div>
              ) : null}

              {!result ? (
                <div className="rounded-[24px] border border-dashed border-slate-300 bg-slate-50 p-8 text-center">
                  <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-200 text-slate-500">
                    <Info className="h-6 w-6" />
                  </div>
                  <h3 className="mt-4 text-xl font-bold text-slate-800">أدخل بيانات المعاملة ثم اضغط احسب لعرض النتيجة.</h3>
                  <p className="mt-2 text-sm text-slate-500">النتيجة تظهر هنا بعد حساب المعاملة من Laravel.</p>
                </div>
              ) : (
                <>
                  {isEstimate && <div className="rounded-2xl border border-amber-300 bg-amber-50 p-5" role="status">
                    <div className="flex flex-wrap items-center justify-between gap-3"><h3 className="text-lg font-bold text-amber-950">نتيجة تصفية تقديرية — غير معتمدة</h3><span className="rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-900">للمعاينة فقط</span></div>
                    {(calculatedForm?.estimate_name || calculatedForm?.estimate_company) && <p className="mt-2 text-sm font-semibold text-amber-900">{[calculatedForm.estimate_name, calculatedForm.estimate_company].filter(Boolean).join(' — ')}</p>}
                    <p className="mt-2 text-sm text-amber-900">{result.calculation_context?.notice || 'نتيجة تقديرية بناءً على البيانات المدخلة، غير معتمدة ولا تنشئ موظفًا أو التزامًا ماليًا.'}</p>
                  </div>}
                  <EntitlementsTable result={result} onWhyClick={setDrawerItem} />
                  <DeductionsTable result={result} />
                  <SettlementSummary result={result} />
                  <div className="grid gap-5 xl:grid-cols-2">
                    <LegalReviewCard result={result} />
                    <LegalReferencesCard result={result} />
                  </div>
                </>
              )}
            </fieldset>
          </main>

      {drawerItem ? <WhyDrawer item={drawerItem} onClose={() => setDrawerItem(null)} /> : null}
    </>
  )
}
