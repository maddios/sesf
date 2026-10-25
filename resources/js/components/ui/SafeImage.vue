<script setup lang="ts">
/**
 * <img> that falls back to a PlaceholderImage tile when the source is empty or fails to load, so a broken
 * logo shows a neutral tile with `placeholder` text (default: the alt text) instead of the browser's
 * broken-image rendering. Loads lazily by default.
 */
import { computed, ref, watch } from 'vue';
import PlaceholderImage from './PlaceholderImage.vue';

const props = withDefaults(
  defineProps<{
    src?: string | null;
    alt: string;
    placeholder?: string;
    width?: number | string;
    height?: number | string;
    loading?: 'lazy' | 'eager';
    imgClass?: string;
  }>(),
  { src: null, placeholder: undefined, width: undefined, height: undefined, loading: 'lazy', imgClass: '' },
);

const emit = defineEmits<{ error: [] }>();

const failed = ref(false);
watch(
  () => props.src,
  () => {
    failed.value = false;
  },
);

const showImage = computed(() => typeof props.src === 'string' && props.src !== '' && !failed.value);

function onError(): void {
  failed.value = true;
  emit('error');
}
</script>

<template>
  <img
    v-if="showImage"
    :src="src ?? undefined"
    :alt="alt"
    :width="width"
    :height="height"
    :loading="loading"
    decoding="async"
    :class="imgClass"
    @error="onError"
  >
  <PlaceholderImage
    v-else
    :label="placeholder ?? alt"
    :decorative="alt === '' && !placeholder"
  />
</template>
