const API_BASE = import.meta.env.VITE_API_BASE_URL || '/api'
export const publicSystemSettings = () => request('/system/public-settings', { token: '' })
export const developerSettings = () => request('/developer/settings')
export const saveDeveloperSettings = body => request('/developer/settings', { method: 'PUT', body })
export const resetDeveloperSettings = body => request('/developer/settings/reset', { method: 'POST', body })
export const restoreDeveloperSettings = body => request('/developer/settings/restore', { method: 'POST', body })
export const developerHealth = () => request('/developer/health')
export const developerAudit = (page = 1) => request(`/developer/settings/audit?page=${page}`)
export const exportDeveloperSettings = () => download('/developer/settings/export')
export const uploadBranding = file => { const body = new FormData(); body.append('file', file); return request('/developer/branding/upload', { method: 'POST', body }) }

export const getProfile = () => request('/auth/profile')
export const userPermissions = () => request('/users/permissions')
export const listUsers = filters => request(`/users?${new URLSearchParams(Object.entries(filters).filter(([, value]) => value !== ''))}`)
export const saveUser = (body, id) => request(id ? `/users/${id}` : '/users', { method: id ? 'PUT' : 'POST', body })
export const userAudit = (id, page = 1) => request(`/users/${id}/audit?page=${page}`)

export const managedRecords = filters => request(`/data-management?${new URLSearchParams(filters)}`)
export const inspectManagedRecord = (kind, id) => request(`/data-management/${kind}/${id}`)
export const deleteManagedRecord = (kind, id, body) => request(`/data-management/${kind}/${id}`, { method: 'DELETE', body })
export const editManagedAdvance = (id, body) => request(`/data-management/advances/${id}`, { method: 'PUT', body })
export const archiveManagedRecord = (kind, id, body) => request(`/data-management/${kind}/${id}/archive`, { method: 'POST', body })

function authToken() {
  return window.localStorage.getItem('auth_token') || window.localStorage.getItem('token') || ''
}

function buildError(payload) {
  if (payload?.errors) {
    const details = Object.values(payload.errors).flat().join(' • ')
    if (details) return details
  }

  return payload?.message || 'حدث خطأ أثناء تنفيذ الطلب. يرجى المحاولة مرة أخرى.'
}

async function request(path, { method = 'GET', body, token = authToken() } = {}) {
  const response = await fetch(`${API_BASE}${path}`, {
    method,
    headers: {
      Accept: 'application/json',
      ...(body && !(body instanceof FormData) ? { 'Content-Type': 'application/json' } : {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    ...(body ? { body: body instanceof FormData ? body : JSON.stringify(body) } : {}),
  })

  const payload = await response.json().catch(() => {
    throw new Error('تعذر قراءة استجابة الخادم. تحقق من تشغيل Laravel.')
  })

  if (!response.ok) {
    if (response.status === 401 && token) {
      for (const key of ['auth_token', 'token', 'user']) window.localStorage.removeItem(key)
      window.dispatchEvent(new Event('auth-expired'))
    }
    const error = new Error(buildError(payload))
    error.errors = payload.errors || {}
    throw error
  }

  return payload
}

async function download(path) {
    const response = await fetch(`${API_BASE}${path}`, {
      headers: {
        Accept: 'application/octet-stream',
        ...(authToken() ? { Authorization: `Bearer ${authToken()}` } : {}),
      },
    })

    if (!response.ok) {
      const payload = await response.json().catch(() => ({}))
      throw new Error(buildError(payload))
    }

    const disposition = response.headers.get('Content-Disposition') || ''
    const encodedFilename = disposition.match(/filename\*=UTF-8''([^;]+)/i)?.[1]
    const plainFilename = disposition.match(/filename="?([^";]+)"?/i)?.[1]

    return {
      blob: await response.blob(),
      filename: encodedFilename ? decodeURIComponent(encodedFilename) : plainFilename || 'transaction-export',
    }
  }


export function calculateSettlement(form) {
  const endpoint = form.transaction_type === 'leave_settlement'
    ? '/hr-transactions/calculate'
    : '/settlements/calculate'

  return request(endpoint, { method: 'POST', body: normalizeForm(form) })
}

function normalizeForm(form) {
  const payload = { ...form, employee_id: form.employee_id || null, contract_id: form.contract_id || null }
  if (payload.leave_balance_method === 'date_accrual') {
    delete payload.available_days
    delete payload.accrued_days
    delete payload.remaining_leave_days
    if (payload.transaction_type === 'leave_settlement' && payload.leave_transaction_type === 'termination_leave_compensation') {
      delete payload.requested_leave_days
      delete payload.days_to_compensate
    }
  }
  return payload
}

export async function login(credentials) {
  const payload = await request('/auth/login', { method: 'POST', body: credentials, token: '' })
  window.localStorage.setItem('auth_token', payload.token)
  window.localStorage.setItem('user', JSON.stringify(payload.user))
  return payload.user
}

export function saveHrTransaction(form) {
  return request('/hr-transactions', { method: 'POST', body: normalizeForm(form) })
}

export function listEmployees(filters = {}) {
  return request(`/employees?${new URLSearchParams(Object.entries(filters).filter(([, value]) => value !== '' && value != null))}`)
}

export const getEmployee = id => request(`/employees/${encodeURIComponent(id)}`)
export const employeeCompanies = () => request('/employees/companies')
export const saveEmployee = (data, id) => request(id ? `/employees/${id}` : '/employees', { method: id ? 'PUT' : 'POST', body: data })
export const employeeImports = page => request(`/employee-imports?page=${page || 1}`)
export const employeeImportPreview = (file, mode) => {
  const body = new FormData(); body.append('file', file); body.append('mode', mode)
  return request('/employee-imports/preview', { method: 'POST', body })
}
export const getEmployeeImport = (id, page = 1) => request(`/employee-imports/${id}?page=${page}`)
export const commitEmployeeImport = id => request(`/employee-imports/${id}/commit`, { method: 'POST', body: {} })
export const employeeTemplate = () => download('/employee-imports/template')
export const employeeImportErrors = id => download(`/employee-imports/${id}/errors`)

export const listAdvances = filters => request(`/advances?${new URLSearchParams(Object.entries(filters || {}).filter(([, value]) => value !== '' && value != null))}`)
export const getAdvance = id => request(`/advances/${id}`)
export const createAdvance = body => request('/advances', { method: 'POST', body })
export const advanceAction = (id, action, body) => request(`/advances/${id}/${action}`, { method: action === 'flags' ? 'PATCH' : 'POST', body })
export const advanceDocument = (id, file, category) => { const body = new FormData(); body.append('file', file); body.append('category', category); return request(`/advances/${id}/documents`, { method: 'POST', body }) }
export const downloadAdvanceDocument = (id, documentId) => download(`/advances/${id}/documents/${documentId}`)
export const employeeAdvanceSummary = id => request(`/employees/${id}/advances`)
export const advanceReports = filters => request(`/advance-reports?${new URLSearchParams(Object.entries(filters).filter(([, value]) => value !== '' && value != null))}`)
export const exportAdvanceReport = (filters, format) => download(`/advance-reports?${new URLSearchParams({ ...Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== '' && value != null)), format })}`)
export const financeQueue = filters => request(`/settlement-finance?${new URLSearchParams(Object.entries(filters || {}).filter(([, value]) => value !== '' && value != null))}`)
export const settlementFinance = id => request(`/hr-transactions/${id}/finance`)
export const settlementFinanceAction = (id, action, body) => request(`/hr-transactions/${id}/${action}`, { method: action === 'advance-decision' ? 'PUT' : 'POST', body })

export function listHrTransactions(filters = {}) {
  const query = new URLSearchParams(
    Object.entries(filters).filter(([, value]) => value !== '' && value != null),
  )

  return request(`/hr-transactions${query.size ? `?${query}` : ''}`)
}

export function getHrTransaction(id) {
  return request(`/hr-transactions/${encodeURIComponent(id)}`)
}

export function adjustTransactionItem(transactionId, itemId, adjustment) {
  return request(
    `/hr-transactions/${encodeURIComponent(transactionId)}/items/${encodeURIComponent(itemId)}/adjust`,
    { method: 'PATCH', body: adjustment },
  )
}

export function revertTransactionAdjustment(transactionId, itemId, itemType = 'entitlement') {
  return request(
    `/hr-transactions/${encodeURIComponent(transactionId)}/items/${encodeURIComponent(itemId)}/revert-adjustment`,
    { method: 'POST', body: { item_type: itemType } },
  )
}

export function submitTransactionForReview(transactionId) {
  return request(`/hr-transactions/${encodeURIComponent(transactionId)}/submit-review`, { method: 'POST' })
}

export function approveTransaction(transactionId) {
  return request(`/hr-transactions/${encodeURIComponent(transactionId)}/approve`, { method: 'POST' })
}

export function rejectTransaction(transactionId, reason) {
  return request(`/hr-transactions/${encodeURIComponent(transactionId)}/reject`, { method: 'POST', body: { reason } })
}

export function requestTransactionChanges(transactionId, reason) {
  return request(`/hr-transactions/${encodeURIComponent(transactionId)}/request-changes`, { method: 'POST', body: { reason } })
}

export function cancelTransaction(transactionId, reason) {
  return request(`/hr-transactions/${encodeURIComponent(transactionId)}/cancel`, { method: 'POST', body: { reason } })
}

export function reopenApprovedTransaction(transactionId, reason) {
  return request(`/hr-transactions/${encodeURIComponent(transactionId)}/reopen`, { method: 'POST', body: { reason } })
}

export function downloadTransactionExcel(transactionId) {
  return download(`/hr-transactions/${encodeURIComponent(transactionId)}/export/excel`)
}

export function downloadTransactionPdf(transactionId) {
  return download(`/hr-transactions/${encodeURIComponent(transactionId)}/export/pdf`)
}

export function getTransactionPrintData(transactionId) {
  return request(`/hr-transactions/${encodeURIComponent(transactionId)}/print`)
}

export function recordTransactionPrint(transactionId) {
  return request(`/hr-transactions/${encodeURIComponent(transactionId)}/print`, { method: 'POST' })
}

export async function logout() {
  try { await request('/auth/logout', { method: 'POST' }) }
  finally {
    for (const key of ['auth_token', 'token', 'user']) window.localStorage.removeItem(key)
  }
}
