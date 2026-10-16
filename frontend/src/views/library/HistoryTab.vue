<script setup lang="ts">
import { History, Trash2 } from 'lucide-vue-next'
import { computed, onMounted, ref } from 'vue'
import EmptyState from '@/components/EmptyState.vue'
import TrackList from '@/components/TrackList.vue'
import { api } from '@/lib/api'
import { cachedGet } from '@/lib/cached'
import { t } from '@/lib/i18n'
import type { ApiTrack, RemoteTrack } from '@/lib/types'
import { useLibrary } from '@/stores/library'

interface Entry { id: number; played_at: string; seconds: number; track: ApiTrack }

const library = useLibrary()
const entries = ref<Entry[]>([])
const loaded = ref(false)

const groups = computed(() => {
  const fmt = new Intl.DateTimeFormat('en', { weekday: 'long', day: 'numeric', month: 'long' })
  const today = new Date().toDateString()
  const yesterday = new Date(Date.now() - 86400000).toDateString()
  const map = new Map<string, RemoteTrack[]>()
  for (const e of entries.value) {
    const d = new Date(e.played_at)
    const key = d.toDateString() === today ? t('history.today') : d.toDateString() === yesterday ? t('history.yesterday') : fmt.format(d)
    map.set(key, [...(map.get(key) ?? []), ...library.remember([e.track])])
  }
  return [...map.entries()]
})

onMounted(async () => {
  try {
    const res = await cachedGet<{ data: Entry[] }>('/history', undefined, (c) => (entries.value = c.data))
    entries.value = res.data
  } finally {
    loaded.value = true
  }
})

async function clear() {
  if (!confirm(t('history.clearConfirm'))) return
  await api.delete('/history')
  entries.value = []
}
</script>

<template>
  <div class="flex items-center px-4 pt-4 sm:px-6">
    <p class="flex-1 text-sm text-muted">{{ t('history.hint') }}</p>
    <button v-if="entries.length" class="btn-ghost h-9 px-3" @click="clear"><Trash2 class="size-4" />{{ t('history.clear') }}</button>
  </div>
  <EmptyState v-if="loaded && !entries.length" :icon="History" :title="t('history.empty')" />
  <section v-for="[day, tracks] in groups" :key="day" class="mt-4">
    <h3 class="px-4 pb-1 text-sm font-bold text-muted sm:px-6">{{ day }}</h3>
    <div class="px-2 sm:px-4">
      <TrackList :tracks="tracks" :context="{ type: 'history', name: t('library.history') }" />
    </div>
  </section>
</template>
