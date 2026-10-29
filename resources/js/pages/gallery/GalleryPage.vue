<script setup lang="ts">
/**
 * One event's gallery (/gallery/e{eventId}) or one gallery in it (/gallery/e{eventId}/{galleryId}): breadcrumb,
 * admin and member actions, sub-galleries, the photo grid with infinite scroll and "Load more images", and the
 * photo viewer.
 */
import { computed, onBeforeUnmount, ref, toRef, watch } from 'vue';
import { RouterLink } from 'vue-router';
import AsyncBlock from '../../components/events/AsyncBlock.vue';
import BaseAccordion from '../../components/ui/BaseAccordion.vue';
import BaseAlert from '../../components/ui/BaseAlert.vue';
import BaseButton from '../../components/ui/BaseButton.vue';
import GalleryBreadcrumb from '../../components/gallery/GalleryBreadcrumb.vue';
import GalleryLightbox from '../../components/gallery/GalleryLightbox.vue';
import type { GalleryItem } from '../../api/gallery';
import { LEGACY_IMAGE_SIZES, resizedImageSrcset, resizedImageUrl } from '../../api/legacyImages';
import { useGalleryPage } from '../../composables/gallery/useGalleryPage';
import { setPageTitle } from '../../composables/usePageTitle';
import { useAuthStore } from '../../stores/auth';

const props = defineProps<{ eventId: number; galleryId?: number }>();

const auth = useAuthStore();
const page = useGalleryPage(toRef(props, 'eventId'), toRef(props, 'galleryId'));
const { status, breadcrumb, subGalleries, gallery, items, hasMore, loadingMore, member } = page;

const viewerOpen = ref(false);
const viewerItems = ref<GalleryItem[]>([]);
const viewerStart = ref(0);
const sentinel = ref<HTMLElement | null>(null);

const heading = computed(() => gallery.value?.title ?? breadcrumb.value?.show.name ?? 'Gallery');
const isMember = computed(() => auth.isLoggedIn && !auth.isAdmin);
const showAddMyPhotos = computed(() => member.value.checkedIn && gallery.value === null && member.value.memberGalleryId === null);
const showEditMyPhotos = computed(() => {
  const own = member.value.memberGalleryId;
  return member.value.checkedIn && own !== null && (gallery.value === null || gallery.value.id === own);
});
const createLink = computed(() => `/gallery/create/${props.eventId}${gallery.value === null ? '' : `/${gallery.value.id}`}`);

watch(
  () => [props.eventId, props.galleryId] as const,
  () => void page.load(),
  { immediate: true },
);

watch(
  () => [props.eventId, isMember.value] as const,
  ([, memberViewer]) => void page.loadMember(memberViewer).catch(() => undefined),
  { immediate: true },
);

watch(heading, (title) => setPageTitle(title));

/** Opens at once on the loaded photos; when more pages exist, the full list replaces them once it arrives. */
function openViewer(item: GalleryItem): void {
  viewerItems.value = items.value;
  viewerStart.value = Math.max(items.value.findIndex((row) => row.id === item.id), 0);
  viewerOpen.value = true;
  if (!hasMore.value) return;
  void page.allItems().then((list) => {
    if (viewerOpen.value) viewerItems.value = list;
  });
}

let observer: IntersectionObserver | null = null;
watch(sentinel, (element) => {
  observer?.disconnect();
  observer = null;
  if (element === null || typeof IntersectionObserver === 'undefined') return;
  observer = new IntersectionObserver((entries) => {
    if (entries.some((entry) => entry.isIntersecting)) void page.loadMore();
  }, { rootMargin: '300px' });
  observer.observe(element);
});
onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
  <div>
    <BaseAlert
      v-if="status === 'forbidden'"
      variant="error"
    >
      <p>This gallery is not available.</p>
    </BaseAlert>
    <AsyncBlock
      v-else
      :status="status"
      loading-label="Loading gallery"
      error-message="This gallery could not be loaded right now."
      @retry="page.load()"
    >
      <GalleryBreadcrumb
        v-if="breadcrumb"
        :breadcrumb="breadcrumb"
        :current-title="gallery?.title ?? null"
      />

      <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h1 class="section-heading mb-0">
          {{ heading }}
        </h1>
        <div
          v-if="auth.isAdmin"
          class="flex flex-wrap gap-2"
        >
          <BaseButton :to="createLink">
            {{ gallery ? 'Create Sub-Gallery' : 'Create Gallery' }}
          </BaseButton>
          <BaseButton
            v-if="gallery"
            :to="`/gallery/manage/${gallery.id}`"
          >
            Manage Gallery
          </BaseButton>
        </div>
        <div
          v-else-if="isMember && member.checkedIn"
          class="flex flex-wrap gap-2"
        >
          <BaseButton
            v-if="showAddMyPhotos"
            :to="`/gallery/create/${eventId}`"
          >
            Add my photos
          </BaseButton>
          <BaseButton
            v-if="showEditMyPhotos"
            :to="`/gallery/manage/${member.memberGalleryId}`"
          >
            Edit my photos
          </BaseButton>
        </div>
      </div>

      <BaseAccordion
        v-if="subGalleries.length > 0"
        class="mb-6"
        :items="[{ id: 'sub-galleries', title: 'Sub-Galleries' }]"
        :default-open="['sub-galleries']"
        :heading-level="2"
      >
        <template #panel>
          <ul class="grid grid-cols-2 gap-4 sm:grid-cols-4 lg:grid-cols-6">
            <li
              v-for="sub in subGalleries"
              :key="sub.id"
            >
              <RouterLink
                :to="`/gallery/e${eventId}/${sub.id}`"
                class="block overflow-hidden rounded-sm bg-panel no-underline shadow-card"
              >
                <img
                  v-if="sub.coverImageUrl"
                  :src="resizedImageUrl(sub.coverImageUrl, LEGACY_IMAGE_SIZES.gallerySubCover)"
                  :srcset="resizedImageSrcset(sub.coverImageUrl, LEGACY_IMAGE_SIZES.gallerySubCover) || undefined"
                  alt=""
                  loading="lazy"
                  width="200"
                  height="140"
                  class="aspect-[200/140] w-full object-cover"
                >
                <span class="block p-2 text-xs font-bold text-heading">
                  {{ sub.title }}{{ sub.isEnabled ? '' : ' (disabled)' }}
                </span>
              </RouterLink>
            </li>
          </ul>
        </template>
      </BaseAccordion>

      <p
        v-if="items.length === 0"
        class="py-4 text-muted"
      >
        No photos yet.
      </p>
      <ul
        v-else
        class="grid grid-cols-2 gap-2 sm:grid-cols-3 sm:gap-4"
        aria-label="Photos"
      >
        <li
          v-for="(item, index) in items"
          :key="item.id"
        >
          <button
            type="button"
            class="block w-full overflow-hidden rounded-sm bg-subtle shadow-card"
            :aria-label="`View photo ${index + 1}${item.title ? `: ${item.title}` : ''}`"
            @click="openViewer(item)"
          >
            <img
              :src="resizedImageUrl(item.imageUrl, LEGACY_IMAGE_SIZES.galleryItem)"
              :srcset="resizedImageSrcset(item.imageUrl, LEGACY_IMAGE_SIZES.galleryItem) || undefined"
              alt=""
              loading="lazy"
              decoding="async"
              width="320"
              height="230"
              class="aspect-[320/230] w-full object-cover"
            >
          </button>
        </li>
      </ul>

      <div
        v-if="hasMore"
        ref="sentinel"
        class="mt-6 text-center"
      >
        <BaseButton
          :loading="loadingMore"
          loading-label="Loading more images"
          @click="page.loadMore()"
        >
          Load More Images
        </BaseButton>
      </div>
    </AsyncBlock>

    <GalleryLightbox
      v-model:open="viewerOpen"
      :items="viewerItems"
      :start-index="viewerStart"
    />
  </div>
</template>
