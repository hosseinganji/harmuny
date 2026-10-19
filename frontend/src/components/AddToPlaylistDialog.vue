<script setup lang="ts">
import { Plus } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'
import { t } from '@/lib/i18n'
import { usePlaylists } from '@/stores/playlists'
import { useUi } from '@/stores/ui'
import CoverMosaic from './CoverMosaic.vue'
import Modal from './Modal.vue'

const ui = useUi()
const playlists = usePlaylists()
const creating = ref(false)
const name = ref('')
const busy = ref(false)

// Your own playlists and shared ones you may add to.
const mine = computed(() => playlists.mine.filter((p) => p.can_edit ?? p.is_owner))
const ids = computed(() => (ui.addToPlaylist ?? []).filter((t) => t.kind === 'remote').map((t) => t.id as number))

watch(() => ui.addToPlaylist, (v) => {
  if (v) {
    creating.value = false
    name.value = ''
    if (!playlists.loaded) playlists.load()
  }
})

async function add(id: number) {
  busy.value = true
  try {
    await playlists.addTracks(id, ids.value)
    ui.addToPlaylist = null
  } catch {
    ui.toast(t('toast.offlineAction'), 'error')
  } finally {
    busy.value = false
  }
}

async function create() {
  if (!name.value.trim()) return
  busy.value = true
  try {
    await playlists.create({ name: name.value.trim(), track_ids: ids.value })
    ui.addToPlaylist = null
  } catch {
    ui.toast(t('toast.offlineAction'), 'error')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Modal :open="!!ui.addToPlaylist" :title="t('menu.addToPlaylist')" @close="ui.addToPlaylist = null">
    <form v-if="creating" class="flex gap-2" @submit.prevent="create">
      <input v-model="name" class="input" :placeholder="t('playlist.namePlaceholder')" autofocus maxlength="100" />
      <button class="btn-primary shrink-0" :disabled="busy || !name.trim()">{{ t('common.create') }}</button>
    </form>
    <button v-else class="flex w-full items-center gap-3 rounded-xl p-2 hover:bg-fg/5" @click="creating = true">
      <span class="flex size-12 items-center justify-center rounded-lg bg-accent text-accent-fg"><Plus class="size-6" /></span>
      <span class="font-semibold">{{ t('playlist.new') }}</span>
    </button>

    <div class="mt-2 space-y-1">
      <button
        v-for="p in mine"
        :key="p.id"
        class="flex w-full items-center gap-3 rounded-xl p-2 text-start hover:bg-fg/5 disabled:opacity-50"
        :disabled="busy"
        @click="add(p.id)"
      >
        <div class="w-12 shrink-0"><CoverMosaic :cover-url="p.cover_url" :track-ids="p.preview_track_ids" rounded="rounded-lg" /></div>
        <div class="min-w-0">
          <div class="truncate font-semibold">{{ p.name }}</div>
          <div class="text-sm text-muted">{{ t('playlist.songs', { n: p.tracks_count ?? 0 }) }}</div>
        </div>
      </button>
      <p v-if="!mine.length && playlists.loaded" class="py-4 text-center text-sm text-subtle">{{ t('playlist.noneYet') }}</p>
    </div>
  </Modal>
</template>
