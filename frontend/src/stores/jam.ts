import { defineStore } from 'pinia'
import { computed, ref, shallowRef, watch } from 'vue'
import { api, ApiError, request } from '@/lib/api'
import { apiBase } from '@/lib/config'
import { t } from '@/lib/i18n'
import type { ApiTrack } from '@/lib/types'
import { useAuth } from './auth'
import { useLibrary } from './library'
import { type JamHooks, type QueueItem, usePlayer } from './player'
import { useUi } from './ui'

export interface JamEntry {
  uid: string
  track_id: number
  /** Who added it (null: the Jam mix). */
  by: number | null
}

export interface JamMember {
  id: number
  name: string
  username: string
  avatar_url: string | null
  /** Plays the music on their own device (off: same room, adds songs and reacts). */
  listening: boolean
  online: boolean
}

export interface JamState {
  code: string
  host_id: number
  guests_control: boolean
  autofill: boolean
  version: number
  ended: boolean
  playing: boolean
  current_uid: string | null
  /** The current song was at position_ms at anchor_ms, on the server clock. */
  position_ms: number
  anchor_ms: number
  server_now: number
  queue: JamEntry[]
  skip_votes: { users: number[]; needed: number } | null
  members: JamMember[]
}

interface JamHub {
  url: string
  topic: string
  token: string
}

export interface JamResponse {
  data: JamState
  member: boolean
  hub: JamHub | null
}

type JamEvent = { type: string; user_id?: number | null; host_id?: number; [key: string]: any }

export const REACTIONS = ['🔥', '❤️', '😍', '😂', '👏', '🎉', '💃', '🥲']

const SAVED_KEY = 'smp.jam'
const LISTENING_KEY = 'smp.jamListening'

/**
 * Jams: listening together. The server keeps the queue and where playback is, anchored to its clock;
 * every device plays the songs itself and stays in step with that anchor (small differences are evened
 * out by nudging the playback rate, big ones by seeking). Changes arrive live over the Mercure hub, with
 * a ping every 15 seconds as a safety net (and as the only channel when there is no hub).
 *
 * A device that loses the connection (screen locked) keeps playing through the shared queue on its own,
 * telling the Jam when a song ends, and lines up again as soon as it hears from the server.
 */
export const useJam = defineStore('jam', () => {
  const state = shallowRef<JamState | null>(null)
  /** Live updates are connected. */
  const live = ref(false)
  /** This device plays the music (off: the music plays on the others' devices). */
  const listening = ref(localStorage.getItem(LISTENING_KEY) !== '0')
  /** Paused on this device only (a guest who can't pause for everyone, or a call). */
  const localPause = ref(false)
  /** Seconds this device is ahead (+) or behind (−) the Jam. */
  const drift = ref(0)
  const reactions = ref<{ id: number; emoji: string; name: string; x: number }[]>([])
  const activity = ref<{ id: number; text: string; at: number }[]>([])

  const me = computed(() => useAuth().user?.id ?? 0)
  const active = computed(() => !!state.value && !state.value.ended)
  const isHost = computed(() => !!state.value && state.value.host_id === me.value)
  const canControl = computed(() => !!state.value && (isHost.value || state.value.guests_control))
  const members = computed(() => state.value?.members ?? [])
  const host = computed(() => members.value.find((m) => m.id === state.value?.host_id) ?? null)
  const syncStatus = computed(() =>
    !listening.value ? 'remote' : localPause.value ? 'paused' : Math.abs(drift.value) > 0.35 ? 'syncing' : 'synced',
  )
  /** Names of everyone seen in this Jam, for events about members who already left. */
  const names = new Map<number, string>()
  const member = (id: number | null | undefined) => members.value.find((m) => m.id === id) ?? null

  // ---- the clock -----------------------------------------------------------------------------------

  /** The server clock minus this device's (ms), measured on the quickest of a few round trips. */
  let offset = 0
  let clockAt = 0
  const serverNow = () => Date.now() + offset

  async function syncClock() {
    let best = Infinity
    for (let i = 0; i < 4; i++) {
      const sent = Date.now()
      try {
        const { now } = await api.get<{ now: number }>('/jams/clock')
        const back = Date.now()
        if (back - sent < best) {
          best = back - sent
          offset = now - (sent + back) / 2
        }
      } catch {
        break
      }
    }
    clockAt = Date.now()
  }

  /** Where the Jam's song is now, in seconds. */
  function expected(s = state.value): number {
    if (!s) return 0
    return Math.max(0, s.playing ? s.position_ms + serverNow() - s.anchor_ms : s.position_ms) / 1000
  }

  // ---- following the Jam ---------------------------------------------------------------------------

  /** Requests in flight: until they answer, this device's own change stands (no snapping back). */
  let inflight = 0
  const fetching = new Set<number>()

  /** The Jam's queue as player entries (songs not in the catalogue yet are fetched, then shown). */
  function queueItems(s: JamState): QueueItem[] {
    const library = useLibrary()
    const out: QueueItem[] = []
    const unknown: number[] = []
    for (const entry of s.queue) {
      const track = library.get(entry.track_id)
      if (track) out.push({ uid: entry.uid, track, by: entry.by })
      else if (!fetching.has(entry.track_id)) unknown.push(entry.track_id)
    }
    if (unknown.length) fetchTracks(unknown)
    return out
  }

  async function fetchTracks(ids: number[]) {
    ids = ids.slice(0, 20)
    ids.forEach((id) => fetching.add(id))
    const found = await Promise.all(ids.map((id) => api.get<{ data: ApiTrack }>(`/tracks/${id}`).then((r) => r.data, () => null)))
    const tracks = found.filter((tr): tr is ApiTrack => !!tr)
    if (!tracks.length) return
    useLibrary().remember(tracks)
    ids.forEach((id) => fetching.delete(id))
    sync()
  }

  /** Bring this device in line with the Jam. */
  function sync() {
    const s = state.value
    if (!s || inflight) return
    const player = usePlayer()
    const list = queueItems(s)
    const at = list.findIndex((i) => i.uid === s.current_uid)
    if (!listening.value) return player.showRemote(list, at, s.playing, expected(s))
    // Still playing the last seconds of the previous song: let it finish here, the next one is the Jam's.
    const audio = player.getAudio()
    const local = player.current
    if (local && !audio.paused && at > 0 && list[at - 1].uid === local.uid && audio.duration - audio.currentTime < 4) return
    player.follow(list, at, s.playing && !localPause.value, expected(s))
  }

  /** The Jam this device is in; answers about any other (a ping that was on its way out) are ignored. */
  let joined: string | null = null

  function apply(next: JamState, event?: JamEvent | null) {
    const prev = state.value
    if (next.code !== joined || (prev && next.version < prev.version)) return
    for (const m of next.members) names.set(m.id, m.name)
    state.value = next
    if (next.ended) return exit('ended')
    if (event?.type === 'kick' && event.user_id === me.value) return exit('kicked')
    if (event) announce(event)
    sync()
  }

  // Every half second: keep the song in step with the Jam (or, without sound here, keep its progress moving).
  let lastSeek = 0
  let lastResync = 0
  function keepInStep() {
    const s = state.value
    if (!s) return
    const player = usePlayer()
    if (!listening.value) {
      player.currentTime = Math.min(expected(s), player.duration || Infinity)
      return
    }
    const audio = player.getAudio()
    if (inflight || localPause.value || !s.playing || audio.paused || player.loading) {
      drift.value = 0
      return
    }
    if (player.current?.uid !== s.current_uid) {
      if (Date.now() - lastResync > 2000) {
        lastResync = Date.now()
        sync()
      }
      return
    }
    const target = expected(s)
    const d = audio.currentTime - target
    drift.value = d
    // The song is about to end: let it finish.
    if (target >= (player.duration || Infinity) - 1) return
    if (Math.abs(d) > 1 && Date.now() - lastSeek > 3000) {
      lastSeek = Date.now()
      audio.playbackRate = 1
      audio.currentTime = target + 0.15 // a seek takes a moment to come through
      return
    }
    // A little faster or slower until in step: nobody hears ±7%, everyone hears a jump.
    audio.playbackRate = Math.abs(d) < 0.05 ? 1 : 1 - Math.max(-0.07, Math.min(0.07, d * 0.4))
  }
  setInterval(keepInStep, 500)

  // ---- talking to the server -----------------------------------------------------------------------

  async function send(path: string, body?: unknown, method: 'POST' | 'PUT' | 'PATCH' | 'DELETE' = 'POST'): Promise<JamResponse | null> {
    const code = state.value?.code
    if (!code) return null
    inflight++
    const abort = new AbortController()
    const timer = setTimeout(() => abort.abort(), 10_000)
    let res: JamResponse | null = null
    try {
      res = await request<JamResponse>(method, `/jams/${code}${path}`, { body, signal: abort.signal })
    } catch (e) {
      failed(e)
    } finally {
      clearTimeout(timer)
      inflight--
    }
    if (res?.data) apply(res.data)
    else sync() // back in line with what the Jam last said
    return res
  }

  function failed(e: unknown, quiet = false) {
    if (!(e instanceof ApiError)) {
      if (!quiet) useUi().toast(t('jam.offline'), 'error')
      return
    }
    const reason = e.data?.reason
    if (e.status === 410) return exit('ended')
    if (reason === 'banned') return exit('kicked')
    if (reason === 'no_control') return useUi().toast(t('jam.noControl'))
    if (reason === 'not_member') return void ping()
    if (!quiet) useUi().toast(e.status ? e.first : t('jam.offline'), 'error')
  }

  /** Only some of the state changes on this device right away (the rest waits for the server). */
  function patch(change: Partial<JamState>) {
    if (state.value) state.value = { ...state.value, ...change }
  }

  /** What the player's controls do while in a Jam (see JamHooks in stores/player.ts). */
  const hooks: JamHooks = {
    control(action, arg) {
      const s = state.value
      if (!s) return true
      const player = usePlayer()
      const here = listening.value
      const positionMs = Math.round(1000 * (here ? player.getAudio().currentTime : expected(s)))
      const from = player.current?.uid ?? s.current_uid ?? undefined

      if (!canControl.value) {
        if (action === 'next') {
          voteSkip()
          return false
        }
        if (here && (action === 'play' || action === 'pause')) {
          // Only this device: pause for a call, then rejoin where everyone is.
          localPause.value = action === 'pause'
          if (action === 'pause') return true
          if (!s.playing) useUi().toast(t('jam.pausedByHost'))
          sync()
          return false
        }
        useUi().toast(t('jam.noControl'))
        return false
      }

      switch (action) {
        case 'play':
          localPause.value = false
          if (!s.playing) patch({ playing: true, position_ms: positionMs, anchor_ms: serverNow() })
          sync() // plays here at once, at the Jam's spot
          if (!s.playing) send('/playback', { action: 'play', position_ms: positionMs })
          return false
        case 'pause':
          patch({ playing: false, position_ms: positionMs, anchor_ms: serverNow() })
          if (!here) sync()
          send('/playback', { action: 'pause', position_ms: positionMs })
          return here
        case 'seek': {
          const ms = Math.round(Number(arg) * 1000)
          patch({ position_ms: ms, anchor_ms: serverNow() })
          if (!here) sync()
          send('/playback', { action: 'seek', position_ms: ms })
          return here
        }
        case 'next':
          send('/playback', { action: 'next', from })
          return here // moves on here at once (the lock screen and headphones need that)
        case 'prev':
          send('/playback', { action: 'prev', from, position_ms: positionMs })
          return here
        case 'jump':
          send('/playback', { action: 'jump', uid: arg })
          return here
      }
      return here
    },
    advanced(from) {
      send('/playback', { action: 'advance', from })
    },
    add(tracks, mode) {
      const ids = tracks.flatMap((tr) => (tr.kind === 'remote' ? [tr.id] : []))
      if (!ids.length) return void useUi().toast(t('jam.deviceSongs'), 'error')
      // Picking a song to play now means listening again on this device.
      if (mode === 'play') localPause.value = false
      send('/queue', { track_ids: ids.slice(0, 200), mode }).then((res) => {
        if (!res || (mode === 'play' && canControl.value)) return
        const message = ids.length > 1 ? t('jam.addedMany', { n: ids.length }) : t('jam.added', { title: tracks.find((tr) => tr.kind === 'remote')!.title })
        useUi().toast(ids.length < tracks.length ? `${message} · ${t('jam.deviceSongsSkipped')}` : message, 'success')
      })
    },
    edit(action, arg) {
      if (action === 'remove') {
        send(`/queue/${arg}`, undefined, 'DELETE')
        return false
      }
      if (!canControl.value) {
        useUi().toast(t('jam.noControl'))
        return false
      }
      if (action === 'reorder') {
        send('/queue', { uids: arg }, 'PUT')
        return true // the new order shows here at once
      }
      send('/playback', { action })
      if (action === 'shuffle') useUi().toast(t('jam.shuffled'))
      return false
    },
  }

  // ---- live updates --------------------------------------------------------------------------------

  let source: EventSource | null = null
  let hub: JamHub | null = null
  /** Changes the ping found that live updates didn't bring: the hub is not delivering, so poll quickly. */
  let missed = 0

  function connect(next?: JamHub | null) {
    if (next) hub = next
    if (!hub || !state.value || (source && source.readyState !== EventSource.CLOSED)) return
    const url = new URL(hub.url)
    url.searchParams.append('topic', hub.topic)
    url.searchParams.set('authorization', hub.token)
    const es = new EventSource(url.toString())
    source = es
    es.onopen = () => (live.value = true)
    // EventSource reconnects by itself; a refused token closes it, and the next ping brings a fresh one.
    es.onerror = () => (live.value = es.readyState === EventSource.OPEN)
    es.onmessage = (e) => {
      let msg: any
      try {
        msg = JSON.parse(e.data)
      } catch {
        return
      }
      if (msg.type === 'state' && msg.jam) {
        missed = 0
        if (!inflight || msg.jam.version > (state.value?.version ?? 0)) apply(msg.jam, msg.event)
      } else if (msg.type === 'reaction' && msg.user_id !== me.value) {
        showReaction(msg.emoji, msg.user_id)
      }
    }
  }

  function disconnect() {
    source?.close()
    source = null
    hub = null
    live.value = false
  }

  let pingTimer: ReturnType<typeof setTimeout> | undefined
  function schedulePing() {
    clearTimeout(pingTimer)
    if (!state.value) return
    const slow = live.value && missed < 2
    pingTimer = setTimeout(ping, slow ? 15_000 : 2500)
  }

  /** Check in: stay a member, report whether this device plays, and catch up on anything missed. */
  async function ping() {
    const s = state.value
    if (!s) return
    clearTimeout(pingTimer)
    if (Date.now() - clockAt > 10 * 60_000) await syncClock()
    try {
      const res = await api.post<JamResponse>(`/jams/${s.code}/ping`, { listening: listening.value })
      if (live.value && state.value && res.data.version > state.value.version) missed++
      if (!inflight) apply(res.data)
      connect(res.hub)
    } catch (e) {
      failed(e, true)
    }
    schedulePing()
  }

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && state.value) {
      connect()
      ping()
    }
  })
  addEventListener('online', () => state.value && (connect(), ping()))

  // ---- joining and leaving -------------------------------------------------------------------------

  function enter(res: JamResponse, options: { paused?: boolean } = {}) {
    disconnect()
    state.value = null
    missed = 0
    localPause.value = !!options.paused
    activity.value = []
    usePlayer().setJamHooks(hooks)
    joined = res.data.code
    localStorage.setItem(SAVED_KEY, res.data.code)
    apply(res.data)
    connect(res.hub)
    schedulePing()
  }

  /** Start a Jam with what is playing now and the queue after it. */
  async function start() {
    const player = usePlayer()
    const remote = player.track?.kind === 'remote'
    const ids = player.queue.slice(Math.max(0, player.index)).flatMap((i) => (i.track.kind === 'remote' ? [i.track.id] : [])).slice(0, 500)
    listening.value = true
    localStorage.setItem(LISTENING_KEY, '1')
    const clock = syncClock()
    const res = await api.post<JamResponse>('/jams', {
      track_ids: ids,
      position_ms: remote ? Math.round(player.currentTime * 1000) : 0,
      playing: remote && player.playing,
    })
    await clock
    enter(res)
    return res.data
  }

  /** The Jam behind an invite link, before joining. */
  function preview(code: string) {
    return api.get<JamResponse>(`/jams/${code}`)
  }

  async function join(code: string, withSound = true) {
    // From the tap itself, so the song can start once the Jam answers (iOS wants a tap for the first play).
    if (withSound) usePlayer().unlock()
    listening.value = withSound
    localStorage.setItem(LISTENING_KEY, withSound ? '1' : '0')
    await syncClock()
    const res = await api.post<JamResponse>(`/jams/${code}/join`, { listening: withSound })
    enter(res)
  }

  /** After a restart: back into the Jam this member is in, paused until they press play. */
  async function restore() {
    if (!localStorage.getItem(SAVED_KEY) || state.value) return
    try {
      const res = await api.get<JamResponse | { data: null }>('/jams/current')
      if (!res.data || !(res as JamResponse).member) return localStorage.removeItem(SAVED_KEY)
      await syncClock()
      enter(res as JamResponse, { paused: listening.value })
    } catch {
      // Offline: try again next launch.
    }
  }

  function exit(reason: 'left' | 'ended' | 'kicked' | 'signedOut') {
    if (!state.value) return
    const wasHost = isHost.value
    clearTimeout(pingTimer)
    disconnect()
    joined = null
    state.value = null
    localPause.value = false
    drift.value = 0
    reactions.value = []
    localStorage.removeItem(SAVED_KEY)
    usePlayer().setJamHooks(null)
    const ui = useUi()
    if (reason === 'ended') ui.toast(wasHost ? t('jam.youEnded') : t('jam.ended'))
    else if (reason === 'kicked') ui.toast(t('jam.kicked'), 'error')
    else if (reason === 'left') ui.toast(t('jam.left'))
  }

  async function leave() {
    const code = state.value?.code
    exit('left')
    if (code) await api.post(`/jams/${code}/leave`).catch(() => {})
  }

  const end = () => send('', undefined, 'DELETE')
  const configure = (settings: Partial<Pick<JamState, 'guests_control' | 'autofill'>>) => send('', settings, 'PATCH')
  const kick = (userId: number) => send(`/members/${userId}`, undefined, 'DELETE')

  function voteSkip() {
    if (state.value?.skip_votes?.users.includes(me.value)) return useUi().toast(t('jam.alreadyVoted'))
    send('/playback', { action: 'vote' }).then((res) => res && !canControl.value && useUi().toast(t('jam.voted')))
  }

  /** Play the music on this device, or not (same room: one speaker plays, the rest add songs and react). */
  function setListening(on: boolean) {
    if (listening.value === on) return
    listening.value = on
    localStorage.setItem(LISTENING_KEY, on ? '1' : '0')
    localPause.value = false
    if (!on) usePlayer().getAudio().pause()
    sync()
    ping()
  }

  async function invite(userIds: number[]) {
    const code = state.value?.code
    if (!code || !userIds.length) return
    await api.post(`/jams/${code}/invite`, { user_ids: userIds })
    useUi().toast(t('jam.invited', { n: userIds.length }), 'success')
  }

  function inviteLink() {
    return `${apiBase() || location.origin}/jam/${state.value?.code ?? ''}`
  }

  // ---- reactions and what happened -----------------------------------------------------------------

  let nextId = 0
  function showReaction(emoji: string, userId: number) {
    const id = ++nextId
    reactions.value = [...reactions.value.slice(-24), { id, emoji, name: names.get(userId) ?? '', x: 8 + Math.random() * 84 }]
    setTimeout(() => (reactions.value = reactions.value.filter((r) => r.id !== id)), 3200)
  }

  function react(emoji: string) {
    showReaction(emoji, me.value)
    const code = state.value?.code
    if (code) api.post(`/jams/${code}/react`, { emoji }).catch(() => {})
  }

  function announce(e: JamEvent) {
    const mine = e.user_id === me.value
    const name = names.get(e.user_id ?? 0) ?? ''
    const title = (id?: number) => (id ? useLibrary().get(id)?.title : undefined) ?? t('jam.aSong')
    let text: string | null = null
    switch (e.type) {
      case 'join':
        if (!mine) text = t('jam.ev.join', { name })
        break
      case 'leave':
      case 'away':
        if (!mine) text = t('jam.ev.leave', { name })
        break
      case 'kick':
        text = t('jam.ev.kick', { name })
        break
      case 'add':
        if (!mine) text = e.count > 1 ? t('jam.ev.addMany', { name, n: e.count }) : t('jam.ev.add', { name, title: title(e.track_ids?.[0]) })
        break
      case 'jump':
        if (!mine) text = t('jam.ev.jump', { name, title: title(e.track_id ?? e.track_ids?.[0]) })
        break
      case 'skip':
        if (e.voted) text = t('jam.ev.voteSkip')
        else if (!mine) text = t('jam.ev.skip', { name })
        break
      case 'vote':
        text = t('jam.ev.vote', { name, votes: e.votes, needed: e.needed })
        break
      case 'play':
      case 'pause':
      case 'prev':
      case 'shuffle':
      case 'clear':
        if (!mine) text = t(`jam.ev.${e.type}`, { name })
        break
      case 'settings':
        if (!mine) text = t('jam.ev.settings')
        break
    }
    if (e.host_id) {
      const handover = e.host_id === me.value ? t('jam.ev.youHost') : t('jam.ev.host', { name: names.get(e.host_id) ?? '' })
      text = text ? `${text} · ${handover}` : handover
    }
    if (!text) return
    activity.value = [{ id: ++nextId, text, at: Date.now() }, ...activity.value].slice(0, 30)
    useUi().toast(text)
  }

  watch(() => useAuth().isLoggedIn, (signedIn) => !signedIn && exit('signedOut'))

  return {
    state, active, live, listening, localPause, drift, syncStatus, reactions, activity,
    isHost, canControl, members, host, member, expected,
    start, preview, join, restore, leave, end, configure, kick, voteSkip, setListening, invite, inviteLink, react,
  }
})
