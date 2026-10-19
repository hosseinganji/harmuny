import { useDebounceFn, useIntervalFn } from '@vueuse/core'
import { watch } from 'vue'
import { api } from './api'
import { usePlayer } from '@/stores/player'

/**
 * Tells the server which song this member is playing, for "what is everyone listening to" in the
 * online list: on every song change or pause, and every minute while music plays (so it stays fresh).
 * Songs from the device itself are not shared.
 */
export function startPresence() {
  const player = usePlayer()
  let last: number | null | undefined

  function report(force = false) {
    const track = player.track
    const id = player.playing && track?.kind === 'remote' ? track.id : null
    if (!force && id === last) return
    last = id
    api.post('/presence', { track_id: id }).catch(() => (last = undefined))
  }

  watch(() => [player.track, player.playing], useDebounceFn(() => report(), 800))
  useIntervalFn(() => player.playing && report(true), 60_000)
}
