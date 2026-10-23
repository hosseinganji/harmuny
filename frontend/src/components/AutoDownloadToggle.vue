<script setup lang="ts">
import { ArrowDownToLine } from 'lucide-vue-next'
import { t } from '@/lib/i18n'

/** A compact pill switch for "keep this collection downloaded", sitting next to the sort control. */
defineProps<{ modelValue: boolean; hint?: string }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>()
</script>

<template>
  <button
    role="switch"
    :aria-checked="modelValue"
    :title="hint"
    class="flex h-10 shrink-0 items-center gap-2 rounded-full border ps-3 pe-1.5 text-sm font-semibold transition active:scale-95"
    :class="modelValue ? 'border-accent/40 bg-accent/10 text-accent' : 'border-line text-muted hover:bg-fg/5'"
    @click="emit('update:modelValue', !modelValue)"
  >
    <ArrowDownToLine class="size-4" />
    {{ t('playlist.autoDownload') }}
    <span class="relative h-6 w-10 rounded-full transition-colors duration-300" :class="modelValue ? 'bg-accent' : 'bg-fg/15'">
      <span
        class="absolute top-0.5 size-5 rounded-full bg-white shadow transition-[inset-inline-start] duration-300 ease-[var(--ease-sheet)]"
        :style="{ insetInlineStart: modelValue ? '1.125rem' : '0.125rem' }"
      />
    </span>
  </button>
</template>
