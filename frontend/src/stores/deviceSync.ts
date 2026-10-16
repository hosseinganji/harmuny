import { useIntervalFn, useOnline } from '@vueuse/core'
import { defineStore } from 'pinia'
import { computed, ref, watch } from 'vue'
import type { RemoteTrack } from '@/lib/types'
import { useDownloads } from './downloads'
import { useLibrary } from './library'
import { usePlaylists } from './playlists'

export type SyncMode = 'off' | 'all' | 'liked' | 'selected'
export type PausedReason = 'offline' | 'cellular' | 'storage' | 'manual' | null

interface SyncConfig {
  mode: SyncMode
  wifiOnly: boolean
  playlistIds: number[]
}

const KEY = 'smp.deviceSync'
// Leave room for the app itself and other sites.
const STORAGE_RESERVE = 300 * 1024 * 1024

/**
 * Keeps songs on this device: downloads every song in the chosen set that the
 * server already stores. Settings are per device, not per account.
 */
export const useDeviceSync = defineStore('deviceSync', () => {
  const library = useLibrary()
  const downloads = useDownloads()
  const playlists = usePlaylists()
  const online = useOnline()

  const config = ref<SyncConfig>({ mode: 'off', wifiOnly: true, playlistIds: [], ...JSON.parse(localStorage.getItem(KEY) ?? '{}') })
  const paused = ref<PausedReason>(null)
  const manualPause = ref(false)
  const playlistTrackIds = ref<Set<number>>(new Set())

  watch(config, (c) => localStorage.setItem(KEY, JSON.stringify(c)), { deep: true })

  /** Everything the chosen mode covers, whether or not the server has it yet. */
  const target = computed<RemoteTrack[]>(() => {
    const mode = config.value.mode
    if (mode === 'off') return []
    if (mode === 'all') return library.list
    const ids = new Set(library.liked)
    if (mode === 'selected') playlistTrackIds.value.forEach((id) => ids.add(id))
    return [...ids].map((id) => library.get(id)).filter(Boolean) as RemoteTrack[]
  })

  const onDevice = computed(() => target.value.filter((t) => downloads.sizes.has(t.id)).length)
  const waitingForServer = computed(() => target.value.filter((t) => !t.on_server && !downloads.sizes.has(t.id)).length)
  const targetBytes = computed(() => target.value.reduce((sum, t) => sum + (t.file_size || 0), 0))

  async function loadPlaylistTracks() {
    const ids = new Set<number>()
    for (const id of config.value.playlistIds) {
      try {
        const p = await playlists.get(id)
        for (const tr of p.tracks ?? []) ids.add(tr.id)
      } catch {
        // Deleted or offline: skip it.
      }
    }
    playlistTrackIds.value = ids
  }

  function onCellular(): boolean {
    const type = (navigator as any).connection?.type as string | undefined
    return type === 'cellular'
  }

  async function freeBytes(): Promise<number> {
    try {
      const est = await navigator.storage?.estimate?.()
      if (est?.quota) return est.quota - (est.usage ?? 0) - STORAGE_RESERVE
    } catch {
      // Unknown quota: assume there is room.
    }
    return Number.POSITIVE_INFINITY
  }

  /** Queue whatever is missing. Safe to call often. */
  async function run() {
    if (config.value.mode === 'off') {
      paused.value = null
      return
    }
    if (manualPause.value) return void (paused.value = 'manual')
    if (!online.value) return void (paused.value = 'offline')
    if (config.value.wifiOnly && onCellular()) {
      downloads.cancelSilent()
      return void (paused.value = 'cellular')
    }

    if (config.value.mode === 'selected') await loadPlaylistTracks()

    // Newest first; only songs the server already stores, skipping ones that failed this session.
    const missing = target.value
      .filter((t) => t.on_server && !downloads.sizes.has(t.id) && !downloads.progress.has(t.id) && !downloads.failed.has(t.id))
      .sort((a, b) => (b.posted_at ?? '').localeCompare(a.posted_at ?? ''))

    let budget = await freeBytes()
    const batch: RemoteTrack[] = []
    for (const track of missing) {
      if (budget - track.file_size < 0) break
      budget -= track.file_size
      batch.push(track)
    }
    paused.value = missing.length > batch.length ? 'storage' : null
    if (batch.length) downloads.download(batch, { silent: true })
  }

  function setMode(mode: SyncMode) {
    config.value.mode = mode
    if (mode === 'off') downloads.cancelSilent()
    run()
  }

  function togglePlaylist(id: number) {
    const ids = config.value.playlistIds
    config.value.playlistIds = ids.includes(id) ? ids.filter((x) => x !== id) : [...ids, id]
    run()
  }

  function pause() {
    manualPause.value = true
    downloads.cancelSilent()
    paused.value = 'manual'
  }

  function resume() {
    manualPause.value = false
    paused.value = null
    run()
  }

  /** Delete downloaded songs that are not part of the chosen set. */
  async function removeOutside(): Promise<number> {
    const keep = new Set(target.value.map((t) => t.id))
    const extra = [...downloads.sizes.keys()].filter((id) => !keep.has(id))
    const tracks = extra.map((id) => library.get(id)).filter(Boolean) as RemoteTrack[]
    await downloads.remove(tracks)
    return tracks.length
  }

  // Triggers: back online, network type changes, new catalog data, and a periodic check.
  watch(online, (v) => v && run())
  watch(() => library.syncing, (busy) => !busy && run())
  watch(() => config.value.wifiOnly, () => run())
  ;(navigator as any).connection?.addEventListener?.('change', () => run())
  useIntervalFn(() => run(), 15 * 60 * 1000)

  return {
    config, paused, target, onDevice, waitingForServer, targetBytes,
    run, setMode, togglePlaylist, pause, resume, removeOutside,
  }
})
