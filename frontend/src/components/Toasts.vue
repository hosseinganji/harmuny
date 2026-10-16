<script setup lang="ts">
import { AlertCircle, CheckCircle2, Info } from 'lucide-vue-next'
import { useUi } from '@/stores/ui'

const ui = useUi()
</script>

<template>
  <div class="pointer-events-none fixed inset-x-0 top-0 z-[90] flex flex-col items-center gap-2 px-4 pt-safe" style="margin-top: 12px">
    <TransitionGroup name="pop">
      <div
        v-for="toast in ui.toasts"
        :key="toast.id"
        class="liquid liquid-strong pointer-events-auto flex max-w-md items-center gap-3 rounded-full px-5 py-3 text-sm font-medium"
        role="status"
        @click="ui.dismiss(toast.id)"
      >
        <CheckCircle2 v-if="toast.kind === 'success'" class="size-5 shrink-0 text-success" />
        <AlertCircle v-else-if="toast.kind === 'error'" class="size-5 shrink-0 text-danger" />
        <Info v-else class="size-5 shrink-0 text-accent" />
        <span>{{ toast.message }}</span>
        <button v-if="toast.action" class="ms-2 font-bold text-accent" @click.stop="toast.action.run(); ui.dismiss(toast.id)">
          {{ toast.action.label }}
        </button>
      </div>
    </TransitionGroup>
  </div>
</template>
