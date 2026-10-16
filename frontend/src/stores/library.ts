import { defineStore } from 'pinia'
import { computed, ref, shallowRef, triggerRef } from 'vue'
import { api } from '@/lib/api'
import { db, kv } from '@/lib/db'
import { t } from '@/lib/i18n'
import { matches, normalize, words } from '@/lib/normalize'
import type { ApiTrack, ArtistSummary, RemoteTrack, Track } from '@/lib/types'
import { useUi } from './ui'

export const toRemote = (t: ApiTrack): RemoteTrack => ({ ...t, kind: 'remote' })

/** The channel catalog, mirrored into IndexedDB so browsing and search work offline. */
export const useLibrary = defineStore('library', () => {
  const tracks = shallowRef<Map<number, RemoteTrack>>(new Map())
  const loaded = ref(false)
  const syncing = ref(false)
  const liked = ref<Set<number>>(new Set())
  let searchIndex: Map<number, string> | null = null

  const list = computed(() =>
    [...tracks.value.values()].sort((a, b) => (b.posted_at ?? '').localeCompare(a.posted_at ?? '') || b.id - a.id),
  )

  const artists = computed<ArtistSummary[]>(() => {
    const map = new Map<string, ArtistSummary>()
    for (const track of tracks.value.values()) {
      if (!track.artist) continue
      const a = map.get(track.artist) ?? { artist: track.artist, tracks_count: 0, plays: 0, cover_track_id: null }
      a.tracks_count++
      a.plays! += track.play_count
      if (track.has_cover && !a.cover_track_id) a.cover_track_id = track.id
      map.set(track.artist, a)
    }
    return [...map.values()].sort((a, b) => b.tracks_count - a.tracks_count)
  })

  async function load() {
    if (loaded.value) return
    const rows = await db.tracks.toArray()
    tracks.value = new Map(rows.map((t) => [t.id, t]))
    liked.value = new Set((await kv.get<number[]>('likes')) ?? [])
    loaded.value = true
  }

  /** Pull catalog changes since the last sync. */
  async function sync() {
    if (syncing.value) return
    syncing.value = true
    try {
      await load()
      const since = await kv.get<string>('library.since')
      const res = await api.get<{ server_time: string; full: boolean; tracks: ApiTrack[]; deleted: number[] }>(
        '/library/sync', since ? { since } : undefined,
      )
      const incoming = res.tracks.map(toRemote)
      await db.transaction('rw', db.tracks, async () => {
        if (res.full) await db.tracks.clear()
        await db.tracks.bulkPut(incoming)
        if (res.deleted.length) await db.tracks.bulkDelete(res.deleted)
      })
      const map = res.full ? new Map<number, RemoteTrack>() : tracks.value
      for (const t of incoming) map.set(t.id, t)
      for (const id of res.deleted) map.delete(id)
      tracks.value = map
      triggerRef(tracks)
      searchIndex = null
      await kv.set('library.since', res.server_time)
      await syncLikes()
    } catch {
      // Offline: the stored catalog is still usable.
    } finally {
      syncing.value = false
    }
  }

  async function syncLikes() {
    const res = await api.get<{ data: ApiTrack[] }>('/likes')
    liked.value = new Set(res.data.map((t) => t.id))
    await kv.set('likes', [...liked.value])
    // Make sure liked tracks exist locally even if the catalog lags behind.
    for (const t of res.data) if (!tracks.value.has(t.id)) tracks.value.set(t.id, toRemote(t))
  }

  /** Drop a track that was hidden or removed on the server. */
  function forget(id: number) {
    tracks.value.delete(id)
    triggerRef(tracks)
    searchIndex = null
    db.tracks.delete(id)
  }

  function get(id: number) {
    return tracks.value.get(id)
  }

  /** Remember tracks the API handed us (playlist pages etc.) so the player and offline views can use them. */
  function remember(items: ApiTrack[]): RemoteTrack[] {
    return items.map((item) => {
      const track = toRemote(item)
      tracks.value.set(track.id, track)
      return track
    })
  }

  /** Apply an edited track everywhere: the catalog, search, and the offline copy read on the next launch. */
  async function update(item: ApiTrack): Promise<RemoteTrack> {
    const track = toRemote(item)
    tracks.value.set(track.id, track)
    triggerRef(tracks)
    searchIndex = null
    await db.tracks.put(track)
    return track
  }

  function isLiked(track: Track) {
    return track.kind === 'remote' && liked.value.has(track.id)
  }

  async function toggleLike(track: Track) {
    if (track.kind !== 'remote') return
    const ui = useUi()
    const was = liked.value.has(track.id)
    const next = new Set(liked.value)
    if (was) next.delete(track.id)
    else next.add(track.id)
    liked.value = next
    try {
      if (was) await api.delete(`/tracks/${track.id}/like`)
      else await api.post(`/tracks/${track.id}/like`)
      await kv.set('likes', [...liked.value])
      ui.toast(was ? t('toast.unliked') : t('toast.liked'), 'success')
      if (!was) {
        const { useDownloads } = await import('./downloads')
        const { useSettings } = await import('./settings')
        if (useSettings().prefs.autoDownloadLiked) useDownloads().download(track)
      }
    } catch {
      const revert = new Set(liked.value)
      if (was) revert.add(track.id)
      else revert.delete(track.id)
      liked.value = revert
      ui.toast(t('toast.offlineAction'), 'error')
    }
  }

  const likedTracks = computed(() => [...liked.value].map((id) => tracks.value.get(id)).filter(Boolean).reverse() as RemoteTrack[])

  function search(query: string, limit = 200): RemoteTrack[] {
    const q = words(query)
    if (!q.length) return []
    if (!searchIndex) {
      searchIndex = new Map()
      for (const t of tracks.value.values()) {
        searchIndex.set(t.id, normalize([t.title, t.artist, t.album, t.genre].filter(Boolean).join(' ')))
      }
    }
    const results: RemoteTrack[] = []
    for (const [id, hay] of searchIndex) {
      if (matches(hay, q)) {
        results.push(tracks.value.get(id)!)
        if (results.length >= limit) break
      }
    }
    // Title hits before artist/album hits, then by popularity.
    const qn = normalize(query)
    return results.sort((a, b) => {
      const at = normalize(a.title).includes(qn) ? 0 : 1
      const bt = normalize(b.title).includes(qn) ? 0 : 1
      return at - bt || b.play_count - a.play_count
    })
  }

  function searchArtists(query: string): ArtistSummary[] {
    const q = words(query)
    if (!q.length) return []
    const joined = ' ' + q.join(' ') + ' '
    return artists.value.filter((a) => {
      const n = normalize(a.artist)
      return matches(n, q) || joined.includes(' ' + n + ' ')
    }).slice(0, 12)
  }

  function byArtist(name: string): RemoteTrack[] {
    return list.value.filter((t) => t.artist === name)
  }

  return {
    tracks, list, artists, loaded, syncing, liked, likedTracks,
    load, sync, syncLikes, forget, get, remember, update, isLiked, toggleLike, search, searchArtists, byArtist,
  }
})
