/**
 * Day-number calendar math for the reports: every date is a Julian Day Number (an integer), which makes
 * ranges and buckets simple, with conversions to the Gregorian and Persian (Jalali) calendars.
 * The Jalali conversion is the well-known algorithm by Borkowski / jalaali-js.
 */

const div = (a: number, b: number) => ~~(a / b)
const mod = (a: number, b: number) => a - ~~(a / b) * b

const BREAKS = [-61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178]

function jalCal(jy: number) {
  const gy = jy + 621
  let leapJ = -14
  let jp = BREAKS[0]
  let jump = 0
  for (let i = 1; i < BREAKS.length; i++) {
    const jm = BREAKS[i]
    jump = jm - jp
    if (jy < jm) break
    leapJ += div(jump, 33) * 8 + div(mod(jump, 33), 4)
    jp = jm
  }
  let n = jy - jp
  leapJ += div(n, 33) * 8 + div(mod(n, 33) + 3, 4)
  if (mod(jump, 33) === 4 && jump - n === 4) leapJ += 1
  const leapG = div(gy, 4) - div((div(gy, 100) + 1) * 3, 4) - 150
  const march = 20 + leapJ - leapG
  if (jump - n < 6) n = n - jump + div(jump + 4, 33) * 33
  let leap = mod(mod(n + 1, 33) - 1, 4)
  if (leap === -1) leap = 4
  return { leap, gy, march }
}

export function fromGregorian(gy: number, gm: number, gd: number): number {
  let d = div((gy + div(gm - 8, 6) + 100100) * 1461, 4) + div(153 * mod(gm + 9, 12) + 2, 5) + gd - 34840408
  d = d - div(div(gy + 100100 + div(gm - 8, 6), 100) * 3, 4) + 752
  return d
}

export function toGregorian(jdn: number) {
  let j = 4 * jdn + 139361631
  j = j + div(div(4 * jdn + 183187720, 146097) * 3, 4) * 4 - 3908
  const i = div(mod(j, 1461), 4) * 5 + 308
  const gd = div(mod(i, 153), 5) + 1
  const gm = mod(div(i, 153), 12) + 1
  const gy = div(j, 1461) - 100100 + div(8 - gm, 6)
  return { y: gy, m: gm, d: gd }
}

export function fromJalali(jy: number, jm: number, jd: number): number {
  const r = jalCal(jy)
  return fromGregorian(r.gy, 3, r.march) + (jm - 1) * 31 - div(jm, 7) * (jm - 7) + jd - 1
}

export function toJalali(jdn: number) {
  const gy = toGregorian(jdn).y
  let jy = gy - 621
  const r = jalCal(jy)
  let k = jdn - fromGregorian(gy, 3, r.march)
  if (k >= 0) {
    if (k <= 185) return { y: jy, m: 1 + div(k, 31), d: mod(k, 31) + 1 }
    k -= 186
  } else {
    jy -= 1
    k += 179
    if (r.leap === 1) k += 1
  }
  return { y: jy, m: 7 + div(k, 30), d: mod(k, 30) + 1 }
}

/** 0 = Monday … 6 = Sunday. */
export const weekday = (jdn: number) => mod(jdn, 7)

export const todayJdn = () => {
  const now = new Date()
  return fromGregorian(now.getFullYear(), now.getMonth() + 1, now.getDate())
}

/** YYYY-MM-DD (Gregorian), the format the reports API takes and returns. */
export function isoDate(jdn: number): string {
  const { y, m, d } = toGregorian(jdn)
  return `${y}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`
}

export function fromIso(iso: string): number {
  const [y, m, d] = iso.split('-').map(Number)
  return fromGregorian(y, m, d)
}

export function toDate(jdn: number): Date {
  const { y, m, d } = toGregorian(jdn)
  return new Date(y, m - 1, d, 12)
}
