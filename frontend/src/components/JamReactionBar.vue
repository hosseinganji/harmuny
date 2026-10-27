<script setup lang="ts">
import { t } from '@/lib/i18n'
import { REACTIONS, useJam } from '@/stores/jam'

/** One tap sends an emoji floating up on everyone's screen. */
withDefaults(defineProps<{ compact?: boolean }>(), { compact: false })
const jam = useJam()
</script>

<template>
  <div class="liquid flex items-center justify-between rounded-full px-1.5 py-1" role="group" :aria-label="t('jam.react')">
    <button
      v-for="e in REACTIONS"
      :key="e"
      class="flex items-center justify-center rounded-full leading-none transition hover:bg-fg/10 active:scale-75"
      :class="compact ? 'size-9 text-xl' : 'size-10 text-2xl'"
      @click="jam.react(e)"
    >
      {{ e }}
    </button>
  </div>
</template>
