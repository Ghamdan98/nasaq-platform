import { brandingAsset } from './settings'
import { useSystemSettings } from './SystemSettingsContext'
export function BrandLogo({ variant = 'logo' }) {
  const { settings } = useSystemSettings()
  const id = settings[`branding.${variant}`] || settings['branding.logo']
  return id ? <img className="system-logo" src={brandingAsset(id)} alt={settings['branding.name_ar']} /> : <span className="system-logo system-logo-fallback">{settings['branding.short_name']}</span>
}
export function SystemFooter() {
  const { settings } = useSystemSettings()
  return <footer className="system-footer"><p>{settings['branding.footer']}</p><p>{settings['branding.copyright']} {settings['branding.owner']}</p>{settings['branding.website'] && <a href={settings['branding.website']} target="_blank" rel="noreferrer">{settings['branding.website']}</a>}</footer>
}
