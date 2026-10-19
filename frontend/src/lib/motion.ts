import { nextTick, ref, watch } from 'vue'
import type { RouteLocationNormalized, Router } from 'vue-router'

/** Respect the system "reduce motion" setting everywhere. */
export const reducedMotion = () => matchMedia('(prefers-reduced-motion: reduce)').matches

/** Page changes use the View Transitions API where it exists (Chrome/Android, Safari 18+); others swap instantly. */
export const viewTransitions = typeof document !== 'undefined' && 'startViewTransition' in document

type Direction = 'forward' | 'back' | 'tab'

// The three tabs, and the library's sub-tabs, swap in place; everything else is a step deeper or back.
const isTab = (r: RouteLocationNormalized) => r.path === '/' || r.path === '/search' || r.path.startsWith('/library')

/**
 * Directional page transitions and scroll memory for the app's scroll container:
 * going deeper slides the page in from the end, going back slides it the other way and returns to
 * where you were, and switching tabs is a soft cross-fade. The bottom bar, mini player and header
 * have their own transition names, so they stay put while the page moves.
 */
export function installPageMotion(router: Router, scroller: () => HTMLElement | null) {
  const scrollByPosition = new Map<number, number>()
  let popDirection: 'back' | 'forward' | null = null
  let finish: (() => void) | null = null
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

    direction = popDirection === 'back' ? 'back' : isTab(to) && isTab(from) ? 'tab' : 'forward'
    popDirection = null
    if (!viewTransitions || reducedMotion()) return

    document.documentElement.dataset.nav = direction
    return new Promise<void>((resolve) => {
      const transition = document.startViewTransition(() => new Promise<void>((done) => {
        finish = done
        resolve()
        // The browser holds the old frame until this settles; never let a stuck navigation freeze it.
        setTimeout(done, 1500)
      }))
      transition.finished.finally(() => delete document.documentElement.dataset.nav)
    })
  })

  router.afterEach(async (to, from) => {
    if (from.matched.length && to.path !== from.path) {
      await nextTick()
      const el = scroller()
      if (el) {
        const saved = direction === 'back' ? scrollByPosition.get((history.state?.position as number | undefined) ?? 0) : undefined
        el.scrollTop = saved ?? 0
      }
    }
    // Let the view transition capture the new page.
    finish?.()
    finish = null
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
