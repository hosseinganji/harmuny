<script setup lang="ts">
import { onClickOutside, useIntervalFn } from '@vueuse/core'
import { Check, Gauge, Moon } from 'lucide-vue-next'
import { computed, ref } from 'vue'
import { duration, num } from '@/lib/format'
import { t } from '@/lib/i18n'
import { usePlayer } from '@/stores/player'

withDefaults(defineProps<{ up?: boolean; large?: boolean }>(), { up: true })

const player = usePlayer()
const open = ref(false)
const root = ref<HTMLElement>()
onClickOutside(root, () => (open.value = false))
const now = ref(Date.now())
useIntervalFn(() => (now.value = Date.now()), 1000)

const remaining = computed(() => (player.sleep?.mode === 'time' ? Math.max(0, (player.sleep.endsAt - now.value) / 1000) : 0))
const rates = [0.75, 1, 1.25, 1.5, 2]
const timers = [5, 15, 30, 45, 60, 90]

function setSleep(v: number | 'track' | null) {
  player.setSleep(v)
  open.value = false
}
</script>

<template>
  <div ref="root" class="relative">
    <button
      class="icon-btn relative"
      :class="[large ? 'size-11' : 'size-9', player.sleep || player.rate !== 1 ? '!text-accent' : '']"
      :aria-label="t('player.options')"
      @click="open = !open"
    >
      <Moon v-if="player.sleep" :class="large ? 'size-5.5' : 'size-4.5'" />
      <Gauge v-else :class="large ? 'size-5.5' : 'size-4.5'" />
    </button>
    <Transition name="pop">
      <div
        v-if="open"
        class="absolute end-0 z-50 w-64 rounded-2xl border border-line bg-elevated p-3 shadow-2xl"
        :class="up ? 'bottom-full mb-2' : 'top-full mt-2'"
      >
        <div class="mb-2 flex items-center gap-2 px-1 text-xs font-semibold uppercase tracking-wide text-subtle">
          <Moon class="size-3.5" />{{ t('player.sleepTimer') }}
          <span v-if="player.sleep?.mode === 'time'" class="ms-auto text-accent tabular" dir="ltr">{{ duration(remaining) }}</span>
          <span v-else-if="player.sleep?.mode === 'track'" class="ms-auto text-accent normal-case">{{ t('player.endOfTrack') }}</span>
        </div>
        <div class="grid grid-cols-3 gap-1.5">
          <button v-for="m in timers" :key="m" class="chip h-8 justify-center px-2 text-xs" @click="setSleep(m)">{{ t('player.minutes', { n: m }) }}</button>
          <button class="chip col-span-2 h-8 justify-center px-2 text-xs" :class="{ 'chip-active': player.sleep?.mode === 'track' }" @click="setSleep('track')">
            {{ t('player.endOfTrack') }}
          </button>
          <button class="chip h-8 justify-center px-2 text-xs" :disabled="!player.sleep" @click="setSleep(null)">{{ t('common.off') }}</button>
        </div>
        <div class="mb-2 mt-4 flex items-center gap-2 px-1 text-xs font-semibold uppercase tracking-wide text-subtle">
          <Gauge class="size-3.5" />{{ t('player.speed') }}
        </div>
        <div class="flex gap-1.5">
          <button
            v-for="r in rates"
            :key="r"
            class="chip h-8 flex-1 justify-center px-0 text-xs"
            :class="{ 'chip-active': player.rate === r }"
            @click="player.setRate(r)"
          >
            <Check v-if="player.rate === r" class="size-3" />{{ num(r) }}×
          </button>
        </div>
      </div>
    </Transition>
  </div>
</template>
