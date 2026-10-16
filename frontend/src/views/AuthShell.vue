<script setup lang="ts"></script>

<template>
  <div class="relative flex min-h-dvh items-center justify-center overflow-hidden px-4 py-10">
    <div class="pointer-events-none absolute inset-0">
      <div class="absolute -top-40 start-1/4 size-[32rem] rounded-full bg-purple-600/30 blur-[120px]" />
      <div class="absolute -bottom-40 end-1/4 size-[28rem] rounded-full bg-pink-600/25 blur-[120px]" />
      <div class="absolute top-1/3 end-0 size-72 rounded-full bg-orange-500/15 blur-[100px]" />
    </div>
    <div class="relative w-full max-w-sm">
      <div class="mb-8 flex flex-col items-center text-center">
        <img src="/logo.png" class="mb-4 size-16 drop-shadow-2xl" alt="" />
        <slot name="title" />
      </div>
      <div class="glass rounded-3xl border border-line p-6 shadow-2xl">
        <slot />
      </div>
      <slot name="footer" />
    </div>
  </div>
</template>
