<script setup lang="ts">
import { computed } from 'vue';
import { RouterView, useRoute } from 'vue-router';
import AppLayout from './layouts/AppLayout.vue';

/** Placeholder until the contact form lands: Contact Us currently does nothing visible. */
function openContact(): void {
  // The contact modal is added in a later task.
}

// Admin pages (/#/admin/...) bring their own shell: the /admin route component is AdminLayout.
const route = useRoute();
const isAdmin = computed(() => route.meta.layout === 'admin');
</script>

<template>
  <RouterView v-if="isAdmin" />
  <AppLayout
    v-else
    @contact="openContact"
  >
    <RouterView />
  </AppLayout>
</template>
