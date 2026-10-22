export const transactionLabels = {
  final_settlement: 'مخالصة نهاية خدمة',
  leave_settlement: 'تصفية إجازة',
}

export const workflowStatusLabels = {
  pending_financial_review: 'بانتظار المراجعة المالية',
  pending_legal_review: 'بانتظار المراجعة القانونية',
  pending_approval: 'بانتظار الاعتماد',
  posted: 'مرحلة ماليًا',
  paid: 'مدفوعة',
  draft: 'مسودة',
  calculated: 'محسوبة',
  pending_review: 'بانتظار المراجعة',
  approved: 'معتمدة',
  rejected: 'مرفوضة',
  cancelled: 'ملغاة',
  changes_requested: 'مطلوب تعديل',
}

export const workflowActionLabels = {
  settlement_financial_reviewed: 'اكتملت المراجعة المالية',
  settlement_advance_legal_reviewed: 'توثيق قرار قانوني للسلف',
  settlement_advance_decision_changed: 'تحديث قرار معالجة السلف',
  settlement_posted: 'تم الترحيل المالي',
  settlement_paid: 'تم توثيق الصرف',
  settlement_financial_cancelled: 'إلغاء أو عكس مالي موثق',
  transaction_created: 'تم إنشاء المعاملة',
  item_adjusted: 'تم تعديل بند مالي',
  adjustment_reverted: 'تمت إعادة بند للقيمة المحسوبة',
  submitted_for_review: 'أرسلت للمراجعة',
  transaction_approved: 'تم اعتماد المعاملة',
  transaction_rejected: 'تم رفض المعاملة',
  changes_requested: 'طُلب تعديل المعاملة',
  transaction_cancelled: 'تم إلغاء المعاملة',
  transaction_reopened: 'أعيد فتح المعاملة',
  transaction_version_created: 'تم حفظ إصدار معتمد',
  transaction_updated: 'تم تحديث المعاملة',
}

export const leaveTransactionLabels = {
  annual_leave_pay: 'تصفية / أجر إجازة',
  termination_leave_compensation: 'تعويض رصيد الإجازة عند انتهاء الخدمة',
  active_service_cash_encashment: 'استبدال رصيد الإجازة بمقابل نقدي أثناء الخدمة',
}

export const reviewLabels = {
  valid: 'سليمة مبدئياً',
  warning: 'تحتاج مراجعة',
  requires_legal_review: 'تحتاج مراجعة نظامية',
  blocked: 'المعاملة غير قابلة للاعتماد حالياً',
}

export const statusTone = {
  valid: 'emerald',
  warning: 'amber',
  requires_legal_review: 'rose',
  blocked: 'red',
}

export const statusBadgeClasses = {
  valid: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
  warning: 'bg-amber-50 text-amber-700 ring-amber-200',
  requires_legal_review: 'bg-rose-50 text-rose-700 ring-rose-200',
  blocked: 'bg-red-50 text-red-700 ring-red-200',
}

export const terminationLabels = {
  termination: 'إنهاء خدمة',
  resignation: 'استقالة',
  article_80: 'المادة 80',
  article_81: 'المادة 81',
}

export const getToneClasses = (tone) => {
  switch (tone) {
    case 'success':
      return 'bg-emerald-50 text-emerald-700 ring-emerald-200'
    case 'warning':
      return 'bg-amber-50 text-amber-700 ring-amber-200'
    case 'danger':
      return 'bg-red-50 text-red-700 ring-red-200'
    default:
      return 'bg-slate-100 text-slate-700 ring-slate-200'
  }
}
