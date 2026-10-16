<script setup lang="ts">
import { ImageUp } from 'lucide-vue-next'
import { reactive, ref, watch } from 'vue'
import { api, ApiError, request } from '@/lib/api'
import { t } from '@/lib/i18n'
import type { ApiTrack } from '@/lib/types'
import { useLibrary } from '@/stores/library'
import { usePlayer } from '@/stores/player'
import { useUi } from '@/stores/ui'
import Modal from './Modal.vue'
import TrackCover from './TrackCover.vue'

const ui = useUi()
const library = useLibrary()
const player = usePlayer()
const form = reactive({ title: '', artist: '', album: '', genre: '', year: '' as string | number })
const busy = ref(false)
const error = ref('')

function fill(track: { title: string; artist?: string | null; album?: string | null; genre?: string | null; year?: number | null }) {
  Object.assign(form, { title: track.title, artist: track.artist ?? '', album: track.album ?? '', genre: track.genre ?? '', year: track.year ?? '' })
}

// The track handed in can be a stale copy held by a list, so show the catalog's copy and then the server's.
watch(() => ui.editTrack?.id, async (id) => {
  if (typeof id !== 'number') return // Only server tracks are editable.
  error.value = ''
  fill(library.get(id) ?? ui.editTrack!)
  try {
    const res = await api.get<{ data: ApiTrack }>(`/tracks/${id}`)
    if (ui.editTrack?.id === id && !busy.value) fill(res.data)
  } catch {
    // Offline: the catalog copy is the best we have.
  }
})

async function apply(updated: ApiTrack) {
  const track = await library.update(updated)
  player.refreshTrack(track)
  ui.editTrack = track
}

async function save() {
  if (!ui.editTrack) return
  busy.value = true
  try {
    const res = await api.patch<{ data: ApiTrack }>(`/admin/tracks/${ui.editTrack.id}`, {
      title: form.title,
      artist: form.artist || null,
      album: form.album || null,
      genre: form.genre || null,
      year: form.year ? Number(form.year) : null,
    })
    await apply(res.data)
    ui.editTrack = null
    ui.toast(t('toast.saved'), 'success')
  } catch (e) {
    error.value = e instanceof ApiError ? e.first : String(e)
  } finally {
    busy.value = false
  }
}

async function uploadCover(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file || !ui.editTrack) return
  const body = new FormData()
  body.append('cover', file)
  const res = await request<{ data: ApiTrack }>('POST', `/admin/tracks/${ui.editTrack.id}/cover`, { body })
  await apply(res.data)
  ui.toast(t('toast.saved'), 'success')
}
</script>

<template>
  <Modal :open="!!ui.editTrack" :title="t('menu.editInfo')" @close="ui.editTrack = null">
    <form v-if="ui.editTrack" class="space-y-3" @submit.prevent="save">
      <div class="flex items-center gap-4">
        <TrackCover :track="ui.editTrack" size="lg" class="!size-24" />
        <label class="btn-ghost cursor-pointer">
          <ImageUp class="size-4" />{{ t('admin.changeCover') }}
          <input type="file" accept="image/*" class="hidden" @change="uploadCover" />
        </label>
      </div>
      <div><label class="label">{{ t('track.title') }}</label><input v-model="form.title" class="input" required /></div>
      <div><label class="label">{{ t('track.artist') }}</label><input v-model="form.artist" class="input" /></div>
      <div><label class="label">{{ t('track.album') }}</label><input v-model="form.album" class="input" /></div>
      <div class="grid grid-cols-2 gap-3">
        <div><label class="label">{{ t('track.genre') }}</label><input v-model="form.genre" class="input" /></div>
        <div><label class="label">{{ t('track.year') }}</label><input v-model="form.year" class="input" type="number" min="1800" max="2200" /></div>
      </div>
      <p v-if="error" class="text-sm text-danger">{{ error }}</p>
      <p class="text-xs text-subtle">{{ t('admin.editHint') }}</p>
      <button class="btn-primary w-full" :disabled="busy">{{ t('common.save') }}</button>
    </form>
  </Modal>
</template>
