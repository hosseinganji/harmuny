<script setup lang="ts">
import {
  Crown, GripVertical, Headphones, ListPlus, Loader2, LogOut, Pause, Play, Power, Shuffle, SkipBack, SkipForward,
  SlidersHorizontal, Sparkles, UserPlus, UsersRound, VolumeX, X,
} from 'lucide-vue-next'
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import Draggable from 'vuedraggable'
import Avatar from '@/components/Avatar.vue'
import JamInviteDialog from '@/components/JamInviteDialog.vue'
import JamReactionBar from '@/components/JamReactionBar.vue'
import PlayingBars from '@/components/PlayingBars.vue'
import SeekBar from '@/components/SeekBar.vue'
import SwitchRow from '@/components/SwitchRow.vue'
import TrackCover from '@/components/TrackCover.vue'
import { api, ApiError } from '@/lib/api'
import { duration } from '@/lib/format'
import { locale, t } from '@/lib/i18n'
import { useAuth } from '@/stores/auth'
import { type JamMember, type JamResponse, useJam } from '@/stores/jam'
import { useLibrary } from '@/stores/library'
import { type QueueItem, usePlayer } from '@/stores/player'
import { useUi } from '@/stores/ui'

/** The Jam page: the Jam this member is in, an invitation to join one (/jam/:code), or how to start one. */
const props = defineProps<{ code?: string }>()
const jam = useJam()
const player = usePlayer()
const auth = useAuth()
const ui = useUi()
const router = useRouter()
const library = useLibrary()
const me = computed(() => auth.user?.id ?? 0)

const mine = computed(() => jam.active && (!props.code || props.code === jam.state?.code))

// ---- an invitation ----

const invitation = ref<JamResponse | null>(null)
const invalid = ref(false)
const joining = ref<'sound' | 'silent' | null>(null)
const inviteTrack = computed(() => {
  const s = invitation.value?.data
  const entry = s?.queue.find((e) => e.uid === s.current_uid)
  return entry ? (library.get(entry.track_id) ?? null) : null
})
const inviteHost = computed(() => invitation.value?.data.members.find((m) => m.id === invitation.value?.data.host_id) ?? null)

watch(() => [props.code, mine.value] as const, async ([code, isMine]) => {
  invitation.value = null
  invalid.value = false
  if (!code || isMine) return
  try {
    const res = await jam.preview(code)
    if (res.data.ended) invalid.value = true
    else invitation.value = res
  } catch {
    invalid.value = true
  }
}, { immediate: true })

async function join(sound: boolean) {
  if (!props.code) return
  joining.value = sound ? 'sound' : 'silent'
  try {
    await jam.join(props.code, sound)
    router.replace('/jam')
  } catch (e) {
    ui.toast(e instanceof ApiError && e.status ? e.first : t('jam.offline'), 'error')
  } finally {
    joining.value = null
  }
}

// ---- starting one ----

interface OnlineUser { id: number; name: string; avatar_url: string | null; jam: { code: string; host_name: string } | null }
const starting = ref(false)
const happening = ref<{ code: string; host: string; people: OnlineUser[] }[]>([])

async function start() {
  starting.value = true
  try {
    await jam.start()
  } catch {
    ui.toast(t('jam.startFailed'), 'error')
  } finally {
    starting.value = false
  }
}

onMounted(async () => {
  if (jam.active || props.code) return
  try {
    const groups = new Map<string, { code: string; host: string; people: OnlineUser[] }>()
    for (const u of (await api.get<{ data: OnlineUser[] }>('/online')).data) {
      if (!u.jam) continue
      const group = groups.get(u.jam.code) ?? { code: u.jam.code, host: u.jam.host_name, people: [] }
      group.people.push(u)
      groups.set(u.jam.code, group)
    }
    happening.value = [...groups.values()]
  } catch {
    // Offline: just the start button.
  }
})

// ---- the Jam ----

const s = computed(() => jam.state)
const here = computed(() => jam.members.filter((m) => m.online).length || 1)
const addedBy = computed(() => (player.current?.by != null ? jam.member(player.current.by) : null))
const votes = computed(() => s.value?.skip_votes ?? null)
const seekPreview = ref<number | null>(null)
const inviteOpen = ref(false)
const upcoming = computed({
  get: () => player.upcoming,
  set: (items: QueueItem[]) => player.setUpcoming(items),
})
const canRemove = (item: QueueItem) => jam.canControl || item.by === me.value
const firstName = (m: JamMember) => (m.id === me.value ? t('jam.you') : m.name.split(/\s+/)[0])
const clock = (at: number) => new Date(at).toLocaleTimeString(locale.value === 'fa' ? 'fa-IR' : 'en', { hour: '2-digit', minute: '2-digit' })

function endJam() {
  if (confirm(t('jam.endConfirm'))) jam.end()
}

function kick(m: JamMember) {
  if (confirm(t('jam.removeConfirm', { name: m.name }))) jam.kick(m.id)
}
</script>

<template>
  <!-- In the Jam -->
  <div v-if="mine && s" class="relative">
    <div class="dyn-gradient pointer-events-none absolute inset-x-0 top-0 h-[34rem]" />
    <div class="relative mx-auto max-w-2xl px-4 pb-8 pt-5 sm:px-6 sm:pt-8">
      <header class="flex items-start gap-3">
        <div class="min-w-0 flex-1">
          <div class="flex items-center gap-2 text-[11px] font-extrabold uppercase tracking-[0.18em] text-accent">
            <span class="relative flex size-2">
              <span class="absolute inline-flex size-full animate-ping rounded-full bg-accent opacity-70" />
              <span class="relative inline-flex size-2 rounded-full bg-accent" />
            </span>
            {{ t('jam.live') }} · {{ t('jam.listeners', { n: here }) }}
          </div>
          <h1 class="mt-1 truncate text-3xl font-black tracking-tight sm:text-4xl">{{ t('jam.hostJam', { name: jam.host?.name ?? '' }) }}</h1>
        </div>
        <button class="btn-primary shrink-0" @click="inviteOpen = true"><UserPlus class="size-4" />{{ t('jam.invite') }}</button>
      </header>

      <!-- Who is here -->
      <div class="-mx-4 mt-1 flex gap-3 overflow-x-auto px-4 pb-1 pt-4 scrollbar-none">
        <div v-for="m in jam.members" :key="m.id" class="flex w-16 shrink-0 flex-col items-center gap-1.5">
          <span class="relative">
            <Avatar :name="m.name" :src="m.avatar_url" size="size-14 text-base" class="ring-2 ring-offset-2 ring-offset-bg transition" :class="m.online ? 'ring-accent' : 'opacity-45 ring-line'" />
            <Crown v-if="m.id === s.host_id" class="absolute -top-2.5 start-1/2 size-5 -translate-x-1/2 fill-amber-400 text-amber-500 rtl:translate-x-1/2" />
            <span class="absolute -bottom-1 -end-1 flex size-6 items-center justify-center rounded-full bg-elevated text-muted shadow">
              <Headphones v-if="m.listening" class="size-3.5" />
              <VolumeX v-else class="size-3.5" />
            </span>
          </span>
          <span class="w-full truncate text-center text-xs font-semibold" :class="m.id === me ? 'text-accent' : ''">{{ firstName(m) }}</span>
        </div>
        <button class="flex w-16 shrink-0 flex-col items-center gap-1.5 text-muted" @click="inviteOpen = true">
          <span class="flex size-14 items-center justify-center rounded-full border-2 border-dashed border-fg/25 transition hover:border-accent hover:text-accent"><UserPlus class="size-5" /></span>
          <span class="text-xs font-semibold">{{ t('jam.invite') }}</span>
        </button>
      </div>

      <!-- Now playing -->
      <section class="liquid mt-5 rounded-[2rem] p-5 sm:p-6">
        <template v-if="player.current">
          <div class="mx-auto w-full max-w-[15rem]">
            <TrackCover
              :track="player.current.track"
              size="fill"
              rounded="rounded-3xl"
              class="shadow-[0_30px_70px_-25px_rgba(0,0,0,0.75)] transition-transform duration-300"
              :class="player.playing ? 'scale-100' : 'scale-[0.94]'"
            />
          </div>
          <div class="mt-5 text-center">
            <h2 class="truncate text-2xl font-extrabold">{{ player.current.track.title }}</h2>
            <p class="truncate text-muted">{{ player.current.track.artist ?? t('track.unknownArtist') }}</p>
            <div class="mt-2 inline-flex items-center gap-1.5 rounded-full bg-fg/[0.07] py-1 pe-3 ps-1 text-xs font-semibold text-muted">
              <template v-if="addedBy">
                <Avatar :name="addedBy.name" :src="addedBy.avatar_url" size="size-5 text-[9px]" />{{ t('jam.addedBy', { name: addedBy.name }) }}
              </template>
              <template v-else><Sparkles class="ms-1 size-3.5 fill-current text-accent" />{{ t('jam.jamMix') }}</template>
            </div>
          </div>

          <div class="mt-4">
            <SeekBar
              thick
              :value="player.progress"
              :label="t('player.seek')"
              @seek="(f) => player.seek(f * player.duration)"
              @preview="(f) => (seekPreview = f)"
            />
            <div dir="ltr" class="flex justify-between text-xs text-muted tabular">
              <span>{{ duration(seekPreview !== null ? seekPreview * player.duration : player.currentTime) }}</span>
              <span>-{{ duration(Math.max(0, player.duration - (seekPreview !== null ? seekPreview * player.duration : player.currentTime))) }}</span>
            </div>
          </div>

          <div dir="ltr" class="mx-auto mt-2 flex max-w-[20rem] items-center justify-between">
            <button class="icon-btn size-12" :class="{ invisible: !jam.canControl }" :aria-label="t('player.shuffle')" @click="player.toggleShuffle()">
              <Shuffle class="size-5.5" />
            </button>
            <button class="icon-btn size-13 text-fg" :class="{ 'opacity-40': !jam.canControl }" :aria-label="t('player.previous')" @click="player.prev()">
              <SkipBack class="size-7 fill-current" />
            </button>
            <button
              class="flex size-18 items-center justify-center rounded-full bg-fg text-bg shadow-xl transition hover:scale-105 active:scale-95"
              :aria-label="player.playing ? t('player.pause') : t('player.play')"
              @click="player.toggle()"
            >
              <Loader2 v-if="player.loading && player.playing" class="size-8 animate-spin" />
              <Pause v-else-if="player.playing" class="size-8 fill-current" />
              <Play v-else class="size-8 translate-x-[1px] fill-current" />
            </button>
            <button class="icon-btn relative size-13 text-fg" :aria-label="jam.canControl ? t('player.next') : t('jam.voteSkip')" @click="player.next()">
              <SkipForward class="size-7 fill-current" />
              <span v-if="votes" class="absolute -top-0.5 end-0 rounded-full bg-accent px-1.5 text-[10px] font-bold leading-4 text-white tabular">{{ votes.users.length }}/{{ votes.needed }}</span>
            </button>
            <span class="size-12" />
          </div>

          <!-- How this phone is doing -->
          <div class="mt-3 flex min-h-8 items-center justify-center gap-2 text-xs font-semibold">
            <template v-if="jam.syncStatus === 'remote'">
              <VolumeX class="size-4 text-muted" /><span class="text-muted">{{ t('jam.remote') }}</span>
            </template>
            <template v-else-if="jam.syncStatus === 'paused'">
              <span class="text-muted">{{ t('jam.pausedHere') }}</span>
              <button class="chip h-8 text-xs" @click="player.play()"><Headphones class="size-3.5" />{{ t('jam.rejoin') }}</button>
            </template>
            <template v-else-if="s.playing && player.playing">
              <span class="size-2 rounded-full" :class="jam.syncStatus === 'synced' ? 'bg-success shadow-[0_0_8px] shadow-success' : 'animate-pulse bg-amber-400'" />
              <span :class="jam.syncStatus === 'synced' ? 'text-success' : 'text-amber-400'">{{ jam.syncStatus === 'synced' ? t('jam.synced') : t('jam.syncing') }}</span>
            </template>
            <span v-if="votes && !jam.canControl" class="text-muted">· {{ t('jam.votes', { votes: votes.users.length, needed: votes.needed }) }}</span>
          </div>

          <JamReactionBar class="mt-3" />
        </template>
        <div v-else class="py-10 text-center text-muted">
          <UsersRound class="mx-auto mb-3 size-10 text-subtle" />{{ t('jam.nothingPlaying') }}
          <div class="mt-4"><RouterLink to="/search" class="btn-primary"><ListPlus class="size-4" />{{ t('jam.addSongs') }}</RouterLink></div>
        </div>
      </section>

      <!-- Up next -->
      <section class="mt-8">
        <div class="mb-2 flex items-center gap-2">
          <h2 class="flex-1 text-xl font-extrabold">{{ t('jam.upNext') }}</h2>
          <RouterLink to="/search" class="chip h-9 text-sm"><ListPlus class="size-4" />{{ t('jam.addSongs') }}</RouterLink>
        </div>
        <p v-if="!upcoming.length" class="rounded-2xl bg-fg/[0.04] p-5 text-center text-sm text-muted">{{ t('jam.emptyQueue') }}</p>
        <Draggable v-model="upcoming" item-key="uid" handle=".drag" :animation="180" ghost-class="opacity-30" :disabled="!jam.canControl" class="flex flex-col">
          <template #item="{ element }: { element: QueueItem }">
            <div class="group flex items-center gap-3 rounded-2xl p-2 transition hover:bg-fg/5" @click="player.jump(element.uid)">
              <span v-if="jam.canControl" class="drag -me-1 cursor-grab touch-none p-1 text-subtle hover:text-fg active:cursor-grabbing" @click.stop>
                <GripVertical class="size-4" />
              </span>
              <TrackCover :track="element.track" size="md" />
              <div class="min-w-0 flex-1">
                <div class="truncate font-semibold">{{ element.track.title }}</div>
                <div class="truncate text-sm text-muted">{{ element.track.artist ?? t('track.unknownArtist') }}</div>
              </div>
              <span v-if="element.by == null" class="flex shrink-0 items-center gap-1 rounded-full bg-accent/12 px-2 py-1 text-[11px] font-bold text-accent">
                <Sparkles class="size-3 fill-current" />{{ t('jam.jamMix') }}
              </span>
              <Avatar v-else :name="jam.member(element.by)?.name" :src="jam.member(element.by)?.avatar_url" size="size-7 text-[10px]" :title="t('jam.addedBy', { name: jam.member(element.by)?.name ?? '' })" />
              <button v-if="canRemove(element)" class="icon-btn size-9 shrink-0" :aria-label="t('queue.remove')" @click.stop="player.remove(element.uid)">
                <X class="size-4" />
              </button>
            </div>
          </template>
        </Draggable>
      </section>

      <!-- People -->
      <section class="mt-8">
        <h2 class="mb-2 text-xl font-extrabold">{{ t('jam.members') }}</h2>
        <ul class="card divide-y divide-line overflow-hidden">
          <li v-for="m in jam.members" :key="m.id" class="flex items-center gap-3 px-4 py-3">
            <span class="relative">
              <Avatar :name="m.name" :src="m.avatar_url" size="size-11 text-sm" :class="{ 'opacity-50': !m.online }" />
              <span class="absolute -bottom-0.5 -end-0.5 size-3.5 rounded-full ring-2 ring-surface" :class="m.online ? 'bg-success' : 'bg-subtle'" />
            </span>
            <span class="min-w-0 flex-1">
              <span class="flex items-center gap-1.5 font-semibold">
                <span class="truncate">{{ m.name }}</span>
                <span v-if="m.id === me" class="text-xs font-normal text-muted">({{ t('jam.you') }})</span>
                <span v-if="m.id === s.host_id" class="flex items-center gap-1 rounded-full bg-amber-400/15 px-2 py-0.5 text-[10px] font-bold text-amber-500"><Crown class="size-3" />{{ t('jam.host') }}</span>
              </span>
              <span class="flex items-center gap-1 text-xs text-muted">
                <template v-if="!m.online">{{ t('jam.offlineMember') }}</template>
                <template v-else-if="m.listening"><Headphones class="size-3.5" />{{ t('jam.listeningOn') }}</template>
                <template v-else><VolumeX class="size-3.5" />{{ t('jam.listeningOff') }}</template>
              </span>
            </span>
            <button v-if="jam.isHost && m.id !== me" class="icon-btn size-9" :aria-label="t('jam.remove')" @click="kick(m)"><X class="size-4" /></button>
          </li>
        </ul>
      </section>

      <!-- Settings -->
      <section class="card mt-8 space-y-5 p-4">
        <SwitchRow :model-value="jam.listening" :icon="Headphones" :title="t('jam.here')" :hint="t('jam.hereHint')" @update:model-value="jam.setListening" />
        <template v-if="jam.isHost">
          <SwitchRow :model-value="s.guests_control" :icon="SlidersHorizontal" :title="t('jam.guestsControl')" :hint="t('jam.guestsControlHint')" @update:model-value="(v) => jam.configure({ guests_control: v })" />
          <SwitchRow :model-value="s.autofill" :icon="Sparkles" :title="t('jam.autofill')" :hint="t('jam.autofillHint')" @update:model-value="(v) => jam.configure({ autofill: v })" />
        </template>
      </section>

      <!-- What happened -->
      <section v-if="jam.activity.length" class="mt-8">
        <h2 class="mb-2 text-xl font-extrabold">{{ t('jam.activity') }}</h2>
        <ul class="space-y-1.5">
          <li v-for="a in jam.activity.slice(0, 12)" :key="a.id" class="flex items-baseline gap-3 text-sm">
            <span class="w-12 shrink-0 text-xs text-subtle tabular">{{ clock(a.at) }}</span>
            <span class="text-muted">{{ a.text }}</span>
          </li>
        </ul>
      </section>

      <div class="mt-8 grid gap-2">
        <button v-if="jam.isHost" class="btn-danger h-12" @click="endJam"><Power class="size-4" />{{ t('jam.end') }}</button>
        <button class="btn-ghost h-12" @click="jam.leave()"><LogOut class="size-4 rtl:-scale-x-100" />{{ t('jam.leave') }}</button>
      </div>
    </div>
    <JamInviteDialog :open="inviteOpen" @close="inviteOpen = false" />
  </div>

  <!-- An invitation -->
  <div v-else-if="code" class="relative min-h-full">
    <div class="dyn-gradient pointer-events-none absolute inset-x-0 top-0 h-96" />
    <div class="relative mx-auto flex max-w-md flex-col items-center px-6 pb-10 pt-12 text-center">
      <template v-if="invitation">
        <div class="relative mb-7">
          <div class="jam-halo absolute -inset-6 rounded-full" />
          <div class="relative size-52 overflow-hidden rounded-[2.2rem] shadow-[0_30px_70px_-25px_rgba(0,0,0,0.8)]">
            <TrackCover v-if="inviteTrack" :track="inviteTrack" size="fill" rounded="rounded-[2.2rem]" />
            <div v-else class="flex size-full items-center justify-center bg-gradient-to-br from-accent to-pink-600"><UsersRound class="size-20 text-white" /></div>
          </div>
          <div class="absolute -bottom-4 start-1/2 flex -translate-x-1/2 -space-x-2.5 rtl:translate-x-1/2 rtl:space-x-reverse">
            <Avatar v-for="m in invitation.data.members.slice(0, 5)" :key="m.id" :name="m.name" :src="m.avatar_url" size="size-10 text-xs ring-[3px] ring-bg" />
          </div>
        </div>
        <div class="mt-3 flex items-center gap-2 text-[11px] font-extrabold uppercase tracking-[0.18em] text-accent">
          <PlayingBars :paused="!invitation.data.playing" />{{ t('jam.live') }} · {{ t('jam.listeners', { n: invitation.data.members.length }) }}
        </div>
        <h1 class="mt-2 text-3xl font-black tracking-tight">{{ t('jam.hostJam', { name: inviteHost?.name ?? '' }) }}</h1>
        <p v-if="inviteTrack" class="mt-1 truncate text-muted">{{ inviteTrack.title }}<template v-if="inviteTrack.artist"> · {{ inviteTrack.artist }}</template></p>

        <div class="mt-8 grid w-full gap-2.5">
          <button class="btn-primary h-14 text-base" :disabled="!!joining" @click="join(true)">
            <Loader2 v-if="joining === 'sound'" class="size-5 animate-spin" /><Headphones v-else class="size-5" />{{ t('jam.joinWithSound') }}
          </button>
          <button class="btn-ghost h-12" :disabled="!!joining" @click="join(false)">
            <Loader2 v-if="joining === 'silent'" class="size-4 animate-spin" /><VolumeX v-else class="size-4" />{{ t('jam.joinSilent') }}
          </button>
          <p class="px-4 text-xs text-subtle">{{ t('jam.joinSilentHint') }}</p>
        </div>
      </template>
      <div v-else-if="invalid" class="pt-10">
        <UsersRound class="mx-auto mb-4 size-14 text-subtle" />
        <p class="font-semibold">{{ t('jam.notFound') }}</p>
        <RouterLink to="/jam" class="btn-primary mt-6">{{ t('jam.start') }}</RouterLink>
      </div>
      <Loader2 v-else class="mt-24 size-8 animate-spin text-muted" />
    </div>
  </div>

  <!-- Start one -->
  <div v-else class="relative min-h-full">
    <div class="dyn-gradient pointer-events-none absolute inset-x-0 top-0 h-96" />
    <div class="relative mx-auto flex max-w-md flex-col items-center px-6 pb-10 pt-14 text-center">
      <div class="relative mb-8">
        <div class="jam-halo absolute -inset-8 rounded-full" />
        <div class="relative flex size-36 items-center justify-center rounded-[2.4rem] bg-gradient-to-br from-accent via-rose-500 to-fuchsia-600 shadow-[0_25px_60px_-20px_var(--accent)]">
          <UsersRound class="size-16 text-white" />
        </div>
      </div>
      <h1 class="text-4xl font-black tracking-tight">{{ t('jam.title') }}</h1>
      <p class="mt-2 text-lg text-muted">{{ t('jam.tagline') }}</p>
      <button class="btn-primary mt-8 h-14 w-full text-base" :disabled="starting" @click="start">
        <Loader2 v-if="starting" class="size-5 animate-spin" /><UsersRound v-else class="size-5" />{{ t('jam.start') }}
      </button>

      <ul class="mt-10 w-full space-y-4 text-start">
        <li class="flex gap-4"><span class="flex size-10 shrink-0 items-center justify-center rounded-2xl bg-accent/15 text-accent"><Headphones class="size-5" /></span><span class="pt-2 text-sm text-muted">{{ t('jam.how1') }}</span></li>
        <li class="flex gap-4"><span class="flex size-10 shrink-0 items-center justify-center rounded-2xl bg-accent/15 text-accent"><ListPlus class="size-5" /></span><span class="pt-2 text-sm text-muted">{{ t('jam.how2') }}</span></li>
        <li class="flex gap-4"><span class="flex size-10 shrink-0 items-center justify-center rounded-2xl bg-accent/15 text-accent"><VolumeX class="size-5" /></span><span class="pt-2 text-sm text-muted">{{ t('jam.how3') }}</span></li>
      </ul>

      <section v-if="happening.length" class="mt-10 w-full text-start">
        <h2 class="mb-2 text-xs font-bold uppercase tracking-wider text-subtle">{{ t('jam.happening') }}</h2>
        <RouterLink v-for="g in happening" :key="g.code" :to="`/jam/${g.code}`" class="card mb-2 flex items-center gap-3 p-3 transition hover:bg-fg/5 active:scale-[0.98]">
          <span class="flex -space-x-2 rtl:space-x-reverse">
            <Avatar v-for="u in g.people.slice(0, 3)" :key="u.id" :name="u.name" :src="u.avatar_url" size="size-9 text-xs ring-2 ring-surface" />
          </span>
          <span class="min-w-0 flex-1 truncate font-semibold">{{ t('jam.hostJam', { name: g.host }) }}</span>
          <span class="chip h-8 text-xs">{{ t('jam.join') }}</span>
        </RouterLink>
      </section>
    </div>
  </div>
</template>
