<script setup lang="ts">
/**
 * White card with the legacy soft shadow. `title` renders a section heading (h2 by default) that labels the card.
 * Use the `title` slot for richer headings and `footer` for actions.
 */
import { computed, useId, useSlots } from 'vue';

const props = withDefaults(
  defineProps<{
    title?: string;
    headingLevel?: 2 | 3 | 4;
    padded?: boolean;
    as?: 'section' | 'article' | 'div';
  }>(),
  { title: undefined, headingLevel: 2, padded: true, as: 'section' },
);

const slots = useSlots();
const headingId = useId();
const hasHeading = computed(() => props.title !== undefined || slots.title !== undefined);
const headingTag = computed(() => `h${props.headingLevel}`);
</script>

<template>
  <component
    :is="as"
    class="rounded-md border border-border bg-panel shadow-card"
    :aria-labelledby="hasHeading && as !== 'div' ? headingId : undefined"
  >
    <div :class="padded ? 'p-4 sm:p-5' : ''">
      <component
        :is="headingTag"
        v-if="hasHeading"
        :id="headingId"
        class="mb-3 text-lg font-semibold text-heading"
      >
        <slot name="title">
          {{ title }}
        </slot>
      </component>
      <slot />
    </div>
    <div
      v-if="$slots.footer"
      class="border-t border-border bg-subtle px-4 py-3 sm:px-5"
    >
      <slot name="footer" />
    </div>
  </component>
</template>
