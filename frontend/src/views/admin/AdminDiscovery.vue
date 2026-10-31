<script setup lang="ts">
import { AlertTriangle, CircleCheck, Loader2, LogOut, Play, Plus, Radar, Send, X } from 'lucide-vue-next'
import { computed, onMounted, ref } from 'vue'
import { api, ApiError } from '@/lib/api'
import { relativeTime } from '@/lib/format'
import { t } from '@/lib/i18n'
import { useUi } from '@/stores/ui'

/**
 * The daily discovery: a Telegram account reads public music channels each morning and brings in this
 * week's songs by the artists members follow or play most.
 */
interface Status {
  account_status: 'unconfigured' | 'logged_out' | 'waiting_code' | 'waiting_password' | 'waiting_signup' | 'logged_in' | 'error'
  account: { name: string; username: string | null; phone: string | null } | null
  error: string | null
  channels: string[]
  days: number
  max_per_run: number
  artists: string[]
  last_run: { status: string; at: string; checked: number; matched: number; duplicates: number; failed: number; added: { id: number; title: string; artist: string | null; channel: string }[] } | null
}

const ui = useUi()
const status = ref<Status | null>(null)
const busy = ref(false)
const error = ref<string | null>(null)
const phone = ref('')
const code = ref('')
const password = ref('')
const newChannel = ref('')
const running = ref(false)
const step = computed(() => status.value?.account_status)

async function load() {
  status.value = await api.get<Status>('/admin/discovery')
}
onMounted(load)

async function attempt(action: () => Promise<unknown>) {
  busy.value = true
  error.value = null
  try {
    await action()
  } catch (e) {
    error.value = e instanceof ApiError ? e.first : String(e)
  } finally {
    busy.value = false
    await load().catch(() => {})
  }
}

const sendPhone = () => attempt(() => api.post('/admin/telegram/phone', { phone: phone.value }))
const sendCode = () => attempt(() => api.post('/admin/telegram/code', { code: code.value }))
const sendPassword = () => attempt(() => api.post('/admin/telegram/password', { password: password.value }))
const logout = () => confirm(t('discovery.logoutConfirm')) && attempt(() => api.post('/admin/telegram/logout'))

async function saveChannels(channels: string[]) {
  status.value = await api.put<Status>('/admin/discovery/channels', { channels })
}
function addChannel() {
  if (!status.value || !newChannel.value.trim()) return
  saveChannels([...status.value.channels, newChannel.value.trim()])
  newChannel.value = ''
}

async function run() {
  running.value = true
  await api.post('/admin/discovery/run')
  ui.toast(t('discovery.started'), 'success')
  // It runs in the background; check back for the result.
  const before = status.value?.last_run?.at
  for (let i = 0; i < 60; i++) {
    await new Promise((r) => setTimeout(r, 5000))
    await load().catch(() => {})
    if (status.value?.last_run?.at !== before) break
  }
  running.value = false
}
</script>

<template>
  <div v-if="!status" class="flex justify-center py-16"><Loader2 class="size-8 animate-spin text-muted" /></div>
  <div v-else class="space-y-4">
    <section class="card p-5">
      <div class="flex items-start gap-4">
        <span class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-accent/15 text-accent"><Radar class="size-6" /></span>
        <div>
          <h2 class="text-lg font-bold">{{ t('discovery.title') }}</h2>
          <p class="text-sm text-muted">{{ t('discovery.hint', { days: status.days, n: status.max_per_run }) }}</p>
        </div>
      </div>
    </section>

    <!-- The Telegram account it reads with -->
    <section class="card p-5">
      <h3 class="mb-1 font-bold">{{ t('discovery.account') }}</h3>
      <p v-if="error || status.error" class="my-3 flex items-start gap-2 rounded-xl bg-danger/10 p-3 text-sm text-danger" dir="auto"><AlertTriangle class="mt-0.5 size-4 shrink-0" />{{ error ?? status.error }}</p>
      <div v-if="step === 'logged_in' && status.account" class="mt-3 flex items-center gap-3">
        <CircleCheck class="size-6 text-success" />
        <div class="min-w-0 flex-1">
          <div class="font-semibold">{{ status.account.name }}</div>
          <div class="text-sm text-muted" dir="ltr">{{ status.account.username ? '@' + status.account.username : status.account.phone }}</div>
        </div>
        <button class="btn-ghost h-9 text-xs" :disabled="busy" @click="logout"><LogOut class="size-4" />{{ t('discovery.logout') }}</button>
      </div>
      <template v-else>
        <p class="mb-3 text-sm text-muted">{{ t('discovery.accountHint') }}</p>
        <form v-if="step === 'logged_out' || step === 'unconfigured' || step === 'error'" class="flex gap-2" @submit.prevent="sendPhone">
          <input v-model="phone" class="input" dir="ltr" type="tel" placeholder="+98 912 345 6789" required />
          <button class="btn-primary h-12 shrink-0" :disabled="busy"><Loader2 v-if="busy" class="size-4 animate-spin" />{{ t('telegram.sendCode') }}</button>
        </form>
        <form v-else-if="step === 'waiting_code'" class="flex gap-2" @submit.prevent="sendCode">
          <input v-model="code" class="input text-center text-xl tracking-[0.5em]" dir="ltr" inputmode="numeric" autocomplete="one-time-code" :placeholder="t('telegram.code')" required />
          <button class="btn-primary h-12 shrink-0" :disabled="busy"><Loader2 v-if="busy" class="size-4 animate-spin" />{{ t('telegram.verify') }}</button>
        </form>
        <form v-else-if="step === 'waiting_password'" class="flex gap-2" @submit.prevent="sendPassword">
          <input v-model="password" class="input" dir="ltr" type="password" :placeholder="t('telegram.password2fa')" required />
          <button class="btn-primary h-12 shrink-0" :disabled="busy"><Loader2 v-if="busy" class="size-4 animate-spin" />{{ t('auth.login') }}</button>
        </form>
        <p v-if="step === 'waiting_code'" class="mt-2 text-xs text-subtle">{{ t('telegram.codeHint') }}</p>
      </template>
    </section>

    <!-- Channels -->
    <section class="card p-5">
      <h3 class="mb-3 font-bold">{{ t('discovery.channels') }}</h3>
      <ul class="mb-3 flex flex-wrap gap-2">
        <li v-for="c in status.channels" :key="c" class="chip h-9 pe-1.5">
          <Send class="size-3.5" /><span dir="ltr">@{{ c }}</span>
          <button class="icon-btn size-7" :aria-label="t('common.remove')" @click="saveChannels(status.channels.filter((x) => x !== c))"><X class="size-3.5" /></button>
        </li>
      </ul>
      <form class="flex gap-2" @submit.prevent="addChannel">
        <input v-model="newChannel" class="input" dir="ltr" placeholder="@channel · https://t.me/…" />
        <button class="btn-ghost h-12 shrink-0"><Plus class="size-4" />{{ t('discovery.add') }}</button>
      </form>
      <p class="mt-4 text-xs text-subtle">{{ t('discovery.artists', { n: status.artists.length }) }}: {{ status.artists.slice(0, 25).join('، ') }}{{ status.artists.length > 25 ? '…' : '' }}</p>
    </section>

    <!-- Last run -->
    <section class="card p-5">
      <div class="mb-3 flex items-center gap-3">
        <h3 class="flex-1 font-bold">{{ t('discovery.lastRun') }}</h3>
        <button class="btn-primary h-10 text-sm" :disabled="running || step !== 'logged_in'" @click="run">
          <Loader2 v-if="running" class="size-4 animate-spin" /><Play v-else class="size-4 fill-current" />{{ running ? t('discovery.running') : t('discovery.runNow') }}
        </button>
      </div>
      <template v-if="status.last_run">
        <p class="text-sm text-muted">
          {{ relativeTime(status.last_run.at) }} ·
          <template v-if="status.last_run.status === 'no_account'">{{ t('discovery.noAccount') }}</template>
          <template v-else>{{ t('discovery.summary', { checked: status.last_run.checked, matched: status.last_run.matched, added: status.last_run.added.length, duplicates: status.last_run.duplicates }) }}</template>
        </p>
        <ul v-if="status.last_run.added.length" class="mt-3 space-y-1 text-sm">
          <li v-for="a in status.last_run.added" :key="a.id" class="flex gap-2">
            <span class="text-success">+</span><span class="min-w-0 flex-1 truncate">{{ a.artist }} – {{ a.title }}</span><span class="text-subtle" dir="ltr">@{{ a.channel }}</span>
          </li>
        </ul>
      </template>
      <p v-else class="text-sm text-subtle">{{ t('discovery.never') }}</p>
    </section>
  </div>
</template>
