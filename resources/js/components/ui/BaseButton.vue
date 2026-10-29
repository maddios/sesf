<script setup lang="ts">
import { computed } from 'vue';
import { RouterLink, type RouteLocationRaw } from 'vue-router';
import BaseSpinner from './BaseSpinner.vue';

export type ButtonVariant = 'primary' | 'default' | 'danger' | 'link';
export type ButtonSize = 'sm' | 'md' | 'lg';

/**
 * Flat button in the legacy Bootstrap colours. Renders a <button>, or a link styled as a button when `to`
 * (router link) or `href` is given. `loading` disables a button, shows a spinner and announces `loadingLabel`.
 */
const props = withDefaults(
  defineProps<{
    variant?: ButtonVariant;
    size?: ButtonSize;
    type?: 'button' | 'submit' | 'reset';
    loading?: boolean;
    loadingLabel?: string;
    disabled?: boolean;
    block?: boolean;
    to?: RouteLocationRaw;
    href?: string;
  }>(),
  {
    variant: 'default',
    size: 'md',
    type: 'button',
    loading: false,
    loadingLabel: 'Working',
    disabled: false,
    block: false,
    to: undefined,
    href: undefined,
  },
);

const VARIANTS: Record<ButtonVariant, string> = {
  primary: 'border border-primary bg-primary text-white hover:border-primary-hover hover:bg-primary-hover hover:text-white',
  default: 'border border-default-border bg-default text-text hover:bg-default-hover hover:text-text',
  danger: 'border border-danger bg-danger text-white hover:border-danger-hover hover:bg-danger-hover hover:text-white',
  link: 'border border-transparent bg-transparent text-brand underline underline-offset-2 hover:text-brand-dark',
};

const SIZES: Record<ButtonSize, string> = {
  sm: 'px-2.5 py-1 text-[0.8125rem]',
  md: 'px-3.5 py-1.5 text-sm',
  lg: 'px-5 py-2.5 text-base',
};

const classes = computed(() => [
  // ui-btn hooks let an area (e.g. the admin, body.admin-ui) restyle buttons without touching each page.
  `ui-btn ui-btn--${props.variant} ui-btn--${props.size}`,
  'inline-flex items-center justify-center gap-2 rounded-sm font-medium leading-5 no-underline transition-colors',
  'disabled:cursor-not-allowed disabled:opacity-60',
  VARIANTS[props.variant],
  props.variant === 'link' ? 'px-0 py-1 text-sm' : SIZES[props.size],
  props.block ? 'w-full' : '',
]);
</script>

<template>
  <RouterLink
    v-if="to !== undefined"
    :to="to"
    :class="classes"
  >
    <slot />
  </RouterLink>
  <a
    v-else-if="href !== undefined"
    :href="href"
    :class="classes"
  >
    <slot />
  </a>
  <button
    v-else
    :type="type"
    :class="classes"
    :disabled="disabled || loading"
    :aria-busy="loading ? 'true' : undefined"
  >
    <BaseSpinner
      v-if="loading"
      size="sm"
    />
    <slot />
    <span
      v-if="loading"
      class="sr-only"
    >{{ loadingLabel }}</span>
  </button>
</template>
