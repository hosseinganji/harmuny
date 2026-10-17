<script setup lang="ts">
import { ArrowDownUp } from 'lucide-vue-next'
import { computed } from 'vue'
import { t } from '@/lib/i18n'
import type { TrackSort } from '@/lib/sortTracks'

/** The sort picker every song list shares. `natural` is the list's own order (playlist order, date liked…). */
const props = defineProps<{ modelValue: TrackSort; naturalLabel?: string }>()
const emit = defineEmits<{ 'update:modelValue': [value: TrackSort] }>()

const options = computed<{ value: TrackSort; label: string }[]>(() => [
  ...(props.naturalLabel ? [{ value: 'natural' as const, label: props.naturalLabel }] : []),
  { value: 'popular', label: t('sort.popular') },
  { value: 'recent', label: t('sort.recent') },
  { value: 'title', label: t('sort.title') },
  { value: 'artist', label: t('sort.artist') },
  { value: 'duration', label: t('sort.duration') },
])
</script>

<template>
  <label class="relative inline-flex items-center">
    <ArrowDownUp class="pointer-events-none absolute start-3 size-4 text-muted" />
    <select
      :value="modelValue"
      class="input h-10 w-auto rounded-full pe-8 ps-9 text-sm"
      :aria-label="t('sort.label')"
      @change="emit('update:modelValue', ($event.target as HTMLSelectElement).value as TrackSort)"
    >
      <option v-for="o in options" :key="o.value" :value="o.value">{{ o.label }}</option>
    </select>
  </label>
</template>
