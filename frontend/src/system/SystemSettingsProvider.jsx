import { useCallback, useEffect, useRef, useState } from 'react'
import { publicSystemSettings } from '../services/api'
import { applyTheme, defaultSettings } from './settings'
import { SystemSettingsContext } from './SystemSettingsContext'

function bootstrap() {
  try { return { ...defaultSettings, ...JSON.parse(localStorage.getItem('public-system-settings') || '{}') } } catch { return defaultSettings }
}
const initial = bootstrap()
applyTheme(initial)
export function SystemSettingsProvider({ children }) {
  const [saved, setSaved] = useState(initial)
  const savedRef = useRef(initial)
  const previewActive = useRef(false)
  const [preview, setPreview] = useState(null)
  const [unavailable, setUnavailable] = useState(false)
  const settings = preview || saved
  const accept = useCallback(values => { const next = { ...defaultSettings, ...values }; localStorage.setItem('public-system-settings', JSON.stringify(next)); savedRef.current = next; previewActive.current = false; setSaved(next); setPreview(null); applyTheme(next) }, [])
  const refresh = useCallback(() => publicSystemSettings().then(response => { if (!response.settings || typeof response.settings !== 'object' || Array.isArray(response.settings)) throw new Error('Invalid settings response'); if (!previewActive.current) accept(response.settings); setUnavailable(false) }).catch(() => setUnavailable(true)), [accept])
  const cancelPreview = useCallback(() => { previewActive.current = false; setPreview(null); applyTheme(savedRef.current) }, [])
  useEffect(() => { refresh(); window.addEventListener('focus', refresh); return () => window.removeEventListener('focus', refresh) }, [refresh])
  useEffect(() => { applyTheme(settings) }, [settings])
  return <SystemSettingsContext.Provider value={{ settings, saved, preview: values => { const next = { ...saved, ...values }; previewActive.current = true; setPreview(next); applyTheme(next) }, cancelPreview, accept, refresh, unavailable }}>{children}</SystemSettingsContext.Provider>
}
