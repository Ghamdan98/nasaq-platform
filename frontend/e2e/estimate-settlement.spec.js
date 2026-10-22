import { test, expect } from '@playwright/test'

test('estimate mode calculates manually, preserves separate drafts and never saves an employee or transaction', async ({ playwright, request }) => {
  const api = process.env.FINANCE_API_URL || 'http://127.0.0.1:8001/api'
  const login = await request.post(`${api}/auth/login`, { headers: { Accept: 'application/json' }, data: { email: 'finance-e2e@example.test', password: 'FinanceE2E123!' } })
  expect(login.status()).toBe(200)
  const auth = await login.json()
  const headers = { Accept: 'application/json', Authorization: `Bearer ${auth.token}` }
  const get = async path => { const response = await request.get(api + path, { headers }); expect(response.status()).toBe(200); return response.json() }
  const employeesBefore = (await get('/employees')).total
  const transactionsBefore = (await get('/hr-transactions')).total
  const browser = await playwright.chromium.launch({ executablePath: process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe', args: ['--disable-gpu', '--renderer-process-limit=1'] })
  try {
    const page = await browser.newPage()
    await page.addInitScript(({ token, user }) => { localStorage.setItem('auth_token', token); localStorage.setItem('user', JSON.stringify(user)) }, auth)
    await page.route('**/api/**', async route => { const url = new URL(route.request().url()); const result = await route.fetch({ url: api + url.pathname.replace(/^\/api/, '') + url.search }); await route.fulfill({ response: result }) })
    await page.goto((process.env.E2E_BASE_URL || 'http://127.0.0.1:5173') + '/#/calculator')
    const employeeMode = page.getByRole('radio', { name: /موظف في النظام/ })
    const estimateMode = page.getByRole('radio', { name: /موظف غير مسجل/ })
    await expect(employeeMode).toBeChecked()
    await estimateMode.check()
    await expect(page.getByRole('combobox', { name: 'البحث عن الموظف' })).toHaveCount(0)
    await page.getByLabel('اسم الموظف أو الحالة (اختياري)').fill('حالة تقديرية للاختبار')
    await page.getByLabel('اسم الشركة (اختياري)').fill('شركة افتراضية')
    await page.getByLabel('تاريخ المباشرة', { exact: true }).fill('2020-01-01')
    await page.getByLabel('تاريخ آخر يوم عمل', { exact: true }).fill('2026-01-01')
    await page.getByLabel('الراتب الأساسي', { exact: true }).fill('6000')
    await page.getByLabel('بدل السكن', { exact: true }).fill('1000')
    await page.getByLabel('بدل النقل', { exact: true }).fill('500')
    await page.getByLabel('بدلات أخرى', { exact: true }).fill('500')
    await page.getByLabel('مدة الإشعار', { exact: true }).fill('60')
    await page.getByLabel('أيام الراتب غير المدفوع', { exact: true }).fill('0')
    await page.getByRole('combobox', { name: 'طريقة حساب رصيد الإجازة' }).selectOption('manual')
    await employeeMode.check()
    await expect(page.getByRole('combobox', { name: 'البحث عن الموظف' })).toBeVisible()
    await estimateMode.check()
    await expect(page.getByLabel('الراتب الأساسي', { exact: true })).toHaveValue('6000')
    await expect(page.getByLabel('اسم الموظف أو الحالة (اختياري)')).toHaveValue('حالة تقديرية للاختبار')
    const calculating = page.waitForResponse(r => r.url().endsWith('/settlements/calculate'))
    await page.getByRole('button', { name: 'احسب المخالصة', exact: true }).click()
    const calculated = await calculating
    expect(calculated.status()).toBe(200)
    const result = await calculated.json()
    expect(Number(result.net_settlement)).toBe(28000)
    expect(result.calculation_context.is_estimate).toBe(true)
    await expect(page.getByRole('heading', { name: 'نتيجة تصفية تقديرية — غير معتمدة' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'حفظ المعاملة', exact: true })).toHaveCount(0)
    await page.setViewportSize({ width: 390, height: 844 })
    await expect(estimateMode).toBeChecked()
    await page.getByRole('button', { name: /^تصفية إجازة/ }).click()
    await expect(page.getByLabel('الراتب الأساسي', { exact: true })).toBeEnabled()
    await expect(page.getByRole('heading', { name: 'نتيجة تصفية تقديرية — غير معتمدة' })).toHaveCount(0)
    await page.getByRole('combobox', { name: 'نوع المعاملة', exact: true }).selectOption('annual_leave_pay')
    await page.getByLabel('الرصيد الافتتاحي').fill('10')
    await page.getByLabel('أيام الإجازة', { exact: true }).fill('5')
    const leaveCalculation = page.waitForResponse(r => r.url().endsWith('/hr-transactions/calculate'))
    await page.getByRole('button', { name: 'احسب المخالصة', exact: true }).click()
    const leave = await leaveCalculation
    expect(leave.status()).toBe(200)
    expect(Number((await leave.json()).gross_leave_amount)).toBe(1333.33)
  } finally { await browser.close() }
  expect((await get('/employees')).total).toBe(employeesBefore)
  expect((await get('/hr-transactions')).total).toBe(transactionsBefore)
})
