<script setup lang="ts">
import { Eye, EyeOff, Loader2 } from 'lucide-vue-next'
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import ServerField from '@/components/ServerField.vue'
import { ApiError } from '@/lib/api'
import { t } from '@/lib/i18n'
import { useAuth } from '@/stores/auth'
import AuthShell from './AuthShell.vue'

const auth = useAuth()
const router = useRouter()
const route = useRoute()

const username = ref('')
const password = ref('')
const show = ref(false)
const busy = ref(false)
const error = ref('')
const pending = ref(false)

async function submit() {
  busy.value = true
  error.value = ''
  pending.value = false
  try {
    await auth.login(username.value.trim(), password.value)
    router.replace((route.query.next as string) || '/')
  } catch (e) {
    if (e instanceof ApiError && e.status === 403) {
      pending.value = e.data?.status === 'pending'
      error.value = pending.value ? t('auth.pendingText') : t('auth.disabled')
    } else if (e instanceof ApiError && e.status === 0) {
      error.value = t('auth.network')
    } else {
      error.value = e instanceof ApiError ? e.first : String(e)
    }
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <AuthShell>
    <template #title>
      <h1 class="text-3xl font-black tracking-tight">{{ t('auth.welcome') }}</h1>
      <p class="mt-1 text-muted">{{ t('auth.loginSubtitle') }}</p>
    </template>

    <form class="space-y-4" @submit.prevent="submit">
      <div>
        <label class="label" for="username">{{ t('auth.usernameOrEmail') }}</label>
        <input id="username" v-model="username" class="input" dir="ltr" autocomplete="username" autocapitalize="none" required />
      </div>
      <div>
        <label class="label" for="password">{{ t('auth.password') }}</label>
        <div class="relative">
          <input id="password" v-model="password" :type="show ? 'text' : 'password'" class="input pe-12" dir="ltr" autocomplete="current-password" required />
          <button type="button" class="icon-btn absolute end-1 top-1" :aria-label="t('auth.showPassword')" @click="show = !show">
            <EyeOff v-if="show" class="size-5" /><Eye v-else class="size-5" />
          </button>
        </div>
      </div>
      <p v-if="error" class="rounded-xl p-3 text-sm" :class="pending ? 'bg-amber-500/10 text-amber-400' : 'bg-danger/10 text-danger'">{{ error }}</p>
      <button class="btn-primary h-12 w-full text-base" :disabled="busy">
        <Loader2 v-if="busy" class="size-5 animate-spin" />{{ t('auth.login') }}
      </button>
      <ServerField />
    </form>

    <template #footer>
      <p class="mt-6 text-center text-sm text-muted">
        {{ t('auth.noAccount') }}
        <RouterLink to="/register" class="font-semibold text-accent hover:underline">{{ t('auth.register') }}</RouterLink>
      </p>
    </template>
  </AuthShell>
</template>
