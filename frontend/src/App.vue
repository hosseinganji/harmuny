<script setup lang="ts">
import { onKeyStroke, useOnline } from '@vueuse/core'
import { WifiOff } from 'lucide-vue-next'
import { computed, onMounted, ref, watch } from 'vue'
import { type RouteLocationNormalizedLoaded, useRoute, useRouter } from 'vue-router'
import { useRegisterSW } from 'virtual:pwa-register/vue'
import AddToPlaylistDialog from '@/components/AddToPlaylistDialog.vue'
import AppSidebar from '@/components/AppSidebar.vue'
import BottomNav from '@/components/BottomNav.vue'
import EditTrackDialog from '@/components/EditTrackDialog.vue'
import MiniPlayer from '@/components/MiniPlayer.vue'
import MobileTopBar from '@/components/MobileTopBar.vue'
import NewPlaylistDialog from '@/components/NewPlaylistDialog.vue'
import NowPlaying from '@/components/NowPlaying.vue'
import ProfileDrawer from '@/components/ProfileDrawer.vue'
import PlayerBar from '@/components/PlayerBar.vue'
import SidePanel from '@/components/SidePanel.vue'
import Toasts from '@/components/Toasts.vue'
import TrackMenu from '@/components/TrackMenu.vue'
import { setUnauthorizedHandler } from '@/lib/api'
import { setPlayerArtwork } from '@/lib/dynamicColor'
import { startPresence } from '@/lib/presence'
import { keepRadioGoing } from '@/lib/radio'
import { t } from '@/lib/i18n'
import { useAuth } from '@/stores/auth'
import { useDeviceSync } from '@/stores/deviceSync'
import { useDownloads } from '@/stores/downloads'
import { useEqualizer } from '@/stores/equalizer'
import { useLibrary } from '@/stores/library'
import { usePlayer } from '@/stores/player'
import { usePlaylists } from '@/stores/playlists'
import { useSettings } from '@/stores/settings'

const auth = useAuth()
const settings = useSettings()
const player = usePlayer()
const library = useLibrary()
const downloads = useDownloads()
const playlists = usePlaylists()
const route = useRoute()
const router = useRouter()
const online = useOnline()
const newPlaylist = ref(false)

const shell = computed(() => auth.isLoggedIn && !route.meta.guest)

// Install updates in the background; the new version applies on next launch.
useRegisterSW({ immediate: true })

setUnauthorizedHandler(async () => {
  await auth.logout(false)
  router.replace({ name: 'login' })
})

async function boot() {
  await Promise.all([library.load(), downloads.load()])
  player.restore()
  auth.refresh()
  playlists.load()
  library.sync()
  player.flushPlays()
  // Keep this device's offline copy topped up (no-op unless enabled in settings).
  const deviceSync = useDeviceSync()
  deviceSync.run()
  // Liked songs and auto-download playlists catch up on anything added meanwhile.
  deviceSync.runAuto()
}

// Shares the song being played with the online list (only while signed in).
startPresence()
// A radio tops itself up with more songs like it.
keepRadioGoing()

onMounted(() => {
  if (auth.isLoggedIn) boot()
  useEqualizer().resumeOnGesture()
})
watch(() => auth.isLoggedIn, (v, was) => {
  if (v && !was) boot()
  if (!v) player.reset()
})

// Re-sync the catalog when coming back online or returning to the app.
watch(online, (v) => v && auth.isLoggedIn && library.sync())
document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'visible' && auth.isLoggedIn && online.value) library.sync()
})

watch(
  () => [player.coverSrc, settings.prefs.dynamicColor] as const,
  ([src, enabled]) => setPlayerArtwork(src, enabled !== false),
  { immediate: true },
)

watch(() => player.track, (tr) => {
  document.title = tr ? `${tr.title}${tr.artist ? ' · ' + tr.artist : ''}` : t('app.name')
})

// Keyboard shortcuts (ignored while typing).
const typing = (e: KeyboardEvent) => ['INPUT', 'TEXTAREA', 'SELECT'].includes((e.target as HTMLElement)?.tagName)
onKeyStroke(' ', (e) => { if (!typing(e) && player.current) { e.preventDefault(); player.toggle() } })
onKeyStroke('ArrowRight', (e) => { if (!typing(e) && e.shiftKey) player.next(); else if (!typing(e) && player.current) player.seekBy(5) })
onKeyStroke('ArrowLeft', (e) => { if (!typing(e) && e.shiftKey) player.prev(); else if (!typing(e) && player.current) player.seekBy(-5) })
onKeyStroke('ArrowUp', (e) => { if (!typing(e) && e.shiftKey) { e.preventDefault(); player.setVolume(player.volume + 0.05) } })
onKeyStroke('ArrowDown', (e) => { if (!typing(e) && e.shiftKey) { e.preventDefault(); player.setVolume(player.volume - 0.05) } })
onKeyStroke(['m', 'M'], (e) => !typing(e) && player.toggleMute())
onKeyStroke(['s', 'S'], (e) => !typing(e) && !e.ctrlKey && !e.metaKey && player.toggleShuffle())
onKeyStroke(['r', 'R'], (e) => !typing(e) && !e.ctrlKey && !e.metaKey && player.cycleRepeat())
onKeyStroke(['l', 'L'], (e) => { if (!typing(e) && player.track) library.toggleLike(player.track) })
onKeyStroke('/', (e) => { if (!typing(e)) { e.preventDefault(); router.push('/search') } })

function pageKey(r: RouteLocationNormalizedLoaded) {
  const root = r.matched[0]?.path
  return root === '/library' || root === '/admin' ? root : r.name === 'search' ? r.path : r.fullPath
}
</script>

<template>
  <template v-if="shell">
    <div class="app-shell flex flex-col overflow-hidden bg-bg">
      <div v-if="!online" class="flex items-center justify-center gap-2 bg-amber-500/15 py-1.5 text-xs font-semibold text-amber-400 pt-safe">
        <WifiOff class="size-3.5" />{{ t('app.offline') }}
      </div>
      <!-- The header belongs to Home only; other pages start right under the status bar. -->
      <MobileTopBar v-if="route.name === 'home'" :safe="online" />
      <div class="flex min-h-0 flex-1">
        <AppSidebar @new-playlist="newPlaylist = true" />
        <main data-scroll class="relative min-w-0 flex-1 overflow-y-auto overflow-x-hidden lg:card lg:my-2" :class="online ? (route.name === 'home' ? 'lg:pt-safe' : 'pt-safe') : ''">
          <RouterView v-slot="{ Component, route: r }">
            <!-- Views have several root nodes; a single keyed wrapper keeps the swap reliable. -->
            <!-- Search keeps its query in the URL; remounting on every keystroke would close the keyboard. -->
            <div :key="pageKey(r)"><component :is="Component" /></div>
          </RouterView>
          <div class="h-40 lg:h-8" />
        </main>
        <SidePanel />
      </div>
      <PlayerBar />
      <div class="pointer-events-none absolute inset-x-0 bottom-0 z-40 px-3 pb-float lg:hidden">
        <!-- Content fades out under the floating glass bars, like iOS scroll edges. -->
        <div class="absolute inset-x-0 bottom-0 -z-10 h-36 bg-gradient-to-t from-bg via-bg/75 to-transparent" />
        <MiniPlayer class="pointer-events-auto mb-2" />
        <BottomNav class="pointer-events-auto" />
      </div>
    </div>
    <NowPlaying />
    <TrackMenu />
    <ProfileDrawer />
    <AddToPlaylistDialog />
    <EditTrackDialog />
    <NewPlaylistDialog :open="newPlaylist" @close="newPlaylist = false" />
  </template>
  <RouterView v-else />
  <Toasts />
</template>
