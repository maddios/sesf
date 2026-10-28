<script setup lang="ts">
/**
 * Photo viewer for a gallery: one photo at a time with Previous / Next, the date taken, the uploader and a link
 * to the original. Keys (as legacy): Left/Up previous, Right/Down/Space next, Escape closes. A horizontal swipe
 * moves between photos, the Zoom button shows the photo at full size, and the neighbouring photos are preloaded.
 * Navigation waits until the current photo has loaded or failed.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import BaseButton from '../ui/BaseButton.vue';
import BaseModal from '../ui/BaseModal.vue';
import type { GalleryItem } from '../../api/gallery';
import { LEGACY_IMAGE_SIZES, resizedImageUrl } from '../../api/legacyImages';

const props = defineProps<{
  open: boolean;
  items: GalleryItem[];
  startIndex: number;
}>();

const emit = defineEmits<{ 'update:open': [open: boolean] }>();

const SWIPE_DISTANCE = 50;

const index = ref(0);
const loading = ref(true);
const zoomed = ref(false);
let swipeStartX: number | null = null;

const item = computed<GalleryItem | undefined>(() => props.items[index.value]);
const hasPrev = computed(() => index.value > 0);
const hasNext = computed(() => index.value < props.items.length - 1);
const title = computed(() => `Photo ${index.value + 1} of ${props.items.length}`);

watch(
  () => [props.open, props.startIndex] as const,
  ([open, start]) => {
    if (open) {
      index.value = Math.min(Math.max(start, 0), Math.max(props.items.length - 1, 0));
      loading.value = true;
    }
  },
  { immediate: true },
);

function preloadNeighbours(): void {
  for (const neighbour of [props.items[index.value - 1], props.items[index.value + 1]]) {
    if (neighbour !== undefined && neighbour.imageUrl !== '') new Image().src = resizedImageUrl(neighbour.imageUrl, LEGACY_IMAGE_SIZES.galleryLightbox);
  }
}

function onLoaded(): void {
  loading.value = false;
  preloadNeighbours();
}

function onPointerDown(event: PointerEvent): void {
  swipeStartX = zoomed.value ? null : event.clientX;
}

function onPointerUp(event: PointerEvent): void {
  if (swipeStartX === null) return;
  const distance = event.clientX - swipeStartX;
  swipeStartX = null;
  if (Math.abs(distance) >= SWIPE_DISTANCE) go(distance < 0 ? 1 : -1);
}

function go(step: -1 | 1): void {
  if (loading.value) return;
  const next = index.value + step;
  if (next < 0 || next >= props.items.length) return;
  index.value = next;
  loading.value = true;
  zoomed.value = false;
}

function close(): void {
  emit('update:open', false);
}

function onKeydown(event: KeyboardEvent): void {
  if (!props.open) return;
  if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
    event.preventDefault();
    go(-1);
  } else if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
    event.preventDefault();
    go(1);
  } else if (event.key === ' ' && !(event.target instanceof HTMLButtonElement || event.target instanceof HTMLAnchorElement)) {
    event.preventDefault();
    go(1);
  }
}

watch(
  () => props.open,
  (open) => {
    if (open) window.addEventListener('keydown', onKeydown);
    else window.removeEventListener('keydown', onKeydown);
  },
  { immediate: true },
);

onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
  <BaseModal
    :open="open"
    :title="title"
    size="lg"
    close-label="Close photo viewer"
    @update:open="emit('update:open', $event)"
  >
    <div
      v-if="item"
      class="space-y-3"
    >
      <div
        class="flex max-h-[70vh] min-h-48 touch-pan-y bg-subtle"
        :class="zoomed ? 'overflow-auto' : 'items-center justify-center overflow-hidden'"
        data-testid="lightbox-stage"
        @pointerdown="onPointerDown"
        @pointerup="onPointerUp"
        @pointercancel="swipeStartX = null"
      >
        <img
          :key="item.id"
          :src="resizedImageUrl(item.imageUrl, LEGACY_IMAGE_SIZES.galleryLightbox)"
          :alt="item.title"
          class="transition-opacity"
          :class="[loading ? 'opacity-0' : 'opacity-100', zoomed ? 'max-w-none' : 'max-h-[70vh] w-auto max-w-full object-contain']"
          draggable="false"
          @load="onLoaded"
          @error="loading = false"
        >
      </div>
      <div class="flex items-center justify-between gap-3">
        <BaseButton
          v-if="hasPrev"
          :disabled="loading"
          @click="go(-1)"
        >
          <span aria-hidden="true">←</span> Previous
        </BaseButton>
        <span v-else />
        <BaseButton
          size="sm"
          :aria-pressed="zoomed ? 'true' : 'false'"
          @click="zoomed = !zoomed"
        >
          Zoom
        </BaseButton>
        <BaseButton
          v-if="hasNext"
          :disabled="loading"
          @click="go(1)"
        >
          Next <span aria-hidden="true">→</span>
        </BaseButton>
      </div>
      <p class="flex flex-wrap gap-x-6 gap-y-1 text-sm text-muted">
        <span v-if="item.dateTaken">Date Taken: {{ item.dateTaken }}</span>
        <span v-if="item.user">
          Submitted by:
          <RouterLink
            v-if="item.user.profilePath"
            :to="item.user.profilePath"
            @click="close"
          >{{ item.user.fullName }}</RouterLink>
          <template v-else>{{ item.user.fullName }}</template>
        </span>
        <a
          :href="item.imageUrl"
          target="_blank"
          rel="noopener"
        >Original Image</a>
      </p>
    </div>
  </BaseModal>
</template>
