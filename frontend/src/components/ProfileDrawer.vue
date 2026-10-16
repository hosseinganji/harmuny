<script setup lang="ts">
import { onKeyStroke } from '@vueuse/core'
import { ChevronRight, Download, HardDrive, Heart, History, LogOut, Settings, Shield } from 'lucide-vue-next'
import { computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Avatar from '@/components/Avatar.vue'
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
  { to: '/library/liked', icon: Heart, label: t('library.liked') },
  { to: '/library/downloads', icon: Download, label: t('library.downloads') },
  { to: '/library/device', icon: HardDrive, label: t('library.device') },
  { to: '/library/history', icon: History, label: t('library.history') },
  { to: '/settings', icon: Settings, label: t('settings.title') },
  ...(auth.isAdmin ? [{ to: '/admin', icon: Shield, label: t('nav.admin') }] : []),
])

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
            :class="route.path.startsWith(item.to) ? 'liquid-active text-accent' : ''"
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
              <div dir="ltr" class="text-start">{{ t('drawer.version', { v: version }) }} · {{ buildDate }}</div>
            </div>
          </div>
        </footer>
      </aside>
    </Transition>
  </Teleport>
</template>
