<script setup lang="ts">
import { useDebounceFn } from '@vueuse/core'
import { Ban, Check, KeyRound, ShieldCheck, ShieldOff, Trash2, UserPlus } from 'lucide-vue-next'
import { onMounted, reactive, ref, watch } from 'vue'
import Avatar from '@/components/Avatar.vue'
import Modal from '@/components/Modal.vue'
import { api, ApiError } from '@/lib/api'
import { relativeTime } from '@/lib/format'
import { t } from '@/lib/i18n'
import type { User } from '@/lib/types'
import { useAuth } from '@/stores/auth'
import { useUi } from '@/stores/ui'

const auth = useAuth()
const ui = useUi()
const users = ref<User[]>([])
const status = ref<'' | 'pending' | 'active' | 'disabled'>('')
const q = ref('')
const creating = ref(false)
const form = reactive({ name: '', username: '', email: '', password: '', role: 'member' as 'member' | 'admin' })
const formError = ref('')

async function load() {
  users.value = (await api.get<{ data: User[] }>('/admin/users', { status: status.value, q: q.value })).data
}
const search = useDebounceFn(load, 300)
watch(status, load)
watch(q, search)
onMounted(load)

async function update(user: User, data: Partial<User> & { password?: string }) {
  try {
    const res = await api.patch<{ data: User }>(`/admin/users/${user.id}`, data)
    Object.assign(user, res.data)
    ui.toast(t('toast.saved'), 'success')
  } catch (e) {
    ui.toast(e instanceof ApiError ? e.first : String(e), 'error')
  }
}

async function resetPassword(user: User) {
  const password = prompt(t('admin.newPasswordFor', { name: user.name }))
  if (password) await update(user, { password })
}

async function remove(user: User) {
  if (!confirm(t('admin.deleteConfirm', { name: user.name }))) return
  await api.delete(`/admin/users/${user.id}`)
  users.value = users.value.filter((u) => u.id !== user.id)
}

async function create() {
  formError.value = ''
  try {
    await api.post('/admin/users', { ...form, email: form.email || null })
    creating.value = false
    Object.assign(form, { name: '', username: '', email: '', password: '', role: 'member' })
    load()
  } catch (e) {
    formError.value = e instanceof ApiError ? e.first : String(e)
  }
}

const badge: Record<string, string> = {
  pending: 'bg-amber-500/15 text-amber-400',
  active: 'bg-success/15 text-success',
  disabled: 'bg-danger/15 text-danger',
}
</script>

<template>
  <div class="mb-4 flex flex-wrap items-center gap-2">
    <input v-model="q" class="input h-10 max-w-xs flex-1 rounded-full" :placeholder="t('admin.searchUsers')" />
    <div class="flex gap-1.5 overflow-x-auto scrollbar-none">
      <button v-for="s in ['', 'pending', 'active', 'disabled'] as const" :key="s" class="chip h-9" :class="{ 'chip-active': status === s }" @click="status = s">
        {{ s ? t('admin.status.' + s) : t('search.all') }}
      </button>
    </div>
    <button class="btn-primary ms-auto h-10" @click="creating = true"><UserPlus class="size-4" />{{ t('admin.addUser') }}</button>
  </div>

  <div class="space-y-2">
    <div v-for="u in users" :key="u.id" class="card flex flex-wrap items-center gap-3 p-3">
      <Avatar :name="u.name" :src="u.avatar_url" />
      <div class="min-w-0 flex-1">
        <div class="flex items-center gap-2">
          <span class="truncate font-semibold">{{ u.name }}</span>
          <span v-if="u.role === 'admin'" class="rounded-full bg-accent/15 px-2 py-0.5 text-[10px] font-bold text-accent">{{ t('admin.roleAdmin') }}</span>
          <span class="rounded-full px-2 py-0.5 text-[10px] font-bold" :class="badge[u.status!]">{{ t('admin.status.' + u.status) }}</span>
        </div>
        <div class="truncate text-xs text-subtle">
          <span dir="ltr">@{{ u.username }}</span><span v-if="u.email"> · {{ u.email }}</span>
          · {{ u.last_seen_at ? t('admin.lastSeen', { when: relativeTime(u.last_seen_at) }) : t('admin.joined', { when: relativeTime(u.created_at) }) }}
        </div>
      </div>
      <div v-if="u.id !== auth.user?.id" class="flex gap-1">
        <button v-if="u.status !== 'active'" class="btn-primary h-9 px-3" @click="update(u, { status: 'active' })">
          <Check class="size-4" />{{ u.status === 'pending' ? t('admin.approve') : t('admin.enable') }}
        </button>
        <button v-else class="icon-btn" :title="t('admin.disable')" @click="update(u, { status: 'disabled' })"><Ban class="size-4.5" /></button>
        <button v-if="u.role === 'member'" class="icon-btn" :title="t('admin.makeAdmin')" @click="update(u, { role: 'admin' })"><ShieldCheck class="size-4.5" /></button>
        <button v-else class="icon-btn" :title="t('admin.removeAdmin')" @click="update(u, { role: 'member' })"><ShieldOff class="size-4.5" /></button>
        <button class="icon-btn" :title="t('admin.resetPassword')" @click="resetPassword(u)"><KeyRound class="size-4.5" /></button>
        <button class="icon-btn hover:!text-danger" :title="t('common.delete')" @click="remove(u)"><Trash2 class="size-4.5" /></button>
      </div>
    </div>
  </div>

  <Modal :open="creating" :title="t('admin.addUser')" @close="creating = false">
    <form class="space-y-3" @submit.prevent="create">
      <input v-model="form.name" class="input" :placeholder="t('profile.name')" required />
      <input v-model="form.username" class="input" dir="ltr" :placeholder="t('profile.username')" required />
      <input v-model="form.email" type="email" class="input" dir="ltr" :placeholder="t('profile.email') + ' (' + t('common.optional') + ')'" />
      <input v-model="form.password" type="text" class="input" dir="ltr" :placeholder="t('auth.password')" minlength="8" required />
      <div class="flex gap-2">
        <button type="button" class="chip flex-1 justify-center" :class="{ 'chip-active': form.role === 'member' }" @click="form.role = 'member'">{{ t('admin.roleMember') }}</button>
        <button type="button" class="chip flex-1 justify-center" :class="{ 'chip-active': form.role === 'admin' }" @click="form.role = 'admin'">{{ t('admin.roleAdmin') }}</button>
      </div>
      <p v-if="formError" class="text-sm text-danger">{{ formError }}</p>
      <button class="btn-primary w-full">{{ t('common.create') }}</button>
    </form>
  </Modal>
</template>
