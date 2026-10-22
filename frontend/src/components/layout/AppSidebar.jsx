import {
  BriefcaseBusiness,
  CalendarDays,
  FileText,
  HelpCircle,
  LayoutDashboard,
  List,
  Settings,
  ShieldCheck,
  Users,
} from 'lucide-react'
import { advancePermission } from '../../utils/advances'
import { canAccessFinance } from '../../utils/permissions'
import { useSystemSettings } from '../../system/SystemSettingsContext'
import { BrandLogo } from '../../system/Brand'

const navItems = [
  { label: 'إعدادات المطور', icon: Settings, page: 'developer/settings' },
  { label: 'المستخدمون والصلاحيات', icon: ShieldCheck, page: 'users' },
  { label: 'إدارة البيانات', icon: Settings, page: 'data-management' },
  { label: 'لوحة التحكم', icon: LayoutDashboard },
  { label: 'إدارة الموظفين', icon: Users, page: 'employees' },
  { label: 'إدارة السلف', icon: BriefcaseBusiness, page: 'advances' },
  { label: 'المراجعة المالية', icon: ShieldCheck, page: 'financial-review' },
  { label: 'العقود', icon: BriefcaseBusiness },
  { label: 'حاسبة المخالصة', icon: FileText, page: 'calculator' },
  { label: 'سجل المعاملات', icon: List, page: 'transactions' },
  { label: 'تصفية الإجازات', icon: CalendarDays },
  { label: 'القواعد النظامية', icon: ShieldCheck },
  { label: 'الإعدادات', icon: Settings },
  { label: 'الدعم والمساعدة', icon: HelpCircle },
]

export default function AppSidebar({ currentPage, onNavigate, user }) {
  const { settings } = useSystemSettings()
  const canDevelop = user?.role === 'developer' && user.effective_permissions?.includes('manage-system-settings')
  return (
    <aside className="system-sidebar flex w-full flex-col rounded-[28px] bg-slate-950 px-4 py-5 text-slate-100 shadow-2xl shadow-slate-900/20 md:w-[280px] md:flex-none">
      <div className="mb-8 flex items-center gap-3 px-3">
        <BrandLogo variant="sidebar_logo" />
        <div>
          <p className="text-[10px] font-semibold tracking-[0.18em] text-slate-400">{settings['branding.short_name']}</p>
          <h1 className="text-lg font-bold text-white">{settings['branding.name_en']}</h1>
        </div>
      </div>

      <nav className="space-y-2">
        {navItems.filter(item => item.page === 'developer/settings' ? canDevelop : user?.role === 'developer' ? false : ['users', 'data-management'].includes(item.page) ? user?.role === 'admin' : item.page === 'advances' ? advancePermission(user, 'view_advances') : item.page === 'financial-review' ? canAccessFinance(user) : true).map(({ label, icon: Icon, page }) => {
          const active = page === currentPage
          return (
          <button
            key={label}
            disabled={!page}
            title={!page ? 'Unavailable' : undefined}
            type="button"
            onClick={() => page && onNavigate?.(`/${page}`)}
            className={`flex w-full items-center justify-between rounded-2xl px-3 py-3 text-right text-sm font-medium transition ${
              active
                ? 'bg-blue-600/15 text-blue-200 ring-1 ring-inset ring-blue-400/40'
                : 'text-slate-300 hover:bg-slate-800/80 hover:text-white'
            }`}
            aria-current={active ? 'page' : undefined}
          >
            <span className="flex items-center gap-3">
              <Icon className="h-4 w-4" />
              <span>{label}{!page && ' (Unavailable)'}</span>
            </span>
            {active ? <span className="h-2.5 w-2.5 rounded-full bg-blue-400" /> : null}
          </button>
          )
        })}
      </nav>

      <div className="mt-auto rounded-2xl border border-slate-800 bg-slate-900/80 p-4">
        <p className="text-[10px] font-semibold tracking-[0.2em] text-slate-400">SYSTEM STATUS</p>
        <div className="mt-3 flex items-center justify-between">
          <span className="text-sm text-slate-200">مؤشرات التشغيل</span>
          <span className="rounded-full bg-emerald-500/15 px-2 py-1 text-[10px] font-semibold text-emerald-300 ring-1 ring-emerald-500/30">
            غير متحقق
          </span>
        </div>
      </div>
    </aside>
  )
}
