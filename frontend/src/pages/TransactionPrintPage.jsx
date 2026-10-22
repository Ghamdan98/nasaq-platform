import { useEffect, useState } from 'react'
import { getTransactionPrintData, recordTransactionPrint } from '../services/api'
import { formatCurrency, formatNumber } from '../utils/currency'
import { formatDate } from '../utils/dates'
import { workflowStatusLabels } from '../utils/labels'
import { useSystemSettings } from '../system/SystemSettingsContext'
import { BrandLogo } from '../system/Brand'

const reviewLabels = {
  valid: 'سليمة مبدئياً',
  warning: 'تحتاج مراجعة',
  requires_legal_review: 'تحتاج مراجعة نظامية',
  blocked: 'غير قابلة للاعتماد',
}

export default function TransactionPrintPage({ transactionId, onBack }) {
  const { settings } = useSystemSettings()
  const [data, setData] = useState(null)
  const [error, setError] = useState('')
  const [printing, setPrinting] = useState(false)

  useEffect(() => {
    getTransactionPrintData(transactionId).then(setData).catch((requestError) => setError(requestError.message))
  }, [transactionId])

  const print = async () => {
    setPrinting(true)
    setError('')
    try {
      await recordTransactionPrint(transactionId)
      window.print()
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setPrinting(false)
    }
  }

  if (error && !data) return <main className="rounded-3xl bg-white p-8 text-right"><p role="alert" className="text-red-700">{error}</p></main>
  if (!data) return <main className="rounded-3xl bg-white p-8 text-center">جاري تحميل مستند المعاملة...</main>

  const transaction = data.transaction
  const approved = transaction.status === 'approved'
  const employee = data.employee || {}
  const contract = data.contract || {}
  const calculation = data.calculation || {}
  const accrualBalance = calculation.accrual_details || calculation.leave_calculation?.accrual_details
  const accrualPeriod = accrualBalance?.accrual_details
  const accrualBasis = accrualPeriod?.year_basis || (accrualPeriod?.year_days ? `${accrualPeriod.month_days || 30}/${accrualPeriod.year_days}` : '—')
  const adjustedItems = [...(data.items || []), ...(data.deductions || [])].filter((item) => item.was_manually_adjusted)
  const warningText = (warning) => typeof warning === 'string' ? warning : warning.message_ar || warning.message || warning.description || JSON.stringify(warning)

  return (
    <main className="print-document mx-auto max-w-[900px] rounded-xl border border-slate-200 bg-white p-6 text-right text-slate-900 shadow-sm print:max-w-none print:border-0 print:p-0 print:shadow-none">
      <div className="no-print mb-5 flex justify-between gap-2">
        <button type="button" onClick={onBack} className="rounded-xl border px-4 py-2 font-bold">العودة</button>
        <button type="button" disabled={printing} onClick={print} className="rounded-xl bg-blue-700 px-4 py-2 font-bold text-white disabled:opacity-50">{printing ? 'جاري التجهيز...' : 'طباعة'}</button>
      </div>
      {error && <p role="alert" className="no-print mb-4 rounded-xl bg-red-50 p-3 text-sm text-red-700">{error}</p>}

      <header className="border-b-2 border-blue-700 pb-4">
        <div className="flex items-start justify-between gap-4">
          <div>{settings['reports.show_logo'] && <BrandLogo variant="print_logo" />}<p className="text-sm text-slate-500">{settings['branding.name_ar']}</p><p>{settings['reports.organization'] || settings['branding.owner']}</p><h1 className="mt-1 text-2xl font-black text-blue-900">{transaction.transaction_type === 'leave_settlement' ? 'تصفية إجازة' : 'مخالصة نهاية خدمة'}</h1></div>
          <div className="text-left"><strong>{transaction.reference_number}</strong><div>الإصدار {transaction.version_number || 1} — {formatDate(transaction.transaction_date)}</div></div>
        </div>
        <div className="mt-3 flex gap-2">
          <span className={`rounded-full px-3 py-1 text-sm font-bold ${approved ? 'bg-emerald-100 text-emerald-800' : 'bg-orange-100 text-orange-900'}`}>{approved ? 'معتمدة' : `غير معتمدة — ${workflowStatusLabels[transaction.status] || transaction.status}`}</span>
          <span className="rounded-full bg-slate-100 px-3 py-1 text-sm font-bold">{reviewLabels[transaction.review_status] || transaction.review_status}</span>
        </div>
      </header>

      {!approved && <div className="my-4 border-2 border-orange-700 bg-orange-50 p-3 text-center text-lg font-black text-orange-800">غير معتمدة — {workflowStatusLabels[transaction.status] || transaction.status}</div>}

      <section className="print-keep">
        <h2 className="print-section-title">بيانات الموظف والعقد</h2>
        <div className="print-info-grid">
          <p><b>الاسم:</b> {employee.full_name || '—'}</p><p><b>رقم الموظف:</b> {employee.employee_number || '—'}</p>
          <p><b>المسمى الوظيفي:</b> {employee.job_title || '—'}</p><p><b>الإدارة:</b> {employee.department || '—'}</p>
          <p><b>نوع العقد:</b> {contract.contract_type || '—'}</p><p><b>تاريخ المباشرة:</b> {contract.start_date || employee.hire_date || '—'}</p>
          <p><b>تاريخ نهاية العقد:</b> {contract.end_date || '—'}</p><p><b>الراتب الأساسي المسجل:</b> {formatCurrency(contract.basic_salary || 0)}</p>
        </div>
      </section>

      {transaction.transaction_type === 'final_settlement' ? (
        <section className="print-keep">
          <h2 className="print-section-title">بيانات الخدمة</h2>
          <div className="print-info-grid">
            <p><b>سبب انتهاء العلاقة:</b> {data.final_details?.termination_type || data.calculation?.termination?.type || '—'}</p>
            <p><b>آخر يوم عمل:</b> {formatDate(data.final_details?.last_working_date || data.input?.last_working_date)}</p>
            <p><b>مدة الخدمة:</b> {data.calculation?.service_period ? `${data.calculation.service_period.years || 0} سنة، ${data.calculation.service_period.months || 0} شهر، ${data.calculation.service_period.days || 0} يوم` : '—'}</p>
            <p><b>الأجر المستخدم:</b> {formatCurrency(data.salary?.gross_monthly_wage || 0)}</p>
          </div>
          {accrualPeriod && <p className="mt-3 text-sm">
            رصيد الإجازة — أساس الحساب {accrualBasis} {accrualPeriod.day_count_convention ? `(${accrualPeriod.day_count_convention})` : ''}، الفترة {accrualPeriod.period_from} إلى {accrualPeriod.period_to}، {formatNumber(accrualPeriod.service_days)} يوم استحقاق محاسبي، المكتسب {formatNumber(accrualBalance.accrued_days)} يوم، النهائي {formatNumber(accrualBalance.available_days)} يوم.
          </p>}
        </section>
      ) : (
        <section className="print-keep">
          <h2 className="print-section-title">بيانات تصفية الإجازة</h2>
          <div className="print-info-grid">
            <p><b>نوع معاملة الإجازة:</b> {data.leave_details?.leave_transaction_type || '—'}</p>
            <p><b>الرصيد الافتتاحي:</b> {data.leave_details?.opening_balance ?? '—'}</p>
            <p><b>المستحق:</b> {data.leave_details?.accrued_days ?? '—'}</p>
            <p><b>المستخدم:</b> {data.leave_details?.used_days ?? '—'}</p>
            <p><b>أيام سبق تسويتها:</b> {data.transaction?.calculation_snapshot_json?.accrual_details?.previously_settled_days ?? '—'}</p>
            <p><b>التعديلات:</b> {data.leave_details?.adjustment_days ?? '—'}</p>
            <p><b>الرصيد المتاح:</b> {data.leave_details?.available_days ?? '—'}</p>
            <p><b>الأيام المصروفة / المستحقة:</b> {data.leave_details?.days_to_compensate ?? '—'}</p>
            <p><b>الرصيد المتبقي:</b> {data.leave_details?.remaining_balance ?? '—'}</p>
            <p><b>مصدر الرصيد:</b> {data.leave_details?.balance_source || '—'}</p>
            <p><b>الأجر المستخدم:</b> {formatCurrency(data.leave_details?.calculation_wage || 0)}</p>
            <p><b>قيمة اليوم:</b> {formatCurrency(data.leave_details?.daily_rate || 0)}</p>
          </div>
          {accrualPeriod && <p className="mt-3 text-sm">
            أساس الحساب: {accrualBasis} {accrualPeriod.day_count_convention ? `(${accrualPeriod.day_count_convention})` : ''} — الفترة {accrualPeriod.period_from} إلى {accrualPeriod.period_to} — {formatNumber(accrualPeriod.service_days)} يوم استحقاق محاسبي.
          </p>}
        </section>
      )}

      <PrintFinancialTable title="المستحقات" rows={data.items || []} />
      <PrintFinancialTable title="الخصومات" rows={data.deductions || []} />

      <section className="print-totals print-keep">
        <p>إجمالي المستحقات <b>{formatCurrency(transaction.gross_amount)}</b></p>
        <p>إجمالي الخصومات <b>{formatCurrency(transaction.total_deductions)}</b></p>
        <p className="print-net">صافي المستحق <b>{formatCurrency(transaction.net_amount)}</b></p>
      </section>

      {adjustedItems.length > 0 && <section className="print-keep">
        <h2 className="print-section-title">التعديلات اليدوية</h2>
        <p className="mb-2 font-bold">تحتوي هذه المعاملة على قيم تم تعديلها يدوياً.</p>
        <PrintFinancialTable title="" rows={adjustedItems} includeReason />
      </section>}

      <section className="print-keep">
        <h2 className="print-section-title">التقييم النظامي</h2>
        <p className="font-bold">{reviewLabels[transaction.review_status] || transaction.review_status}</p>
        {(data.warnings || []).map((warning, index) => <p className="print-warning" key={index}>{warningText(warning)}</p>)}
      </section>

      {!!data.legal_references?.length && <section className="print-keep">
        <h2 className="print-section-title">السند النظامي</h2>
        <ul>{data.legal_references.map((reference, index) => <li key={index}>المادة {reference.article_number || reference.article || '—'} — {reference.title || reference.rule_code || '—'} — {reference.source_name || reference.source || '—'}</li>)}</ul>
      </section>}

      {approved && <section className="print-keep">
        <h2 className="print-section-title">بيانات الاعتماد</h2>
        <div className="print-info-grid">
          <p><b>أنشأها:</b> {data.created_by_name || '—'}</p><p><b>راجعها:</b> {data.reviewed_by_name || '—'}</p>
          <p><b>اعتمدها:</b> {data.approved_by_name || '—'}</p><p><b>تاريخ الاعتماد:</b> {transaction.approved_at || '—'}</p>
        </div>
      </section>}

      <section>
        <h2 className="print-section-title">التسوية المالية والسلف</h2>
        <p>المرحلة المالية: {transaction.financial_stage} · قرار السلف: {transaction.advance_decision_json?.choice || '—'}</p>
        <p>سبب القرار: {transaction.advance_decision_json?.reason || '—'} · المديونية المتبقية: {transaction.advance_decision_json?.remaining_debt_reason || '—'}</p>
        <table className="print-table"><thead><tr><th>السلفة</th><th>المبلغ المخصص</th><th>الحالة</th></tr></thead><tbody>{data.advance_allocations?.map(row => <tr key={row.id}><td>{row.advance?.reference_number || row.advance_id}</td><td>{formatCurrency(row.allocated_amount)}</td><td>{row.status}</td></tr>)}</tbody></table>
        {data.settlement_payments?.map(row => <p key={row.id}>مستند {row.reversal_of_id ? 'عكس صرف' : 'صرف'}: {row.reference_number} · {row.payment_date} · {formatCurrency(row.amount)}</p>)}
      </section>
      <table className="print-signatures">
        <thead><tr>{['إعداد الموارد البشرية', 'المراجعة', 'الاعتماد', 'الموظف'].map((label) => <th key={label}>{label}</th>)}</tr></thead>
        <tbody><tr>{['إعداد الموارد البشرية', 'المراجعة', 'الاعتماد', 'الموظف'].map((label) => <td key={label}>الاسم: __________________<br /><br />التوقيع: ________________<br /><br />التاريخ: _________________</td>)}</tr></tbody>
      </table>
      <footer className="print-footer">{transaction.reference_number} — الإصدار {transaction.version_number || 1} — {settings['reports.footer'] || settings['branding.footer']}</footer>
    </main>
  )
}

function PrintFinancialTable({ title, rows, includeReason = false }) {
  return (
    <section>
      {title && <h2 className="print-section-title">{title}</h2>}
      <table className="print-table">
        <thead><tr><th>البند</th><th>الكمية</th><th>القيمة المحسوبة</th><th>القيمة المعتمدة</th><th>الفرق</th>{includeReason && <th>سبب التعديل</th>}</tr></thead>
        <tbody>
          {rows.map((item) => <tr key={`${item.code}-${item.id}`}>
            <td>{item.name_ar || item.name || item.code}{item.calculation_details_json?.reason && <div className="text-xs">{item.calculation_details_json.reason}</div>}</td><td>{item.quantity ?? '—'}</td>
            <td>{formatCurrency(item.calculated_amount)}</td><td>{formatCurrency(item.approved_amount)}</td><td>{formatCurrency(item.difference_amount)}</td>
            {includeReason && <td>{item.adjustment_reason || '—'}</td>}
          </tr>)}
          {rows.length === 0 && <tr><td colSpan={includeReason ? 6 : 5}>لا توجد بنود.</td></tr>}
        </tbody>
      </table>
    </section>
  )
}
