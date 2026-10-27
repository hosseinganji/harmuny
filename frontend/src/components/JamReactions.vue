<script setup lang="ts">
import { useJam } from '@/stores/jam'

/** Emoji the members of the Jam send, floating up over whatever screen is open. */
const jam = useJam()
</script>

<template>
  <div v-if="jam.active && jam.reactions.length" class="pointer-events-none fixed inset-0 z-[75] overflow-hidden" aria-hidden="true">
    <div
      v-for="r in jam.reactions"
      :key="r.id"
      class="jam-float absolute bottom-28 flex flex-col items-center"
      :style="{ left: `${r.x}%`, '--sway': `${(r.id % 2 ? 1 : -1) * (12 + (r.id * 7) % 18)}px` }"
    >
      <span class="text-[2.6rem] leading-none drop-shadow-[0_6px_14px_rgba(0,0,0,0.45)]">{{ r.emoji }}</span>
      <span v-if="r.name" class="mt-1 rounded-full bg-black/55 px-2 py-0.5 text-[10px] font-bold text-white">{{ r.name }}</span>
    </div>
  </div>
</template>
