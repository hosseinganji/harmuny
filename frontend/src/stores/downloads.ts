import { defineStore } from 'pinia'
import { computed, reactive, ref } from 'vue'
import { coverUrl, streamUrl } from '@/lib/api'
import { db, requestPersistentStorage } from '@/lib/db'
import { t } from '@/lib/i18n'
import type { RemoteTrack, Track } from '@/lib/types'
import { useUi } from './ui'

const CONCURRENCY = 2
const RETRIES = 3
const isIOS = /iPhone|iPad|iPod/.test(navigator.userAgent)
const wait = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms))

/** A download stopped by the app going to the background (iOS cuts its network): resume, don't fail. */
class Interrupted extends Error {}
/** The device has no room left for the app. */
class StorageFull extends Error {}

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

  // Several songs failing together (a whole artist or playlist) make one message with a Retry button.
  let failedBatch: { track: RemoteTrack; silent: boolean }[] = []
  let failedTimer: ReturnType<typeof setTimeout> | undefined
  function reportFailure(track: RemoteTrack, silent: boolean) {
    failedBatch.push({ track, silent })
    clearTimeout(failedTimer)
    failedTimer = setTimeout(() => {
      const shown = failedBatch.filter((f) => !f.silent)
      failedBatch = []
      if (!shown.length) return
      const retry = { label: t('downloads.retry'), run: () => download(shown.map((f) => f.track)) }
      useUi().toast(
        shown.length === 1 ? t('toast.downloadFailed', { title: shown[0].track.title }) : t('downloads.failedMany', { n: shown.length }),
        'error',
        retry,
      )
    }, 1500)
  }

  let storageWarned = false

  function pump() {
    // iOS cuts network requests of an app in the background: wait until it is in view again.
    if (isIOS && document.visibilityState === 'hidden') return
    while (active < CONCURRENCY && pending.length) {
      const job = pending.shift()!
      const { track, silent } = job
      active++
      fetchTrack(track)
        .catch((e) => {
          if (e instanceof Interrupted) {
            pending.unshift(job) // picked up again when the app is back in view
            return
          }
          progress.delete(track.id)
          if (e instanceof StorageFull) {
            // Nothing else fits either: stop the queue and say so once.
            for (const p of pending.splice(0)) progress.delete(p.track.id)
            if (!storageWarned) useUi().toast(t('downloads.full'), 'error')
            storageWarned = true
            return
          }
          failed.add(track.id)
          reportFailure(track, silent)
        })
        .finally(() => {
          active--
          pump()
        })
    }
  }
  document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && pump())
  addEventListener('online', () => pump())

  /** Download with retries: dropped connections and busy servers are retried, with a growing pause. */
  async function fetchTrack(track: RemoteTrack) {
    for (let attempt = 1; ; attempt++) {
      try {
        return await fetchOnce(track)
      } catch (e) {
        if (e instanceof StorageFull) throw e
        if (document.visibilityState === 'hidden' || !navigator.onLine) throw new Interrupted()
        const status = Number((e as Error).message)
        const retryable = !status || status >= 500 || status === 429 || status === 408
        if (!retryable || attempt >= RETRIES) throw e
        await wait(2000 * attempt * attempt)
      }
    }
  }

  async function fetchOnce(track: RemoteTrack) {
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
    try {
      await db.downloads.put({ trackId: track.id, blob, size: blob.size, mime, downloadedAt: Date.now() })
    } catch (e) {
      const name = (e as { name?: string; inner?: { name?: string } }).inner?.name ?? (e as { name?: string }).name
      if (name === 'QuotaExceededError') throw new StorageFull()
      throw e
    }
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
