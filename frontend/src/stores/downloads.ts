import { defineStore } from 'pinia'
import { computed, reactive, ref } from 'vue'
import { coverUrl, streamUrl } from '@/lib/api'
import { db, requestPersistentStorage } from '@/lib/db'
import { t } from '@/lib/i18n'
import type { RemoteTrack, Track } from '@/lib/types'
import { useUi } from './ui'

const CONCURRENCY = 2

/** Tracks saved to IndexedDB for offline playback. */
export const useDownloads = defineStore('downloads', () => {
  const sizes = ref<Map<number, number>>(new Map())
  const progress = reactive(new Map<number, number>())
  const pending: { track: RemoteTrack; silent: boolean }[] = []
  let active = 0
  // Songs that failed during this session (e.g. not on the server yet); background sync skips them until reload.
  const failed = new Set<number>()
  let loaded = false

  const totalBytes = computed(() => [...sizes.value.values()].reduce((a, b) => a + b, 0))
  const count = computed(() => sizes.value.size)

  async function load() {
    if (loaded) return
    loaded = true
    const rows = await db.downloads.toArray()
    sizes.value = new Map(rows.map((r) => [r.trackId, r.size]))
  }

  function isDownloaded(track: Track) {
    return track.kind === 'local' || sizes.value.has(track.id)
  }

  function isDownloading(track: Track) {
    return track.kind === 'remote' && progress.has(track.id)
  }

  /** Queue downloads. `silent` is for background sync: no toasts, failures are retried on a later run. */
  function download(tracks: Track | Track[], options: { silent?: boolean } = {}) {
    const silent = !!options.silent
    const list = (Array.isArray(tracks) ? tracks : [tracks]).filter(
      (t): t is RemoteTrack => t.kind === 'remote' && !sizes.value.has(t.id) && !progress.has(t.id),
    )
    if (!list.length) return
    requestPersistentStorage()
    for (const track of list) {
      if (!silent) failed.delete(track.id)
      progress.set(track.id, 0)
      pending.push({ track, silent })
    }
    if (list.length > 1 && !silent) useUi().toast(t('toast.downloadQueued', { n: list.length }))
    pump()
  }

  /** Drop queued background downloads (the ones in flight finish). */
  function cancelSilent() {
    for (let i = pending.length - 1; i >= 0; i--) {
      if (pending[i].silent) {
        progress.delete(pending[i].track.id)
        pending.splice(i, 1)
      }
    }
  }

  const queued = computed(() => progress.size)

  function pump() {
    while (active < CONCURRENCY && pending.length) {
      const { track, silent } = pending.shift()!
      active++
      fetchTrack(track)
        .catch(() => {
          progress.delete(track.id)
          failed.add(track.id)
          if (!silent) useUi().toast(t('toast.downloadFailed', { title: track.title }), 'error')
        })
        .finally(() => {
          active--
          pump()
        })
    }
  }

  async function fetchTrack(track: RemoteTrack) {
    const response = await fetch(streamUrl(track.id))
    if (!response.ok || !response.body) throw new Error(String(response.status))

    const total = Number(response.headers.get('Content-Length')) || track.file_size || 0
    const reader = response.body.getReader()
    const chunks: Uint8Array<ArrayBuffer>[] = []
    let received = 0
    for (;;) {
      const { done, value } = await reader.read()
      if (done) break
      chunks.push(value)
      received += value.length
      if (total) progress.set(track.id, Math.min(0.99, received / total))
    }

    const mime = track.mime_type || response.headers.get('Content-Type') || 'audio/mpeg'
    const blob = new Blob(chunks, { type: mime })
    await db.downloads.put({ trackId: track.id, blob, size: blob.size, mime, downloadedAt: Date.now() })
    // Make sure the track's metadata and artwork are available offline too.
    await db.tracks.put(track)
    if (track.has_cover) fetch(coverUrl(track.id, track.updated_at)).catch(() => {})

    sizes.value = new Map(sizes.value).set(track.id, blob.size)
    progress.delete(track.id)
  }

  function cancel(track: Track) {
    if (track.kind !== 'remote') return
    const i = pending.findIndex((p) => p.track.id === track.id)
    if (i >= 0) {
      pending.splice(i, 1)
      progress.delete(track.id)
    }
  }

  async function remove(tracks: Track | Track[]) {
    const ids = (Array.isArray(tracks) ? tracks : [tracks]).filter((t) => t.kind === 'remote').map((t) => t.id as number)
    await db.downloads.bulkDelete(ids)
    const next = new Map(sizes.value)
    ids.forEach((id) => next.delete(id))
    sizes.value = next
  }

  async function clear() {
    await db.downloads.clear()
    sizes.value = new Map()
  }

  async function blob(trackId: number): Promise<Blob | null> {
    return (await db.downloads.get(trackId))?.blob ?? null
  }

  return {
    sizes, progress, queued, failed, totalBytes, count,
    load, isDownloaded, isDownloading, download, cancelSilent, cancel, remove, clear, blob,
  }
})
