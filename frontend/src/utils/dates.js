import { getSystemSetting } from '../system/settings'
export function formatDate(value, fallback = '-') {
  if (!value) return fallback

  const date = new Date(value)

  if (Number.isNaN(date.getTime())) {
    return value
  }

  const parts = /^\d{4}-\d{2}-\d{2}$/.test(value) ? Object.fromEntries(['year','month','day'].map((key,index) => [key,value.split('-')[index]])) : Object.fromEntries(new Intl.DateTimeFormat('en-GB', {
    calendar: 'gregory', timeZone: getSystemSetting('display.timezone'),
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(date).filter(part => ['year','month','day'].includes(part.type)).map(part => [part.type,part.value]))
  const number = (key, width) => new Intl.NumberFormat(getSystemSetting('display.locale'), { useGrouping: false, numberingSystem: getSystemSetting('display.numbering'), minimumIntegerDigits: width }).format(Number(parts[key]))
  return getSystemSetting('display.date_format').replace('yyyy',number('year',4)).replace('MM',number('month',2)).replace('dd',number('day',2))
}
export function formatDateTime(value, fallback = '-') {
  if (!value) return fallback
  const date=new Date(value)
  if (Number.isNaN(date.getTime())) return fallback
  return formatDate(value,fallback)+' '+new Intl.DateTimeFormat(getSystemSetting('display.locale'),{timeZone:getSystemSetting('display.timezone'),numberingSystem:getSystemSetting('display.numbering'),hour:'2-digit',minute:'2-digit'}).format(date)
}
export function todayForInput() {
  const parts=Object.fromEntries(new Intl.DateTimeFormat('en-GB',{calendar:'gregory',timeZone:getSystemSetting('display.timezone'),year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(new Date()).filter(part=>['year','month','day'].includes(part.type)).map(part=>[part.type,part.value]))
  return `${parts.year}-${parts.month}-${parts.day}`
}
