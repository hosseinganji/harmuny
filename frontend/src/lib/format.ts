import { locale } from './i18n'

const PERSIAN_DIGITS = '۰۱۲۳۴۵۶۷۸۹'

/** Digits in the UI language (Persian digits in Persian). */
export function num(value: string | number): string {
  const text = String(value)
  return locale.value === 'fa' ? text.replace(/\d/g, (d) => PERSIAN_DIGITS[Number(d)]) : text
}

export function duration(seconds: number | null | undefined): string {
  if (!seconds || !isFinite(seconds) || seconds < 0) return '0:00'
  const s = Math.floor(seconds)
  const h = Math.floor(s / 3600)
  const m = Math.floor((s % 3600) / 60)
  const sec = String(s % 60).padStart(2, '0')
  return num(h ? `${h}:${String(m).padStart(2, '0')}:${sec}` : `${m}:${sec}`)
}

/** "2 h 14 min" style total length. */
export function longDuration(seconds: number): string {
  const h = Math.floor(seconds / 3600)
  const m = Math.round((seconds % 3600) / 60)
  if (locale.value === 'fa') return num(h ? `${h} ساعت و ${m} دقیقه` : `${m} دقیقه`)
  return h ? `${h} h ${m} min` : `${m} min`
}

export function bytes(n: number | null | undefined): string {
  if (!n) return '0 MB'
  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  const i = Math.min(units.length - 1, Math.floor(Math.log(n) / Math.log(1024)))
  return num(`${(n / 1024 ** i).toFixed(i >= 2 ? 1 : 0)} ${units[i]}`)
}

export function relativeTime(iso: string | null | undefined): string {
  if (!iso) return ''
  const rtf = new Intl.RelativeTimeFormat(locale.value, { numeric: 'auto' })
  const diff = (new Date(iso).getTime() - Date.now()) / 1000
  const steps: [Intl.RelativeTimeFormatUnit, number][] = [
    ['year', 31536000], ['month', 2592000], ['week', 604800], ['day', 86400], ['hour', 3600], ['minute', 60],
  ]
  for (const [unit, secs] of steps) {
    if (Math.abs(diff) >= secs) return rtf.format(Math.round(diff / secs), unit)
  }
  return rtf.format(Math.round(diff), 'second')
}

export function count(n: number): string {
  return new Intl.NumberFormat(locale.value).format(n)
}
