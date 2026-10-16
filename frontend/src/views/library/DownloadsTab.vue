<script setup lang="ts">
import { Download, HardDriveDownload, Trash2 } from 'lucide-vue-next'
import { computed, onMounted, ref } from 'vue'
import CollectionActions from '@/components/CollectionActions.vue'
import DeviceSyncPanel from '@/components/DeviceSyncPanel.vue'
import EmptyState from '@/components/EmptyState.vue'
import TrackList from '@/components/TrackList.vue'
import { db } from '@/lib/db'
import { bytes } from '@/lib/format'
import { t } from '@/lib/i18n'
import type { RemoteTrack } from '@/lib/types'
import { useDownloads } from '@/stores/downloads'
import { useLibrary } from '@/stores/library'

const downloads = useDownloads()
const library = useLibrary()
const quota = ref<{ usage: number; quota: number } | null>(null)
const order = ref<number[]>([])

// Newest download first; songs downloaded while this page is open go to the top.
const tracks = computed(() => {
  const rank = new Map(order.value.map((id, i) => [id, i]))
  return [...downloads.sizes.keys()]
    .sort((a, b) => (rank.get(a) ?? -1) - (rank.get(b) ?? -1))
    .map((id) => library.get(id))
    .filter(Boolean) as RemoteTrack[]
})

onMounted(async () => {
  order.value = (await db.downloads.orderBy('downloadedAt').reverse().primaryKeys()) as number[]
  const est = await navigator.storage?.estimate?.()
  if (est) quota.value = { usage: est.usage ?? 0, quota: est.quota ?? 0 }
})

async function clearAll() {
  if (confirm(t('downloads.clearConfirm'))) await downloads.clear()
}
</script>

<template>
  <div class="mx-4 mt-4 rounded-2xl bg-fg/5 p-4 sm:mx-6">
    <div class="flex items-center gap-3">
      <HardDriveDownload class="size-8 text-accent" />
      <div class="flex-1">
        <div class="font-bold">{{ t('downloads.storage', { size: bytes(downloads.totalBytes) }) }}</div>
        <div class="text-sm text-muted">{{ t('downloads.offlineHint') }}</div>
      </div>
      <button v-if="downloads.count" class="btn-danger h-9 px-3" @click="clearAll"><Trash2 class="size-4" /><span class="hidden sm:inline">{{ t('downloads.clear') }}</span></button>
    </div>
    <div v-if="quota?.quota" class="mt-3">
      <div class="h-1.5 overflow-hidden rounded-full bg-fg/10" dir="ltr">
        <div class="h-full rounded-full bg-accent" :style="{ width: `${Math.min(100, (quota.usage / quota.quota) * 100)}%` }" />
      </div>
      <div class="mt-1 text-xs text-subtle">{{ t('downloads.quota', { used: bytes(quota.usage), total: bytes(quota.quota) }) }}</div>
    </div>
  </div>
  <DeviceSyncPanel class="mx-4 mt-4 sm:mx-6" />
  <CollectionActions :tracks="tracks" :context="{ type: 'downloads', name: t('library.downloads') }" :downloadable="false" />
  <EmptyState v-if="!tracks.length" :icon="Download" :title="t('downloads.empty')" :text="t('downloads.emptyHint')" />
  <div class="px-2 sm:px-4">
    <TrackList :tracks="tracks" :context="{ type: 'downloads', name: t('library.downloads') }" />
  </div>
</template>
