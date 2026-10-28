<script setup lang="ts">
/**
 * Compact icon-only button, only where the icon alone is obvious (close, move up/down, sort, clear). Row actions use
 * AdminRowActions instead. `label` is the accessible name and the tooltip. Renders a link instead when `href` is given.
 */
import AdminIcon, { type AdminIconName } from './AdminIcon.vue';

withDefaults(
  defineProps<{
    icon: AdminIconName;
    label: string;
    variant?: 'default' | 'danger' | 'primary';
    disabled?: boolean;
    href?: string;
    target?: string;
  }>(),
  { variant: 'default', disabled: false, href: undefined, target: undefined },
);
</script>

<template>
  <a
    v-if="href !== undefined"
    :href="href"
    :target="target"
    :rel="target === '_blank' ? 'noopener' : undefined"
    class="a2-icon-btn"
    :class="`a2-icon-btn--${variant}`"
    :aria-label="label"
    :title="label"
  ><AdminIcon :name="icon" /></a>
  <button
    v-else
    type="button"
    class="a2-icon-btn"
    :class="`a2-icon-btn--${variant}`"
    :aria-label="label"
    :title="label"
    :disabled="disabled"
  >
    <AdminIcon :name="icon" />
  </button>
</template>
