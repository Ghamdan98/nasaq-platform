// Bootstrap fallback only. Server registry is the source of truth after loading.
export const defaultSettings = {
  'appearance.dark_background': '#101827', 'appearance.dark_card': '#1d293b', 'appearance.dark_text': '#edf2fa',
  'branding.name_ar': 'نظام المخالصات', 'branding.name_en': 'GHAMDAN SYS', 'branding.short_name': 'HR', 'branding.description': 'إدارة المخالصات والموظفين والسلف', 'branding.owner': '', 'branding.footer': '', 'branding.copyright': 'جميع الحقوق محفوظة', 'branding.website': '',
  'branding.logo': null, 'branding.sidebar_logo': null, 'branding.favicon': null, 'branding.print_logo': null,
  'appearance.primary': '#2563eb', 'appearance.secondary': '#4f46e5', 'appearance.accent': '#0891b2', 'appearance.background': '#f1f5f9', 'appearance.card': '#ffffff', 'appearance.text': '#0f172a', 'appearance.success': '#15803d', 'appearance.warning': '#b45309', 'appearance.error': '#b91c1c', 'appearance.font_ar': 'Tahoma', 'appearance.font_en': 'Segoe UI', 'appearance.radius': 16, 'appearance.mode': 'light', 'appearance.table_density': 'comfortable', 'appearance.logo_size': 44, 'appearance.sidebar': 'dark',
  'display.locale': 'ar-SA', 'display.date_format': 'dd/MM/yyyy', 'display.numbering': 'arab', 'display.currency': 'SAR', 'display.timezone': 'Asia/Riyadh', 'display.rows': 20, 'display.money_decimals': 2,
  'documents.settlement_prefix': 'SET', 'documents.leave_prefix': 'LEV', 'documents.advance_prefix': 'ADV', 'reports.organization': '', 'reports.paper': 'A4', 'reports.orientation': 'portrait', 'reports.show_logo': true, 'reports.footer': '',
}
let current = defaultSettings
export const getSystemSetting = key => current[key] ?? defaultSettings[key]
export const installFormattingSettings = settings => { current = settings }
export const brandingAsset = id => id ? `${import.meta.env.VITE_API_BASE_URL || '/api'}/system/branding/${encodeURIComponent(id)}` : null

export function applyTheme(settings) {
  const root = document.documentElement
  const dark = settings['appearance.mode'] === 'dark'
  const colors = { primary: settings['appearance.primary'], secondary: settings['appearance.secondary'], accent: settings['appearance.accent'], success: settings['appearance.success'], warning: settings['appearance.warning'], error: settings['appearance.error'], background: settings[dark ? 'appearance.dark_background' : 'appearance.background'], card: settings[dark ? 'appearance.dark_card' : 'appearance.card'], text: settings[dark ? 'appearance.dark_text' : 'appearance.text'] }
  for (const [key, value] of Object.entries(colors)) root.style.setProperty(`--color-${key}`, value)
  // Bridge the installed Tailwind palette to design tokens; existing screens inherit the theme.
  for (const [family, source] of Object.entries({ blue: 'primary', indigo: 'secondary', cyan: 'accent', emerald: 'success', green: 'success', amber: 'warning', orange: 'warning', red: 'error', rose: 'error' })) {
    for (const [shade, percent] of Object.entries({ 50: 5, 100: 10, 200: 25, 300: 45, 400: 70, 500: 90, 600: 100 })) root.style.setProperty(`--color-${family}-${shade}`, shade === '600' ? `var(--color-${source})` : `color-mix(in srgb, var(--color-${source}) ${percent}%, var(--color-card))`)
    for (const [shade, percent] of Object.entries({ 700: 85, 800: 65, 900: 45, 950: 25 })) root.style.setProperty(`--color-${family}-${shade}`, `color-mix(in srgb, var(--color-${source}) ${percent}%, ${dark ? 'white' : 'black'})`)
  }
  root.style.setProperty('--color-white', 'var(--color-card)')
  for (const [shade, color] of Object.entries({ 50: 'color-mix(in srgb, var(--color-background) 45%, var(--color-card))', 100: 'var(--color-background)', 200: 'color-mix(in srgb, var(--color-text) 12%, var(--color-card))', 300: 'color-mix(in srgb, var(--color-text) 25%, var(--color-card))', 400: 'color-mix(in srgb, var(--color-text) 55%, var(--color-card))', 500: 'color-mix(in srgb, var(--color-text) 70%, var(--color-card))', 600: 'color-mix(in srgb, var(--color-text) 80%, var(--color-card))', 700: 'var(--color-text)', 800: 'var(--color-text)', 900: 'var(--color-text)', 950: 'var(--color-text)' })) root.style.setProperty(`--color-slate-${shade}`, color)
  root.style.setProperty('--border-radius', `${settings['appearance.radius']}px`)
  for (const [size, factor] of Object.entries({ lg: 0.5, xl: 0.75, '2xl': 1, '3xl': 1.5 })) root.style.setProperty(`--radius-${size}`, `${settings['appearance.radius'] * factor}px`)
  root.style.setProperty('--logo-size', `${settings['appearance.logo_size']}px`)
  root.style.setProperty('--font-ar', `"${settings['appearance.font_ar']}", sans-serif`)
  root.style.setProperty('--font-en', `"${settings['appearance.font_en']}", sans-serif`)
  root.style.setProperty('--table-padding', settings['appearance.table_density'] === 'compact' ? '0.35rem' : '0.8rem')
  root.style.setProperty('--sidebar-surface', settings['appearance.sidebar'] === 'dark' ? settings['appearance.dark_background'] : colors.card)
  root.style.setProperty('--sidebar-text', settings['appearance.sidebar'] === 'dark' ? settings['appearance.dark_text'] : colors.text)
  root.style.setProperty('--sidebar-background', settings['appearance.sidebar'] === 'dark' ? settings['appearance.dark_card'] : colors.background)
  root.dataset.theme = dark ? 'dark' : 'light'
  root.dataset.sidebar = settings['appearance.sidebar']
  root.lang = settings['display.locale'].startsWith('ar') ? 'ar' : 'en'
  root.dir = root.lang === 'ar' ? 'rtl' : 'ltr'
  document.title = settings[root.lang === 'ar' ? 'branding.name_ar' : 'branding.name_en']
  let favicon = document.querySelector('link[rel="icon"]')
  if (!favicon) { favicon = document.createElement('link'); favicon.rel = 'icon'; document.head.appendChild(favicon) }
  favicon.href = brandingAsset(settings['branding.favicon']) || '/favicon.svg'
  favicon.type = settings['branding.favicon'] ? 'image/png' : 'image/svg+xml'
  let printStyle = document.getElementById('system-print-settings')
  if (!printStyle) { printStyle = document.createElement('style'); printStyle.id = 'system-print-settings'; document.head.appendChild(printStyle) }
  printStyle.textContent = `@media print { @page { size: ${settings['reports.paper']} ${settings['reports.orientation']}; } }`
  installFormattingSettings(settings)
}
