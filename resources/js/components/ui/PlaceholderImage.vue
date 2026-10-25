<script setup lang="ts">
/**
 * Neutral tile shown instead of a missing or broken image. It shows `label` (e.g. a sponsor name) as text in a
 * styled tile, never the browser's broken-image icon with raw alt text. Exposed as role="img" with the label as
 * its accessible name, unless `decorative`.
 */
withDefaults(
  defineProps<{
    label?: string;
    decorative?: boolean;
  }>(),
  { label: '', decorative: false },
);
</script>

<template>
  <span
    class="flex h-full min-h-12 w-full items-center justify-center overflow-hidden rounded-sm border border-border bg-subtle p-2 text-center text-sm font-medium text-muted"
    :role="decorative || label === '' ? undefined : 'img'"
    :aria-label="decorative || label === '' ? undefined : label"
    :aria-hidden="decorative || label === '' ? 'true' : undefined"
    data-placeholder
  >
    <span
      aria-hidden="true"
      class="line-clamp-3 break-words"
    >{{ label }}</span>
  </span>
</template>
