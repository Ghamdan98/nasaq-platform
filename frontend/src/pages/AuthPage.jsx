import { useState } from 'react'
import { login } from '../services/api'
import { useSystemSettings } from '../system/SystemSettingsContext'
import { BrandLogo, SystemFooter } from '../system/Brand'

export default function AuthPage({ onAuthenticated }) {
  const { settings } = useSystemSettings()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')

  const submit = async (event) => {
    event.preventDefault()
    setLoading(true)
    setError('')
    try {
      onAuthenticated(await login({ email, password }))
    } catch (requestError) {
      setError(requestError.message)
    } finally {
      setLoading(false)
    }
  }

  return (
    <main dir={settings['display.locale'].startsWith('ar') ? 'rtl' : 'ltr'} className="flex min-h-screen items-center justify-center bg-slate-100 p-4">
      <form onSubmit={submit} className="w-full max-w-md space-y-5 rounded-3xl border border-slate-200 bg-white p-7 shadow-xl">
        <div>
          <BrandLogo /><p className="text-xs font-bold tracking-wider text-blue-600">{settings['branding.name_en']}</p>
          <p className="mt-2 font-bold">{settings['branding.name_ar']}</p>
          <h1 className="mt-2 text-2xl font-black text-slate-900">تسجيل الدخول</h1>
          <p className="mt-1 text-sm text-slate-500">سجّل الدخول للوصول إلى المعاملات المحفوظة.</p>
        </div>
        {error && <p role="alert" className="rounded-xl bg-red-50 p-3 text-sm text-red-700">{error}</p>}
        <label className="block text-sm font-semibold text-slate-700">
          البريد الإلكتروني
          <input required type="email" autoComplete="username" className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2.5 outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100" value={email} onChange={(event) => setEmail(event.target.value)} />
        </label>
        <label className="block text-sm font-semibold text-slate-700">
          كلمة المرور
          <input required type="password" autoComplete="current-password" className="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2.5 outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100" value={password} onChange={(event) => setPassword(event.target.value)} />
        </label>
        <button type="submit" disabled={loading} className="w-full rounded-xl bg-blue-600 px-4 py-3 font-bold text-white disabled:opacity-50">
          {loading ? 'جاري تسجيل الدخول...' : 'دخول'}
        </button>
        <SystemFooter />
      </form>
    </main>
  )
}
