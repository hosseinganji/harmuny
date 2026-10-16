<script setup lang="ts">
import { ListMusic } from 'lucide-vue-next'
import { computed } from 'vue'
import { coverUrl, withMediaKey } from '@/lib/api'

const props = defineProps<{ coverUrl?: string | null; trackIds?: number[]; rounded?: string }>()

const tiles = computed(() => (props.trackIds ?? []).slice(0, 4).map((id) => coverUrl(id)))
</script>

<template>
  <div class="relative aspect-square w-full overflow-hidden bg-gradient-to-br from-accent/60 to-surface-2" :class="rounded ?? 'rounded-xl'">
    <img v-if="props.coverUrl" :src="withMediaKey(props.coverUrl)!" class="size-full object-cover" alt="" loading="lazy" />
    <div v-else-if="tiles.length >= 4" class="grid size-full grid-cols-2">
      <img v-for="src in tiles" :key="src" :src="src" class="size-full object-cover" alt="" loading="lazy" />
    </div>
    <img v-else-if="tiles.length" :src="tiles[0]" class="size-full object-cover" alt="" loading="lazy" />
    <div v-else class="flex size-full items-center justify-center text-white/80">
      <ListMusic class="size-1/3" />
    </div>
  </div>
</template>
