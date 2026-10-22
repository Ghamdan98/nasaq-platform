import { test, expect } from '@playwright/test'

test('admin deletes an unspent test advance through management screen with explicit confirmation', async ({ playwright, request }) => {
  const api = process.env.FINANCE_API_URL || 'http://127.0.0.1:8001/api'
  const login = await request.post(`${api}/auth/login`, { headers: { Accept: 'application/json' }, data: { email: 'finance-e2e@example.test', password: 'FinanceE2E123!' } })
  expect(login.status()).toBe(200)
  const auth = await login.json()
  const headers = { Accept: 'application/json', Authorization: `Bearer ${auth.token}` }
  const stamp = Date.now()
  const employeeResponse = await request.post(`${api}/employees`, { headers, data: { employee_number: `DM-${stamp}`, full_name: 'اختبار إدارة البيانات', company: 'اختبار معزول', hire_date: '2020-01-01', status: 'active', basic_salary: 6000 } })
  expect(employeeResponse.status()).toBe(201)
  const employee = await employeeResponse.json()
  const reference = `DM-ADV-${stamp}`
  const response = await request.post(`${api}/advances`, { headers, data: { employee_id: employee.id, reference_number: reference, original_amount: 5000 } })
  expect(response.status()).toBe(201)
  const advance = await response.json()
  const browser = await playwright.chromium.launch({ executablePath: process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe', args: ['--disable-gpu', '--renderer-process-limit=1'] })
  try {
    const page = await browser.newPage()
    await page.addInitScript(({ token, user }) => { localStorage.setItem('auth_token', token); localStorage.setItem('user', JSON.stringify(user)) }, auth)
    await page.route('**/api/**', async route => {
      const url = new URL(route.request().url())
      const result = await route.fetch({ url: api + url.pathname.replace(/^\/api/, '') + url.search })
      await route.fulfill({ response: result })
    })
    await page.goto((process.env.E2E_BASE_URL || 'http://127.0.0.1:5173') + '/#/data-management')
    await page.getByRole('textbox', { name: 'بحث', exact: true }).fill(reference)
    const row = page.getByRole('row').filter({ hasText: reference })
    await row.getByRole('button', { name: 'إدارة السجل' }).click()
    const remove = page.getByRole('button', { name: 'حذف نهائي مع نسخة احتياطية' })
    await expect(remove).toBeDisabled()
    await page.getByLabel('سبب الإجراء (10 أحرف على الأقل)').fill('حذف بيانات تجريبية من الاختبار المعزول')
    await page.getByRole('checkbox', { name: /أؤكد أن السجل تجريبي/ }).check()
    await page.getByRole('textbox', { name: 'رمز تأكيد الحذف' }).fill(`DELETE-${advance.id}`)
    const deleted = page.waitForResponse(r => r.request().method() === 'DELETE' && r.url().includes(`/advances/${advance.id}`))
    await remove.click()
    expect((await deleted).status()).toBe(200)
    await expect(page.getByRole('status')).toHaveText('تم الحذف وحفظ النسخة الاحتياطية وسجل التدقيق.')
    await expect(row).toHaveCount(0)
  } finally { await browser.close() }
  expect((await request.get(`${api}/advances/${advance.id}`, { headers })).status()).toBe(404)
})
