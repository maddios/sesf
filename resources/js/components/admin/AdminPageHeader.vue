<script setup lang="ts">
/**
 * Page title bar in the legacy admin's results-header style: the page heading (h1), an optional note (e.g. the
 * record count) and the page actions on the right (slot `actions`, e.g. "New sponsor").
 */
defineProps<{ title: string; note?: string }>();
</script>

<template>
  <div class="admin-page-header">
    <h1 class="admin-page-title">
      {{ title }}
      <span
        v-if="note"
        class="admin-page-note"
      >{{ note }}</span>
    </h1>
    <div
      v-if="$slots.actions"
      class="admin-page-actions"
    >
      <slot name="actions" />
    </div>
  </div>
</template>
