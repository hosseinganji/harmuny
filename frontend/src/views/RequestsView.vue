<script setup lang="ts">
import { Check, CircleCheck, Clock, Loader2, Play, Search, Send, Trash2, X } from 'lucide-vue-next'
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import Avatar from '@/components/Avatar.vue'
import Modal from '@/components/Modal.vue'
import TrackCover from '@/components/TrackCover.vue'
import { api, ApiError } from '@/lib/api'
import { relativeTime } from '@/lib/format'
import { t } from '@/lib/i18n'
import type { ApiTrack, RemoteTrack } from '@/lib/types'
import { useAuth } from '@/stores/auth'
import { useLibrary } from '@/stores/library'
import { usePlayer } from '@/stores/player'
import { useUi } from '@/stores/ui'

/** Ask for a song the library doesn't have; admins see every request and answer it. */
interface SongRequest {
  id: number
  title: string
  artist: string | null
  note: string | null
  status: 'pending' | 'fulfilled' | 'rejected'
  created_at: string
  user: { id: number; name: string; avatar_url: string | null }
  handler: { id: number; name: string } | null
  track: ApiTrack | null
}

const auth = useAuth()
const ui = useUi()
const library = useLibrary()
const player = usePlayer()
const route = useRoute()

const requests = ref<SongRequest[] | null>(null)
const form = ref({ title: String(route.query.q ?? ''), artist: '', note: '' })
const sending = ref(false)
const pending = computed(() => requests.value?.filter((r) => r.status === 'pending') ?? [])
const done = computed(() => requests.value?.filter((r) => r.status !== 'pending') ?? [])

async function load() {
  try {
    requests.value = (await api.get<{ data: SongRequest[] }>('/requests')).data
  } catch {
    requests.value ??= []
  }
}
onMounted(load)

async function send() {
  sending.value = true
  try {
    const res = await api.post<{ data: SongRequest }>('/requests', form.value)
    requests.value = [res.data, ...(requests.value ?? [])]
    form.value = { title: '', artist: '', note: '' }
    ui.toast(res.data.status === 'fulfilled' ? t('requests.alreadyHere') : t('requests.sent'), 'success')
  } catch (e) {
    ui.toast(e instanceof ApiError && e.status ? e.first : t('toast.offlineAction'), 'error')
  } finally {
    sending.value = false
  }
}

function playTrack(tr: ApiTrack) {
  const [track] = library.remember([tr])
  player.playTracks([track], 0, { shuffle: false })
}

async function setStatus(r: SongRequest, status: 'rejected' | 'pending', trackId?: number) {
  const res = await api.patch<{ data: SongRequest }>(`/requests/${r.id}`, { status, track_id: trackId })
  Object.assign(r, res.data)
}

async function remove(r: SongRequest) {
  await api.delete(`/requests/${r.id}`)
  requests.value = requests.value?.filter((x) => x.id !== r.id) ?? null
}

// Admins answer a request by picking the song from the library.
const answering = ref<SongRequest | null>(null)
const query = ref('')
const matches = computed<RemoteTrack[]>(() => (query.value.trim() ? library.search(query.value, 30) : []))
function openAnswer(r: SongRequest) {
  answering.value = r
  query.value = [r.artist, r.title].filter(Boolean).join(' ')
}
async function answer(track: RemoteTrack) {
  const r = answering.value
  if (!r) return
  const res = await api.patch<{ data: SongRequest }>(`/requests/${r.id}`, { status: 'fulfilled', track_id: track.id })
  Object.assign(r, res.data)
  answering.value = null
}
</script>

<template>
  <div class="mx-auto max-w-2xl px-4 pb-6 pt-6 sm:px-6 sm:pt-10">
    <h1 class="text-3xl font-black tracking-tight sm:text-4xl">{{ t('requests.title') }}</h1>
    <p class="mt-1 text-muted">{{ t('requests.hint') }}</p>

    <form class="card mt-5 space-y-3 p-4" @submit.prevent="send">
      <input v-model="form.title" class="input" :placeholder="t('requests.songTitle')" required maxlength="250" />
      <input v-model="form.artist" class="input" :placeholder="t('requests.artist')" maxlength="250" />
      <input v-model="form.note" class="input" :placeholder="t('requests.note')" maxlength="500" />
      <button class="btn-primary h-12 w-full" :disabled="sending || !form.title.trim()">
        <Loader2 v-if="sending" class="size-4 animate-spin" /><Send v-else class="size-4 rtl:-scale-x-100" />{{ t('requests.send') }}
      </button>
    </form>

    <div v-if="!requests" class="flex justify-center py-10"><Loader2 class="size-6 animate-spin text-muted" /></div>
    <template v-else>
      <section v-for="group in [{ key: 'pending', list: pending }, { key: 'done', list: done }]" :key="group.key" class="mt-8">
        <h2 v-if="group.list.length" class="mb-2 text-xs font-bold uppercase tracking-wider text-subtle">{{ t('requests.' + group.key) }}</h2>
        <ul class="space-y-2">
          <li v-for="r in group.list" :key="r.id" class="card p-3">
            <div class="flex items-start gap-3">
              <span
                class="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full"
                :class="r.status === 'fulfilled' ? 'bg-success/15 text-success' : r.status === 'rejected' ? 'bg-danger/15 text-danger' : 'bg-amber-400/15 text-amber-400'"
              >
                <CircleCheck v-if="r.status === 'fulfilled'" class="size-5" /><X v-else-if="r.status === 'rejected'" class="size-5" /><Clock v-else class="size-5" />
              </span>
              <div class="min-w-0 flex-1">
                <div class="truncate font-bold">{{ r.title }}</div>
                <div class="truncate text-sm text-muted">{{ r.artist ?? t('track.unknownArtist') }}</div>
                <p v-if="r.note" class="mt-1 text-sm text-subtle">{{ r.note }}</p>
                <div class="mt-1 flex items-center gap-1.5 text-xs text-subtle">
                  <template v-if="auth.isAdmin"><Avatar :name="r.user.name" :src="r.user.avatar_url" size="size-4 text-[7px]" />{{ r.user.name }} · </template>
                  {{ relativeTime(r.created_at) }} · {{ t('requests.status.' + r.status) }}
                </div>
              </div>
              <button v-if="r.user.id === auth.user?.id || auth.isAdmin" class="icon-btn size-8 shrink-0" :aria-label="t('common.delete')" @click="remove(r)"><Trash2 class="size-4" /></button>
            </div>
            <!-- The song, once it is here -->
            <button v-if="r.track" class="mt-3 flex w-full items-center gap-3 rounded-xl bg-fg/[0.05] p-2 text-start transition hover:bg-fg/10" @click="playTrack(r.track)">
              <TrackCover :track="{ ...r.track, kind: 'remote' }" size="sm" />
              <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-semibold">{{ r.track.title }}</span>
                <span class="block truncate text-xs text-muted">{{ r.track.artist }}</span>
              </span>
              <Play class="size-5 fill-current" />
            </button>
            <div v-if="auth.isAdmin && r.status === 'pending'" class="mt-3 flex gap-2">
              <button class="btn-primary h-9 flex-1 text-xs" @click="openAnswer(r)"><Check class="size-4" />{{ t('requests.answer') }}</button>
              <button class="btn-ghost h-9 text-xs" @click="setStatus(r, 'rejected')"><X class="size-4" />{{ t('requests.reject') }}</button>
            </div>
          </li>
        </ul>
      </section>
      <p v-if="!requests.length" class="py-10 text-center text-sm text-subtle">{{ t('requests.empty') }}</p>
    </template>

    <Modal :open="!!answering" :title="t('requests.pick')" @close="answering = null">
      <label class="relative block">
        <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-subtle" />
        <input v-model="query" class="input ps-9" :placeholder="t('search.placeholder')" />
      </label>
      <ul class="mt-3 space-y-1">
        <li v-for="m in matches" :key="m.id">
          <button class="flex w-full items-center gap-3 rounded-xl p-2 text-start hover:bg-fg/5" @click="answer(m)">
            <TrackCover :track="m" size="sm" />
            <span class="min-w-0 flex-1">
              <span class="block truncate text-sm font-semibold">{{ m.title }}</span>
              <span class="block truncate text-xs text-muted">{{ m.artist }}</span>
            </span>
          </button>
        </li>
      </ul>
    </Modal>
  </div>
</template>
