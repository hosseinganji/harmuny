import { nextTick, ref, watch } from 'vue'
import type { Router } from 'vue-router'

/** Respect the system "reduce motion" setting everywhere. */
export const reducedMotion = () => matchMedia('(prefers-reduced-motion: reduce)').matches

type Direction = 'forward' | 'back'

/**
 * Scroll memory for the app's scroll container: a new page opens at the top, Back returns to where you
 * were. (Page changes themselves are instant; animated page transitions cost too much on phones.)
 */
export function installPageMotion(router: Router, scroller: () => HTMLElement | null) {
  const scrollByPosition = new Map<number, number>()
  let popDirection: 'back' | 'forward' | null = null
  let direction: Direction = 'forward'

  // Browser back/forward (and swipe-back) come through the history listener before the route resolves.
  router.options.history.listen((_to, _from, info) => {
    popDirection = info.direction === 'back' ? 'back' : info.direction === 'forward' ? 'forward' : null
  })

  router.beforeResolve((to, from) => {
    if (!from.matched.length || to.path === from.path) return // first load, or only the query changed (search)
    const position = (history.state?.position as number | undefined) ?? 0
    const el = scroller()
    if (el) scrollByPosition.set(popDirection ? position + (popDirection === 'back' ? 1 : -1) : position, el.scrollTop)
    direction = popDirection === 'back' ? 'back' : 'forward'
    popDirection = null
  })

  router.afterEach(async (to, from) => {
    if (!from.matched.length || to.path === from.path) return
    await nextTick()
    const el = scroller()
    if (el) {
      const saved = direction === 'back' ? scrollByPosition.get((history.state?.position as number | undefined) ?? 0) : undefined
      el.scrollTop = saved ?? 0
    }
  })
}

/** True for a moment right after `liked` turns on, so a heart pops when tapped (not on every render). */
export function useLikePop(liked: () => boolean) {
  const popping = ref(false)
  let timer: ReturnType<typeof setTimeout> | undefined
  watch(liked, (now, before) => {
    if (!now || before) return
    popping.value = true
    clearTimeout(timer)
    timer = setTimeout(() => (popping.value = false), 600)
  })
  return popping
}
