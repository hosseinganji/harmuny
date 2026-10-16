import { defineStore } from 'pinia'
import { ref, watch } from 'vue'
import { usePlayer } from './player'

const KEY = 'smp.eq'

/** Band centre frequencies (Hz), the classic 10-band graphic EQ. */
export const BANDS = [32, 64, 125, 250, 500, 1000, 2000, 4000, 8000, 16000] as const
export const GAIN_LIMIT = 12

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

/**
 * Sound controls built on Web Audio. The audio element is only routed through the graph once the
 * equalizer is first switched on (createMediaElementSource is permanent), so default playback stays
 * a plain <audio> element.
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
  let compressor: DynamicsCompressorNode
  let output: GainNode

  /** Must run from a user gesture the first time (browsers only start audio from one). */
  function connect() {
    if (ctx) return
    const audio = usePlayer().audio
    // Needed when the server is on another origin (the Android app); same-origin is unaffected.
    audio.crossOrigin = 'anonymous'
    ctx = new AudioContext()
    const source = ctx.createMediaElementSource(audio)
    preamp = ctx.createGain()
    filters = BANDS.map((freq, i) => {
      const f = ctx!.createBiquadFilter()
      f.type = i === 0 ? 'lowshelf' : i === BANDS.length - 1 ? 'highshelf' : 'peaking'
      f.frequency.value = freq
      f.Q.value = 1.1
      return f
    })
    // Fixing the channel count to 1 makes Web Audio downmix to mono.
    mixer = ctx.createGain()
    panner = ctx.createStereoPanner()
    compressor = ctx.createDynamicsCompressor()
    output = ctx.createGain()

    source.connect(preamp)
    let node: AudioNode = preamp
    for (const f of filters) {
      node.connect(f)
      node = f
    }
    node.connect(mixer).connect(panner).connect(output).connect(ctx.destination)
    apply()

    // Playback may start before the context does (and it suspends with the page); keep it running.
    audio.addEventListener('play', () => ctx?.state !== 'running' && ctx?.resume())
  }

  function apply() {
    if (!ctx) return
    const s = state.value
    const now = ctx.currentTime
    const on = s.enabled
    preamp.gain.setTargetAtTime(on ? 10 ** (s.preamp / 20) : 1, now, 0.02)
    filters.forEach((f, i) => f.gain.setTargetAtTime(on ? s.gains[i] ?? 0 : 0, now, 0.02))
    panner.pan.setTargetAtTime(on ? s.balance : 0, now, 0.02)

    mixer.channelCount = on && s.mono ? 1 : 2
    mixer.channelCountMode = on && s.mono ? 'explicit' : 'max'

    // The leveler evens out loud and quiet songs; route through the compressor only while it is on.
    panner.disconnect()
    if (on && s.leveler) {
      compressor.threshold.value = -24
      compressor.knee.value = 24
      compressor.ratio.value = 4
      compressor.attack.value = 0.01
      compressor.release.value = 0.25
      panner.connect(compressor).connect(output)
      output.gain.setTargetAtTime(1.4, now, 0.02) // make-up gain for the compression
    } else {
      panner.connect(output)
      output.gain.setTargetAtTime(1, now, 0.02)
    }
  }

  watch(state, (s) => {
    localStorage.setItem(KEY, JSON.stringify(s))
    apply()
  }, { deep: true })

  /** Reconnect a saved, enabled equalizer on the first tap anywhere after launch. */
  function resumeOnGesture() {
    if (!state.value.enabled) return
    const start = () => {
      connect()
      removeEventListener('pointerdown', start, true)
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
    gains[i] = db
    state.value.gains = gains
    state.value.preset = Object.entries(PRESETS).find(([, g]) => g.every((v, j) => v === gains[j]))?.[0] ?? 'custom'
  }

  function reset() {
    state.value = { ...state.value, preset: 'flat', gains: [...PRESETS.flat], preamp: 0, balance: 0, mono: false, leveler: false }
  }

  return { state, setEnabled, setPreset, setGain, reset, resumeOnGesture }
})
