<script setup lang="ts">
import { Loader2, MicVocal } from 'lucide-vue-next'
import { computed, nextTick, ref, watch } from 'vue'
import { api } from '@/lib/api'
import { kv } from '@/lib/db'
import { t } from '@/lib/i18n'
import { activeLine, type LyricLine, parseLrc } from '@/lib/lrc'
import type { Lyrics, Track } from '@/lib/types'
import { trackKey, usePlayer } from '@/stores/player'

const props = withDefaults(defineProps<{ big?: boolean }>(), { big: false })
const player = usePlayer()

const lyrics = ref<Lyrics | null>(null)
const loading = ref(false)
const container = ref<HTMLElement>()
const userScrolled = ref(false)
let scrollTimer: ReturnType<typeof setTimeout> | undefined

const lines = computed<LyricLine[]>(() => (lyrics.value?.synced ? parseLrc(lyrics.value.synced) : []))
const active = computed(() => activeLine(lines.value, player.currentTime))

async function fetchLyrics(track: Track): Promise<Lyrics> {
  const key = 'lyrics:' + trackKey(track)
  const cached = await kv.get<Lyrics>(key)
  if (cached) return cached
  const res = track.kind === 'remote'
    ? await api.get<Lyrics>(`/tracks/${track.id}/lyrics`)
    : await api.get<Lyrics>('/lyrics/lookup', { title: track.title, artist: track.artist, album: track.album, duration: track.duration })
  // Only cache hits; the server retries misses later.
  if (res.synced || res.plain || res.instrumental) await kv.set(key, res)
  return res
}

watch(
  () => player.track,
  async (track) => {
    lyrics.value = null
    if (!track) return
    loading.value = true
    try {
      const res = await fetchLyrics(track)
      if (player.track === track) lyrics.value = res
    } catch {
      lyrics.value = null
    } finally {
      loading.value = false
    }
  },
  { immediate: true },
)

watch(active, async (i) => {
  if (userScrolled.value || i < 0) return
  await nextTick()
  const el = container.value?.querySelector<HTMLElement>(`[data-line="${i}"]`)
  el?.scrollIntoView({ behavior: 'smooth', block: 'center' })
})

function onUserScroll() {
  userScrolled.value = true
  clearTimeout(scrollTimer)
  scrollTimer = setTimeout(() => (userScrolled.value = false), 3500)
}

// Lyrics are usually in the song's own language, so let the browser pick the direction.
const textDir = 'auto'
</script>

<template>
  <div ref="container" class="h-full overflow-y-auto scrollbar-none" @wheel.passive="onUserScroll" @touchmove.passive="onUserScroll">
    <div v-if="loading" class="flex h-full items-center justify-center text-muted">
      <Loader2 class="size-6 animate-spin" />
    </div>

    <div v-else-if="lines.length" class="py-[30vh]" :class="props.big ? 'space-y-5 px-2' : 'space-y-3 px-1'">
      <p
        v-for="(line, i) in lines"
        :key="i"
        :data-line="i"
        :dir="textDir"
        class="cursor-pointer font-bold leading-snug transition-all duration-500"
        :class="[
          props.big ? 'text-2xl sm:text-3xl' : 'text-xl',
          i === active ? 'scale-[1.02] text-fg' : i < active ? 'text-fg/35' : 'text-fg/25 hover:text-fg/60',
        ]"
        @click="player.seek(line.time)"
      >
        {{ line.text || '♪' }}
      </p>
    </div>

    <div v-else-if="lyrics?.plain" :dir="textDir" class="whitespace-pre-line py-6 font-semibold leading-relaxed text-fg/80" :class="props.big ? 'text-xl' : 'text-lg'">
      {{ lyrics.plain }}
    </div>

    <div v-else class="flex h-full flex-col items-center justify-center gap-3 p-6 text-center text-muted">
      <MicVocal class="size-10 opacity-50" />
      <p class="font-semibold">{{ lyrics?.instrumental ? t('lyrics.instrumental') : t('lyrics.none') }}</p>
      <p v-if="!lyrics?.instrumental" class="text-sm text-subtle">{{ t('lyrics.noneHint') }}</p>
    </div>
  </div>
</template>
