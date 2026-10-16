<script setup lang="ts">
import { Camera, Check, Laptop, LogOut, Shield } from 'lucide-vue-next'
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import Avatar from '@/components/Avatar.vue'
import { api, ApiError, request } from '@/lib/api'
import { count, relativeTime } from '@/lib/format'
import { t } from '@/lib/i18n'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'

interface Stats { plays: number; minutes: number; top_artists: { artist: string; plays: number }[]; liked: number; playlists: number }
interface Session { id: number; name: string; last_used_at: string | null; created_at: string; current: boolean }

const auth = useAuth()
const ui = useUi()
const router = useRouter()

const form = reactive({ name: '', username: '', email: '', bio: '' })
const errors = ref<Record<string, string[]>>({})
const saving = ref(false)
const stats = ref<Stats | null>(null)
const sessions = ref<Session[]>([])
const pw = reactive({ current_password: '', password: '', password_confirmation: '' })
const pwError = ref('')

const dirty = computed(() => !!auth.user && (form.name !== auth.user.name || form.username !== auth.user.username || form.email !== (auth.user.email ?? '') || form.bio !== (auth.user.bio ?? '')))

onMounted(async () => {
  Object.assign(form, { name: auth.user?.name ?? '', username: auth.user?.username ?? '', email: auth.user?.email ?? '', bio: auth.user?.bio ?? '' })
  api.get<Stats>('/me/stats').then((s) => (stats.value = s)).catch(() => {})
  api.get<Session[]>('/me/sessions').then((s) => (sessions.value = s)).catch(() => {})
})

async function saveProfile() {
  saving.value = true
  errors.value = {}
  try {
    auth.applySession(await api.patch('/me', { ...form, email: form.email || null, bio: form.bio || null }))
    ui.toast(t('toast.saved'), 'success')
  } catch (e) {
    if (e instanceof ApiError) errors.value = e.errors
  } finally {
    saving.value = false
  }
}

async function uploadAvatar(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return
  const body = new FormData()
  body.append('avatar', file)
  try {
    auth.applySession(await request('POST', '/me/avatar', { body }))
  } catch (err) {
    ui.toast(err instanceof ApiError ? err.first : String(err), 'error')
  }
}

async function removeAvatar() {
  auth.applySession(await api.delete('/me/avatar'))
}

async function changePassword() {
  pwError.value = ''
  try {
    auth.applySession(await api.put('/me/password', pw))
    Object.assign(pw, { current_password: '', password: '', password_confirmation: '' })
    ui.toast(t('profile.passwordChanged'), 'success')
    sessions.value = sessions.value.filter((s) => s.current)
  } catch (e) {
    pwError.value = e instanceof ApiError ? e.first : String(e)
  }
}

async function revoke(s: Session) {
  await api.delete(`/me/sessions/${s.id}`)
  sessions.value = sessions.value.filter((x) => x.id !== s.id)
}

async function logout() {
  await auth.logout()
  router.replace('/login')
}
</script>

<template>
  <div class="dyn-gradient px-4 pb-8 pt-8 sm:px-6 sm:pt-12">
    <div class="flex flex-col items-center gap-5 sm:flex-row sm:items-end">
      <div class="group relative">
        <Avatar :name="auth.user?.name" :src="auth.user?.avatar_url" size="size-32 text-4xl sm:size-40" class="shadow-2xl" />
        <label class="absolute inset-0 flex cursor-pointer items-center justify-center rounded-full bg-black/50 opacity-0 transition group-hover:opacity-100">
          <Camera class="size-8 text-white" />
          <input type="file" accept="image/*" class="hidden" @change="uploadAvatar" />
        </label>
      </div>
      <div class="text-center sm:text-start">
        <div class="text-xs font-bold uppercase tracking-widest text-fg/70">{{ t('profile.kicker') }}</div>
        <h1 class="text-4xl font-black tracking-tight sm:text-6xl">{{ auth.user?.name }}</h1>
        <p class="mt-1 text-muted" dir="ltr">@{{ auth.user?.username }}</p>
        <p v-if="auth.user?.bio" class="mt-2 max-w-lg text-sm text-fg/80">{{ auth.user.bio }}</p>
      </div>
    </div>
    <div v-if="stats" class="mt-6 grid grid-cols-2 gap-2 sm:grid-cols-4">
      <div class="rounded-2xl bg-bg/40 p-4 backdrop-blur"><div class="text-2xl font-black">{{ count(stats.plays) }}</div><div class="text-xs text-muted">{{ t('profile.plays30') }}</div></div>
      <div class="rounded-2xl bg-bg/40 p-4 backdrop-blur"><div class="text-2xl font-black">{{ count(stats.minutes) }}</div><div class="text-xs text-muted">{{ t('profile.minutes30') }}</div></div>
      <div class="rounded-2xl bg-bg/40 p-4 backdrop-blur"><div class="text-2xl font-black">{{ count(stats.liked) }}</div><div class="text-xs text-muted">{{ t('library.liked') }}</div></div>
      <div class="rounded-2xl bg-bg/40 p-4 backdrop-blur"><div class="text-2xl font-black">{{ count(stats.playlists) }}</div><div class="text-xs text-muted">{{ t('library.playlists') }}</div></div>
    </div>
    <div v-if="stats?.top_artists.length" class="mt-4 flex flex-wrap gap-2">
      <span class="text-sm text-muted">{{ t('profile.topArtists') }}:</span>
      <RouterLink v-for="a in stats.top_artists.slice(0, 6)" :key="a.artist" :to="{ name: 'artist', query: { name: a.artist } }" class="chip h-7 px-3 text-xs">{{ a.artist }}</RouterLink>
    </div>
  </div>

  <div class="mx-auto grid max-w-5xl gap-4 px-4 sm:px-6 lg:grid-cols-2">
    <RouterLink v-if="auth.isAdmin" to="/admin" class="card flex items-center gap-3 p-4 transition hover:bg-surface-2 lg:hidden">
      <Shield class="size-6 text-accent" /><span class="font-bold">{{ t('nav.admin') }}</span>
    </RouterLink>

    <!-- Profile -->
    <section class="card p-5 lg:row-span-2">
      <h2 class="mb-4 text-lg font-bold">{{ t('profile.details') }}</h2>
      <form class="space-y-4" @submit.prevent="saveProfile">
        <div><label class="label">{{ t('profile.name') }}</label><input v-model="form.name" class="input" maxlength="60" required />
          <p v-if="errors.name" class="mt-1 text-xs text-danger">{{ errors.name[0] }}</p></div>
        <div><label class="label">{{ t('profile.username') }}</label><input v-model="form.username" class="input" dir="ltr" required />
          <p v-if="errors.username" class="mt-1 text-xs text-danger">{{ errors.username[0] }}</p></div>
        <div><label class="label">{{ t('profile.email') }}</label><input v-model="form.email" type="email" class="input" dir="ltr" />
          <p v-if="errors.email" class="mt-1 text-xs text-danger">{{ errors.email[0] }}</p></div>
        <div><label class="label">{{ t('profile.bio') }}</label><textarea v-model="form.bio" class="input h-24 resize-none py-3" maxlength="300" /></div>
        <div class="flex gap-2">
          <button class="btn-primary flex-1" :disabled="!dirty || saving"><Check class="size-4" />{{ t('common.save') }}</button>
          <button v-if="auth.user?.avatar_url" type="button" class="btn-ghost" @click="removeAvatar">{{ t('profile.removePhoto') }}</button>
        </div>
      </form>
    </section>

    <!-- Password -->
    <section class="card p-5">
      <h2 class="mb-4 text-lg font-bold">{{ t('profile.changePassword') }}</h2>
      <form class="space-y-3" @submit.prevent="changePassword">
        <input v-model="pw.current_password" type="password" class="input" dir="ltr" :placeholder="t('profile.currentPassword')" autocomplete="current-password" required />
        <input v-model="pw.password" type="password" class="input" dir="ltr" :placeholder="t('profile.newPassword')" autocomplete="new-password" minlength="8" required />
        <input v-model="pw.password_confirmation" type="password" class="input" dir="ltr" :placeholder="t('profile.confirmPassword')" autocomplete="new-password" required />
        <p v-if="pwError" class="text-sm text-danger">{{ pwError }}</p>
        <button class="btn-ghost w-full">{{ t('profile.changePassword') }}</button>
      </form>
    </section>

    <!-- Sessions -->
    <section class="card p-5">
      <h2 class="mb-3 text-lg font-bold">{{ t('profile.sessions') }}</h2>
      <div class="space-y-1">
        <div v-for="s in sessions" :key="s.id" class="flex items-center gap-3 rounded-xl p-2">
          <Laptop class="size-5 text-muted" />
          <div class="min-w-0 flex-1">
            <div class="truncate text-sm font-semibold" dir="auto">{{ s.name }}</div>
            <div class="text-xs text-subtle">{{ s.current ? t('profile.thisDevice') : relativeTime(s.last_used_at ?? s.created_at) }}</div>
          </div>
          <button v-if="!s.current" class="btn-ghost h-8 px-3 text-xs" @click="revoke(s)">{{ t('profile.signOut') }}</button>
        </div>
      </div>
    </section>

    <button class="btn-danger h-12 w-full lg:col-span-2" @click="logout"><LogOut class="size-5" />{{ t('auth.logout') }}</button>
  </div>
</template>
