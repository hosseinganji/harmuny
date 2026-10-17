<script setup lang="ts">
import { ArrowDown, ArrowUp, ChevronLeft, ChevronRight, Loader2, Minus } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'
import Avatar from '@/components/Avatar.vue'
import BarChart from '@/components/BarChart.vue'
import TrackCover from '@/components/TrackCover.vue'
import { api } from '@/lib/api'
import type { Bar } from '@/lib/chart'
import { fromIso, isoDate } from '@/lib/calendar'
import { bytes, count, longDuration, num } from '@/lib/format'
import { locale, t } from '@/lib/i18n'
import { bucketsFor, periodAt, PERIOD_KINDS, type PeriodKind, weekdays } from '@/lib/reportPeriods'
import type { ApiTrack } from '@/lib/types'
import { toRemote } from '@/stores/library'

interface Summary {
  plays: number; minutes: number; listeners: number; minutes_per_listener: number; songs_played: number
  new_users: number; new_tracks: number; likes: number; playlists: number
}
interface Ranked { name: string; plays: number; listeners: number; minutes: number }
interface Report {
  bucket: 'hour' | 'day'
  summary: Summary
  previous: Summary
  timeline: { key: string; plays: number; minutes: number; listeners: number }[]
  by_hour: number[]
  by_weekday: number[]
  top_tracks: { track: ApiTrack; plays: number; listeners: number }[]
  top_artists: Ranked[]
  top_genres: Ranked[]
  top_listeners: { user: { id: number; name: string; username: string; avatar_url: string | null }; plays: number; minutes: number }[]
  library: { tracks: number; never_played: number; on_server: number; bytes: number; seconds: number; channels: number; liked_tracks: number }
  users: { total: number; active: number; pending: number; online_now: number }
}

const kind = ref<PeriodKind>('week')
const back = ref(0)
const report = ref<Report | null>(null)
const loading = ref(false)
const failed = ref(false)

const period = computed(() => {
  void locale.value // labels and calendars follow the language
  return periodAt(kind.value, back.value)
})

let seq = 0
async function load() {
  const mine = ++seq
  loading.value = true
  failed.value = false
  try {
    const res = await api.get<Report>('/admin/reports', { from: isoDate(period.value.from), to: isoDate(period.value.to) })
    if (mine === seq) report.value = res
  } catch {
    if (mine === seq) failed.value = true
  } finally {
    if (mine === seq) loading.value = false
  }
}
watch(period, load, { immediate: true })
watch(kind, () => (back.value = 0))

// ---- headline tiles ---------------------------------------------------------
const tiles = computed(() => {
  const r = report.value
  if (!r) return []
  const s = r.summary
  const p = r.previous
  return [
    { key: 'minutes', label: t('reports.listeningTime'), value: longDuration(s.minutes * 60), cur: s.minutes, prev: p.minutes },
    { key: 'listeners', label: t('reports.listeners'), value: count(s.listeners), cur: s.listeners, prev: p.listeners },
    { key: 'perListener', label: t('reports.perListener'), value: longDuration(s.minutes_per_listener * 60), cur: s.minutes_per_listener, prev: p.minutes_per_listener },
    { key: 'songs', label: t('reports.songsPlayed'), value: count(s.songs_played), cur: s.songs_played, prev: p.songs_played },
    { key: 'likes', label: t('reports.likes'), value: count(s.likes), cur: s.likes, prev: p.likes },
    { key: 'newUsers', label: t('reports.newUsers'), value: count(s.new_users), cur: s.new_users, prev: p.new_users },
    { key: 'newTracks', label: t('reports.newTracks'), value: count(s.new_tracks), cur: s.new_tracks, prev: p.new_tracks },
    { key: 'playlists', label: t('reports.newPlaylists'), value: count(s.playlists), cur: s.playlists, prev: p.playlists },
  ]
})

/** Signed change against the previous period of the same length; null when there is nothing to compare with. */
function change(cur: number, prev: number): { pct: number; text: string } | null {
  if (!prev) return cur ? { pct: 100, text: t('reports.new') } : null
  const pct = Math.round(((cur - prev) / prev) * 100)
  return { pct, text: `${pct > 0 ? '+' : ''}${num(pct)}${locale.value === 'fa' ? '٪' : '%'}` }
}

// ---- charts -----------------------------------------------------------------
const timelineBars = computed<{ plays: Bar[]; minutes: Bar[] }>(() => {
  const r = report.value
  if (!r) return { plays: [], minutes: [] }
  const buckets = bucketsFor(period.value)
  if (buckets === 'hours') {
    const byKey = new Map(r.timeline.map((x) => [Number(x.key), x]))
    const hours = Array.from({ length: 24 }, (_, h) => ({ h, row: byKey.get(h) }))
    return {
      plays: hours.map(({ h, row }) => ({ label: num(h), title: t('reports.hourN', { n: h }), value: row?.plays ?? 0, detail: row ? t('reports.minutesN', { n: row.minutes }) : undefined })),
      minutes: hours.map(({ h, row }) => ({ label: num(h), title: t('reports.hourN', { n: h }), value: row?.minutes ?? 0 })),
    }
  }
  const byDay = new Map(r.timeline.map((x) => [fromIso(x.key), x]))
  const sum = (from: number, to: number, f: 'plays' | 'minutes') => {
    let n = 0
    for (let d = from; d <= to; d++) n += byDay.get(d)?.[f] ?? 0
    return n
  }
  return {
    plays: buckets.map((b) => ({ label: b.label, title: b.title, value: sum(b.from, b.to, 'plays'), detail: t('reports.minutesN', { n: sum(b.from, b.to, 'minutes') }) })),
    minutes: buckets.map((b) => ({ label: b.label, title: b.title, value: sum(b.from, b.to, 'minutes') })),
  }
})

const hourBars = computed<Bar[]>(() =>
  (report.value?.by_hour ?? []).map((v, h) => ({ label: num(h), title: t('reports.hourN', { n: h }), value: v })),
)
const weekdayBars = computed<Bar[]>(() => {
  void locale.value
  return weekdays().map(({ index, label }) => ({ label, title: label, value: report.value?.by_weekday[index] ?? 0 }))
})

const busiestHour = computed(() => {
  const h = report.value?.by_hour ?? []
  const top = Math.max(0, ...h)
  return top ? h.indexOf(top) : null
})

const topTracks = computed(() => (report.value?.top_tracks ?? []).map((x) => ({ ...x, track: toRemote(x.track) })))
const maxOf = (list: { plays: number }[]) => Math.max(1, ...list.map((x) => x.plays))
</script>

<template>
  <div class="min-w-0 space-y-4 px-4 pb-8 pt-4 sm:px-6">
    <!-- Period picker -->
    <div class="space-y-3">
      <div class="scrollbar-none -mx-4 flex gap-1.5 overflow-x-auto px-4 sm:mx-0 sm:px-0">
        <button
          v-for="k in PERIOD_KINDS"
          :key="k"
          class="h-9 shrink-0 rounded-full px-4 text-sm font-semibold transition active:scale-95"
          :class="kind === k ? 'bg-accent text-white' : 'bg-fg/[0.06] text-fg hover:bg-fg/10'"
          @click="kind = k"
        >{{ t(`reports.period.${k}`) }}</button>
      </div>
      <div class="flex items-center gap-2">
        <button class="icon-btn" :aria-label="t('reports.previous')" @click="back++"><ChevronRight class="size-5 ltr:rotate-180" /></button>
        <div class="min-w-0 flex-1 text-center">
          <div class="truncate text-lg font-bold">{{ period.label }}</div>
        </div>
        <button class="icon-btn" :disabled="back === 0" :aria-label="t('reports.next')" @click="back--"><ChevronLeft class="size-5 ltr:rotate-180" /></button>
      </div>
    </div>

    <div v-if="loading && !report" class="flex justify-center py-16"><Loader2 class="size-6 animate-spin text-muted" /></div>
    <p v-else-if="failed && !report" class="py-16 text-center text-sm text-muted">{{ t('reports.failed') }}</p>

    <template v-else-if="report">
      <div class="space-y-4 transition-opacity" :class="{ 'opacity-60': loading }">
        <!-- Hero: plays -->
        <section class="card p-5">
          <div class="text-sm font-semibold text-muted">{{ t('reports.plays') }}</div>
          <div class="mt-1 flex flex-wrap items-end gap-x-3 gap-y-1">
            <span class="text-5xl font-black leading-none">{{ count(report.summary.plays) }}</span>
            <span v-if="change(report.summary.plays, report.previous.plays)" class="flex items-center gap-1 pb-1 text-sm font-semibold" :class="change(report.summary.plays, report.previous.plays)!.pct >= 0 ? 'text-success' : 'text-danger'">
              <component :is="change(report.summary.plays, report.previous.plays)!.pct > 0 ? ArrowUp : change(report.summary.plays, report.previous.plays)!.pct < 0 ? ArrowDown : Minus" class="size-4" />
              {{ change(report.summary.plays, report.previous.plays)!.text }}
              <span class="font-normal text-subtle">{{ t('reports.vsPrevious') }}</span>
            </span>
          </div>
          <div class="mt-5">
            <BarChart :bars="timelineBars.plays" :unit="t('reports.playsUnit')" />
          </div>
        </section>

        <!-- Headline tiles -->
        <section class="grid grid-cols-2 gap-2 sm:grid-cols-4 [&>*]:min-w-0">
          <div v-for="tile in tiles" :key="tile.key" class="card p-4">
            <div class="text-xs font-semibold text-muted">{{ tile.label }}</div>
            <div class="mt-1 text-xl font-bold leading-tight">{{ tile.value }}</div>
            <div v-if="change(tile.cur, tile.prev)" class="mt-1 flex items-center gap-0.5 text-xs font-semibold" :class="change(tile.cur, tile.prev)!.pct >= 0 ? 'text-success' : 'text-danger'">
              <component :is="change(tile.cur, tile.prev)!.pct > 0 ? ArrowUp : change(tile.cur, tile.prev)!.pct < 0 ? ArrowDown : Minus" class="size-3" />{{ change(tile.cur, tile.prev)!.text }}
            </div>
            <div v-else class="mt-1 text-xs text-subtle">—</div>
          </div>
        </section>

        <section class="card p-5">
          <h2 class="mb-4 font-bold">{{ t('reports.listeningTime') }}</h2>
          <BarChart :bars="timelineBars.minutes" :unit="t('reports.minutesUnit')" />
        </section>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2 [&>*]:min-w-0">
          <section class="card p-5">
            <h2 class="font-bold">{{ t('reports.byHour') }}</h2>
            <p class="mb-4 text-xs text-muted">{{ busiestHour !== null ? t('reports.busiestHour', { n: busiestHour }) : t('reports.noPlays') }}</p>
            <BarChart :bars="hourBars" :unit="t('reports.playsUnit')" height="h-32" />
          </section>
          <section class="card p-5">
            <h2 class="mb-4 font-bold">{{ t('reports.byWeekday') }}</h2>
            <BarChart :bars="weekdayBars" :unit="t('reports.playsUnit')" height="h-32" />
          </section>
        </div>

        <!-- Top lists -->
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2 [&>*]:min-w-0">
          <section class="card p-5">
            <h2 class="mb-3 font-bold">{{ t('reports.topTracks') }}</h2>
            <p v-if="!topTracks.length" class="text-sm text-muted">{{ t('reports.noPlays') }}</p>
            <ol class="space-y-2.5">
              <li v-for="(row, i) in topTracks" :key="row.track.id" class="flex items-center gap-3">
                <span class="w-5 shrink-0 text-center text-xs font-bold text-subtle tabular">{{ num(i + 1) }}</span>
                <TrackCover :track="row.track" size="sm" />
                <div class="min-w-0 flex-1">
                  <div class="truncate text-sm font-semibold">{{ row.track.title }}</div>
                  <div class="truncate text-xs text-muted">{{ row.track.artist ?? t('track.unknownArtist') }}</div>
                  <div class="mt-1 h-1 rounded-full bg-fg/[0.06]"><div class="h-full rounded-full bg-accent" :style="{ width: `${(row.plays / maxOf(topTracks)) * 100}%` }" /></div>
                </div>
                <div class="shrink-0 text-end text-xs">
                  <div class="font-bold tabular">{{ count(row.plays) }}</div>
                  <div class="text-subtle">{{ t('reports.listenersN', { n: row.listeners }) }}</div>
                </div>
              </li>
            </ol>
          </section>

          <section class="card p-5">
            <h2 class="mb-3 font-bold">{{ t('reports.topListeners') }}</h2>
            <p v-if="!report.top_listeners.length" class="text-sm text-muted">{{ t('reports.noPlays') }}</p>
            <ol class="space-y-2.5">
              <li v-for="(row, i) in report.top_listeners" :key="row.user.id" class="flex items-center gap-3">
                <span class="w-5 shrink-0 text-center text-xs font-bold text-subtle tabular">{{ num(i + 1) }}</span>
                <Avatar :name="row.user.name" :src="row.user.avatar_url" size="size-9 text-xs" />
                <div class="min-w-0 flex-1">
                  <div class="truncate text-sm font-semibold">{{ row.user.name }}</div>
                  <div class="mt-1 h-1 rounded-full bg-fg/[0.06]"><div class="h-full rounded-full bg-accent" :style="{ width: `${(row.minutes / Math.max(1, report.top_listeners[0].minutes)) * 100}%` }" /></div>
                </div>
                <div class="shrink-0 text-end text-xs">
                  <div class="font-bold">{{ longDuration(row.minutes * 60) }}</div>
                  <div class="text-subtle">{{ t('reports.playsN', { n: row.plays }) }}</div>
                </div>
              </li>
            </ol>
          </section>

          <section v-for="list in ([['topArtists', report.top_artists], ['topGenres', report.top_genres]] as const)" :key="list[0]" class="card p-5">
            <h2 class="mb-3 font-bold">{{ t(`reports.${list[0]}`) }}</h2>
            <p v-if="!list[1].length" class="text-sm text-muted">{{ t('reports.noPlays') }}</p>
            <ol class="space-y-2.5">
              <li v-for="(row, i) in list[1]" :key="row.name" class="flex items-center gap-3">
                <span class="w-5 shrink-0 text-center text-xs font-bold text-subtle tabular">{{ num(i + 1) }}</span>
                <div class="min-w-0 flex-1">
                  <div class="truncate text-sm font-semibold">{{ row.name }}</div>
                  <div class="mt-1 h-1 rounded-full bg-fg/[0.06]"><div class="h-full rounded-full bg-accent" :style="{ width: `${(row.plays / maxOf(list[1])) * 100}%` }" /></div>
                </div>
                <div class="shrink-0 text-end text-xs">
                  <div class="font-bold tabular">{{ count(row.plays) }}</div>
                  <div class="text-subtle">{{ t('reports.listenersN', { n: row.listeners }) }}</div>
                </div>
              </li>
            </ol>
          </section>
        </div>

        <!-- Library & users (current state, not tied to the period) -->
        <section class="card p-5">
          <h2 class="mb-1 font-bold">{{ t('reports.libraryNow') }}</h2>
          <p class="mb-4 text-xs text-muted">{{ t('reports.libraryNowHint') }}</p>
          <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-4">
            <div><dt class="text-xs text-muted">{{ t('admin.tracks') }}</dt><dd class="font-bold">{{ count(report.library.tracks) }}</dd></div>
            <div><dt class="text-xs text-muted">{{ t('reports.neverPlayed') }}</dt><dd class="font-bold">{{ count(report.library.never_played) }}</dd></div>
            <div><dt class="text-xs text-muted">{{ t('admin.serverCache') }}</dt><dd class="font-bold">{{ count(report.library.on_server) }}</dd></div>
            <div><dt class="text-xs text-muted">{{ t('admin.libraryBytes') }}</dt><dd class="font-bold">{{ bytes(report.library.bytes) }}</dd></div>
            <div><dt class="text-xs text-muted">{{ t('reports.totalLength') }}</dt><dd class="font-bold">{{ longDuration(report.library.seconds) }}</dd></div>
            <div><dt class="text-xs text-muted">{{ t('reports.likedSongs') }}</dt><dd class="font-bold">{{ count(report.library.liked_tracks) }}</dd></div>
            <div><dt class="text-xs text-muted">{{ t('admin.users') }}</dt><dd class="font-bold">{{ count(report.users.active) }} <span class="text-xs font-normal text-subtle">/ {{ count(report.users.total) }}</span></dd></div>
            <div><dt class="text-xs text-muted">{{ t('reports.onlineNow') }}</dt><dd class="font-bold">{{ count(report.users.online_now) }}</dd></div>
          </dl>
        </section>
      </div>
    </template>
  </div>
</template>
