import { test, expect } from '@playwright/test'

test('real HTTP financial scenarios and unsupported termination contracts', async ({ request }) => {
  const login = await request.post('/api/auth/login', { data: { email: 'hr@audit.test', password: 'audit-test-password' } })
  expect(login.status()).toBe(200)
  const headers = { Accept: 'application/json', Authorization: `Bearer ${(await login.json()).token}` }
  const base = { transaction_type: 'final_settlement', employee_id: 1, contract_id: 1,
    hire_date: '2022-01-01', last_working_date: '2026-01-01', basic_salary: 6000,
    termination_type: 'termination', actual_notice_days: 60, unpaid_days: 0 }
  for (const [hire, type, expected] of [
    ['2022-01-01', 'termination', '12000'], ['2019-01-01', 'termination', '27000'],
    ['2022-01-01', 'resignation', '4000'], ['2019-01-01', 'resignation', '18000'],
    ['2016-01-01', 'resignation', '45000'],
  ]) {
    for (const endpoint of ['/api/settlements/calculate', '/api/hr-transactions/calculate']) {
      const response = await request.post(endpoint, { headers, data: { ...base, hire_date: hire, termination_type: type } })
      expect(response.status()).toBe(200)
      const result = await response.json()
      expect(result.entitlements.find(i => i.code === 'end_of_service').amount).toBe(expected)
      expect(Number(result.gross_entitlements) - Number(result.total_deductions)).toBe(Number(result.net_settlement))
    }
  }
  for (const type of ['article_80', 'article_81']) {
    const response = await request.post('/api/hr-transactions/calculate', { headers, data: { ...base, termination_type: type } })
    expect(response.status()).toBe(200)
    expect((await response.json()).review_status).toBe('requires_legal_review')
  }
  for (const type of ['contract_expiry', 'mutual_agreement', 'employer_termination', 'employee_termination', 'article_77', 'death', 'disability', 'force_majeure', 'business_closure']) {
    const response = await request.post('/api/hr-transactions/calculate', { headers, data: { ...base, termination_type: type } })
    expect(response.status(), type).toBe(422)
  }
  const partial = await request.post('/api/hr-transactions/calculate', { headers, data: { ...base, hire_date: '2020-02-29', last_working_date: '2024-05-15' } })
  expect(partial.status()).toBe(200)
  const result = await partial.json()
  expect(result.service_period).toMatchObject({ years: 4, months: 2, days: 16, total_days: 1537 })
  expect(result.entitlements.find(i => i.code === 'end_of_service').amount).toBe('12633.33')
  expect((await request.post('/api/hr-transactions/calculate', { headers: { Accept: 'application/json' }, data: base })).status()).toBe(401)
  expect((await request.post('/api/auth/login', { headers: { Accept: 'application/json' }, data: { email: 'hr@audit.test', password: 'incorrect-password' } })).status()).toBe(401)
  expect((await request.get('/api/auth/profile', { headers: { Accept: 'application/json', Authorization: 'Bearer invalid-token' } })).status()).toBe(401)
  expect((await request.post('/api/auth/logout', { headers })).status()).toBe(200)
  expect((await request.get('/api/auth/profile', { headers })).status()).toBe(401)
})
