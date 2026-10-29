<script setup lang="ts">
import { onKeyStroke } from '@vueuse/core'
import { BarChart3, ChevronRight, Download, HardDrive, Heart, History, LogOut, Settings, Shield, UsersRound } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Avatar from '@/components/Avatar.vue'
import { apiBase, isNative } from '@/lib/config'
import { t } from '@/lib/i18n'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'

const ui = useUi()
const auth = useAuth()
const route = useRoute()
const router = useRouter()
const version = __APP_VERSION__
const buildDate = __BUILD_DATE__

const items = computed(() => [
  { to: '/jam', icon: UsersRound, label: t('jam.title') },
  { to: '/library/liked', icon: Heart, label: t('library.liked') },
  { to: '/library/downloads', icon: Download, label: t('library.downloads') },
  { to: '/library/device', icon: HardDrive, label: t('library.device') },
  { to: '/library/history', icon: History, label: t('library.history') },
  { to: '/settings', icon: Settings, label: t('settings.title') },
  // Admins only: the reports, and the rest of the admin area.
  ...(auth.isAdmin
    ? [
        { to: '/admin/reports', icon: BarChart3, label: t('reports.title') },
        { to: '/admin', icon: Shield, label: t('nav.admin') },
      ]
    : []),
])

// Compare this copy of the app with the version the server has now (version.json is never cached).
const latest = ref<string | null>(null)
const isNewer = (a: string, b: string) => {
  const [x, y] = [a, b].map((v) => v.split('.').map(Number))
  for (let i = 0; i < 3; i++) if ((x[i] ?? 0) !== (y[i] ?? 0)) return (x[i] ?? 0) > (y[i] ?? 0)
  return false
}
const updateAvailable = computed(() => !!latest.value && isNewer(latest.value, version))
watch(() => ui.drawerOpen, async (open) => {
  if (!open || !navigator.onLine) return
  try {
    const res = await fetch(`${apiBase()}/version.json`, { cache: 'no-store' })
    if (res.ok) latest.value = (await res.json()).version ?? null
  } catch {
    // Offline or blocked: show no badge.
  }
})
/** The Android app updates by installing the newest APK from GitHub; the web app by reloading. */
const APK_URL = 'https://github.com/hosseinganji/harmuny/releases/latest/download/harmony.apk'
function update() {
  if (isNative) location.href = APK_URL
  else location.reload()
}

// Reports live under /admin but have their own entry, so Admin is not lit up there too.
const isActive = (to: string) =>
  to === '/admin' ? route.path.startsWith('/admin') && !route.path.startsWith('/admin/reports') : route.path.startsWith(to)

const close = () => (ui.drawerOpen = false)
watch(() => route.fullPath, close)
onKeyStroke('Escape', () => ui.drawerOpen && close())

async function logout() {
  close()
  await auth.logout()
  router.replace('/login')
}
</script>

<template>
  <Teleport to="body">
    <Transition name="fade">
      <div v-if="ui.drawerOpen" class="fixed inset-0 z-[65] bg-black/50" @click="close" />
    </Transition>
    <Transition name="drawer">
      <aside
        v-if="ui.drawerOpen"
        class="liquid liquid-strong fixed inset-y-0 start-0 z-[66] flex w-[min(82vw,340px)] flex-col rounded-e-[2rem]"
        style="--lg-tint: color-mix(in oklab, var(--elevated) 94%, transparent)"
        role="dialog"
        aria-modal="true"
      >
        <!-- Profile: opens the profile settings -->
        <RouterLink to="/profile" class="m-3 mt-[calc(var(--safe-top)+0.75rem)] flex items-center gap-3 rounded-3xl p-3 transition hover:bg-fg/5 active:scale-[0.98]">
          <Avatar :name="auth.user?.name" :src="auth.user?.avatar_url" size="size-14 text-lg" />
          <span class="min-w-0 flex-1">
            <span class="block truncate text-lg font-bold">{{ auth.user?.name }}</span>
            <span class="block truncate text-sm text-muted">{{ t('drawer.viewProfile') }}</span>
          </span>
          <ChevronRight class="size-5 text-subtle rtl:rotate-180" />
        </RouterLink>

        <div class="mx-6 h-px bg-line" />

        <nav class="flex-1 space-y-0.5 overflow-y-auto p-3">
          <RouterLink
            v-for="item in items"
            :key="item.to"
            :to="item.to"
            class="flex items-center gap-4 rounded-2xl px-3 py-3 font-semibold transition hover:bg-fg/5 active:scale-[0.98]"
            :class="isActive(item.to) ? 'liquid-active text-accent' : ''"
          >
            <component :is="item.icon" class="size-5" />
            {{ item.label }}
          </RouterLink>
        </nav>

        <!-- App info, pinned to the bottom -->
        <footer class="space-y-3 border-t border-line p-5 pb-[calc(var(--safe-bottom)+1rem)] text-xs text-subtle">
          <button class="flex items-center gap-2 font-semibold text-danger" @click="logout"><LogOut class="size-4" />{{ t('auth.logout') }}</button>
          <div class="flex items-center gap-3">
            <img src="/logo.png" class="size-9 rounded-[22%]" alt="" />
            <div class="min-w-0">
              <div class="font-bold text-fg">{{ t('app.name') }}</div>
              <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                <span>{{ t('drawer.version', { v: version }) }} · {{ buildDate }}</span>
                <span v-if="latest && !updateAvailable" class="rounded-full bg-success/15 px-2 py-0.5 text-[10px] font-bold text-success">{{ t('drawer.latest') }}</span>
                <button v-else-if="updateAvailable" class="rounded-full bg-accent px-2 py-0.5 text-[10px] font-bold text-white" @click="update">{{ t('drawer.update', { v: latest! }) }}</button>
              </div>
            </div>
          </div>
        </footer>
      </aside>
    </Transition>
  </Teleport>
</template>
