import { useEffect, useState } from 'react'
import { getProfile, logout } from './services/api'
import { hasUserPermission } from './utils/permissions'
import AppHeader from './components/layout/AppHeader'
import AppSidebar from './components/layout/AppSidebar'
import AuthPage from './pages/AuthPage'
import SettlementCalculatorPage from './pages/SettlementCalculatorPage'
import TransactionDetailsPage from './pages/TransactionDetailsPage'
import TransactionHistoryPage from './pages/TransactionHistoryPage'
import TransactionPrintPage from './pages/TransactionPrintPage'
import EmployeesPage from './pages/EmployeesPage'
import AdvancesPage from './pages/AdvancesPage'
import DataManagementPage from './pages/DataManagementPage'
import UsersPage from './pages/UsersPage'
import DeveloperSettingsPage from './pages/DeveloperSettingsPage'
import { useSystemSettings } from './system/SystemSettingsContext'
import { SystemFooter } from './system/Brand'

function readUser() {
  if (!window.localStorage.getItem('auth_token') && !window.localStorage.getItem('token')) return null
  try {
    return JSON.parse(window.localStorage.getItem('user') || 'null') || { name: 'المستخدم', role: 'hr' }
  } catch {
    return { name: 'المستخدم', role: 'hr' }
  }
}

export default function App() {
  const { settings, unavailable } = useSystemSettings()
  const [route, setRoute] = useState(window.location.hash.slice(1) || '/calculator')
  const [user, setUser] = useState(readUser)
  const authenticated = Boolean(user)
  useEffect(() => {
    if (!authenticated) return
    let active = true
    const refresh = () => getProfile().then(({ user: current }) => {
      if (!active || !current || typeof current.name !== 'string' || !current.role) return
      window.localStorage.setItem('user', JSON.stringify(current))
      setUser(previous => JSON.stringify(previous) === JSON.stringify(current) ? previous : current)
    }).catch(() => {})
    refresh()
    window.addEventListener('focus', refresh)
    return () => { active = false; window.removeEventListener('focus', refresh) }
  }, [authenticated])
  const navigate = (path) => {
    window.location.hash = path
    setRoute(path)
  }

  useEffect(() => {
    const updateRoute = () => setRoute(window.location.hash.slice(1) || '/calculator')
    window.addEventListener('hashchange', updateRoute)
    return () => window.removeEventListener('hashchange', updateRoute)
  }, [])

  useEffect(() => {
    const expired = () => setUser(null)
    window.addEventListener('auth-expired', expired)
    return () => window.removeEventListener('auth-expired', expired)
  }, [])

  const detailsMatch = route.match(/^\/transactions\/(\d+)$/)
  const printMatch = route.match(/^\/transactions\/(\d+)\/print$/)
  const isHistory = route === '/transactions'
  const isEmployees = route === '/employees'
  const isAdvances = route === '/advances' || route === '/financial-review'
  const hasPermission = permission => hasUserPermission(user, permission)

  if (!user) {
    return <AuthPage onAuthenticated={setUser} />
  }

  return (
    <div dir={settings['display.locale'].startsWith('ar') ? 'rtl' : 'ltr'} className="min-h-screen bg-slate-100 p-4 text-slate-900 md:p-6">
      <div className="mx-auto flex max-w-[1600px] flex-col gap-6 md:flex-row">
        {!printMatch && <AppSidebar user={user} currentPage={['/developer/settings', '/users', '/data-management'].includes(route) ? route.slice(1) : isAdvances ? route.slice(1) : isEmployees ? 'employees' : detailsMatch || isHistory ? 'transactions' : 'calculator'} onNavigate={navigate} />}
        <div className={`min-w-0 flex-1 space-y-6 ${printMatch ? 'print-route' : ''}`}>
          {!printMatch && <AppHeader onLogout={async () => { try { await logout() } finally { setUser(null) } }} user={{ name: user.name || 'المستخدم', role: user.role || 'hr' }} />}
          {unavailable && <p role="status" className="system-alert no-print">تعذر تحديث إعدادات الهوية؛ يجري استخدام آخر إعدادات متاحة.</p>}
          {route === '/developer/settings' ? (
            <DeveloperSettingsPage user={user} />
          ) : user.role === 'developer' ? (
            <div className="system-panel"><p>حساب المطور مخصص لإعدادات النسخة.</p><button className="system-button" onClick={() => navigate('/developer/settings')}>فتح إعدادات المطور</button></div>
          ) : route === '/users' ? (
            <UsersPage user={user} onSessionInvalidated={() => { for (const key of ['auth_token', 'token', 'user']) window.localStorage.removeItem(key); setUser(null) }} />
          ) : route === '/data-management' ? (
            <DataManagementPage user={user} onOpenTransaction={id => navigate(`/transactions/${id}`)} onOpenAdvances={() => navigate('/advances')} />
          ) : isAdvances ? (
            <AdvancesPage key={route} user={user} initialTab={route === '/financial-review' ? 'finance' : 'advances'} onOpenTransaction={id => navigate(`/transactions/${id}`)} />
          ) : isEmployees ? (
            <EmployeesPage user={user} onCalculate={id => navigate(`/calculator?employee=${id}`)} />
          ) : isHistory ? (
            <TransactionHistoryPage onOpen={(id) => navigate(`/transactions/${id}`)} onNew={() => navigate('/calculator')} />
          ) : printMatch ? (
            <TransactionPrintPage transactionId={printMatch[1]} onBack={() => navigate(`/transactions/${printMatch[1]}`)} />
          ) : detailsMatch ? (
            <TransactionDetailsPage
              user={user}
              transactionId={detailsMatch[1]}
              permissions={{
                adjust: user.role !== 'reviewer' && hasPermission('adjust_transaction_values'),
                submit: hasPermission('submit_transactions'),
                approve: hasPermission('approve_transactions'),
                approveLegal: hasPermission('approve_legal_review_transaction'),
                reject: hasPermission('reject_transactions'),
                requestChanges: hasPermission('request_transaction_changes'),
                cancel: hasPermission('cancel_transactions'),
                reopen: hasPermission('reopen_approved_transaction'),
                export: hasPermission('export_transactions'),
              }}
              onBack={() => navigate('/transactions')}
              onPrint={() => navigate(`/transactions/${detailsMatch[1]}/print`)}
            />
          ) : (
            <SettlementCalculatorPage user={user} initialEmployeeId={new URLSearchParams(route.split('?')[1] || '').get('employee')} onOpenTransactions={() => navigate('/transactions')} />
          )}
          {!printMatch && <SystemFooter />}
        </div>
      </div>
    </div>
  )
}
