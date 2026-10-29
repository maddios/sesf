<script setup lang="ts">
import BaseButton, { type ButtonVariant } from './ui/BaseButton.vue';

/** Form submit button: a BaseButton (primary by default) that is disabled with a spinner while busy. */
withDefaults(
  defineProps<{
    busy?: boolean;
    disabled?: boolean;
    busyLabel?: string;
    variant?: ButtonVariant;
  }>(),
  { busy: false, disabled: false, busyLabel: 'Working', variant: 'primary' },
);
</script>

<template>
  <BaseButton
    type="submit"
    :variant="variant"
    :loading="busy"
    :loading-label="busyLabel"
    :disabled="disabled"
  >
    <slot />
  </BaseButton>
</template>
