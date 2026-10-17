<script setup lang="ts">
import { computed, ref } from 'vue'
import { count } from '@/lib/format'
import type { Bar } from '@/lib/chart'
import { t } from '@/lib/i18n'

/**
 * A single-series column chart (so no legend: the card title names it). Columns are capped at 24px with a
 * 4px rounded top, hairline gridlines at clean values, sparse axis labels, a tooltip on hover/tap with the
 * whole column as the hit target, and the same numbers as a table for screen readers or on request.
 */
const props = withDefaults(defineProps<{ bars: Bar[]; unit?: string; height?: string }>(), { unit: '', height: 'h-40' })

const active = ref<number | null>(null)

/** Round the axis up to 1, 2 or 5 × 10ⁿ so gridlines land on clean numbers. */
const max = computed(() => {
  const top = Math.max(0, ...props.bars.map((b) => b.value))
  if (top <= 0) return 1
  const p = 10 ** Math.floor(Math.log10(top))
  return ([1, 2, 5, 10].find((m) => m * p >= top) ?? 10) * p
})
const ticks = computed(() => [max.value, max.value / 2, 0])
const compact = (n: number) => (n >= 1000 ? new Intl.NumberFormat(undefined, { notation: 'compact' }).format(n) : count(n))

// Label roughly every seventh column so labels never collide.
const every = computed(() => Math.max(1, Math.ceil(props.bars.length / 7)))
const total = computed(() => props.bars.reduce((s, b) => s + b.value, 0))
</script>

<template>
  <div>
    <div class="relative flex gap-2" dir="ltr" @mouseleave="active = null">
      <!-- y-axis: same height as the plot, each label centred on its gridline -->
      <div class="relative w-8 shrink-0 self-start text-end text-[10px] leading-none text-subtle tabular" :class="height">
        <span v-for="(tick, i) in ticks" :key="tick" class="absolute end-0 -translate-y-1/2" :style="{ top: `${(i / (ticks.length - 1)) * 100}%` }">{{ compact(tick) }}</span>
      </div>

      <div class="relative min-w-0 flex-1">
        <!-- gridlines -->
        <div class="pointer-events-none absolute inset-x-0 top-0 flex flex-col justify-between" :class="height">
          <div v-for="tick in ticks" :key="tick" class="h-px bg-fg/[0.08]" />
        </div>

        <div class="relative flex items-end justify-between gap-[2px]" :class="height">
          <button
            v-for="(b, i) in bars"
            :key="i"
            class="group relative flex h-full flex-1 items-end justify-center outline-none"
            :aria-label="`${b.title}: ${count(b.value)} ${unit}`"
            @mouseenter="active = i"
            @focus="active = i"
            @blur="active = null"
            @click="active = active === i ? null : i"
          >
            <span
              class="w-full max-w-6 rounded-t-[4px] bg-accent transition-[height,opacity] duration-500 ease-[var(--ease-sheet)]"
              :class="active !== null && active !== i ? 'opacity-45' : ''"
              :style="{ height: b.value ? `max(2px, ${(b.value / max) * 100}%)` : '0' }"
            />
          </button>

          <!-- tooltip -->
          <div
            v-if="active !== null && bars[active]"
            class="liquid liquid-strong pointer-events-none absolute bottom-full z-10 mb-2 min-w-28 -translate-x-1/2 rounded-xl px-3 py-2 text-xs shadow-xl"
            :style="{ left: `clamp(3.5rem, ${((active + 0.5) / bars.length) * 100}%, calc(100% - 3.5rem))` }"
          >
            <div class="font-semibold text-fg">{{ bars[active].title }}</div>
            <div class="text-muted"><span class="font-bold text-fg tabular">{{ count(bars[active].value) }}</span> {{ unit }}</div>
            <div v-if="bars[active].detail" class="text-subtle">{{ bars[active].detail }}</div>
          </div>
        </div>

        <!-- x-axis labels (sparse) -->
        <div class="flex justify-between gap-[2px] pt-1.5 text-[10px] leading-none text-subtle">
          <span v-for="(b, i) in bars" :key="i" class="flex-1 text-center tabular" :class="{ invisible: i % every !== 0 }">{{ b.label }}</span>
        </div>
      </div>
    </div>

    <details class="mt-3 text-xs text-muted">
      <summary class="cursor-pointer select-none font-semibold">{{ t('reports.showTable') }}</summary>
      <table class="mt-2 w-full">
        <tbody>
          <tr v-for="(b, i) in bars" :key="i" class="border-t border-line">
            <td class="py-1">{{ b.title }}</td>
            <td class="py-1 text-end font-semibold text-fg tabular">{{ count(b.value) }}</td>
          </tr>
          <tr class="border-t border-line"><td class="py-1 font-semibold">{{ t('reports.total') }}</td><td class="py-1 text-end font-bold text-fg tabular">{{ count(total) }}</td></tr>
        </tbody>
      </table>
    </details>
  </div>
</template>
