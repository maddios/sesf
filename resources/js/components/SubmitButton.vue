<script setup lang="ts">
withDefaults(
  defineProps<{
    busy?: boolean;
    disabled?: boolean;
    busyLabel?: string;
  }>(),
  { busy: false, disabled: false, busyLabel: 'Working' },
);
</script>

<template>
  <button
    type="submit"
    class="inline-flex items-center justify-center gap-2 rounded-md bg-blue-800 px-4 py-2 text-sm font-medium text-white hover:bg-blue-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-800 disabled:cursor-not-allowed disabled:opacity-60"
    :disabled="busy || disabled"
    :aria-busy="busy ? 'true' : undefined"
  >
    <svg
      v-if="busy"
      class="h-4 w-4 animate-spin"
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
    <slot />
    <span
      v-if="busy"
      class="sr-only"
    >{{ busyLabel }}</span>
  </button>
</template>
