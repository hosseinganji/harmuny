import en from '@/locales/en'

/** UI strings live in one dictionary so wording stays consistent across views. */
export function applyLocale() {
  document.documentElement.lang = 'en'
  document.documentElement.dir = 'ltr'
}

/** Look up a string, replacing {name} placeholders. Falls back to the key. */
export function t(key: string, params?: Record<string, string | number>): string {
  let text = (en as Record<string, string>)[key] ?? key
  if (params) {
    for (const [k, v] of Object.entries(params)) text = text.replaceAll(`{${k}}`, String(v))
  }
  return text
}
