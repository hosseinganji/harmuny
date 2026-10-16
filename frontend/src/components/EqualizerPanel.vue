<script setup lang="ts">
import { RotateCcw, SlidersHorizontal } from 'lucide-vue-next'
import { computed } from 'vue'
import { t } from '@/lib/i18n'
import { BANDS, GAIN_LIMIT, PRESETS, useEqualizer } from '@/stores/equalizer'

const eq = useEqualizer()
const s = computed(() => eq.state)
const presets = Object.keys(PRESETS)
const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent)

const freqLabel = (hz: number) => (hz >= 1000 ? `${hz / 1000}k` : String(hz))
const dbLabel = (db: number) => `${db > 0 ? '+' : ''}${db} dB`
const balanceLabel = computed(() => {
  const b = Math.round(s.value.balance * 100)
  return b === 0 ? t('eq.center') : b < 0 ? `${t('eq.left')} ${-b}%` : `${t('eq.right')} ${b}%`
})
const value = (e: Event) => Number((e.target as HTMLInputElement).value)
</script>

<template>
  <section class="card space-y-5 p-5">
    <div class="flex items-center gap-3">
      <SlidersHorizontal class="size-5 text-accent" />
      <h2 class="flex-1 text-lg font-bold">{{ t('eq.title') }}</h2>
      <input type="checkbox" class="size-5 accent-[var(--accent)]" :checked="s.enabled" :aria-label="t('eq.enable')" @change="eq.setEnabled(($event.target as HTMLInputElement).checked)" />
    </div>
    <p v-if="isIOS" class="-mt-3 text-xs text-subtle">{{ t('eq.iosNote') }}</p>

    <div :class="{ 'pointer-events-none opacity-40': !s.enabled }" class="space-y-5 transition">
      <!-- Presets -->
      <div class="scrollbar-none -mx-5 flex gap-2 overflow-x-auto px-5">
        <button
          v-for="p in presets"
          :key="p"
          class="chip h-8 shrink-0 px-3 text-xs"
          :class="{ 'chip-active': s.preset === p }"
          @click="eq.setPreset(p)"
        >{{ t(`eq.preset.${p}`) }}</button>
        <span v-if="s.preset === 'custom'" class="chip chip-active h-8 shrink-0 px-3 text-xs">{{ t('eq.preset.custom') }}</span>
      </div>

      <!-- Bands -->
      <div class="space-y-2.5">
        <div v-for="(hz, i) in BANDS" :key="hz" class="flex items-center gap-3">
          <span class="w-10 shrink-0 text-xs font-semibold text-muted tabular" dir="ltr">{{ freqLabel(hz) }}</span>
          <input
            type="range"
            class="min-w-0 flex-1 accent-[var(--accent)]"
            :min="-GAIN_LIMIT"
            :max="GAIN_LIMIT"
            step="1"
            :value="s.gains[i]"
            dir="ltr"
            :aria-label="`${freqLabel(hz)} Hz`"
            @input="eq.setGain(i, value($event))"
          />
          <span class="w-14 shrink-0 text-end text-xs text-subtle tabular" dir="ltr">{{ dbLabel(s.gains[i] ?? 0) }}</span>
        </div>
      </div>

      <!-- Preamp -->
      <div>
        <div class="mb-1 flex justify-between text-sm"><span class="font-semibold">{{ t('eq.preamp') }}</span><span class="text-subtle tabular" dir="ltr">{{ dbLabel(s.preamp) }}</span></div>
        <input type="range" class="w-full accent-[var(--accent)]" :min="-GAIN_LIMIT" :max="GAIN_LIMIT" step="1" :value="s.preamp" dir="ltr" @input="s.preamp = value($event)" />
      </div>

      <!-- Balance -->
      <div>
        <div class="mb-1 flex justify-between text-sm"><span class="font-semibold">{{ t('eq.balance') }}</span><span class="text-subtle">{{ balanceLabel }}</span></div>
        <input type="range" class="w-full accent-[var(--accent)]" min="-1" max="1" step="0.05" :value="s.balance" dir="ltr" @input="s.balance = value($event)" @dblclick="s.balance = 0" />
      </div>

      <label class="flex cursor-pointer items-center justify-between gap-4">
        <div><div class="font-semibold">{{ t('eq.leveler') }}</div><div class="text-sm text-muted">{{ t('eq.levelerHint') }}</div></div>
        <input v-model="s.leveler" type="checkbox" class="size-5 accent-[var(--accent)]" />
      </label>
      <label class="flex cursor-pointer items-center justify-between gap-4">
        <div><div class="font-semibold">{{ t('eq.mono') }}</div><div class="text-sm text-muted">{{ t('eq.monoHint') }}</div></div>
        <input v-model="s.mono" type="checkbox" class="size-5 accent-[var(--accent)]" />
      </label>

      <button class="btn-ghost w-full" @click="eq.reset()"><RotateCcw class="size-4" />{{ t('eq.reset') }}</button>
    </div>
  </section>
</template>
