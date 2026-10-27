import { defineStore } from 'pinia'
import { computed, ref, shallowRef, watch } from 'vue'
import { api, coverUrl, streamUrl } from '@/lib/api'
import { kv } from '@/lib/db'
import { t } from '@/lib/i18n'
import type { ApiTrack, RepeatMode, Track } from '@/lib/types'
import { useDownloads } from './downloads'
import { useLibrary } from './library'
import { useLocal } from './local'
import { useUi } from './ui'

export interface QueueItem {
  uid: string
  track: Track
  /** Added by Smart Shuffle (a song picked from the listener's taste). */
  smart?: boolean
}

export interface PlayContext {
  type: 'playlist' | 'artist' | 'liked' | 'downloads' | 'local' | 'search' | 'library' | 'home' | 'history' | 'radio' | 'mix'
  id?: string | number
  name?: string
}

interface PendingPlay {
  track_id: number
  seconds: number
  played_at: string
}

type SleepTimer = { mode: 'time'; endsAt: number } | { mode: 'track' } | null

const STATE_KEY = 'player.state'
let uidCounter = 0
const makeUid = () => `${Date.now().toString(36)}-${(uidCounter++).toString(36)}`
const wrap = (track: Track): QueueItem => ({ uid: makeUid(), track })

function shuffled<T>(items: T[]): T[] {
  const a = [...items]
  for (let i = a.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1))
    ;[a[i], a[j]] = [a[j], a[i]]
  }
  return a
}

// ---- fair shuffle ----------------------------------------------------------
// A plain random order kept bringing back songs heard a moment ago. Songs played recently on this device
// go to the end (the most recent last), and the same artist doesn't play twice in a row when avoidable.

const RECENT_KEY = 'smp.recentPlays'
const RECENT_MAX = 300

function recentPlays(): string[] {
  try {
    return JSON.parse(localStorage.getItem(RECENT_KEY) ?? '[]')
  } catch {
    return []
  }
}

function rememberPlay(key: string) {
  const list = [key, ...recentPlays().filter((k) => k !== key)].slice(0, RECENT_MAX)
  try {
    localStorage.setItem(RECENT_KEY, JSON.stringify(list))
  } catch {
    // Storage full or blocked: shuffle just gets a little less fair.
  }
}

function spreadArtists(items: QueueItem[]): QueueItem[] {
  const pending = [...items]
  const out: QueueItem[] = []
  while (pending.length) {
    const last = out[out.length - 1]?.track.artist
    const i = last ? pending.findIndex((p) => p.track.artist !== last) : 0
    out.push(...pending.splice(Math.max(i, 0), 1))
  }
  return out
}

function fairShuffle(items: QueueItem[]): QueueItem[] {
  const rank = new Map(recentPlays().map((key, i) => [key, i]))
  const fresh = shuffled(items.filter((i) => !rank.has(trackKey(i.track))))
  // Heard recently: oldest first, the ones just played last.
  const heard = items.filter((i) => rank.has(trackKey(i.track))).sort((a, b) => rank.get(trackKey(b.track))! - rank.get(trackKey(a.track))!)
  return spreadArtists([...fresh, ...heard])
}

export function trackKey(track: Track) {
  return (track.kind === 'remote' ? 'r' : 'l') + track.id
}

function createAudio(): HTMLAudioElement {
  const el = new Audio()
  el.preload = 'auto'
  return el
}

export const usePlayer = defineStore('player', () => {
  // The element can be swapped for a fresh one (see swapAudioElement), so it is a `let`.
  let audio = createAudio()
  // Audio Session API (Safari 16.4+): mark this as music playback, so iOS keeps the sound going
  // with the screen locked or the app in the background, as it does for native music apps.
  const audioSession = (navigator as { audioSession?: { type: string } }).audioSession
  if (audioSession) audioSession.type = 'playback'

  const queue = shallowRef<QueueItem[]>([])
  const index = ref(-1)
  const shuffle = ref(false)
  /** Smart Shuffle: a shuffled queue with songs picked from the listener's taste mixed in. */
  const smartShuffle = ref(false)
  const originalOrder = ref<string[] | null>(null)
  const repeat = ref<RepeatMode>('off')
  const playing = ref(false)
  const loading = ref(false)
  const currentTime = ref(0)
  const duration = ref(0)
  const buffered = ref(0)
  const volume = ref(Number(localStorage.getItem('smp.volume') ?? 1))
  const muted = ref(false)
  const rate = ref(1)
  const sleep = ref<SleepTimer>(null)
  const context = ref<PlayContext | null>(null)
  const coverSrc = ref<string | null>(null)

  const current = computed(() => queue.value[index.value] ?? null)
  const track = computed(() => current.value?.track ?? null)
  const upcoming = computed(() => queue.value.slice(index.value + 1))
  const history = computed(() => queue.value.slice(0, Math.max(0, index.value)))
  const progress = computed(() => (duration.value ? currentTime.value / duration.value : 0))

  let objectUrl: string | null = null
  let nextInsert = 0
  let listened = 0
  let lastTick = 0
  let consecutiveErrors = 0
  let restoreTime = 0

  audio.volume = volume.value

  // ---- loading & playback -------------------------------------------------

  async function sourceFor(item: Track): Promise<string> {
    if (item.kind === 'local') return useLocal().source(item)
    const blob = await useDownloads().blob(item.id)
    if (blob) return URL.createObjectURL(blob)
    if (!navigator.onLine) throw new Error('offline')
    return streamUrl(item.id)
  }

  async function load(autoplay: boolean, startAt = 0) {
    commitListen()
    nextInsert = 0
    const item = current.value
    if (!item) {
      audio.removeAttribute('src')
      audio.load()
      playing.value = false
      return
    }

    loading.value = true
    currentTime.value = startAt
    duration.value = item.track.duration || 0
    buffered.value = 0
    try {
      // A source prepared while the previous song played is used straight away, with nothing to await:
      // iOS only lets a background app start the next song if it happens right as the last one ends.
      const ready = takePrepared(item.uid) ?? syncSource(item.track)
      const src = ready ?? (await sourceFor(item.track))
      if (current.value?.uid !== item.uid) return // the user skipped meanwhile
      if (objectUrl && objectUrl !== src) URL.revokeObjectURL(objectUrl)
      objectUrl = src.startsWith('blob:') ? src : null
      audio.src = src
      audio.playbackRate = rate.value
      restoreTime = startAt
      if (autoplay) await play()
    } catch (e) {
      loading.value = false
      handleError((e as Error).message === 'offline' ? t('player.offlineSkip') : t('player.cannotPlay'), autoplay)
    }
    updateCover()
    updateMediaSession()
  }

  /** The user wants music playing (cleared by pause), so a start the system refused can be retried. */
  let wantPlay = false
  let blocked = false

  async function play() {
    wantPlay = true
    if (!audio.src && current.value) return load(true, currentTime.value)
    try {
      await audio.play()
      blocked = false
    } catch (e) {
      const name = (e as Error).name
      if (name === 'NotAllowedError') blocked = true // e.g. iOS in the background; retried below
      if (name !== 'AbortError') playing.value = false
    }
  }

  function pause() {
    wantPlay = false
    blocked = false
    audio.pause()
  }

  // A start iOS refused (screen locked, app in the background) goes ahead as soon as it is allowed:
  // when the app is in view again, or on the next tap anywhere.
  const retryBlocked = () => {
    if (blocked && wantPlay && audio.paused && current.value) play()
  }
  document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && retryBlocked())
  addEventListener('pointerdown', retryBlocked, true)

  // Stall watchdog: playing but not moving for a while (the connection dropped during a call, or while the
  // phone was locked) reloads the song at the same spot instead of hanging until the app is reopened.
  let lastProgressAt = Date.now()
  let lastPosition = -1
  setInterval(() => {
    const position = audio.currentTime
    if (position !== lastPosition || audio.paused || !wantPlay || !current.value) {
      lastPosition = position
      lastProgressAt = Date.now()
      return
    }
    if (Date.now() - lastProgressAt > 12_000 && navigator.onLine) {
      lastProgressAt = Date.now()
      load(true, position)
    }
  }, 4000)

  // ---- the next song, prepared ahead ---------------------------------------

  // iOS lets an app in the background (screen locked) start a song only right as the last one ends, or
  // straight from a lock-screen / car / headphone button, never after waiting on the network or the
  // database. So the songs either side of the current one are ready ahead of time:
  //  - the next song is loaded fully into memory while the current one plays: starting it needs no network,
  //    even after minutes with the screen locked when iOS holds back the connection;
  //  - the previous song's source is resolved too, for "previous" from a car or headphones;
  //  - any other song a remote button reaches starts from its stream address without waiting (syncSource).

  const prepared = new Map<string, string>() // queue uid → blob: or stream URL
  let nextFetch: { uid: string; controller: AbortController } | null = null

  function takePrepared(uid: string): string | null {
    const src = prepared.get(uid) ?? null
    prepared.delete(uid)
    return src
  }

  /** A source available with no waiting: the stream for server songs (also downloaded ones while in the background). */
  function syncSource(track: Track): string | null {
    if (track.kind !== 'remote' || !navigator.onLine) return null
    const downloaded = useDownloads().sizes.has(track.id)
    return !downloaded || document.visibilityState === 'hidden' ? streamUrl(track.id) : null
  }

  async function prepareNeighbours() {
    const nextItem = queue.value[index.value + 1] ?? (repeat.value === 'all' && queue.value.length > 1 ? queue.value[0] : undefined)
    const prevItem = index.value > 0 ? queue.value[index.value - 1] : undefined
    const keep = new Set([nextItem?.uid, prevItem?.uid].filter(Boolean) as string[])
    for (const [uid, src] of prepared) {
      if (keep.has(uid)) continue
      if (src.startsWith('blob:')) URL.revokeObjectURL(src)
      prepared.delete(uid)
    }
    if (nextFetch && nextFetch.uid !== nextItem?.uid) {
      nextFetch.controller.abort()
      nextFetch = null
    }

    for (const item of [nextItem, prevItem]) {
      if (!item || item.uid === current.value?.uid || prepared.has(item.uid)) continue
      try {
        prepared.set(item.uid, await sourceFor(item.track))
      } catch {
        // Offline and not on the device: load() reports it when the time comes.
      }
    }

    // The next song isn't on the device: bring it into memory now.
    const pending = nextItem && prepared.get(nextItem.uid)
    if (!nextItem || nextItem.track.kind !== 'remote' || !pending || pending.startsWith('blob:') || nextFetch || !navigator.onLine) return
    const controller = new AbortController()
    nextFetch = { uid: nextItem.uid, controller }
    try {
      const response = await fetch(streamUrl(nextItem.track.id), { signal: controller.signal })
      if (!response.ok) return
      const blob = await response.blob()
      if (prepared.get(nextItem.uid) === pending) prepared.set(nextItem.uid, URL.createObjectURL(blob))
    } catch {
      // Aborted (the queue changed) or the network dropped: the stream address still works.
    } finally {
      if (nextFetch?.controller === controller) nextFetch = null
    }
  }

  // The queue changed around the current song (play next, add to queue, shuffle, reorder): prepare again.
  watch(
    () => [queue.value[index.value + 1]?.uid, queue.value[index.value - 1]?.uid, repeat.value],
    () => !audio.paused && prepareNeighbours(),
  )

  /** Smart Shuffle: one suggestion after every three songs still to come. */
  async function addSmartPicks() {
    if (!navigator.onLine) return
    const queued = queue.value.flatMap((i) => (i.track.kind === 'remote' ? [i.track.id] : []))
    const want = Math.min(30, Math.max(3, Math.ceil(upcoming.value.length / 3)))
    try {
      const res = await api.post<{ data: ApiTrack[] }>('/smart-shuffle', { exclude: queued.slice(-3000), limit: want })
      if (!smartShuffle.value || !res.data.length) return
      const picks = useLibrary().remember(res.data).map((track) => ({ ...wrap(track), smart: true }))
      const head = queue.value.slice(0, index.value + 1)
      const rest = upcoming.value.filter((i) => !i.smart)
      const mixed: QueueItem[] = []
      rest.forEach((item, i) => {
        mixed.push(item)
        if ((i + 1) % 3 === 0 && picks.length) mixed.push(picks.shift()!)
      })
      queue.value = [...head, ...mixed, ...picks]
    } catch {
      // Offline or the server is busy: the plain shuffle carries on.
    }
  }

  /**
   * Move playback to a fresh, plain <audio> element at the same spot. The equalizer uses this on iPhone:
   * an element routed through Web Audio goes silent when iOS suspends the audio context, a plain one
   * keeps playing in the background.
   */
  function swapAudioElement(): HTMLAudioElement {
    const old = audio
    const wasPlaying = !old.paused
    const fresh = createAudio()
    fresh.volume = old.volume
    fresh.muted = old.muted
    fresh.playbackRate = old.playbackRate
    audioEvents.abort()
    audio = fresh
    audioEvents = attachAudioEvents(fresh)
    const src = old.currentSrc || old.src
    if (src) {
      restoreTime = old.currentTime
      fresh.src = src
      if (wasPlaying) fresh.play().catch(() => (blocked = true))
    }
    old.pause()
    old.removeAttribute('src')
    old.load()
    return fresh
  }

  function toggle() {
    if (!current.value) return
    if (audio.paused) play()
    else pause()
  }

  function handleError(message: string, autoAdvance: boolean) {
    useUi().toast(message, 'error')
    consecutiveErrors++
    if (autoAdvance && consecutiveErrors < Math.min(queue.value.length, 10) && index.value < queue.value.length - 1) {
      setTimeout(() => next(true), 400)
    } else {
      playing.value = false
    }
  }

  function next(auto = false) {
    if (!queue.value.length) return
    if (index.value < queue.value.length - 1) {
      index.value++
    } else if (repeat.value === 'all') {
      index.value = 0
    } else {
      if (auto) {
        pause()
        audio.currentTime = 0
      }
      return
    }
    load(true)
  }

  function prev() {
    if (audio.currentTime > 3 || index.value <= 0) {
      if (index.value <= 0 && repeat.value === 'all' && audio.currentTime <= 3 && queue.value.length > 1) {
        index.value = queue.value.length - 1
        return load(true)
      }
      seek(0)
      return
    }
    index.value--
    load(true)
  }

  function seek(time: number) {
    if (!isFinite(time)) return
    audio.currentTime = Math.max(0, Math.min(time, duration.value || time))
    currentTime.value = audio.currentTime
  }

  function seekBy(delta: number) {
    seek(audio.currentTime + delta)
  }

  // ---- queue management ---------------------------------------------------

  /** Replace the queue and start playing. */
  function playTracks(tracks: Track[], start = 0, options: { shuffle?: boolean; context?: PlayContext } = {}) {
    if (!tracks.length) return
    let items = tracks.map(wrap)
    const first = items[Math.min(Math.max(start, 0), items.length - 1)]
    const wantShuffle = options.shuffle ?? shuffle.value

    if (wantShuffle) {
      originalOrder.value = items.map((i) => i.uid)
      // An explicit pick plays first; "shuffle play" (start < 0) picks at random.
      const rest = fairShuffle(items.filter((i) => i !== first))
      items = start >= 0 ? [first, ...rest] : fairShuffle(items)
      index.value = 0
    } else {
      originalOrder.value = null
      index.value = items.indexOf(first)
    }
    shuffle.value = wantShuffle
    queue.value = items
    context.value = options.context ?? null
    consecutiveErrors = 0
    load(true)
    if (wantShuffle && smartShuffle.value) addSmartPicks()
  }

  function playNext(tracks: Track | Track[]) {
    const items = (Array.isArray(tracks) ? tracks : [tracks]).map(wrap)
    if (!current.value) return playTracks(items.map((i) => i.track))
    const at = index.value + 1 + nextInsert
    const q = [...queue.value]
    q.splice(at, 0, ...items)
    queue.value = q
    nextInsert += items.length
    if (originalOrder.value) {
      const o = [...originalOrder.value]
      o.splice(o.indexOf(current.value.uid) + 1, 0, ...items.map((i) => i.uid))
      originalOrder.value = o
    }
    useUi().toast(items.length > 1 ? t('toast.playNextMany', { n: items.length }) : t('toast.playNext', { title: items[0].track.title }), 'success')
  }

  function addToQueue(tracks: Track | Track[], options: { silent?: boolean } = {}) {
    const items = (Array.isArray(tracks) ? tracks : [tracks]).map(wrap)
    if (!current.value) return playTracks(items.map((i) => i.track))
    queue.value = [...queue.value, ...items]
    if (originalOrder.value) originalOrder.value = [...originalOrder.value, ...items.map((i) => i.uid)]
    if (options.silent) return
    useUi().toast(items.length > 1 ? t('toast.queuedMany', { n: items.length }) : t('toast.queued', { title: items[0].track.title }), 'success')
  }

  function remove(uid: string) {
    const i = queue.value.findIndex((item) => item.uid === uid)
    if (i < 0) return
    const q = [...queue.value]
    q.splice(i, 1)
    queue.value = q
    if (originalOrder.value) originalOrder.value = originalOrder.value.filter((u) => u !== uid)
    if (i < index.value) {
      index.value--
    } else if (i === index.value) {
      if (index.value >= q.length) index.value = q.length - 1
      load(playing.value)
    }
  }

  /** Replace the "next up" part of the queue (used by drag-and-drop reordering). */
  function setUpcoming(items: QueueItem[]) {
    queue.value = [...queue.value.slice(0, index.value + 1), ...items]
  }

  function clearUpcoming() {
    queue.value = queue.value.slice(0, index.value + 1)
    if (originalOrder.value) originalOrder.value = queue.value.map((i) => i.uid)
  }

  function jump(uid: string) {
    const i = queue.value.findIndex((item) => item.uid === uid)
    if (i < 0) return
    index.value = i
    load(true)
  }

  /** The shuffle button cycles: off → shuffle → smart shuffle → off. */
  function toggleShuffle() {
    const cur = current.value
    if (shuffle.value && !smartShuffle.value) {
      smartShuffle.value = true
      useUi().toast(t('toast.smartShuffle'))
      addSmartPicks()
      return
    }
    if (smartShuffle.value) {
      smartShuffle.value = false
      // Take the suggestions back out (not the one playing).
      queue.value = queue.value.filter((i) => !i.smart || i.uid === cur?.uid)
      if (cur) index.value = queue.value.findIndex((i) => i.uid === cur.uid)
    }
    if (!shuffle.value) {
      originalOrder.value = queue.value.map((i) => i.uid)
      if (cur) queue.value = [...queue.value.slice(0, index.value + 1), ...fairShuffle(upcoming.value)]
      shuffle.value = true
    } else {
      const byUid = new Map(queue.value.map((i) => [i.uid, i]))
      const ordered = (originalOrder.value ?? []).map((u) => byUid.get(u)).filter(Boolean) as QueueItem[]
      const extra = queue.value.filter((i) => !originalOrder.value?.includes(i.uid))
      queue.value = [...ordered, ...extra]
      if (cur) index.value = queue.value.findIndex((i) => i.uid === cur.uid)
      originalOrder.value = null
      shuffle.value = false
    }
  }

  function cycleRepeat() {
    repeat.value = repeat.value === 'off' ? 'all' : repeat.value === 'all' ? 'one' : 'off'
  }

  function setVolume(v: number) {
    volume.value = Math.max(0, Math.min(1, v))
    audio.volume = volume.value
    muted.value = volume.value === 0
    audio.muted = muted.value
    localStorage.setItem('smp.volume', String(volume.value))
  }

  function toggleMute() {
    muted.value = !muted.value
    audio.muted = muted.value
  }

  function setRate(r: number) {
    rate.value = r
    audio.playbackRate = r
  }

  function setSleep(minutes: number | 'track' | null) {
    sleep.value = minutes === null ? null : minutes === 'track' ? { mode: 'track' } : { mode: 'time', endsAt: Date.now() + minutes * 60_000 }
  }

  // ---- listen tracking ----------------------------------------------------

  function commitListen() {
    const item = current.value
    if (item && listened >= 10) rememberPlay(trackKey(item.track))
    if (item && item.track.kind === 'remote' && listened >= 10) {
      queuePlay({ track_id: item.track.id, seconds: Math.round(listened), played_at: new Date().toISOString() })
    }
    listened = 0
  }

  async function queuePlay(play: PendingPlay) {
    const pending = [...((await kv.get<PendingPlay[]>('plays.pending')) ?? []), play]
    await kv.set('plays.pending', pending)
    flushPlays()
  }

  let flushing = false
  async function flushPlays() {
    if (flushing || !navigator.onLine) return
    flushing = true
    try {
      const pending = (await kv.get<PendingPlay[]>('plays.pending')) ?? []
      if (pending.length) {
        await api.post('/plays', { plays: pending.slice(0, 500) })
        await kv.set('plays.pending', pending.slice(500))
      }
    } catch {
      // Retried on the next track change or when back online.
    } finally {
      flushing = false
    }
  }
  addEventListener('online', flushPlays)

  // ---- audio element events -----------------------------------------------

  /** Wire the player to an element; aborting the returned controller unhooks it again. */
  function attachAudioEvents(el: HTMLAudioElement): AbortController {
    const controller = new AbortController()
    const on = (type: string, handler: () => void) => el.addEventListener(type, handler, { signal: controller.signal })
    on('play', () => (playing.value = true))
    on('pause', () => (playing.value = false))
    on('playing', () => {
      loading.value = false
      consecutiveErrors = 0
      blocked = false
      prepareNeighbours()
    })
    on('waiting', () => (loading.value = true))
    on('canplay', () => (loading.value = false))
    on('loadedmetadata', () => {
      if (isFinite(audio.duration)) duration.value = audio.duration
      if (restoreTime) {
        audio.currentTime = restoreTime
        restoreTime = 0
      }
    })
    on('durationchange', () => {
      if (isFinite(audio.duration)) duration.value = audio.duration
    })
    on('progress', () => {
      const b = audio.buffered
      buffered.value = b.length ? b.end(b.length - 1) : 0
    })
    on('timeupdate', () => {
      const now = audio.currentTime
      const delta = now - lastTick
      if (delta > 0 && delta < 2 && !audio.paused) listened += delta / (audio.playbackRate || 1)
      lastTick = now
      currentTime.value = now
      if (sleep.value?.mode === 'time' && Date.now() >= sleep.value.endsAt) {
        sleep.value = null
        pause()
      }
      updatePositionState()
    })
    on('ended', () => {
      if (sleep.value?.mode === 'track') {
        sleep.value = null
        commitListen()
        playing.value = false
        return
      }
      if (repeat.value === 'one') {
        commitListen()
        audio.currentTime = 0
        play()
        return
      }
      next(true)
    })
    on('error', () => {
      if (!audio.getAttribute('src')) return
      loading.value = false
      handleError(navigator.onLine ? t('player.cannotPlay') : t('player.offlineSkip'), true)
    })
    on('playing', registerMediaHandlers)
    return controller
  }
  let audioEvents = attachAudioEvents(audio)

  // ---- artwork & media session --------------------------------------------

  async function updateCover() {
    const tr = track.value
    if (!tr || !tr.has_cover) {
      coverSrc.value = null
    } else if (tr.kind === 'remote') {
      coverSrc.value = coverUrl(tr.id, tr.updated_at)
    } else {
      coverSrc.value = await useLocal().cover(tr)
    }
    updateMediaSession()
  }

  function updateMediaSession() {
    if (!('mediaSession' in navigator)) return
    const tr = track.value
    if (!tr) {
      navigator.mediaSession.metadata = null
      return
    }
    const art = coverSrc.value ?? new URL('/pwa-512x512.png', location.href).toString()
    navigator.mediaSession.metadata = new MediaMetadata({
      title: tr.title,
      artist: tr.artist ?? '',
      album: tr.album ?? context.value?.name ?? '',
      artwork: [{ src: art, sizes: '512x512', type: 'image/jpeg' }],
    })
  }

  let lastPositionUpdate = 0
  function updatePositionState() {
    if (!('mediaSession' in navigator) || !navigator.mediaSession.setPositionState) return
    const now = Date.now()
    if (now - lastPositionUpdate < 1000 || !duration.value) return
    lastPositionUpdate = now
    try {
      navigator.mediaSession.setPositionState({ duration: duration.value, position: Math.min(audio.currentTime, duration.value), playbackRate: audio.playbackRate })
    } catch {
      // Some browsers reject transient states.
    }
  }

  const isIOS = /iPhone|iPad|iPod/.test(navigator.userAgent)

  // Lock screen / Control Center buttons. iOS drops handlers registered before the element is
  // "Now Playing" and falls back to ±10 s buttons, so (re)register them every time playback starts.
  function registerMediaHandlers() {
    if (!('mediaSession' in navigator)) return
    const handlers: [MediaSessionAction, MediaSessionActionHandler | null][] = [
      ['play', () => play()],
      ['pause', () => pause()],
      ['previoustrack', () => prev()],
      ['nexttrack', () => next()],
      ['seekto', (d) => d.seekTime !== undefined && seek(d.seekTime)],
      // iOS shows ±10 s instead of previous/next whenever seek handlers exist, so clear them there.
      ['seekbackward', isIOS ? null : (d) => seekBy(-(d.seekOffset ?? 10))],
      ['seekforward', isIOS ? null : (d) => seekBy(d.seekOffset ?? 10)],
    ]
    for (const [action, handler] of handlers) {
      try {
        navigator.mediaSession.setActionHandler(action, handler)
      } catch {
        // Unsupported action.
      }
    }
  }
  registerMediaHandlers()

  watch(playing, (p) => {
    if ('mediaSession' in navigator) navigator.mediaSession.playbackState = p ? 'playing' : 'paused'
  })

  // ---- persistence --------------------------------------------------------

  let saveTimer: ReturnType<typeof setTimeout> | undefined
  function save() {
    clearTimeout(saveTimer)
    saveTimer = setTimeout(() => {
      kv.set(STATE_KEY, {
        queue: queue.value,
        index: index.value,
        time: audio.currentTime,
        shuffle: shuffle.value,
        smartShuffle: smartShuffle.value,
        originalOrder: originalOrder.value,
        repeat: repeat.value,
        rate: rate.value,
        context: context.value,
      }).catch(() => {})
    }, 1500)
  }
  watch([queue, index, shuffle, smartShuffle, repeat, rate, context], save)
  watch(() => Math.floor(currentTime.value / 5), save)
  addEventListener('pagehide', () => commitListen())

  async function restore() {
    const s = await kv.get<any>(STATE_KEY)
    if (!s?.queue?.length) return
    queue.value = s.queue
    index.value = Math.min(s.index ?? 0, s.queue.length - 1)
    shuffle.value = !!s.shuffle
    smartShuffle.value = !!s.smartShuffle && !!s.shuffle
    originalOrder.value = s.originalOrder ?? null
    repeat.value = s.repeat ?? 'off'
    rate.value = s.rate ?? 1
    context.value = s.context ?? null
    currentTime.value = s.time ?? 0
    // Don't touch the network until the user presses play.
    duration.value = track.value?.duration ?? 0
    updateCover()
  }

  /** Swap in fresh track data (e.g. after a metadata edit) without disturbing playback. */
  function refreshTrack(updated: Track) {
    const key = trackKey(updated)
    let changed = false
    const q = queue.value.map((item) => {
      if (trackKey(item.track) !== key) return item
      changed = true
      return { ...item, track: updated }
    })
    if (changed) {
      queue.value = q
      updateCover()
    }
  }

  function reset() {
    pause()
    queue.value = []
    index.value = -1
    originalOrder.value = null
    context.value = null
    load(false)
    kv.delete(STATE_KEY)
  }

  return {
    /** The current element (it can be swapped, so always read it through this). */
    getAudio: () => audio,
    swapAudioElement,
    queue, index, current, track, upcoming, history, shuffle, repeat, playing, loading,
    currentTime, duration, buffered, progress, volume, muted, rate, sleep, context, coverSrc,
    play, pause, toggle, next, prev, seek, seekBy,
    smartShuffle,
    playTracks, playNext, addToQueue, remove, setUpcoming, clearUpcoming, jump,
    toggleShuffle, cycleRepeat, setVolume, toggleMute, setRate, setSleep,
    restore, flushPlays, refreshTrack, reset,
  }
})
