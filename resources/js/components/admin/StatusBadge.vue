<script setup lang="ts">
/** Small text badge for a record state (Enabled, Paid, Cancelled...). The label is the text; colour is extra. */
export type StatusVariant = 'success' | 'neutral' | 'warning' | 'danger' | 'info';

withDefaults(defineProps<{ label: string; variant?: StatusVariant }>(), { variant: 'neutral' });

const VARIANTS: Record<StatusVariant, string> = {
  success: 'border-success-border bg-success-bg text-success-fg',
  neutral: 'border-border bg-subtle text-muted',
  warning: 'border-warning-border bg-warning-bg text-warning-fg',
  danger: 'border-danger-border bg-danger-bg text-danger-fg',
  info: 'border-info-border bg-info-bg text-info-fg',
};
</script>

<template>
  <span
    class="inline-flex items-center rounded-sm border px-2 py-0.5 text-xs font-semibold whitespace-nowrap"
    :class="VARIANTS[variant]"
  >{{ label }}</span>
</template>
