<script setup lang="ts">
import { AlertTriangle, Bot, CheckCircle2, ExternalLink, Loader2, LogOut, Plus, RefreshCw, Send, Trash2, UserRound } from 'lucide-vue-next'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import Modal from '@/components/Modal.vue'
import { api, ApiError } from '@/lib/api'
import { num, relativeTime } from '@/lib/format'
import { t } from '@/lib/i18n'
import { useLibrary } from '@/stores/library'
import { useUi } from '@/stores/ui'

interface Status {
  mode: 'bot' | 'user'
  status: 'unconfigured' | 'logged_out' | 'waiting_code' | 'waiting_password' | 'waiting_signup' | 'logged_in' | 'error' | 'bot_token' | 'bot_start'
  api_id: number | null
  bot?: { id: number; username: string | null; name: string } | null
  max_file_mb?: number
  account: { name: string; username: string | null; phone: string | null } | null
  error: string | null
}
interface Channel {
  id: number
  peer: string
  title: string | null
  username: string | null
  kind: 'channel' | 'supergroup' | 'group'
  enabled: boolean
  sync_status: 'idle' | 'queued' | 'syncing' | 'error'
  sync_error: string | null
  sync_progress: number
  skipped_count: number
  last_synced_at: string | null
  tracks_count: number
}
interface Dialog { id: number; title: string; username: string | null; broadcast: boolean }

const ui = useUi()
const library = useLibrary()
const status = ref<Status | null>(null)
const channels = ref<Channel[]>([])
const busy = ref(false)
const error = ref('')

const creds = ref({ api_id: '', api_hash: '' })
const phone = ref('')
const code = ref('')
const password = ref('')
const newPeer = ref('')
const botToken = ref('')
const picking = ref(false)
const dialogs = ref<Dialog[] | null>(null)

const step = computed(() => status.value?.status ?? 'unconfigured')
const isBot = computed(() => status.value?.mode === 'bot')
const anySyncing = computed(() => channels.value.some((c) => c.sync_status === 'syncing' || c.sync_status === 'queued'))

async function loadStatus() {
  status.value = await api.get<Status>('/admin/telegram')
  if (status.value.status === 'logged_in') loadChannels()
}
async function loadChannels() {
  const was = anySyncing.value
  channels.value = (await api.get<{ data: Channel[] }>('/admin/channels')).data
  if (was && !anySyncing.value) library.sync()
}

async function attempt(fn: () => Promise<Status | unknown>) {
  busy.value = true
  error.value = ''
  try {
    const res = await fn()
    if (res && typeof res === 'object' && 'status' in res && typeof (res as Status).status === 'string' && 'api_id' in res) {
      status.value = res as Status
      if (status.value.status === 'logged_in') loadChannels()
    }
  } catch (e) {
    error.value = e instanceof ApiError ? e.first : String(e)
  } finally {
    busy.value = false
  }
}

const saveCreds = () => attempt(() => api.post('/admin/telegram/credentials', { api_id: Number(creds.value.api_id), api_hash: creds.value.api_hash.trim() }))
const sendPhone = () => attempt(() => api.post('/admin/telegram/phone', { phone: phone.value }))
const sendCode = () => attempt(() => api.post('/admin/telegram/code', { code: code.value }))
const sendPassword = () => attempt(() => api.post('/admin/telegram/password', { password: password.value }))
async function logout() {
  if (!confirm(t('telegram.logoutConfirm'))) return
  await attempt(() => api.post(isBot.value ? '/admin/telegram/bot/disconnect' : '/admin/telegram/logout'))
}
function resetCreds() {
  status.value = { mode: 'user', status: 'unconfigured', api_id: null, account: null, error: null }
}

const setMode = (mode: 'bot' | 'user') => attempt(() => api.post('/admin/telegram/mode', { mode }))
const saveBot = () => attempt(() => api.post('/admin/telegram/bot', { token: botToken.value.trim() }))
const checkStart = () => attempt(() => api.post('/admin/telegram/bot/check'))

async function addChannel(peer: string) {
  busy.value = true
  error.value = ''
  try {
    await api.post('/admin/channels', { peer })
    newPeer.value = ''
    picking.value = false
    ui.toast(t('telegram.channelAdded'), 'success')
    loadChannels()
  } catch (e) {
    error.value = e instanceof ApiError ? e.first : String(e)
  } finally {
    busy.value = false
  }
}

async function openPicker() {
  picking.value = true
  dialogs.value = null
  try {
    dialogs.value = (await api.get<{ data: Dialog[] }>('/admin/telegram/dialogs')).data
  } catch (e) {
    error.value = e instanceof ApiError ? e.first : String(e)
    picking.value = false
  }
}

async function sync(c: Channel, full: boolean) {
  await api.post(`/admin/channels/${c.id}/sync`, { full })
  loadChannels()
}
async function toggle(c: Channel) {
  await api.patch(`/admin/channels/${c.id}`, { enabled: !c.enabled })
  loadChannels()
}
async function remove(c: Channel) {
  if (!confirm(t('telegram.removeConfirm', { name: c.title ?? c.peer }))) return
  const deleteTracks = confirm(t('telegram.removeTracksConfirm'))
  await api.delete(`/admin/channels/${c.id}`, { delete_tracks: deleteTracks ? 1 : 0 })
  loadChannels()
  if (deleteTracks) library.sync()
}

let timer: ReturnType<typeof setInterval>
onMounted(() => {
  loadStatus().catch((e) => (error.value = String(e)))
  timer = setInterval(() => {
    if (anySyncing.value) loadChannels()
    // Notice the /start as soon as it arrives.
    if (step.value === 'bot_start' && !busy.value) api.post<Status>('/admin/telegram/bot/check').then((s) => {
      status.value = s
      error.value = ''
      if (s.status === 'logged_in') loadChannels()
    }).catch((e) => (error.value = e instanceof ApiError ? e.first : String(e)))
  }, 3000)
})
onBeforeUnmount(() => clearInterval(timer))

const statusBadge: Record<Channel['sync_status'], string> = {
  idle: 'bg-success/15 text-success',
  queued: 'bg-sky-500/15 text-sky-400',
  syncing: 'bg-accent/15 text-accent',
  error: 'bg-danger/15 text-danger',
}
</script>

<template>
  <div v-if="!status" class="flex justify-center py-16"><Loader2 class="size-8 animate-spin text-muted" /></div>

  <template v-else>
    <p v-if="error" class="mb-4 flex items-start gap-2 rounded-xl bg-danger/10 p-3 text-sm text-danger"><AlertTriangle class="mt-0.5 size-4 shrink-0" />{{ error }}</p>

    <!-- How to connect -->
    <div v-if="step !== 'logged_in'" class="mb-4 grid gap-3 sm:grid-cols-2">
      <button
        v-for="m in (['bot', 'user'] as const)"
        :key="m"
        class="card flex items-start gap-3 p-4 text-start transition"
        :class="status.mode === m ? 'border-accent ring-1 ring-accent' : 'hover:bg-surface-2'"
        :disabled="busy"
        @click="status.mode !== m && setMode(m)"
      >
        <component :is="m === 'bot' ? Bot : UserRound" class="mt-0.5 size-6 shrink-0 text-accent" />
        <div>
          <div class="font-bold">{{ t('telegram.mode.' + m) }}</div>
          <div class="text-sm text-muted">{{ t('telegram.mode.' + m + 'Hint') }}</div>
        </div>
      </button>
    </div>

    <!-- Bot 1. Token from @BotFather -->
    <section v-if="isBot && step === 'bot_token'" class="card p-5">
      <div class="mb-4 flex items-center gap-3">
        <span class="flex size-10 items-center justify-center rounded-full bg-sky-500/15 text-sky-400"><Bot class="size-5" /></span>
        <div><h2 class="font-bold">{{ t('telegram.bot.step1') }}</h2><p class="text-sm text-muted">{{ t('telegram.bot.step1Hint') }}</p></div>
      </div>
      <ol class="mb-4 list-decimal space-y-1 ps-5 text-sm text-muted">
        <li>{{ t('telegram.bot.howto1') }} <a href="https://t.me/BotFather" target="_blank" rel="noopener" class="inline-flex items-center gap-1 font-semibold text-accent">@BotFather <ExternalLink class="size-3" /></a> {{ t('telegram.bot.howto1b') }}</li>
        <li>{{ t('telegram.bot.howto2') }}</li>
        <li>{{ t('telegram.bot.howto3') }}</li>
      </ol>
      <form class="flex gap-2" @submit.prevent="saveBot">
        <input v-model="botToken" class="input font-mono text-sm" placeholder="123456789:AAH…" required autocomplete="off" />
        <button class="btn-primary h-12 shrink-0" :disabled="busy"><Loader2 v-if="busy" class="size-4 animate-spin" />{{ t('common.save') }}</button>
      </form>
    </section>

    <!-- Bot 2. /start so the bot has a private scratch chat -->
    <section v-else-if="isBot && step === 'bot_start'" class="card p-5">
      <div class="mb-4 flex items-center gap-3">
        <span class="flex size-10 items-center justify-center rounded-full bg-sky-500/15 text-sky-400"><Send class="size-5" /></span>
        <div class="flex-1"><h2 class="font-bold">{{ t('telegram.bot.step2', { bot: '@' + (status.bot?.username ?? '') }) }}</h2><p class="text-sm text-muted">{{ t('telegram.bot.step2Hint') }}</p></div>
        <button class="text-xs text-subtle hover:text-fg" @click="logout">{{ t('telegram.bot.changeBot') }}</button>
      </div>
      <div class="flex flex-wrap items-center gap-3">
        <a :href="`https://t.me/${status.bot?.username}?start=connect`" target="_blank" rel="noopener" class="btn-primary"><Send class="size-4" />{{ t('telegram.bot.openBot') }}</a>
        <button class="btn-ghost" :disabled="busy" @click="checkStart"><Loader2 v-if="busy" class="size-4 animate-spin" />{{ t('telegram.bot.check') }}</button>
        <span class="flex items-center gap-2 text-xs text-subtle"><Loader2 class="size-3.5 animate-spin" />{{ t('telegram.bot.waiting') }}</span>
      </div>
    </section>

    <!-- 1. API credentials -->
    <section v-else-if="!isBot && (step === 'unconfigured' || step === 'error')" class="card p-5">
      <div class="mb-4 flex items-center gap-3">
        <span class="flex size-10 items-center justify-center rounded-full bg-sky-500/15 text-sky-400"><Send class="size-5" /></span>
        <div><h2 class="font-bold">{{ t('telegram.step1') }}</h2><p class="text-sm text-muted">{{ t('telegram.step1Hint') }}</p></div>
      </div>
      <p v-if="status.error" class="mb-3 text-sm text-danger" dir="ltr">{{ status.error }}</p>
      <ol class="mb-4 list-decimal space-y-1 ps-5 text-sm text-muted">
        <li>{{ t('telegram.howto1') }} <a href="https://my.telegram.org/apps" target="_blank" rel="noopener" class="inline-flex items-center gap-1 font-semibold text-accent">my.telegram.org <ExternalLink class="size-3" /></a></li>
        <li>{{ t('telegram.howto2') }}</li>
        <li>{{ t('telegram.howto3') }}</li>
      </ol>
      <form class="grid gap-3 sm:grid-cols-[1fr_2fr_auto]" @submit.prevent="saveCreds">
        <input v-model="creds.api_id" class="input" dir="ltr" inputmode="numeric" placeholder="api_id" required />
        <input v-model="creds.api_hash" class="input" dir="ltr" placeholder="api_hash" required minlength="32" maxlength="32" />
        <button class="btn-primary h-12" :disabled="busy"><Loader2 v-if="busy" class="size-4 animate-spin" />{{ t('common.save') }}</button>
      </form>
    </section>

    <!-- 2. Log in with the account that can see the channel -->
    <section v-else-if="!isBot && step !== 'logged_in'" class="card p-5">
      <div class="mb-4 flex items-center gap-3">
        <span class="flex size-10 items-center justify-center rounded-full bg-sky-500/15 text-sky-400"><Send class="size-5" /></span>
        <div class="flex-1"><h2 class="font-bold">{{ t('telegram.step2') }}</h2><p class="text-sm text-muted">{{ t('telegram.step2Hint') }}</p></div>
        <button class="text-xs text-subtle hover:text-fg" @click="resetCreds">{{ t('telegram.changeCreds') }}</button>
      </div>
      <form v-if="step === 'logged_out'" class="flex gap-2" @submit.prevent="sendPhone">
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
      <p v-else class="text-sm text-danger">{{ t('telegram.needsSignup') }}</p>
      <p v-if="step === 'waiting_code'" class="mt-2 text-xs text-subtle">{{ t('telegram.codeHint') }}</p>
    </section>

    <!-- 3. Connected: manage channels -->
    <template v-else>
      <section class="card flex items-center gap-3 p-4">
        <CheckCircle2 class="size-6 text-success" />
        <div class="min-w-0 flex-1">
          <div class="font-bold">{{ isBot ? t('telegram.bot.connected', { bot: '@' + (status.bot?.username ?? '') }) : t('telegram.connectedAs', { name: status.account?.name ?? '' }) }}</div>
          <div class="text-sm text-muted" dir="ltr">{{ status.account?.username ? '@' + status.account.username : '' }} {{ status.account?.phone ? '+' + status.account.phone : '' }}</div>
        </div>
        <button class="btn-ghost h-9 px-3" @click="logout"><LogOut class="size-4" /><span class="hidden sm:inline">{{ t('auth.logout') }}</span></button>
      </section>

      <section class="card mt-4 p-5">
        <h2 class="mb-1 font-bold">{{ t('telegram.channels') }}</h2>
        <p class="mb-4 text-sm text-muted">{{ t('telegram.channelsHint') }}</p>
        <p v-if="isBot" class="mb-4 rounded-xl bg-accent/10 p-3 text-sm">{{ t('telegram.bot.addAdminHint', { bot: '@' + (status.bot?.username ?? ''), mb: status.max_file_mb ?? 20 }) }}</p>
        <form class="flex flex-wrap gap-2" @submit.prevent="addChannel(newPeer)">
          <input v-model="newPeer" class="input min-w-48 flex-1" dir="ltr" placeholder="@channel · @group · https://t.me/… · -100…" required />
          <button class="btn-primary h-12" :disabled="busy"><Plus class="size-4" />{{ t('telegram.add') }}</button>
          <button type="button" class="btn-ghost h-12" @click="openPicker">{{ isBot ? t('telegram.bot.pick') : t('telegram.pick') }}</button>
        </form>

        <div class="mt-5 space-y-3">
          <div v-for="c in channels" :key="c.id" class="rounded-2xl border border-line p-4" :class="{ 'opacity-60': !c.enabled }">
            <div class="flex flex-wrap items-center gap-3">
              <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2">
                  <span class="truncate font-bold">{{ c.title ?? c.peer }}</span>
                  <span v-if="c.kind && c.kind !== 'channel'" class="rounded-full bg-sky-500/15 px-2 py-0.5 text-[10px] font-bold text-sky-400">{{ t('telegram.group') }}</span>
                  <span class="rounded-full px-2 py-0.5 text-[10px] font-bold" :class="statusBadge[c.sync_status]">{{ t('telegram.status.' + c.sync_status) }}</span>
                </div>
                <div class="text-xs text-subtle">
                  <span v-if="c.username" dir="ltr">@{{ c.username }} · </span>
                  {{ t('playlist.songs', { n: c.tracks_count }) }}
                  <template v-if="c.last_synced_at"> · {{ t('telegram.lastSync', { when: relativeTime(c.last_synced_at) }) }}</template>
                  <template v-if="c.skipped_count"> · <span class="text-amber-400">{{ t('telegram.bot.skipped', { n: c.skipped_count, mb: status.max_file_mb ?? 20 }) }}</span></template>
                </div>
              </div>
              <div class="flex gap-1">
                <button class="btn-ghost h-9 px-3 text-xs" :disabled="c.sync_status === 'syncing'" @click="sync(c, false)">
                  <RefreshCw class="size-4" :class="{ 'animate-spin': c.sync_status === 'syncing' }" />{{ t('telegram.sync') }}
                </button>
                <button class="btn-ghost h-9 px-3 text-xs" :disabled="c.sync_status === 'syncing'" :title="t('telegram.fullSyncHint')" @click="sync(c, true)">{{ t('telegram.fullSync') }}</button>
                <label class="flex items-center px-2" :title="t('telegram.enabled')"><input type="checkbox" class="size-4 accent-[var(--accent)]" :checked="c.enabled" @change="toggle(c)" /></label>
                <button class="icon-btn size-9 hover:!text-danger" :title="t('common.delete')" @click="remove(c)"><Trash2 class="size-4" /></button>
              </div>
            </div>
            <div v-if="c.sync_status === 'syncing'" class="mt-2 text-xs text-accent">{{ t('telegram.syncing', { n: c.sync_progress }) }}</div>
            <p v-if="c.sync_status === 'error' && c.sync_error" class="mt-2 text-xs text-danger" dir="ltr">{{ c.sync_error }}</p>
          </div>
          <p v-if="!channels.length" class="py-4 text-center text-sm text-subtle">{{ t('telegram.noChannels') }}</p>
        </div>
        <p class="mt-4 text-xs text-subtle">{{ isBot ? t('telegram.bot.autoSyncHint') : t('telegram.autoSyncHint') }}</p>
      </section>
    </template>
  </template>

  <Modal :open="picking" :title="isBot ? t('telegram.bot.pick') : t('telegram.pick')" @close="picking = false">
    <div v-if="!dialogs" class="flex justify-center py-8"><Loader2 class="size-6 animate-spin text-muted" /></div>
    <div v-else class="space-y-1">
      <button
        v-for="d in dialogs"
        :key="d.id"
        class="flex w-full items-center gap-3 rounded-xl p-3 text-start hover:bg-fg/5"
        :disabled="busy"
        @click="addChannel(String(d.id))"
      >
        <span class="flex size-10 items-center justify-center rounded-full bg-sky-500/15 font-bold text-sky-400">{{ d.title.charAt(0) }}</span>
        <div class="min-w-0 flex-1">
          <div class="truncate font-semibold">{{ d.title }}</div>
          <div class="text-xs text-subtle" dir="ltr">{{ d.username ? '@' + d.username : num(d.id) }}</div>
        </div>
        <span v-if="!d.broadcast" class="text-xs text-subtle">{{ t('telegram.group') }}</span>
      </button>
      <p v-if="!dialogs.length" class="py-6 text-center text-sm text-subtle">{{ isBot ? t('telegram.bot.noChats') : t('telegram.noDialogs') }}</p>
    </div>
  </Modal>
</template>
