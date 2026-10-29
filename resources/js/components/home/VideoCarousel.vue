<script setup lang="ts">
/**
 * Accessible YouTube carousel (WAI-ARIA carousel pattern): previous/next buttons and dot buttons, a polite live
 * region naming the current slide, no autoplay. Only the visible slide renders an iframe (privacy-enhanced
 * youtube-nocookie.com), so hidden videos cost nothing.
 */
import { computed, ref } from 'vue';

export interface CarouselVideo {
  id: string;
  title: string;
}

const props = defineProps<{
  videos: CarouselVideo[];
  label: string;
}>();

const index = ref(0);
const announce = ref(false);
const total = computed(() => props.videos.length);
const current = computed(() => props.videos[index.value]);

function show(next: number): void {
  if (total.value === 0) {
    return;
  }
  index.value = (next + total.value) % total.value;
  announce.value = true;
}

function embedUrl(id: string): string {
  return `https://www.youtube-nocookie.com/embed/${encodeURIComponent(id)}?rel=0`;
}

const arrowClass = 'inline-flex h-10 w-10 items-center justify-center rounded-full border border-default-border bg-panel text-text hover:bg-subtle disabled:opacity-50';
</script>

<template>
  <section
    aria-roledescription="carousel"
    :aria-label="label"
  >
    <div
      v-if="current"
      role="group"
      aria-roledescription="slide"
      :aria-label="`${index + 1} of ${total}`"
      :aria-live="announce ? 'polite' : 'off'"
    >
      <div class="relative aspect-video w-full overflow-hidden rounded-md bg-heading">
        <iframe
          :key="current.id"
          class="absolute inset-0 h-full w-full"
          :src="embedUrl(current.id)"
          :title="current.title"
          loading="lazy"
          referrerpolicy="strict-origin-when-cross-origin"
          allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
          allowfullscreen
        />
      </div>
    </div>

    <div
      v-if="total > 1"
      class="mt-3 flex items-center justify-between gap-3"
    >
      <button
        type="button"
        :class="arrowClass"
        aria-label="Previous video"
        @click="show(index - 1)"
      >
        <svg
          class="h-5 w-5"
          viewBox="0 0 20 20"
          fill="currentColor"
          aria-hidden="true"
          focusable="false"
        ><path d="M12.79 5.23a.75.75 0 01-.02 1.06L8.83 10l3.94 3.71a.75.75 0 11-1.04 1.08l-4.5-4.25a.75.75 0 010-1.08l4.5-4.25a.75.75 0 011.06.02z" /></svg>
      </button>

      <ul class="flex items-center gap-1">
        <li
          v-for="(video, n) in videos"
          :key="video.id"
        >
          <button
            type="button"
            class="flex h-6 w-6 items-center justify-center rounded-full"
            :aria-label="`Show video ${n + 1}: ${video.title}`"
            :aria-current="n === index ? 'true' : undefined"
            @click="show(n)"
          >
            <span
              class="block h-2.5 w-2.5 rounded-full border border-brand"
              :class="n === index ? 'bg-brand' : 'bg-panel'"
              aria-hidden="true"
            />
          </button>
        </li>
      </ul>

      <button
        type="button"
        :class="arrowClass"
        aria-label="Next video"
        @click="show(index + 1)"
      >
        <svg
          class="h-5 w-5"
          viewBox="0 0 20 20"
          fill="currentColor"
          aria-hidden="true"
          focusable="false"
        ><path d="M7.21 14.77a.75.75 0 01.02-1.06L11.17 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" /></svg>
      </button>
    </div>
  </section>
</template>
