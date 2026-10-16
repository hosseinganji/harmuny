export interface LyricLine {
  time: number
  text: string
}

/** Parse LRC text ("[mm:ss.xx] line") into time-sorted lines. */
export function parseLrc(lrc: string): LyricLine[] {
  const lines: LyricLine[] = []
  for (const raw of lrc.split(/\r?\n/)) {
    const stamps = [...raw.matchAll(/\[(\d{1,3}):(\d{1,2})(?:[.:](\d{1,3}))?\]/g)]
    if (!stamps.length) continue
    const text = raw.replace(/\[[^\]]*\]/g, '').trim()
    for (const [, m, s, frac] of stamps) {
      const fraction = frac ? Number(frac) / 10 ** frac.length : 0
      lines.push({ time: Number(m) * 60 + Number(s) + fraction, text })
    }
  }
  return lines.sort((a, b) => a.time - b.time)
}

/** Index of the line being sung at `time`, or -1 before the first line. */
export function activeLine(lines: LyricLine[], time: number): number {
  let lo = 0
  let hi = lines.length - 1
  let found = -1
  while (lo <= hi) {
    const mid = (lo + hi) >> 1
    if (lines[mid].time <= time + 0.15) {
      found = mid
      lo = mid + 1
    } else {
      hi = mid - 1
    }
  }
  return found
}
