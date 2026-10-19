import { watch } from 'vue'
import { api } from './api'
import { t } from './i18n'
import type { ApiTrack } from './types'
import { useLibrary } from '@/stores/library'
import { usePlayer } from '@/stores/player'
import { useUi } from '@/stores/ui'

/** A radio is seeded by a song (its id) or an artist (its name); the context id keeps it across restarts. */
type Seed = { trackId: number; name: string } | { artist: string; name: string }

const seedQuery = (seed: Seed) => ('trackId' in seed ? { track_id: seed.trackId } : { artist: seed.artist })

/** Start an endless radio from a song or an artist. */
export async function startRadio(seed: Seed) {
  const ui = useUi()
  try {
    const res = await api.get<{ data: ApiTrack[] }>('/radio', seedQuery(seed))
    if (!res.data.length) return
    const tracks = useLibrary().remember(res.data)
    usePlayer().playTracks(tracks, 0, {
      shuffle: false,
      context: { type: 'radio', id: JSON.stringify(seedQuery(seed)), name: t('radio.of', { name: seed.name }) },
    })
  } catch {
    ui.toast(t('toast.offlineAction'), 'error')
  }
}

/** Play today's mix. */
export async function playDailyMix(tracks?: ApiTrack[]) {
  try {
    const list = tracks ?? (await api.get<{ data: ApiTrack[] }>('/mixes/daily')).data
    if (list.length) usePlayer().playTracks(useLibrary().remember(list), 0, { shuffle: false, context: { type: 'mix', name: t('mix.daily') } })
  } catch {
    useUi().toast(t('toast.offlineAction'), 'error')
  }
}

/** Keeps a radio going: when only a few songs are left, fetch more like it (never repeating the queue). */
export function keepRadioGoing() {
  const player = usePlayer()
  let fetching = false
  watch(() => [player.context, player.upcoming.length] as const, async ([context, left]) => {
    if (fetching || context?.type !== 'radio' || typeof context.id !== 'string' || left > 4 || !navigator.onLine) return
    fetching = true
    try {
      // The latest few hundred are enough to avoid repeats and keep the URL short.
      const exclude = player.queue.flatMap((item) => (item.track.kind === 'remote' ? [item.track.id] : [])).slice(-300)
      const res = await api.get<{ data: ApiTrack[] }>('/radio', { ...JSON.parse(context.id), more: 1, exclude })
      if (player.context?.id === context.id && res.data.length) player.addToQueue(useLibrary().remember(res.data), { silent: true })
    } catch {
      // Try again when the queue moves on.
    } finally {
      fetching = false
    }
  })
}
