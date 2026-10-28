<script setup lang="ts">
/**
 * Renders admin-authored HTML (agenda, schedule, location notes, event short descriptions). The server sanitizes this markup before it
 * is sent, so this is the single place in the events area where v-html is allowed. Never pass user input here.
 */
defineProps<{ html: string }>();
</script>

<template>
  <!-- eslint-disable vue/no-v-html -->
  <div
    class="event-html"
    v-html="html"
  />
  <!-- eslint-enable vue/no-v-html -->
</template>
