<script setup lang="ts">
import { onKeyStroke } from '@vueuse/core'
import { X } from 'lucide-vue-next'
import { t } from '@/lib/i18n'

const props = withDefaults(defineProps<{ open: boolean; title?: string; wide?: boolean }>(), { wide: false })
const emit = defineEmits<{ close: [] }>()

onKeyStroke('Escape', () => props.open && emit('close'))
</script>

<template>
  <Teleport to="body">
    <Transition name="fade">
      <div v-if="open" class="fixed inset-0 z-[60] bg-black/50 backdrop-blur-sm" @click="emit('close')" />
    </Transition>
    <Transition name="sheet">
      <div
        v-if="open"
        class="liquid liquid-strong fixed inset-x-0 bottom-0 z-[61] flex max-h-[90dvh] flex-col rounded-t-[2rem] sm:inset-auto sm:start-1/2 sm:top-1/2 sm:max-h-[85vh] sm:w-full sm:-translate-y-1/2 sm:rounded-3xl sm:-translate-x-1/2"
        :class="wide ? 'sm:max-w-2xl' : 'sm:max-w-md'"
        role="dialog"
        aria-modal="true"
      >
        <div class="mx-auto mt-2 h-1 w-10 rounded-full bg-fg/20 sm:hidden" />
        <header v-if="title" class="flex items-center gap-2 px-5 pb-2 pt-4">
          <h2 class="flex-1 text-lg font-bold">{{ title }}</h2>
          <button class="icon-btn size-9" :aria-label="t('common.close')" @click="emit('close')"><X class="size-5" /></button>
        </header>
        <div class="overflow-y-auto px-5 pb-6 pb-safe">
          <slot />
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
