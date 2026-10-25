<script setup lang="ts">
import { RouterView } from 'vue-router';
import AppLayout from './layouts/AppLayout.vue';

/** Placeholder until the contact form lands: Contact Us currently does nothing visible. */
function openContact(): void {
  // The contact modal is added in a later task.
}
</script>

<template>
  <AppLayout @contact="openContact">
    <RouterView />
  </AppLayout>
</template>
