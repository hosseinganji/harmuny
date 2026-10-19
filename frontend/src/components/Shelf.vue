<script setup lang="ts">
import { ChevronLeft, ChevronRight } from 'lucide-vue-next'
import { ref } from 'vue'

defineProps<{ title: string; subtitle?: string; to?: string }>()
const row = ref<HTMLElement>()

function scroll(dir: 1 | -1) {
  const el = row.value
  if (!el) return
  el.scrollBy({ left: dir * el.clientWidth * 0.8, behavior: 'smooth' })
}
</script>

<template>
  <section class="group/shelf">
    <div class="mb-3 flex items-end gap-2 px-4 sm:px-6">
      <div class="min-w-0 flex-1">
        <h2 class="truncate text-xl font-extrabold tracking-tight sm:text-2xl">
          <RouterLink v-if="to" :to="to" class="hover:underline">{{ title }}</RouterLink>
          <template v-else>{{ title }}</template>
        </h2>
        <p v-if="subtitle" class="text-sm text-muted">{{ subtitle }}</p>
      </div>
      <div class="hidden gap-1 opacity-0 transition group-hover/shelf:opacity-100 sm:flex">
        <button class="icon-btn size-8 bg-fg/5" @click="scroll(-1)"><ChevronLeft class="size-4" /></button>
        <button class="icon-btn size-8 bg-fg/5" @click="scroll(1)"><ChevronRight class="size-4" /></button>
      </div>
    </div>
    <div ref="row" class="stagger flex snap-x gap-3 overflow-x-auto px-4 pb-2 scrollbar-none sm:gap-4 sm:px-6">
      <slot />
    </div>
  </section>
</template>
