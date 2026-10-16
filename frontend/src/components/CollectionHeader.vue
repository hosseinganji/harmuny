<script setup lang="ts">
defineProps<{ kicker?: string; title: string; meta?: string; description?: string | null; round?: boolean }>()
</script>

<template>
  <header class="dyn-gradient relative px-4 pb-6 pt-8 sm:px-6 sm:pt-14">
    <div class="flex flex-col items-center gap-5 sm:flex-row sm:items-end sm:gap-7">
      <div class="w-48 shrink-0 shadow-[0_20px_60px_-15px_rgba(0,0,0,0.6)] sm:w-56" :class="round ? 'rounded-full' : 'rounded-2xl'">
        <slot name="cover" />
      </div>
      <div class="min-w-0 text-center sm:text-start">
        <div v-if="kicker" class="text-xs font-bold uppercase tracking-widest text-fg/70">{{ kicker }}</div>
        <h1 class="mt-1 break-words text-3xl font-black tracking-tight sm:text-5xl lg:text-6xl">{{ title }}</h1>
        <p v-if="description" class="mt-2 line-clamp-2 text-sm text-muted">{{ description }}</p>
        <div v-if="meta" class="mt-2 text-sm font-medium text-fg/80">{{ meta }}</div>
        <slot name="meta" />
      </div>
    </div>
  </header>
</template>
