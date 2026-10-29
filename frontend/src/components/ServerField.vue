<script setup lang="ts">
import { Server } from 'lucide-vue-next'
import { ref } from 'vue'
import { apiBase, isNative, savedApiBase, setApiBase } from '@/lib/config'
import { t } from '@/lib/i18n'

// The native app has no same-origin backend, so the server address is asked for here.
const open = ref(isNative && !apiBase())
const server = ref(savedApiBase())
</script>

<template>
  <div v-if="isNative || server" class="mt-4">
    <button v-if="!open" type="button" class="flex items-center gap-1.5 text-xs text-subtle hover:text-fg" @click="open = true">
      <Server class="size-3.5" />{{ server || t('auth.server') }}
    </button>
    <div v-else>
      <label class="label">{{ t('auth.server') }}</label>
      <input v-model="server" class="input" dir="ltr" placeholder="https://music.example.com" inputmode="url" @change="setApiBase(server)" />
    </div>
  </div>
</template>
