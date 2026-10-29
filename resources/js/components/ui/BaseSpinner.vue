<script setup lang="ts">
/**
 * Loading indicator. Without a label it is decorative (aria-hidden), for use inside a control that already
 * announces its busy state. With a label it is a polite status region that screen readers announce.
 */
withDefaults(
  defineProps<{
    label?: string;
    size?: 'sm' | 'md' | 'lg';
  }>(),
  { label: undefined, size: 'md' },
);

const sizes = { sm: 'h-4 w-4', md: 'h-6 w-6', lg: 'h-10 w-10' } as const;
</script>

<template>
  <span
    class="inline-flex items-center gap-2"
    :role="label ? 'status' : undefined"
  >
    <svg
      class="animate-spin text-current"
      :class="sizes[size]"
      viewBox="0 0 24 24"
      fill="none"
      aria-hidden="true"
      focusable="false"
    >
      <circle
        class="opacity-25"
        cx="12"
        cy="12"
        r="10"
        stroke="currentColor"
        stroke-width="4"
      />
      <path
        class="opacity-75"
        fill="currentColor"
        d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"
      />
    </svg>
    <span
      v-if="label"
      class="sr-only"
    >{{ label }}</span>
  </span>
</template>
