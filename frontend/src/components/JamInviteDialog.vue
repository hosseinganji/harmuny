<script setup lang="ts">
import { Check, Copy, Send, Share2 } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'
import { api } from '@/lib/api'
import { t } from '@/lib/i18n'
import type { Member } from '@/lib/types'
import { useJam } from '@/stores/jam'
import { useUi } from '@/stores/ui'
import Avatar from './Avatar.vue'
import JamQr from './JamQr.vue'
import Modal from './Modal.vue'

/** Bring people in: a QR code to scan, the link to share, or a notification straight to a friend's phone. */
const props = defineProps<{ open: boolean }>()
defineEmits<{ close: [] }>()
const jam = useJam()
const ui = useUi()

const link = computed(() => jam.inviteLink())
const people = ref<Member[]>([])
const sent = ref(new Set<number>())
const inJam = computed(() => new Set(jam.members.map((m) => m.id)))

watch(() => props.open, async (open) => {
  if (!open) return
  sent.value = new Set()
  try {
    people.value = (await api.get<{ data: Member[] }>('/members')).data
  } catch {
    // Offline: the link and the code still work.
  }
})

async function share() {
  if (navigator.share) await navigator.share({ title: t('jam.title'), text: t('jam.shareText'), url: link.value }).catch(() => {})
  else copy()
}

async function copy() {
  await navigator.clipboard?.writeText(link.value).catch(() => {})
  ui.toast(t('jam.copied'), 'success')
}

async function invite(member: Member) {
  await jam.invite([member.id]).catch(() => ui.toast(t('jam.offline'), 'error'))
  sent.value = new Set([...sent.value, member.id])
}
</script>

<template>
  <Modal :open="open" :title="t('jam.inviteTitle')" @close="$emit('close')">
    <div class="flex flex-col items-center gap-3 pb-2">
      <div class="w-52 rounded-3xl bg-white p-3 shadow-[0_20px_50px_-20px_rgb(var(--dyn)/0.9)]">
        <JamQr :value="link" />
      </div>
      <p class="text-sm font-semibold text-muted">{{ t('jam.scan') }}</p>
      <div dir="ltr" class="w-full truncate rounded-2xl bg-fg/5 px-4 py-2.5 text-center font-mono text-sm">{{ link.replace(/^https?:\/\//, '') }}</div>
      <div class="grid w-full grid-cols-2 gap-2">
        <button class="btn-primary" @click="share"><Share2 class="size-4" />{{ t('jam.share') }}</button>
        <button class="btn-ghost" @click="copy"><Copy class="size-4" />{{ t('jam.copy') }}</button>
      </div>
    </div>

    <template v-if="people.length">
      <h3 class="mb-1 mt-5 text-xs font-bold uppercase tracking-wider text-subtle">{{ t('jam.inviteHint') }}</h3>
      <ul class="space-y-1">
        <li v-for="p in people" :key="p.id" class="flex items-center gap-3 rounded-2xl py-1.5">
          <Avatar :name="p.name" :src="p.avatar_url" size="size-10 text-sm" />
          <span class="min-w-0 flex-1">
            <span class="block truncate font-semibold">{{ p.name }}</span>
            <span class="block truncate text-xs text-muted" dir="ltr">@{{ p.username }}</span>
          </span>
          <span v-if="inJam.has(p.id)" class="text-xs font-semibold text-accent">{{ t('jam.members') }}</span>
          <button v-else-if="sent.has(p.id)" class="chip pointer-events-none h-8 text-xs text-success"><Check class="size-3.5" />{{ t('jam.inviteSent') }}</button>
          <button v-else class="chip h-8 text-xs" @click="invite(p)"><Send class="size-3.5 rtl:-scale-x-100" />{{ t('jam.invite') }}</button>
        </li>
      </ul>
    </template>
  </Modal>
</template>
