import { getSystemSetting } from '../system/settings'
export function formatCurrency(value) {
  const amount = Number(value ?? 0)

  if (!Number.isFinite(amount)) {
    return '0.00 ر.س'
  }

  return new Intl.NumberFormat(getSystemSetting('display.locale'), {
    style: 'currency',
    currency: getSystemSetting('display.currency'),
    numberingSystem: getSystemSetting('display.numbering'),
    minimumFractionDigits: getSystemSetting('display.money_decimals'),
    maximumFractionDigits: getSystemSetting('display.money_decimals'),
  }).format(amount)
}

export function formatNumber(value) {
  const amount = Number(value ?? 0)

  if (!Number.isFinite(amount)) {
    return '0'
  }

  return new Intl.NumberFormat(getSystemSetting('display.locale'), {
    numberingSystem: getSystemSetting('display.numbering'),
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
  }).format(amount)
}
