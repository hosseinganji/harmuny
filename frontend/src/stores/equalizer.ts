import { defineStore } from 'pinia'
import { computed, ref, watch } from 'vue'
import { usePlayer } from './player'

const KEY = 'smp.eq'

/** Band centre frequencies (Hz), the classic 10-band graphic EQ. */
export const BANDS = [32, 64, 125, 250, 500, 1000, 2000, 4000, 8000, 16000] as const
export const GAIN_LIMIT = 12
/** Band width: wide enough that neighbouring octave bands blend into a smooth curve, without ripples. */
const Q = 1.0

export const PRESETS: Record<string, number[]> = {
  flat: [0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
  bass: [7, 6, 5, 3, 1, 0, 0, 0, 0, 0],
  treble: [0, 0, 0, 0, 0, 1, 3, 5, 6, 7],
  vocal: [-2, -2, -1, 1, 3, 4, 4, 3, 1, 0],
  pop: [-1, 1, 3, 4, 3, 0, -1, -1, 1, 2],
  rock: [5, 4, 3, 1, -1, -1, 1, 3, 4, 5],
  hiphop: [6, 5, 2, 3, -1, -1, 1, 0, 2, 3],
  electronic: [5, 4, 1, 0, -2, 2, 1, 1, 4, 5],
  jazz: [3, 2, 1, 2, -1, -1, 0, 1, 2, 3],
  classical: [4, 3, 2, 1, -1, -1, 0, 2, 3, 4],
  acoustic: [4, 3, 2, 1, 2, 2, 3, 3, 2, 1],
  loudness: [6, 4, 0, 0, -2, 0, -1, -3, 4, 2],
  night: [-3, -2, -1, 0, 1, 2, 2, 1, -1, -2],
}

interface EqState {
  enabled: boolean
  preset: string
  gains: number[]
  preamp: number
  balance: number
  mono: boolean
  leveler: boolean
}

function configure(filter: BiquadFilterNode, i: number) {
  filter.type = i === 0 ? 'lowshelf' : i === BANDS.length - 1 ? 'highshelf' : 'peaking'
  filter.frequency.value = BANDS[i]
  filter.Q.value = Q
}

/** Log-spaced frequencies for drawing the response curve. */
export const CURVE_FREQS = Float32Array.from({ length: 96 }, (_, i) => 20 * 1000 ** (i / 95))

/**
 * Sound controls built on Web Audio. The audio element is only routed through the graph once the
 * equalizer is first switched on (createMediaElementSource is permanent), so default playback stays
 * a plain <audio> element.
 *
 * The graph is wired once and never rewired while playing (reconnecting nodes is what made it crackle):
 * source → preamp → 10 bands → mono mix → balance → leveler → headroom → limiter → speakers.
 */
export const useEqualizer = defineStore('equalizer', () => {
  const saved = JSON.parse(localStorage.getItem(KEY) ?? '{}') as Partial<EqState>
  const state = ref<EqState>({
    enabled: false,
    preset: 'flat',
    gains: [...PRESETS.flat],
    preamp: 0,
    balance: 0,
    mono: false,
    leveler: false,
    ...saved,
  })

  let ctx: AudioContext | null = null
  let preamp: GainNode
  let filters: BiquadFilterNode[] = []
  let mixer: GainNode
  let panner: StereoPannerNode
  let leveler: DynamicsCompressorNode
  let headroom: GainNode
  let limiter: DynamicsCompressorNode

  /** Must run from a user gesture the first time (browsers only start audio from one). */
  // The element this graph is wired to (createMediaElementSource is permanent for an element).
  let wired: HTMLAudioElement | null = null
  let elementEvents: AbortController | null = null
  let silentSince = 0

  /** Must run from a user gesture the first time (browsers only start audio from one). */
  function connect() {
    const player = usePlayer()
    const audio = player.getAudio()
    if (ctx && wired === audio) return
    if (ctx) detach() // the player moved to a new element; rebuild on that one
    // Needed when the server is on another origin (the Android app); same-origin is unaffected.
    audio.crossOrigin = 'anonymous'
    ctx = new AudioContext({ latencyHint: 'playback' })
    wired = audio
    const source = ctx.createMediaElementSource(audio)
    preamp = ctx.createGain()
    filters = BANDS.map((_, i) => {
      const f = ctx!.createBiquadFilter()
      configure(f, i)
      return f
    })
    mixer = ctx.createGain()
    panner = ctx.createStereoPanner()
    leveler = ctx.createDynamicsCompressor()
    headroom = ctx.createGain()
    // A gentle safety limiter just under full scale: boosts never clip into distortion.
    limiter = ctx.createDynamicsCompressor()
    limiter.threshold.value = -1.5
    limiter.knee.value = 2
    limiter.ratio.value = 20
    limiter.attack.value = 0.002
    limiter.release.value = 0.12

    let node: AudioNode = source.connect(preamp)
    for (const f of filters) node = node.connect(f)
    node.connect(mixer).connect(panner).connect(leveler).connect(headroom).connect(limiter).connect(ctx.destination)
    apply(true)

    // Keep the context running whenever music plays; iOS suspends it with the page.
    elementEvents = new AbortController()
    const signal = elementEvents.signal
    const keepRunning = () => {
      if (ctx && ctx.state !== 'running' && !audio.paused) ctx.resume().catch(() => {})
    }
    ctx.addEventListener('statechange', keepRunning, { signal })
    audio.addEventListener('play', keepRunning, { signal })
    audio.addEventListener('playing', keepRunning, { signal })
    // Watchdog: the song plays but the context stays stuck (an iOS interruption, a call, Siri…) means
    // silence that only a restart used to fix. Move the song to a plain element so it is heard again.
    audio.addEventListener('timeupdate', () => {
      if (!ctx || audio.paused || ctx.state === 'running') return void (silentSince = 0)
      silentSince ||= Date.now()
      if (Date.now() - silentSince > 2500) rescue()
    }, { signal })
  }

  /** Drop the graph (the element it was wired to is no longer the player's). */
  function detach() {
    elementEvents?.abort()
    elementEvents = null
    ctx?.close().catch(() => {})
    ctx = null
    wired = null
    silentSince = 0
  }

  /** Hand the song to a plain element so it keeps sounding; the equalizer comes back on the next tap in view. */
  function rescue() {
    if (!ctx) return
    detach()
    usePlayer().swapAudioElement()
    resumeOnGesture()
  }

  // iPhone suspends Web Audio when the app goes to the background or the screen locks, which made songs
  // play in silence. Before that happens, move playback to a plain element (which iOS keeps playing).
  const isIOS = /iPhone|iPad|iPod/.test(navigator.userAgent)
  // Paused too: a song started later from the lock screen, a car or headphones must not go through a
  // suspended context either.
  document.addEventListener('visibilitychange', () => {
    if (isIOS && document.visibilityState === 'hidden' && ctx) rescue()
  })

  /** Glide a parameter to its new value (no zipper noise, no piled-up automation). */
  function glide(param: AudioParam, value: number, immediate = false) {
    const now = ctx!.currentTime
    param.cancelScheduledValues(now)
    if (immediate) param.setValueAtTime(value, now)
    else {
      param.setValueAtTime(param.value, now)
      param.setTargetAtTime(value, now, 0.04)
    }
  }

  /** Total boost the current settings can add, so the headroom can take it back out. */
  const peakBoost = computed(() => {
    const s = state.value
    return Math.max(0, Math.max(...s.gains) + s.preamp)
  })

  function apply(immediate = false) {
    if (!ctx) return
    const s = state.value
    const on = s.enabled
    glide(preamp.gain, on ? 10 ** (s.preamp / 20) : 1, immediate)
    filters.forEach((f, i) => glide(f.gain, on ? s.gains[i] ?? 0 : 0, immediate))
    glide(panner.pan, on ? s.balance : 0, immediate)

    const mono = on && s.mono
    if (mixer.channelCount !== (mono ? 1 : 2)) {
      mixer.channelCountMode = mono ? 'explicit' : 'max'
      mixer.channelCount = mono ? 1 : 2
    }

    // Leveler on: even out loud and quiet passages. Off: the same node, made transparent.
    const level = on && s.leveler
    glide(leveler.threshold, level ? -26 : 0, immediate)
    glide(leveler.ratio, level ? 4 : 1, immediate)
    glide(leveler.knee, level ? 20 : 0, immediate)
    leveler.attack.value = 0.008
    leveler.release.value = 0.25

    // Headroom: cut by most of the boost (plus make-up for the leveler); the limiter catches the rest.
    const db = on ? -peakBoost.value * 0.85 + (level ? 6 : 0) : 0
    glide(headroom.gain, 10 ** (db / 20), immediate)
  }

  watch(state, (s) => {
    localStorage.setItem(KEY, JSON.stringify(s))
    apply()
  }, { deep: true })

  /** Reconnect a saved, enabled equalizer on the first tap anywhere while the app is in view. */
  let armed = false
  function resumeOnGesture() {
    if (!state.value.enabled || armed) return
    armed = true
    const start = () => {
      if (document.visibilityState !== 'visible') return
      armed = false
      removeEventListener('pointerdown', start, true)
      if (state.value.enabled) connect()
    }
    addEventListener('pointerdown', start, true)
  }

  function setEnabled(on: boolean) {
    if (on) connect()
    state.value.enabled = on
    ctx?.resume()
  }

  function setPreset(name: string) {
    state.value.preset = name
    state.value.gains = [...(PRESETS[name] ?? PRESETS.flat)]
  }

  function setGain(i: number, db: number) {
    const gains = [...state.value.gains]
    gains[i] = Math.round(db * 2) / 2
    state.value.gains = gains
    state.value.preset = Object.entries(PRESETS).find(([, g]) => g.every((v, j) => v === gains[j]))?.[0] ?? 'custom'
  }

  function reset() {
    state.value = { ...state.value, preset: 'flat', gains: [...PRESETS.flat], preamp: 0, balance: 0, mono: false, leveler: false }
  }

  // The response curve is computed on a tiny offline context, so it can be drawn before (or without)
  // routing any audio through the equalizer.
  let curveFilters: BiquadFilterNode[] | null = null
  function responseCurve(gains: number[], preampDb: number): number[] {
    if (!curveFilters) {
      const offline = new OfflineAudioContext(1, 1, 44100)
      curveFilters = BANDS.map((_, i) => {
        const f = offline.createBiquadFilter()
        configure(f, i)
        return f
      })
    }
    const total = new Float32Array(CURVE_FREQS.length).fill(preampDb)
    const mag = new Float32Array(CURVE_FREQS.length)
    const phase = new Float32Array(CURVE_FREQS.length)
    curveFilters.forEach((f, i) => {
      f.gain.value = gains[i] ?? 0
      f.getFrequencyResponse(CURVE_FREQS, mag, phase)
      for (let k = 0; k < mag.length; k++) total[k] += 20 * Math.log10(mag[k] || 1e-6)
    })
    return [...total]
  }

  return { state, setEnabled, setPreset, setGain, reset, resumeOnGesture, responseCurve }
})
