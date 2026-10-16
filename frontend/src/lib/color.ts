/** Pick a vivid, readable color from an image, as "r g b" for CSS rgb() with alpha. */
export async function extractColor(src: string): Promise<string | null> {
  try {
    const img = new Image()
    img.crossOrigin = 'anonymous'
    img.decoding = 'async'
    img.src = src
    await img.decode()

    const size = 32
    const canvas = document.createElement('canvas')
    canvas.width = size
    canvas.height = size
    const ctx = canvas.getContext('2d', { willReadFrequently: true })
    if (!ctx) return null
    ctx.drawImage(img, 0, 0, size, size)
    const { data } = ctx.getImageData(0, 0, size, size)

    // Bucket colors and weight by saturation so the accent is not a dull gray.
    const buckets = new Map<string, { r: number; g: number; b: number; w: number }>()
    for (let i = 0; i < data.length; i += 4) {
      const [r, g, b] = [data[i], data[i + 1], data[i + 2]]
      const max = Math.max(r, g, b)
      const min = Math.min(r, g, b)
      const sat = max === 0 ? 0 : (max - min) / max
      const light = (max + min) / 510
      if (light < 0.12 || light > 0.92) continue
      const key = `${r >> 5}-${g >> 5}-${b >> 5}`
      const w = 0.2 + sat * sat * 2
      const bucket = buckets.get(key) ?? { r: 0, g: 0, b: 0, w: 0 }
      bucket.r += r * w
      bucket.g += g * w
      bucket.b += b * w
      bucket.w += w
      buckets.set(key, bucket)
    }
    const best = [...buckets.values()].sort((a, b) => b.w - a.w)[0]
    if (!best) return null
    let [r, g, b] = [best.r / best.w, best.g / best.w, best.b / best.w]
    // Lift very dark picks so they still read as a glow.
    const max = Math.max(r, g, b)
    if (max < 140) {
      const k = 140 / Math.max(max, 1)
      ;[r, g, b] = [r * k, g * k, b * k]
    }
    return `${Math.round(r)} ${Math.round(g)} ${Math.round(b)}`
  } catch {
    return null
  }
}
