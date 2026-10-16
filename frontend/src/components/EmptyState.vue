<script setup lang="ts">
import type { Component } from 'vue'

defineProps<{ icon: Component; title: string; text?: string }>()
</script>

<template>
  <div class="flex flex-col items-center justify-center px-6 py-16 text-center">
    <div class="mb-4 flex size-20 items-center justify-center rounded-full bg-fg/5">
      <component :is="icon" class="size-9 text-muted" />
    </div>
    <h3 class="text-lg font-bold">{{ title }}</h3>
    <p v-if="text" class="mt-1 max-w-sm text-sm text-muted">{{ text }}</p>
    <div class="mt-5"><slot /></div>
  </div>
</template>
