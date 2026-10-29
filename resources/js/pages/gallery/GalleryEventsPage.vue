<script setup lang="ts">
/**
 * Gallery home (/gallery): one card per event that has a gallery, newest first. Admins also get a
 * "Create gallery" link per event.
 */
import { computed } from 'vue';
import { RouterLink } from 'vue-router';
import AsyncBlock from '../../components/events/AsyncBlock.vue';
import SafeImage from '../../components/ui/SafeImage.vue';
import { fetchGalleryShows } from '../../api/gallery';
import { useAsyncData } from '../../composables/useAsyncData';
import { useAuthStore } from '../../stores/auth';

const auth = useAuthStore();
const shows = useAsyncData(fetchGalleryShows);
const list = computed(() => shows.data.value ?? []);
</script>

<template>
  <div>
    <h1 class="sr-only">
      Galleries
    </h1>
    <AsyncBlock
      :status="shows.status.value"
      loading-label="Loading galleries"
      error-message="The galleries could not be loaded right now."
      @retry="shows.reload()"
    >
      <p
        v-if="list.length === 0"
        class="py-4 text-muted"
      >
        No galleries yet.
      </p>
      <ul
        v-else
        class="grid gap-3 sm:grid-cols-2"
      >
        <li
          v-for="show in list"
          :key="show.id"
          class="overflow-hidden rounded-md bg-panel shadow-card"
        >
          <RouterLink
            :to="`/gallery/e${show.id}`"
            class="block no-underline"
            tabindex="-1"
            aria-hidden="true"
          >
            <SafeImage
              :src="show.imageUrl"
              resize="galleryEvent"
              alt=""
              :placeholder="show.name"
              width="459"
              height="367"
              img-class="h-auto w-full"
            />
          </RouterLink>
          <div class="flex items-center justify-between gap-3 px-2 py-2.5">
            <h2 class="text-xs font-bold text-heading">
              <RouterLink
                :to="`/gallery/e${show.id}`"
                class="text-heading no-underline hover:text-brand hover:underline"
              >
                {{ show.name }}
              </RouterLink>
            </h2>
            <RouterLink
              v-if="auth.isAdmin"
              :to="`/gallery/create/${show.id}`"
              class="shrink-0 text-sm"
              :aria-label="`Create gallery for ${show.name}`"
            >
              Create gallery
            </RouterLink>
          </div>
        </li>
      </ul>
    </AsyncBlock>
  </div>
</template>
