<script setup lang="ts">
import { computed } from 'vue'
import { withMediaKey } from '@/lib/api'

const props = withDefaults(defineProps<{ name?: string | null; src?: string | null; size?: string }>(), { size: 'size-10' })
const initials = computed(() =>
  (props.name ?? '?').trim().split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase(),
)
</script>

<template>
  <div class="flex shrink-0 items-center justify-center overflow-hidden rounded-full bg-gradient-to-br from-accent to-pink-500 font-bold text-white" :class="size">
    <img v-if="src" :src="withMediaKey(src)!" class="size-full object-cover" alt="" />
    <span v-else>{{ initials }}</span>
  </div>
</template>
