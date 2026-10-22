import { hasUserPermission } from './permissions'

export const financialStages = { draft: 'مسودة', pending_financial_review: 'بانتظار المراجعة المالية', pending_legal_review: 'بانتظار المراجعة القانونية', pending_approval: 'بانتظار الاعتماد', approved: 'معتمدة', posted: 'مرحلة ماليًا', paid: 'مدفوعة', cancelled: 'ملغاة' }
export const advanceStatuses = { requested: 'طلب سلفة', approved: 'معتمدة ولم تصرف', active: 'قائمة', suspended: 'معلقة', cancelled: 'ملغاة', settled: 'مسددة' }
export const movementLabels = { disbursement: 'صرف السلفة', payroll_repayment: 'سداد من الرواتب', external_repayment: 'سداد خارجي', settlement: 'خصم من مخالصة', reversal: 'مستند عكسي' }
export const reportKinds = { outstanding: 'السلف القائمة', settled: 'السلف المسددة', terminated_debt: 'مديونيات انتهاء الخدمة', settlement_deductions: 'خصومات المخالصات المرحلة', no_deduction: 'مخالصات دون خصم السلف', pending: 'بانتظار مراجعة أو اعتماد', statement: 'كشف حساب موظف' }
export function advancePermission(user, permission) {
  return hasUserPermission(user, permission)
}
export const financialInput = 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-blue-400'
export const financialButton = 'rounded-xl border border-blue-200 bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-700 disabled:opacity-40'
