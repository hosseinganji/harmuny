import { computed, ref } from 'vue'
import en from '@/locales/en'
import fa from '@/locales/fa'

export type Locale = 'en' | 'fa'
export const LOCALES: { value: Locale; label: string }[] = [
  { value: 'fa', label: 'فارسی' },
  { value: 'en', label: 'English' },
]

const dictionaries: Record<Locale, Record<string, string>> = { en, fa }

/** First launch follows the device language; afterwards the user's choice (settings) wins. */
export const defaultLocale = (): Locale => (navigator.language?.toLowerCase().startsWith('fa') ? 'fa' : 'en')

/** Reactive, so every t() in a template re-renders when the language changes. */
export const locale = ref<Locale>(defaultLocale())
export const isRtl = computed(() => locale.value === 'fa')

/** UI strings live in one dictionary per language so wording stays consistent across views. */
export function applyLocale(next?: Locale) {
  if (next) locale.value = next
  document.documentElement.lang = locale.value
  document.documentElement.dir = isRtl.value ? 'rtl' : 'ltr'
}

/** Look up a string, replacing {name} placeholders. Falls back to English, then the key. */
export function t(key: string, params?: Record<string, string | number>): string {
  let text = dictionaries[locale.value][key] ?? en[key] ?? key
  if (params) {
    for (const [k, v] of Object.entries(params)) {
      // Counts get Persian digits in Persian; names and titles (which may contain digits) are left alone.
      const value = locale.value === 'fa' && /^[\d.,:]+$/.test(String(v)) ? String(v).replace(/\d/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[Number(d)]) : String(v)
      text = text.replaceAll(`{${k}}`, value)
    }
  }
  return text
}
