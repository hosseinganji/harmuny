import { fromGregorian, fromJalali, toDate, toGregorian, toJalali, todayJdn, weekday } from './calendar'
import { num } from './format'
import { locale, t } from './i18n'

export type PeriodKind = 'day' | 'week' | 'month' | 'quarter' | 'year'
export const PERIOD_KINDS: PeriodKind[] = ['day', 'week', 'month', 'quarter', 'year']

export interface Period {
  kind: PeriodKind
  from: number // JDN, inclusive
  to: number
  label: string
}

/** Persian follows the Jalali calendar (weeks from Saturday, seasons from Nowruz); English the Gregorian one. */
const jalali = () => locale.value === 'fa'
const weekStart = () => (jalali() ? 5 : 0) // Saturday / Monday (0 = Monday)

const fmt = (opts: Intl.DateTimeFormatOptions) => new Intl.DateTimeFormat(locale.value === 'fa' ? 'fa-IR' : 'en', opts)
const dayLabel = (jdn: number) => fmt({ day: 'numeric', month: 'short' }).format(toDate(jdn))
const SEASONS_FA = ['بهار', 'تابستان', 'پاییز', 'زمستان']

/** The current period of a kind, or `back` periods before it. */
export function periodAt(kind: PeriodKind, back: number): Period {
  const today = todayJdn()
  switch (kind) {
    case 'day': {
      const d = today - back
      const label = back === 0 ? t('reports.today') : back === 1 ? t('history.yesterday') : fmt({ weekday: 'long', day: 'numeric', month: 'long' }).format(toDate(d))
      return { kind, from: d, to: d, label }
    }
    case 'week': {
      const from = today - ((weekday(today) - weekStart() + 7) % 7) - 7 * back
      return { kind, from, to: from + 6, label: back === 0 ? t('reports.thisWeek') : `${dayLabel(from)} – ${dayLabel(from + 6)}` }
    }
    case 'month':
    case 'quarter': {
      const span = kind === 'month' ? 1 : 3
      if (jalali()) {
        const j = toJalali(today)
        const first = Math.floor((j.m - 1) / span) * span // 0-based first month of the current span
        const idx = j.y * 12 + first - back * span
        const [y, m] = [Math.floor(idx / 12), (idx % 12) + 1]
        const end = idx + span
        const from = fromJalali(y, m, 1)
        const to = fromJalali(Math.floor(end / 12), (end % 12) + 1, 1) - 1
        const label = kind === 'month' ? fmt({ month: 'long', year: 'numeric' }).format(toDate(from)) : `${SEASONS_FA[(m - 1) / 3]} ${num(y)}`
        return { kind, from, to, label }
      }
      const g = toGregorian(today)
      const idx = g.y * 12 + Math.floor((g.m - 1) / span) * span - back * span
      const [y, m] = [Math.floor(idx / 12), (idx % 12) + 1]
      const end = idx + span
      const from = fromGregorian(y, m, 1)
      const to = fromGregorian(Math.floor(end / 12), (end % 12) + 1, 1) - 1
      const label = kind === 'month' ? fmt({ month: 'long', year: 'numeric' }).format(toDate(from)) : `Q${(m - 1) / 3 + 1} ${y}`
      return { kind, from, to, label }
    }
    case 'year': {
      if (jalali()) {
        const y = toJalali(today).y - back
        return { kind, from: fromJalali(y, 1, 1), to: fromJalali(y + 1, 1, 1) - 1, label: num(y) }
      }
      const y = toGregorian(today).y - back
      return { kind, from: fromGregorian(y, 1, 1), to: fromGregorian(y + 1, 1, 1) - 1, label: String(y) }
    }
  }
}

export interface Bucket {
  label: string
  /** Longer label for tooltips and the table view. */
  title: string
  from: number
  to: number
}

/** How a period's timeline is split: hours for a day, days for a week or month, weeks for a season, months for a year. */
export function bucketsFor(period: Period): Bucket[] | 'hours' {
  if (period.kind === 'day') return 'hours'
  const out: Bucket[] = []
  if (period.kind === 'week' || period.kind === 'month') {
    for (let d = period.from; d <= period.to; d++) {
      const day = jalali() ? toJalali(d).d : toGregorian(d).d
      out.push({ label: num(day), title: fmt({ weekday: 'short', day: 'numeric', month: 'short' }).format(toDate(d)), from: d, to: d })
    }
  } else if (period.kind === 'quarter') {
    for (let d = period.from; d <= period.to; d += 7) {
      const to = Math.min(d + 6, period.to)
      out.push({ label: dayLabel(d), title: `${dayLabel(d)} – ${dayLabel(to)}`, from: d, to })
    }
  } else {
    let d = period.from
    while (d <= period.to) {
      let next: number
      if (jalali()) {
        const j = toJalali(d)
        next = j.m === 12 ? fromJalali(j.y + 1, 1, 1) : fromJalali(j.y, j.m + 1, 1)
      } else {
        const g = toGregorian(d)
        next = g.m === 12 ? fromGregorian(g.y + 1, 1, 1) : fromGregorian(g.y, g.m + 1, 1)
      }
      const name = fmt({ month: 'short' }).format(toDate(d))
      out.push({ label: name, title: fmt({ month: 'long', year: 'numeric' }).format(toDate(d)), from: d, to: next - 1 })
      d = next
    }
  }
  return out
}

/** Weekday names in the order the locale's week runs, with their ISO index (0 = Monday). */
export function weekdays(): { index: number; label: string }[] {
  const start = weekStart()
  const monday = fromGregorian(2026, 1, 5) // any Monday
  return Array.from({ length: 7 }, (_, i) => {
    const index = (start + i) % 7
    return { index, label: fmt({ weekday: 'short' }).format(toDate(monday + index)) }
  })
}
