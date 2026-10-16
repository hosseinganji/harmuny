<script setup lang="ts">
import { FileAudio, FolderOpen, FolderPlus, HardDrive, Loader2, RefreshCw, ScanSearch, Trash2 } from 'lucide-vue-next'
import { computed, onMounted, ref } from 'vue'
import CollectionActions from '@/components/CollectionActions.vue'
import EmptyState from '@/components/EmptyState.vue'
import TrackList from '@/components/TrackList.vue'
import { num } from '@/lib/format'
import { t } from '@/lib/i18n'
import { normalize, words, matches } from '@/lib/normalize'
import type { LocalFolder } from '@/lib/types'
import { supportsFolderAccess, supportsNativeScan, useLocal } from '@/stores/local'
import { useUi } from '@/stores/ui'

const local = useLocal()
const ui = useUi()
const folderFilter = ref<string | null>(null)
const filter = ref('')
const filesInput = ref<HTMLInputElement>()
const dirInput = ref<HTMLInputElement>()
const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)

const folders = computed(() => [...local.byFolder.keys()].sort())
const tracks = computed(() => {
  let list = folderFilter.value ? (local.byFolder.get(folderFilter.value) ?? []) : local.tracks
  const q = words(filter.value)
  if (q.length) list = list.filter((tr) => matches(normalize([tr.title, tr.artist, tr.album, tr.file_name].join(' ')), q))
  return list
})

onMounted(() => local.load())

async function run(task: () => Promise<unknown>) {
  try {
    await task()
  } catch (e) {
    if ((e as Error).name !== 'AbortError') ui.toast(t('local.failed'), 'error')
  }
}

function picked(e: Event) {
  const files = (e.target as HTMLInputElement).files
  if (files?.length) run(() => local.importFiles(files))
  ;(e.target as HTMLInputElement).value = ''
}

async function removeFolder(folder: LocalFolder) {
  if (confirm(t('local.removeFolderConfirm', { name: folder.name }))) await local.removeFolder(folder)
}
</script>

<template>
  <div class="mx-4 mt-4 rounded-2xl bg-fg/5 p-4 sm:mx-6">
    <p class="mb-3 text-sm text-muted">
      {{ supportsNativeScan ? t('local.hintNative') : supportsFolderAccess ? t('local.hintDesktop') : isIOS ? t('local.hintIOS') : t('local.hintMobile') }}
    </p>
    <div class="flex flex-wrap gap-2">
      <button v-if="supportsNativeScan" class="btn-primary" :disabled="!!local.scanning" @click="run(local.nativeScan)">
        <ScanSearch class="size-4" />{{ t('local.scanDevice') }}
      </button>
      <button v-if="supportsFolderAccess" class="btn-primary" :disabled="!!local.scanning" @click="run(local.addFolder)">
        <FolderPlus class="size-4" />{{ t('local.addFolder') }}
      </button>
      <template v-if="!supportsNativeScan && !supportsFolderAccess">
        <button v-if="!isIOS" class="btn-primary" :disabled="!!local.scanning" @click="dirInput?.click()">
          <FolderOpen class="size-4" />{{ t('local.importFolder') }}
        </button>
        <button :class="isIOS ? 'btn-primary' : 'btn-ghost'" :disabled="!!local.scanning" @click="filesInput?.click()">
          <FileAudio class="size-4" />{{ t('local.importFiles') }}
        </button>
      </template>
      <input ref="filesInput" type="file" accept="audio/*,.mp3,.m4a,.flac,.ogg,.opus,.wav,.aac" multiple class="hidden" @change="picked" />
      <input ref="dirInput" type="file" webkitdirectory multiple class="hidden" @change="picked" />
    </div>

    <div v-if="local.scanning" class="mt-4 flex items-center gap-3 text-sm">
      <Loader2 class="size-4 animate-spin text-accent" />
      <span>{{ local.scanning.total ? t('local.scanning', { done: local.scanning.done, total: local.scanning.total }) : t('local.searching') }}</span>
      <div v-if="local.scanning.total" class="h-1.5 flex-1 overflow-hidden rounded-full bg-fg/10" dir="ltr">
        <div class="h-full bg-accent transition-all" :style="{ width: `${(local.scanning.done / local.scanning.total) * 100}%` }" />
      </div>
    </div>

    <div v-if="local.folders.length" class="mt-4 flex flex-wrap gap-2">
      <div v-for="f in local.folders" :key="f.id" class="chip pe-1">
        <HardDrive class="size-3.5 text-subtle" />{{ f.name }} <span class="text-subtle">· {{ num(f.count) }}</span>
        <button v-if="f.kind === 'handle'" class="rounded-full p-1 hover:bg-fg/10" :title="t('local.rescan')" @click="run(() => local.scanFolder(f))"><RefreshCw class="size-3.5" /></button>
        <button v-if="f.kind === 'native'" class="rounded-full p-1 hover:bg-fg/10" :title="t('local.rescan')" @click="run(local.nativeScan)"><RefreshCw class="size-3.5" /></button>
        <button class="rounded-full p-1 hover:bg-fg/10" :title="t('common.remove')" @click="removeFolder(f)"><Trash2 class="size-3.5" /></button>
      </div>
    </div>
  </div>

  <template v-if="local.tracks.length">
    <CollectionActions :tracks="tracks" :context="{ type: 'local', name: folderFilter ?? t('library.device') }" :downloadable="false">
      <span class="text-sm text-muted">{{ t('playlist.songs', { n: tracks.length }) }}</span>
    </CollectionActions>
    <div class="flex flex-wrap items-center gap-2 px-4 pb-2 sm:px-6">
      <input v-model="filter" class="input h-10 max-w-xs flex-1 rounded-full" :placeholder="t('library.filter')" />
      <div v-if="folders.length > 1" class="flex gap-2 overflow-x-auto scrollbar-none">
        <button class="chip h-8" :class="{ 'chip-active': !folderFilter }" @click="folderFilter = null">{{ t('search.all') }}</button>
        <button v-for="f in folders" :key="f" class="chip h-8" :class="{ 'chip-active': folderFilter === f }" dir="auto" @click="folderFilter = f">
          {{ f.split('/').pop() }}
        </button>
      </div>
    </div>
    <div class="px-2 sm:px-4">
      <TrackList :tracks="tracks" show-album :context="{ type: 'local', name: folderFilter ?? t('library.device') }" />
    </div>
  </template>
  <EmptyState v-else-if="!local.scanning" :icon="HardDrive" :title="t('local.emptyTitle')" :text="t('local.emptyText')" />
</template>
