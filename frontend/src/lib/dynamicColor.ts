import { onBeforeUnmount, ref, watch, type Ref } from 'vue'
import { extractColor } from './color'

const DEFAULT = '255 21 0'
const playerColor = ref(DEFAULT)
const overrides: Ref<string | null>[] = []

function apply() {
  const top = [...overrides].reverse().find((o) => o.value)
  document.documentElement.style.setProperty('--dyn', top?.value ?? playerColor.value)
}

/** The app-wide glow follows the playing track's artwork. */
export async function setPlayerArtwork(src: string | null, enabled: boolean) {
  playerColor.value = (enabled && src && (await extractColor(src))) || DEFAULT
  apply()
}

/** While a page is mounted, tint the glow with that page's artwork (playlist, artist...). */
export function usePageColor(src: Ref<string | null | undefined>) {
  const color = ref<string | null>(null)
  overrides.push(color)
  watch(src, async (s) => {
    color.value = s ? await extractColor(s) : null
    apply()
  }, { immediate: true })
  onBeforeUnmount(() => {
    overrides.splice(overrides.indexOf(color), 1)
    apply()
  })
}
