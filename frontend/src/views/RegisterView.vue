<script setup lang="ts">
import { Hourglass, Loader2 } from 'lucide-vue-next'
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import ServerField from '@/components/ServerField.vue'
import { ApiError } from '@/lib/api'
import { t } from '@/lib/i18n'
import { useAuth } from '@/stores/auth'
import AuthShell from './AuthShell.vue'

const auth = useAuth()
const router = useRouter()
const form = reactive({ name: '', username: '', email: '', password: '' })
const errors = ref<Record<string, string[]>>({})
const general = ref('')
const busy = ref(false)
const done = ref(false)

async function submit() {
  busy.value = true
  errors.value = {}
  general.value = ''
  try {
    const status = await auth.register({ ...form, email: form.email || undefined })
    if (status === 'active') router.replace('/')
    else done.value = true
  } catch (e) {
    if (e instanceof ApiError) {
      errors.value = e.errors
      if (!Object.keys(e.errors).length) general.value = e.status === 0 ? t('auth.network') : e.message
    }
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <AuthShell>
    <template #title>
      <h1 class="text-3xl font-black tracking-tight">{{ done ? t('auth.pendingTitle') : t('auth.createAccount') }}</h1>
      <p class="mt-1 text-muted">{{ done ? '' : t('auth.registerSubtitle') }}</p>
    </template>

    <div v-if="done" class="py-4 text-center">
      <div class="mx-auto mb-4 flex size-16 items-center justify-center rounded-full bg-amber-500/15">
        <Hourglass class="size-8 text-amber-400" />
      </div>
      <p class="text-muted">{{ t('auth.pendingText') }}</p>
      <RouterLink to="/login" class="btn-primary mt-6 w-full">{{ t('auth.backToLogin') }}</RouterLink>
    </div>

    <form v-else class="space-y-4" @submit.prevent="submit">
      <div>
        <label class="label">{{ t('profile.name') }}</label>
        <input v-model="form.name" class="input" autocomplete="name" required maxlength="60" />
        <p v-if="errors.name" class="mt-1 text-xs text-danger">{{ errors.name[0] }}</p>
      </div>
      <div>
        <label class="label">{{ t('profile.username') }}</label>
        <input v-model="form.username" class="input" dir="ltr" autocomplete="username" autocapitalize="none" required pattern="[a-zA-Z0-9_.]{3,32}" />
        <p v-if="errors.username" class="mt-1 text-xs text-danger">{{ errors.username[0] }}</p>
        <p v-else class="mt-1 text-xs text-subtle">{{ t('auth.usernameHint') }}</p>
      </div>
      <div>
        <label class="label">{{ t('profile.email') }} <span class="text-subtle">({{ t('common.optional') }})</span></label>
        <input v-model="form.email" type="email" class="input" dir="ltr" autocomplete="email" />
        <p v-if="errors.email" class="mt-1 text-xs text-danger">{{ errors.email[0] }}</p>
      </div>
      <div>
        <label class="label">{{ t('auth.password') }}</label>
        <input v-model="form.password" type="password" class="input" dir="ltr" autocomplete="new-password" required minlength="8" />
        <p v-if="errors.password" class="mt-1 text-xs text-danger">{{ errors.password[0] }}</p>
      </div>
      <p v-if="general" class="rounded-xl bg-danger/10 p-3 text-sm text-danger">{{ general }}</p>
      <button class="btn-primary h-12 w-full text-base" :disabled="busy">
        <Loader2 v-if="busy" class="size-5 animate-spin" />{{ t('auth.register') }}
      </button>
      <ServerField />
    </form>

    <template #footer>
      <p v-if="!done" class="mt-6 text-center text-sm text-muted">
        {{ t('auth.haveAccount') }}
        <RouterLink to="/login" class="font-semibold text-accent hover:underline">{{ t('auth.login') }}</RouterLink>
      </p>
    </template>
  </AuthShell>
</template>
