<script setup lang="ts">
defineProps<{ paused?: boolean }>()
</script>

<template>
  <span class="inline-flex h-3.5 items-end gap-[2px]" aria-hidden="true">
    <span
      v-for="i in 3"
      :key="i"
      class="eq-bar w-[3px] rounded-full bg-accent"
      :style="{ height: '100%', animationDelay: `${i * 0.18}s`, animationPlayState: paused ? 'paused' : 'running' }"
    />
  </span>
</template>
