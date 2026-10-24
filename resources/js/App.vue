<script setup lang="ts">
import { RouterView } from 'vue-router';
</script>

<template>
  <div class="min-h-screen flex flex-col">
    <header class="border-b border-slate-200 bg-white px-4 py-3">
      <!-- Header placeholder: navigation and auth controls land here -->
      <span class="font-semibold">Southeast Sharkfest</span>
    </header>
    <main class="flex-1 px-4 py-6">
      <RouterView />
    </main>
  </div>
</template>
