export function hasUserPermission(user, permission) {
  if (permission === 'manage-system-settings') return user?.role === 'developer' && user?.effective_permissions?.includes(permission)
  if (user?.role === 'developer') return false
  if (user?.role === 'admin') return true
  if (Array.isArray(user?.effective_permissions)) return user.effective_permissions.includes(permission)
  if (user?.denied_permissions_json?.includes(permission)) return false
  if (user?.permissions_json?.includes(permission)) return true
  const defaults = {
    hr: ['manage_employees', 'view_employee_sensitive', 'view_advances', 'manage_advances', 'view_transactions', 'create_transactions', 'edit_transactions', 'submit_transactions', 'export_transactions'],
    reviewer: ['view_advances', 'review_financial_settlements', 'view_transactions', 'review_transactions', 'approve_transactions', 'reject_transactions', 'request_transaction_changes', 'export_transactions'],
  }
  return defaults[user?.role]?.includes(permission) || false
}

export function canAccessFinance(user) {
  return hasUserPermission(user, 'view_transactions') && ['review_financial_settlements', 'review_advance_legal', 'post_settlements', 'pay_settlements', 'reverse_settlements'].some(permission => hasUserPermission(user, permission))
}
