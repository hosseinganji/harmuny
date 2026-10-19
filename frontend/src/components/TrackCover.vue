<script setup lang="ts">
import { Music2 } from 'lucide-vue-next'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { coverUrl } from '@/lib/api'
import type { Track } from '@/lib/types'
import { useLocal } from '@/stores/local'

const props = withDefaults(defineProps<{ track?: Track | null; trackId?: number | null; size?: 'sm' | 'md' | 'lg' | 'xl' | 'fill'; rounded?: string }>(), {
  size: 'md',
  rounded: 'rounded-lg',
})

const failed = ref(false)
const loaded = ref(false)
// The server fetches missing artwork in the background, so retry a couple of times.
const attempt = ref(0)
let retryTimer: ReturnType<typeof setTimeout> | undefined
const localSrc = ref<string | null>(null)

const src = computed(() => {
  if (failed.value) return null
  const retry = (url: string) => (attempt.value ? `${url}&r=${attempt.value}` : url)
  if (props.trackId) return retry(coverUrl(props.trackId))
  const t = props.track
  if (!t?.has_cover) return null
  return t.kind === 'remote' ? retry(coverUrl(t.id, t.updated_at)) : localSrc.value
})

watch(
  () => props.track,
  async (t) => {
    failed.value = false
    loaded.value = false
    attempt.value = 0
    clearTimeout(retryTimer)
    localSrc.value = t?.kind === 'local' && t.has_cover ? await useLocal().cover(t) : null
  },
  { immediate: true },
)

// A stable gradient per track so missing artwork still looks intentional.
const gradient = computed(() => {
  const seed = String(props.track?.id ?? props.trackId ?? '0')
  let h = 0
  for (const c of seed) h = (h * 31 + c.charCodeAt(0)) % 360
  return `linear-gradient(135deg, hsl(${h} 70% 45%), hsl(${(h + 50) % 360} 75% 30%))`
})

function onError() {
  failed.value = true
  if (attempt.value < 2) {
    retryTimer = setTimeout(() => {
      attempt.value++
      failed.value = false
    }, 6000 * (attempt.value + 1))
  }
}
onBeforeUnmount(() => clearTimeout(retryTimer))

const sizeClass = computed(() => ({ sm: 'size-10', md: 'size-12', lg: 'size-40', xl: 'size-56', fill: 'w-full aspect-square' })[props.size])
const iconClass = computed(() => ({ sm: 'size-4', md: 'size-5', lg: 'size-12', xl: 'size-16', fill: 'size-1/3' })[props.size])
</script>

<template>
  <div class="relative shrink-0 overflow-hidden bg-surface-2" :class="[sizeClass, rounded]">
    <img
      v-if="src"
      :src="src"
      loading="lazy"
      decoding="async"
      alt=""
      class="img-fade size-full object-cover"
      :class="{ loaded }"
      @load="loaded = true"
      @error="onError"
    />
    <div v-else class="flex size-full items-center justify-center text-white/80" :style="{ background: gradient }">
      <Music2 :class="iconClass" />
    </div>
    <slot />
  </div>
</template>
