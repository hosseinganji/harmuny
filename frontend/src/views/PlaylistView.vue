<script setup lang="ts">
import { ArrowUpDown, Check, GripVertical, ImageMinus, ImageUp, ListMusic, Lock, LogOut, MoreHorizontal, Pencil, Trash2, UserPlus, UsersRound } from 'lucide-vue-next'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import Draggable from 'vuedraggable'
import CollectionActions from '@/components/CollectionActions.vue'
import CollectionHeader from '@/components/CollectionHeader.vue'
import AutoDownloadToggle from '@/components/AutoDownloadToggle.vue'
import Avatar from '@/components/Avatar.vue'
import CollaboratorsDialog from '@/components/CollaboratorsDialog.vue'
import CoverMosaic from '@/components/CoverMosaic.vue'
import EmptyState from '@/components/EmptyState.vue'
import Modal from '@/components/Modal.vue'
import TrackCover from '@/components/TrackCover.vue'
import SortSelect from '@/components/SortSelect.vue'
import TrackList from '@/components/TrackList.vue'
import { api, ApiError, coverUrl, withMediaKey } from '@/lib/api'
import { usePageColor } from '@/lib/dynamicColor'
import { duration, longDuration } from '@/lib/format'
import { t } from '@/lib/i18n'
import { sortTracks, useTrackSort } from '@/lib/sortTracks'
import type { Playlist, RemoteTrack } from '@/lib/types'
import { useDeviceSync } from '@/stores/deviceSync'
import { toRemote, useLibrary } from '@/stores/library'
import { usePlaylists } from '@/stores/playlists'
import { useUi } from '@/stores/ui'

const props = defineProps<{ id: number }>()
const playlists = usePlaylists()
const ui = useUi()
const router = useRouter()

const playlist = ref<Playlist | null>(null)
const error = ref(false)
const editing = ref(false)
const reordering = ref(false)
const sharing = ref(false)
/** Owner, or a member invited to this shared playlist. */
const canEdit = computed(() => !!playlist.value && (playlist.value.can_edit ?? playlist.value.is_owner))
const form = ref({ name: '', description: '', is_public: false })
const busy = ref(false)

const library = useLibrary()
const deviceSync = useDeviceSync()
const tracks = computed<RemoteTrack[]>(() => (playlist.value?.tracks ?? []).map(toRemote))
const sort = useTrackSort('playlist', 'natural')
const sorted = computed(() => sortTracks(tracks.value, sort.value, library.playsOf))
const order = ref<RemoteTrack[]>([])
const total = computed(() => tracks.value.reduce((s, x) => s + x.duration, 0))
const context = computed(() => ({ type: 'playlist' as const, id: props.id, name: playlist.value?.name }))

usePageColor(computed(() => withMediaKey(playlist.value?.cover_url) ?? (playlist.value?.preview_track_ids[0] ? coverUrl(playlist.value.preview_track_ids[0]) : null)))

async function load() {
  try {
    playlist.value = await playlists.get(props.id, (cached) => (playlist.value ??= cached))
    error.value = false
  } catch {
    error.value = !playlist.value
  }
}

function onChanged(e: Event) {
  if ((e as CustomEvent).detail === props.id) load()
}
onMounted(() => {
  load()
  addEventListener('playlist:changed', onChanged)
})
onBeforeUnmount(() => removeEventListener('playlist:changed', onChanged))
watch(() => props.id, load)

function openEdit() {
  if (!playlist.value) return
  form.value = { name: playlist.value.name, description: playlist.value.description ?? '', is_public: playlist.value.is_public }
  editing.value = true
}

async function save() {
  busy.value = true
  try {
    playlist.value = await playlists.update(props.id, { ...form.value, description: form.value.description || null })
    editing.value = false
  } catch (e) {
    ui.toast(e instanceof ApiError ? e.first : t('toast.offlineAction'), 'error')
  } finally {
    busy.value = false
  }
}

async function uploadCover(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (file) playlist.value = await playlists.uploadCover(props.id, file)
}

async function removeCover() {
  playlist.value = await playlists.deleteCover(props.id)
}

async function leave() {
  if (!confirm(t('collab.leaveConfirm', { name: playlist.value?.name ?? '' }))) return
  await api.delete(`/playlists/${props.id}/collaborators/me`)
  playlists.load()
  router.replace('/library')
}

async function destroy() {
  if (!confirm(t('playlist.deleteConfirm', { name: playlist.value?.name ?? '' }))) return
  await playlists.remove(props.id)
  ui.toast(t('toast.playlistDeleted'))
  router.replace('/library')
}

function startReorder() {
  order.value = [...tracks.value]
  reordering.value = true
}

async function finishReorder() {
  reordering.value = false
  const ids = order.value.map((x) => x.entry_id!).filter(Boolean)
  if (ids.join() === tracks.value.map((x) => x.entry_id).join()) return
  try {
    playlist.value = await playlists.reorder(props.id, ids)
  } catch {
    ui.toast(t('toast.offlineAction'), 'error')
  }
}
</script>

<template>
  <EmptyState v-if="error" :icon="ListMusic" :title="t('playlist.notFound')" />
  <div v-else-if="!playlist" class="p-6"><div class="skeleton size-56 rounded-2xl" /></div>
  <template v-else>
    <CollectionHeader
      :kicker="playlist.is_collaborative ? t('collab.kicker') : playlist.is_public ? t('playlist.publicPlaylist') : t('playlist.privatePlaylist')"
      :title="playlist.name"
      :description="playlist.description"
    >
      <template #cover><CoverMosaic :cover-url="playlist.cover_url" :track-ids="playlist.preview_track_ids" rounded="rounded-2xl" /></template>
      <template #meta>
        <div class="mt-3 flex flex-wrap items-center justify-center gap-x-2 text-sm font-medium text-fg/80 sm:justify-start">
          <span class="flex items-center gap-1">
            <UsersRound v-if="playlist.is_public" class="size-4" /><Lock v-else class="size-4" />
            {{ playlist.owner.name }}
          </span>
          <!-- Shared playlists show who adds songs -->
          <span v-if="playlist.is_collaborative" class="flex items-center gap-1.5">
            <span>·</span>
            <span class="flex -space-x-2 rtl:space-x-reverse">
              <Avatar v-for="c in playlist.collaborators!.slice(0, 4)" :key="c.id" :name="c.name" :src="c.avatar_url" size="size-6 text-[9px] ring-2 ring-bg" />
            </span>
            <span v-if="playlist.collaborators!.length > 4" class="text-xs">+{{ playlist.collaborators!.length - 4 }}</span>
          </span>
          <span>·</span><span>{{ t('playlist.songs', { n: tracks.length }) }}</span>
          <template v-if="total"><span>·</span><span>{{ longDuration(total) }}</span></template>
        </div>
      </template>
    </CollectionHeader>

    <CollectionActions :tracks="sorted" :context="context">
      <template v-if="canEdit">
        <button v-if="reordering" class="btn-primary h-9 px-4" @click="finishReorder"><Check class="size-4" />{{ t('common.done') }}</button>
        <button v-else-if="tracks.length > 1" class="icon-btn" :title="t('playlist.reorder')" @click="startReorder"><ArrowUpDown class="size-5" /></button>
      </template>
      <template v-if="playlist.is_owner">
        <button class="icon-btn" :class="{ '!text-accent': playlist.is_collaborative }" :title="t('collab.title')" @click="sharing = true"><UserPlus class="size-5" /></button>
        <button class="icon-btn" :title="t('common.edit')" @click="openEdit"><Pencil class="size-5" /></button>
      </template>
      <button v-else-if="canEdit" class="icon-btn" :title="t('collab.leave')" @click="leave"><LogOut class="size-5 rtl:rotate-180" /></button>
      <button v-if="tracks.length" class="icon-btn" :title="t('track.more')" @click="ui.openTrackMenu(tracks, $event)"><MoreHorizontal class="size-5" /></button>
    </CollectionActions>

    <EmptyState v-if="!tracks.length" :icon="ListMusic" :title="t('playlist.empty')" :text="t('playlist.emptyHint')">
      <RouterLink to="/search" class="btn-primary">{{ t('nav.search') }}</RouterLink>
    </EmptyState>

    <!-- Sort, and keeping this playlist downloaded (now and whenever songs are added), on their own row. -->
    <div v-if="tracks.length && !reordering" class="flex flex-wrap items-center gap-2 px-4 pb-2 sm:px-6">
      <SortSelect v-if="tracks.length > 1" v-model="sort" :natural-label="t('sort.playlistOrder')" />
      <AutoDownloadToggle :model-value="deviceSync.isAutoPlaylist(id)" :hint="t('playlist.autoDownloadHint')" @update:model-value="deviceSync.toggleAutoPlaylist(id)" />
    </div>
    <div v-if="reordering" class="px-2 sm:px-4">
      <Draggable v-model="order" item-key="entry_id" handle=".drag" :animation="180" ghost-class="opacity-30">
        <template #item="{ element }: { element: RemoteTrack }">
          <div class="flex h-15 items-center gap-3 rounded-xl px-2 hover:bg-fg/5">
            <span class="drag cursor-grab touch-none p-2 text-muted active:cursor-grabbing"><GripVertical class="size-5" /></span>
            <TrackCover :track="element" size="md" />
            <div class="min-w-0 flex-1">
              <div class="truncate font-medium">{{ element.title }}</div>
              <div class="truncate text-sm text-muted">{{ element.artist }}</div>
            </div>
            <span class="text-sm text-subtle tabular">{{ duration(element.duration) }}</span>
          </div>
        </template>
      </Draggable>
    </div>
    <div v-else class="px-2 sm:px-4">
      <TrackList
        :tracks="sorted"
        numbered
        show-album
        :context="context"
        :menu-context="(tr) => ({ playlistId: id, entryId: (tr as RemoteTrack).entry_id, canRemoveFromPlaylist: canEdit })"
      />
    </div>
  </template>

  <CollaboratorsDialog v-if="playlist" :open="sharing" :playlist="playlist" @close="sharing = false" @saved="load" />

  <Modal :open="editing" :title="t('playlist.edit')" @close="editing = false">
    <form v-if="playlist" class="space-y-4" @submit.prevent="save">
      <div class="flex items-center gap-4">
        <div class="w-24 shrink-0"><CoverMosaic :cover-url="playlist.cover_url" :track-ids="playlist.preview_track_ids" /></div>
        <div class="flex flex-col gap-2">
          <label class="btn-ghost h-9 cursor-pointer px-3 text-xs">
            <ImageUp class="size-4" />{{ t('playlist.changeCover') }}
            <input type="file" accept="image/*" class="hidden" @change="uploadCover" />
          </label>
          <button v-if="playlist.cover_url" type="button" class="btn-ghost h-9 px-3 text-xs" @click="removeCover"><ImageMinus class="size-4" />{{ t('playlist.removeCover') }}</button>
        </div>
      </div>
      <div><label class="label">{{ t('playlist.name') }}</label><input v-model="form.name" class="input" required maxlength="100" /></div>
      <div><label class="label">{{ t('playlist.description') }}</label><textarea v-model="form.description" class="input h-24 resize-none py-3" maxlength="500" /></div>
      <label class="flex cursor-pointer items-center justify-between gap-4 rounded-xl bg-fg/5 p-4">
        <div>
          <div class="font-semibold">{{ t('playlist.public') }}</div>
          <div class="text-sm text-muted">{{ t('playlist.publicHint') }}</div>
        </div>
        <input v-model="form.is_public" type="checkbox" class="size-5 accent-[var(--accent)]" />
      </label>
      <div class="flex gap-2">
        <button type="button" class="btn-danger" @click="destroy"><Trash2 class="size-4" />{{ t('common.delete') }}</button>
        <button class="btn-primary flex-1" :disabled="busy">{{ t('common.save') }}</button>
      </div>
    </form>
  </Modal>
</template>
