<script setup lang="ts">
import type { Component } from 'vue'

/** A setting that is on or off: icon, title and hint, with the switch at the end. */
defineProps<{ modelValue: boolean; icon: Component; title: string; hint?: string; disabled?: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>()
</script>

<template>
  <div class="flex items-center gap-4">
    <span class="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-accent/15 text-accent"><component :is="icon" class="size-5" /></span>
    <div class="min-w-0 flex-1">
      <div class="font-semibold">{{ title }}</div>
      <div v-if="hint" class="text-sm text-muted">{{ hint }}</div>
    </div>
    <button
      role="switch"
      :aria-checked="modelValue"
      :aria-label="title"
      class="relative h-8 w-14 shrink-0 rounded-full transition-colors duration-300 disabled:opacity-50"
      :class="modelValue ? 'bg-accent' : 'bg-fg/15'"
      :disabled="disabled"
      @click="emit('update:modelValue', !modelValue)"
    >
      <span class="absolute top-1 size-6 rounded-full bg-white shadow transition-[inset-inline-start] duration-300 ease-[var(--ease-sheet)]" :style="{ insetInlineStart: modelValue ? '1.75rem' : '0.25rem' }" />
    </button>
  </div>
</template>
