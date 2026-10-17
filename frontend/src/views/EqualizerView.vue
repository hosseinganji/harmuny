<script setup lang="ts">
import { ChevronLeft, Headphones, RotateCcw, Volume2 } from 'lucide-vue-next'
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import VerticalSlider from '@/components/VerticalSlider.vue'
import { t } from '@/lib/i18n'
import { BANDS, CURVE_FREQS, GAIN_LIMIT, PRESETS, useEqualizer } from '@/stores/equalizer'

const eq = useEqualizer()
const router = useRouter()
const s = computed(() => eq.state)
const presets = Object.keys(PRESETS)
const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent)

const freqLabel = (hz: number) => (hz >= 1000 ? `${hz / 1000}k` : String(hz))
const dbLabel = (db: number) => (db === 0 ? '0' : `${db > 0 ? '+' : ''}${db}`)
const balanceLabel = computed(() => {
  const b = Math.round(s.value.balance * 100)
  return b === 0 ? t('eq.center') : b < 0 ? `${t('eq.left')} ${-b}%` : `${t('eq.right')} ${b}%`
})
const number = (e: Event) => Number((e.target as HTMLInputElement).value)

// Response curve: x is log-frequency 20 Hz–20 kHz, y spans ±15 dB.
const W = 320
const H = 140
const RANGE = 15
const y = (db: number) => H / 2 - (Math.max(-RANGE, Math.min(RANGE, db)) / RANGE) * (H / 2 - 6)
const x = (hz: number) => (Math.log10(hz / 20) / 3) * W
const curve = computed(() => {
  const db = eq.responseCurve(s.value.gains, s.value.preamp)
  const pts = db.map((d, i) => `${x(CURVE_FREQS[i]).toFixed(1)},${y(d).toFixed(1)}`)
  return { line: `M${pts.join(' L')}`, area: `M0,${H / 2} L${pts.join(' L')} L${W},${H / 2} Z` }
})

function back() {
  if (history.length > 1) router.back()
  else router.push('/settings')
}
</script>

<template>
  <div class="mx-auto max-w-2xl px-4 pb-6 pt-4 sm:px-6 sm:pt-8">
    <header class="mb-5 flex items-center gap-2">
      <button class="icon-btn -ms-2 text-fg" :aria-label="t('common.back')" @click="back"><ChevronLeft class="size-7 rtl:rotate-180" /></button>
      <h1 class="flex-1 text-2xl font-black tracking-tight sm:text-3xl">{{ t('eq.title') }}</h1>
      <button
        role="switch"
        :aria-checked="s.enabled"
        :aria-label="t('eq.enable')"
        class="relative h-8 w-14 rounded-full transition-colors duration-300"
        :class="s.enabled ? 'bg-accent' : 'bg-fg/15'"
        @click="eq.setEnabled(!s.enabled)"
      >
        <span class="absolute top-1 size-6 rounded-full bg-white shadow transition-[inset-inline-start] duration-300 ease-[var(--ease-sheet)]" :style="{ insetInlineStart: s.enabled ? '1.75rem' : '0.25rem' }" />
      </button>
    </header>

    <div class="space-y-4 transition-opacity duration-300" :class="{ 'opacity-50': !s.enabled }">
      <!-- Curve + bands -->
      <section class="card overflow-hidden p-4">
        <div class="mb-2 flex items-center justify-between text-xs font-semibold text-subtle">
          <span>{{ t(`eq.preset.${s.preset}`) }}</span>
          <span dir="ltr">±{{ GAIN_LIMIT }} dB</span>
        </div>
        <svg :viewBox="`0 0 ${W} ${H}`" class="h-32 w-full" preserveAspectRatio="none" dir="ltr" aria-hidden="true">
          <defs>
            <linearGradient id="eq-fill" x1="0" x2="0" y1="0" y2="1">
              <stop offset="0%" stop-color="var(--accent)" stop-opacity="0.55" />
              <stop offset="50%" stop-color="var(--accent)" stop-opacity="0.08" />
              <stop offset="100%" stop-color="var(--accent)" stop-opacity="0.55" />
            </linearGradient>
          </defs>
          <line v-for="d in [-12, -6, 6, 12]" :key="d" x1="0" :x2="W" :y1="y(d)" :y2="y(d)" class="stroke-fg/[0.07]" stroke-dasharray="3 4" />
          <line x1="0" :x2="W" :y1="H / 2" :y2="H / 2" class="stroke-fg/20" />
          <path :d="curve.area" fill="url(#eq-fill)" />
          <path :d="curve.line" fill="none" stroke="color-mix(in oklab, var(--accent) 55%, white)" stroke-width="2.5" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
        </svg>

        <div class="mt-4 grid grid-cols-10 gap-0.5" dir="ltr">
          <div v-for="(hz, i) in BANDS" :key="hz" class="flex flex-col items-center gap-2">
            <span class="text-[10px] font-semibold text-muted tabular">{{ dbLabel(s.gains[i] ?? 0) }}</span>
            <div class="h-44 w-full">
              <VerticalSlider :model-value="s.gains[i] ?? 0" :min="-GAIN_LIMIT" :max="GAIN_LIMIT" :label="`${freqLabel(hz)} Hz`" @update:model-value="eq.setGain(i, $event)" />
            </div>
            <span class="text-[10px] font-semibold text-subtle tabular">{{ freqLabel(hz) }}</span>
          </div>
        </div>
      </section>

      <!-- Presets -->
      <section>
        <h2 class="mb-2 px-1 text-sm font-bold text-muted">{{ t('eq.presets') }}</h2>
        <div class="grid grid-cols-3 gap-2 sm:grid-cols-4">
          <button
            v-for="p in presets"
            :key="p"
            class="h-10 truncate rounded-2xl px-2 text-sm font-semibold transition active:scale-95"
            :class="s.preset === p ? 'bg-accent text-white shadow-lg' : 'bg-fg/[0.06] text-fg hover:bg-fg/10'"
            @click="eq.setPreset(p)"
          >{{ t(`eq.preset.${p}`) }}</button>
        </div>
      </section>

      <!-- Preamp & balance -->
      <section class="card space-y-5 p-5">
        <div>
          <div class="mb-2 flex items-center gap-2 text-sm"><Volume2 class="size-4 text-muted" /><span class="flex-1 font-semibold">{{ t('eq.preamp') }}</span><span class="text-subtle tabular" dir="ltr">{{ dbLabel(s.preamp) }} dB</span></div>
          <input type="range" class="w-full accent-[var(--accent)]" :min="-GAIN_LIMIT" :max="GAIN_LIMIT" step="0.5" :value="s.preamp" dir="ltr" @input="s.preamp = number($event)" />
        </div>
        <div>
          <div class="mb-2 flex items-center gap-2 text-sm"><Headphones class="size-4 text-muted" /><span class="flex-1 font-semibold">{{ t('eq.balance') }}</span><span class="text-subtle">{{ balanceLabel }}</span></div>
          <input type="range" class="w-full accent-[var(--accent)]" min="-1" max="1" step="0.05" :value="s.balance" dir="ltr" @input="s.balance = number($event)" @dblclick="s.balance = 0" />
          <div class="mt-1 flex justify-between text-[11px] text-subtle" dir="ltr"><span>L</span><span>R</span></div>
        </div>
      </section>

      <section class="card divide-y divide-line px-5">
        <label v-for="opt in (['leveler', 'mono'] as const)" :key="opt" class="flex cursor-pointer items-center justify-between gap-4 py-4">
          <div><div class="font-semibold">{{ t(`eq.${opt}`) }}</div><div class="text-sm text-muted">{{ t(`eq.${opt}Hint`) }}</div></div>
          <input v-model="s[opt]" type="checkbox" class="size-5 shrink-0 accent-[var(--accent)]" />
        </label>
      </section>

      <button class="btn-ghost w-full" @click="eq.reset()"><RotateCcw class="size-4" />{{ t('eq.reset') }}</button>
      <p class="px-1 text-center text-xs text-subtle">{{ t('eq.headroomNote') }}<template v-if="isIOS"> {{ t('eq.iosNote') }}</template></p>
    </div>
  </div>
</template>
