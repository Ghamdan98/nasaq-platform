import { test, expect } from '@playwright/test'

test.use({ launchOptions: { executablePath: process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe', timeout: 15000 } })

const employee = { id: 501, employee_number: '000501', full_name: 'موظف اختبار', company: 'شركة الاختبار', hire_date: '2020-08-26', status: 'active',
  basic_salary: '3000.00', housing_allowance: '1000.00', total_salary: '4000', last_leave_settlement_date: '2025-01-01',
  national_id_or_iqama: '0012345678', employee_profile_version: 'a'.repeat(64), contracts: [],
  settlement_input: { hire_date: '2020-08-26', last_settlement_date: '2025-01-01', basic_salary: '3000.00', housing_allowance: '1000.00', transport_allowance: '0', fixed_allowances: '0', commissions: '0', other_allowances: '0', salary_components: [] } }

test.beforeEach(async ({ page }) => {
  page.on('pageerror', error => { throw error })
  await page.addInitScript(() => {
    localStorage.setItem('auth_token', 'ui-test-token')
    localStorage.setItem('user', JSON.stringify({ name: 'موارد بشرية', role: 'hr' }))
  })
  await page.route('**/api/**', async route => {
    const path = new URL(route.request().url()).pathname
    if (path === '/api/employees/companies') return route.fulfill({ json: ['شركة الاختبار'] })
    if (path === '/api/employees/501/advances') return route.fulfill({ json: { items: [], total_balance: '0', available_balance: '0', reserved_balance: '0' } })
    if (path === '/api/employees/501') return route.fulfill({ json: employee })
    if (path === '/api/employees') return route.fulfill({ json: { data: [employee], total: 1, last_page: 1 } })
    if (path === '/api/employee-imports/preview') return route.fulfill({ status: 201, json: { id: 4, status: 'rejected', rejected_count: 1, new_count: 0, updated_count: 0, skipped_count: 0, pages: 1, rows: [{ row: 2, data: employee, action: 'create', errors: ['كود الموظف مكرر داخل الشركة في الملف.'] }] } })
    if (path === '/api/employee-imports') return route.fulfill({ json: { data: [], last_page: 1 } })
    return route.fulfill({ json: {} })
  })
})

test('employee search loads master salary and dates while last working day remains editable', async ({ page }) => {
  await page.goto('/#/calculator')
  const search = page.getByRole('combobox', { name: 'البحث عن الموظف' })
  await search.fill('000501')
  await page.getByRole('option', { name: /000501/ }).click()
  await expect(page.getByText('رقم الهوية: 0012345678')).toBeVisible()
  await expect(page.getByRole('link', { name: 'إدارة الموظفين' })).toBeVisible()
  await expect(page.getByText('الراتب الأساسي', { exact: true }).locator('..').locator('input')).toBeDisabled()
  const actual = page.getByText('تاريخ آخر يوم عمل', { exact: true }).locator('..').locator('input')
  await actual.fill('2026-10-15')
  const salaryDays = page.getByText('أيام الراتب غير المدفوع', { exact: true }).locator('..').locator('input')
  await expect(salaryDays).toHaveValue('15')
  await salaryDays.fill('4')
  await expect(salaryDays).toHaveValue('4')
  await actual.fill('2026-10-20')
  await expect(salaryDays).toHaveValue('20')
  const box = page.getByRole('checkbox', { name: 'خصم أيام سبق تسويتها من مدة احتساب استحقاق الإجازة أيضًا' })
  await expect(box).toBeVisible()
  expect(await box.evaluate(element => getComputedStyle(element).appearance)).not.toBe('none')
  await box.uncheck(); await expect(box).not.toBeChecked()
})

test('employee management opens details and editable inclusion rules', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/#/employees')
  await expect(page.getByRole('heading', { name: 'إدارة الموظفين' })).toBeVisible()
  await expect(page.getByRole('cell', { name: '000501', exact: true })).toBeVisible()
  await page.getByRole('button', { name: 'تعديل', exact: true }).click()
  const dialog = page.getByRole('dialog', { name: 'ملف الموظف' })
  await expect(dialog.getByLabel('كود الموظف')).toHaveValue('000501')
  await dialog.getByLabel('الراتب الأساسي', { exact: true }).fill('3500')
  await expect(dialog.getByLabel('بدل السكن eos')).toBeChecked()
  await dialog.getByLabel('بدل السكن eos').uncheck()
  await expect(dialog.getByLabel('بدل السكن eos')).not.toBeChecked()
  await dialog.getByRole('button', { name: 'إغلاق' }).click()
  await expect(dialog).toHaveCount(0)
})

test('rejected import preview cannot be confirmed', async ({ page }) => {
  await page.goto('/#/employees')
  await page.getByRole('button', { name: 'الاستيراد وسجله' }).click()
  await page.getByLabel('ملف الموظفين').setInputFiles({ name: 'employees.xlsx', mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', buffer: Buffer.from('mocked workbook') })
  await page.getByRole('button', { name: 'معاينة قبل الحفظ' }).click()
  await expect(page.getByText('كود الموظف مكرر داخل الشركة في الملف.', { exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: 'تأكيد حفظ الاستيراد كاملًا' })).toBeDisabled()
  await expect(page.getByRole('button', { name: 'تنزيل تقرير الأخطاء' })).toBeVisible()
})
