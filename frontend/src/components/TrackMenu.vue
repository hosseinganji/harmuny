<script setup lang="ts">
import { onClickOutside, onKeyStroke, useMediaQuery } from '@vueuse/core'
import {
  CircleMinus, Download, EyeOff, Heart, Info, Radio, ListEnd, ListMinus, ListPlus, ListStart, PencilLine, Share2, Trash2, UserRound,
} from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '@/lib/api'
import { t } from '@/lib/i18n'
import { startRadio } from '@/lib/radio'
import type { Track } from '@/lib/types'
import { useAuth } from '@/stores/auth'
import { useDownloads } from '@/stores/downloads'
import { useLibrary } from '@/stores/library'
import { useLocal } from '@/stores/local'
import { usePlayer } from '@/stores/player'
import { usePlaylists } from '@/stores/playlists'
import { useUi } from '@/stores/ui'
import TrackCover from './TrackCover.vue'

const ui = useUi()
const player = usePlayer()
const library = useLibrary()
const downloads = useDownloads()
const playlists = usePlaylists()
const local = useLocal()
const authStore = useAuth()
const router = useRouter()
const desktop = useMediaQuery('(min-width: 640px)')

const panel = ref<HTMLElement>()
onClickOutside(panel, () => close())
onKeyStroke('Escape', () => close())

const tracks = computed(() => ui.menu?.tracks ?? [])
const single = computed<Track | null>(() => (tracks.value.length === 1 ? tracks.value[0] : null))
const remote = computed(() => tracks.value.filter((t) => t.kind === 'remote'))
const ctx = computed(() => ui.menu?.context ?? {})
const allDownloaded = computed(() => remote.value.length > 0 && remote.value.every((t) => downloads.isDownloaded(t)))

const position = ref<Record<string, string>>({})
watch(() => ui.menu, (m) => {
  if (!m || !desktop.value) return
  // Keep the popover on screen.
  const w = 260
  const h = 420
  const x = Math.min(Math.max(8, m.x - w), innerWidth - w - 8)
  const y = Math.min(m.y, innerHeight - h - 8)
  position.value = { left: `${x}px`, top: `${Math.max(8, y)}px` }
})

function close() {
  ui.menu = null
}

function run(action: () => unknown) {
  action()
  close()
}

async function share(track: Track) {
  const text = [track.title, track.artist].filter(Boolean).join(' — ')
  if (navigator.share) await navigator.share({ title: track.title, text }).catch(() => {})
  else {
    await navigator.clipboard.writeText(text)
    ui.toast(t('toast.copied'))
  }
}

async function removeFromPlaylist() {
  if (!ctx.value.playlistId || !ctx.value.entryId) return
  await playlists.removeEntry(ctx.value.playlistId, ctx.value.entryId)
  ui.toast(t('toast.removedFromPlaylist'))
  window.dispatchEvent(new CustomEvent('playlist:changed', { detail: ctx.value.playlistId }))
}

async function hide(track: Track) {
  if (!confirm(t('admin.hideConfirm'))) return
  await api.delete(`/admin/tracks/${track.id}`)
  library.forget(track.id as number)
  ui.toast(t('toast.hidden'))
}
</script>

<template>
  <Teleport to="body">
    <Transition name="fade">
      <div v-if="ui.menu && !desktop" class="fixed inset-0 z-[70] bg-black/50" />
    </Transition>
    <Transition :name="desktop ? 'pop' : 'sheet'">
      <div
        v-if="ui.menu"
        ref="panel"
        class="liquid liquid-strong fixed z-[71] overflow-hidden"
        :class="desktop ? 'w-[260px] rounded-2xl py-1.5' : 'inset-x-0 bottom-0 rounded-t-[2rem] pb-safe'"
        :style="desktop ? position : {}"
        role="menu"
      >
        <div v-if="single" class="flex items-center gap-3 border-b border-line px-4 py-3" :class="{ 'pt-5': !desktop }">
          <TrackCover :track="single" size="md" />
          <div class="min-w-0">
            <div class="truncate font-semibold">{{ single.title }}</div>
            <div class="truncate text-sm text-muted">{{ single.artist ?? t('track.unknownArtist') }}</div>
          </div>
        </div>
        <div v-else class="border-b border-line px-4 py-3 font-semibold">{{ t('menu.selected', { n: tracks.length }) }}</div>

        <div class="max-h-[60vh] overflow-y-auto py-1 [&>button]:flex [&>button]:w-full [&>button]:items-center [&>button]:gap-3 [&>button]:px-4 [&>button]:py-2.5 [&>button]:text-start [&>button]:text-sm [&>button]:font-medium [&>button:hover]:bg-fg/5 [&_svg]:size-5 [&_svg]:shrink-0 [&_svg]:text-muted">
          <button @click="run(() => player.playNext(tracks))"><ListStart />{{ t('menu.playNext') }}</button>
          <button @click="run(() => player.addToQueue(tracks))"><ListEnd />{{ t('menu.addToQueue') }}</button>
          <button v-if="ctx.queueUid && player.current?.uid !== ctx.queueUid" @click="run(() => player.remove(ctx.queueUid!))"><ListMinus />{{ t('queue.remove') }}</button>
          <button v-if="remote.length" @click="run(() => (ui.addToPlaylist = remote))"><ListPlus />{{ t('menu.addToPlaylist') }}</button>
          <button v-if="ctx.canRemoveFromPlaylist && ctx.entryId" @click="run(removeFromPlaylist)"><CircleMinus />{{ t('menu.removeFromPlaylist') }}</button>
          <button v-if="single?.kind === 'remote'" @click="run(() => library.toggleLike(single!))">
            <Heart :class="{ 'fill-accent !text-accent': library.isLiked(single) }" />{{ library.isLiked(single) ? t('menu.unlike') : t('menu.like') }}
          </button>
          <template v-if="remote.length">
            <button v-if="!allDownloaded" @click="run(() => downloads.download(remote))"><Download />{{ t('menu.download') }}</button>
            <button v-else @click="run(() => downloads.remove(remote))"><Trash2 />{{ t('menu.removeDownload') }}</button>
          </template>
          <button v-if="single?.kind === 'remote'" @click="run(() => startRadio({ trackId: single!.id as number, name: single!.title }))"><Radio />{{ t('radio.start') }}</button>
          <button v-if="single?.kind === 'remote' && single.artist" @click="run(() => router.push({ name: 'artist', query: { name: single!.artist! } }))">
            <UserRound />{{ t('menu.goToArtist') }}
          </button>
          <button v-if="single" @click="run(() => (ui.trackInfo = single))"><Info />{{ t('info.title') }}</button>
          <button v-if="single" @click="run(() => share(single!))"><Share2 />{{ t('menu.share') }}</button>
          <button v-if="single?.kind === 'local'" @click="run(() => local.removeTrack(single as any))"><Trash2 />{{ t('menu.removeFromDevice') }}</button>
          <template v-if="authStore.isAdmin && single?.kind === 'remote'">
            <div class="my-1 border-t border-line" />
            <button @click="run(() => (ui.editTrack = single))"><PencilLine />{{ t('menu.editInfo') }}</button>
            <button class="!text-danger" @click="run(() => hide(single!))"><EyeOff class="!text-danger" />{{ t('menu.hide') }}</button>
          </template>
        </div>
        <button v-if="!desktop" class="mx-4 mb-3 mt-1 h-12 w-[calc(100%-2rem)] rounded-2xl bg-fg/5 font-semibold" @click="close">{{ t('common.cancel') }}</button>
      </div>
    </Transition>
  </Teleport>
</template>
