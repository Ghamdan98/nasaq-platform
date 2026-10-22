import { Bell, Search, ShieldCheck } from 'lucide-react'
import { useSystemSettings } from '../../system/SystemSettingsContext'

export default function AppHeader({ user, onLogout }) {
  const { settings } = useSystemSettings()
  return (
    <header className="flex flex-col gap-4 rounded-[28px] border border-slate-200 bg-white/80 p-4 shadow-sm backdrop-blur md:flex-row md:items-center md:justify-between">
      <div>
        <p className="text-[10px] font-bold tracking-[0.18em] text-blue-600">{settings['branding.name_en']}</p>
        <h2 className="mt-1 text-2xl font-bold text-slate-900">{settings['branding.name_ar']}</h2>
      </div>

      <div className="flex items-center gap-3 md:gap-4">
        <label className="relative hidden min-w-[280px] md:block">
          <Search className="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
          <input
            disabled title="Unavailable" aria-label="البحث عن موظف أو عقد أو معلومة"
            className="w-full rounded-2xl border border-slate-200 bg-slate-50 py-2.5 pr-10 pl-4 text-sm text-slate-700 outline-none transition placeholder:text-slate-400 focus:border-blue-400 focus:bg-white focus:ring-4 focus:ring-blue-100"
            placeholder="البحث عن موظف، عقد، أو أي معلومة..."
          />
        </label>

        <button
          type="button"
          disabled title="Unavailable" aria-label="الإشعارات"
          className="relative flex h-11 w-11 items-center justify-center rounded-2xl border border-slate-200 bg-slate-50 text-slate-600 transition hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700"
        >
          <Bell className="h-4 w-4" />

        </button>

        <div className="flex items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-2 py-1.5">
          <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-blue-600 text-sm font-bold text-white">
            {user.name.charAt(0)}
          </div>
          <div className="hidden text-right md:block">
            <div className="text-sm font-bold text-slate-800">{user.name}</div>
            <div className="flex items-center gap-1 text-[11px] text-slate-500">
              <ShieldCheck className="h-3.5 w-3.5 text-emerald-500" />
              {user.role}
            </div>
          </div>
          <button type="button" onClick={onLogout}>Logout</button>
        </div>
      </div>
    </header>
  )
}
