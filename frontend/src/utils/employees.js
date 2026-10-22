export const employeeMoney = {
  basic_salary: 'الراتب الأساسي', allowance_1: 'بدل 1', transportation_allowance: 'بدل الانتقال',
  work_nature_allowance: 'بدل طبيعة العمل', subsistence_allowance: 'بدل الإعاشة', food_allowance: 'بدل الطعام',
  additional_fixed_allowance: 'بدل إضافي ثابت', other_allowances: 'بدلات أخرى', housing_allowance: 'بدل السكن',
}
export const employeeStatuses = { active: 'نشط', inactive: 'غير نشط', terminated: 'منتهي الخدمة' }
export const importStatuses = { preview: 'جاهز للتأكيد', rejected: 'أخطاء تمنع الحفظ', completed: 'تم الاستيراد' }
export function saveDownload({ blob, filename }) {
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a'); anchor.href = url; anchor.download = filename; anchor.click()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}
