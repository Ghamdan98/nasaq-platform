import { test, expect } from '@playwright/test'

test('admin creates a restricted user, disables sessions and resets password through real HTTP', async ({ browser, request }) => {
  test.setTimeout(120000)
  const api = process.env.FINANCE_API_URL || 'http://127.0.0.1:8001/api'
  const login = await request.post(`${api}/auth/login`, { headers: { Accept: 'application/json' }, data: { email: 'finance-e2e@example.test', password: 'FinanceE2E123!' } })
  expect(login.status()).toBe(200)
  const auth = await login.json()
  const headers = { Accept: 'application/json', Authorization: `Bearer ${auth.token}` }
  const email = `users-e2e-${Date.now()}@example.test`
  const password = 'UserPassword123!'
  let page
  try {
    page = await browser.newPage()
    await page.addInitScript(({ token, user }) => { localStorage.setItem('auth_token', token); localStorage.setItem('user', JSON.stringify(user)) }, auth)
    await page.route('**/api/**', async route => { const url = new URL(route.request().url()); const result = await route.fetch({ url: api + url.pathname.replace(/^\/api/, '') + url.search }); await route.fulfill({ response: result }) })
    await page.goto((process.env.E2E_BASE_URL || 'http://127.0.0.1:5173') + '/#/users')
    await page.getByRole('button', { name: 'إضافة مستخدم', exact: true }).click()
    await page.getByLabel('اسم المستخدم', { exact: true }).fill('مستخدم تجربة الصلاحيات')
    await page.getByLabel('البريد الإلكتروني', { exact: true }).fill(email)
    await page.getByLabel('كلمة المرور', { exact: true }).fill(password)
    await page.getByLabel('تأكيد كلمة المرور', { exact: true }).fill(password)
    await page.getByRole('checkbox', { name: 'إنشاء التصفيات', exact: true }).uncheck()
    await page.getByLabel('سبب إنشاء الحساب أو التغيير').fill('إنشاء حساب محدود لاختبار إدارة الصلاحيات')
    const creating = page.waitForResponse(r => new URL(r.url()).pathname === '/api/users' && r.request().method() === 'POST')
    await page.getByRole('button', { name: 'إنشاء الحساب', exact: true }).click()
    const created = await creating
    expect(created.status()).toBe(201)
    const managedUser = (await created.json()).user
    await expect(page.getByRole('checkbox', { name: 'إنشاء التصفيات', exact: true })).not.toBeChecked()
    const userLogin = await request.post(`${api}/auth/login`, { headers: { Accept: 'application/json' }, data: { email, password } })
    expect(userLogin.status()).toBe(200)
    const userAuth = await userLogin.json()
    const userHeaders = { Accept: 'application/json', Authorization: `Bearer ${userAuth.token}` }
    expect((await request.post(`${api}/hr-transactions`, { headers: userHeaders, data: {} })).status()).toBe(403)
    expect((await request.get(`${api}/users`, { headers: userHeaders })).status()).toBe(403)
    await page.getByRole('combobox', { name: 'حالة الحساب', exact: true }).selectOption('inactive')
    await page.getByLabel('سبب إنشاء الحساب أو التغيير').fill('تعطيل حساب الاختبار وإنهاء الجلسة السابقة')
    let saving = page.waitForResponse(r => r.url().endsWith(`/users/${managedUser.id}`) && r.request().method() === 'PUT')
    await page.getByRole('button', { name: 'حفظ الحساب والصلاحيات', exact: true }).click()
    expect((await saving).status()).toBe(200)
    expect((await request.get(`${api}/auth/profile`, { headers: userHeaders })).status()).toBe(401)
    expect((await request.post(`${api}/auth/login`, { headers: { Accept: 'application/json' }, data: { email, password } })).status()).toBe(401)
    await page.getByRole('combobox', { name: 'حالة الحساب', exact: true }).selectOption('active')
    await page.getByLabel('كلمة مرور جديدة', { exact: true }).fill('NewUserPassword123!')
    await page.getByLabel('تأكيد كلمة المرور', { exact: true }).fill('NewUserPassword123!')
    await page.getByLabel('سبب إنشاء الحساب أو التغيير').fill('إعادة تفعيل الحساب وتعيين كلمة مرور جديدة')
    saving = page.waitForResponse(r => r.url().endsWith(`/users/${managedUser.id}`) && r.request().method() === 'PUT')
    await page.getByRole('button', { name: 'حفظ الحساب والصلاحيات', exact: true }).click()
    expect((await saving).status()).toBe(200)
    expect((await request.post(`${api}/auth/login`, { headers: { Accept: 'application/json' }, data: { email, password } })).status()).toBe(401)
    expect((await request.post(`${api}/auth/login`, { headers: { Accept: 'application/json' }, data: { email, password: 'NewUserPassword123!' } })).status()).toBe(200)
    const audit = await request.get(`${api}/users/${managedUser.id}/audit`, { headers })
    expect(audit.status()).toBe(200)
    expect((await audit.json()).total).toBe(4)
    await expect(page.getByRole('heading', { name: 'سجل تغييرات الحساب' })).toBeVisible()
  } finally { await page?.close() }
})
