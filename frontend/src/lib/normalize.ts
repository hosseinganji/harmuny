// Mirrors App\Support\TextNormalizer on the backend so offline search behaves the same.
const MAP: Record<string, string> = {
  'ي': 'ی', 'ى': 'ی', 'ئ': 'ی', 'ك': 'ک', 'ة': 'ه', 'ۀ': 'ه',
  'أ': 'ا', 'إ': 'ا', 'آ': 'ا', 'ٱ': 'ا', 'ؤ': 'و', '‌': ' ', 'ـ': '',
  '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4', '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9',
  '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4', '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9',
}
const MAP_RE = new RegExp(`[${Object.keys(MAP).join('')}]`, 'g')

export function normalize(text: string | null | undefined): string {
  if (!text) return ''
  return text
    .replace(MAP_RE, (c) => MAP[c] ?? c)
    .replace(/[ً-ٰٟ]/g, '')
    .toLowerCase()
    .replace(/[_\-.,;:!?()[\]{}"'«»|/\\#@]+/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
}

export function words(text: string | null | undefined): string[] {
  const n = normalize(text)
  return n ? [...new Set(n.split(' '))] : []
}

/** True when every word of the query appears in the haystack (already normalized). */
export function matches(haystack: string, queryWords: string[]): boolean {
  return queryWords.every((w) => haystack.includes(w))
}
