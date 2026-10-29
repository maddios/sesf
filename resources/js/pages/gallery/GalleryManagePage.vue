<script setup lang="ts">
/**
 * /gallery/manage/{galleryId}: the owner or an admin uploads photos, edits titles, reorders (saved with "Save
 * order"), picks the cover and deletes photos. Admins also enable or disable the gallery. Owners of a hidden
 * gallery see that it waits for review.
 */
import { computed, onMounted, ref, shallowRef } from 'vue';
import { RouterLink } from 'vue-router';
import AsyncBlock from '../../components/events/AsyncBlock.vue';
import ReorderableList from '../../components/admin/ReorderableList.vue';
import BaseAlert from '../../components/ui/BaseAlert.vue';
import BaseButton from '../../components/ui/BaseButton.vue';
import GalleryUploader from '../../components/gallery/GalleryUploader.vue';
import {
  errorMessage,
  fetchGalleryInfo,
  fetchGalleryItems,
  removeGalleryItem,
  saveGalleryItems,
  setGalleryCover,
  setGalleryEnabled,
  type Gallery,
  type GalleryItem,
} from '../../api/gallery';
import { LEGACY_IMAGE_SIZES, resizedImageUrl } from '../../api/legacyImages';
import { setPageTitle } from '../../composables/usePageTitle';
import type { AsyncStatus } from '../../composables/useAsyncData';
import { useAuthStore } from '../../stores/auth';

const props = defineProps<{ galleryId: number }>();

const auth = useAuthStore();
const status = ref<AsyncStatus>('loading');
const gallery = shallowRef<Gallery | null>(null);
const items = ref<GalleryItem[]>([]);
const saving = ref(false);
const message = ref<{ text: string; variant: 'success' | 'error' } | null>(null);

const isOwner = computed(() => gallery.value !== null && auth.user !== null && gallery.value.userId === auth.user.userId);
const canManage = computed(() => auth.isAdmin || isOwner.value);

async function loadItems(): Promise<void> {
  items.value = (await fetchGalleryItems({ galleryId: props.galleryId })).items;
}

async function load(): Promise<void> {
  status.value = 'loading';
  try {
    gallery.value = await fetchGalleryInfo(props.galleryId);
    if (gallery.value === null) throw new Error('Gallery not found');
    setPageTitle(`Manage ${gallery.value.title}`);
    await loadItems();
    status.value = 'ready';
  } catch {
    status.value = 'error';
  }
}

function itemLabel(item: GalleryItem): string {
  const index = items.value.findIndex((row) => row.id === item.id);
  return item.title === '' ? `photo ${index + 1}` : item.title;
}

function reorder(ids: number[]): void {
  const byId = new Map(items.value.map((item) => [item.id, item]));
  items.value = ids.map((id) => byId.get(id)).filter((item): item is GalleryItem => item !== undefined);
}

async function save(): Promise<void> {
  saving.value = true;
  message.value = null;
  try {
    const page = await saveGalleryItems(
      props.galleryId,
      items.value.map((item, index) => ({ id: item.id, title: item.title, sort_order: index + 1 })),
    );
    items.value = page.items;
    message.value = { text: 'Saved', variant: 'success' };
  } catch {
    message.value = { text: 'Failed to save', variant: 'error' };
  } finally {
    saving.value = false;
  }
}

async function toggleEnabled(): Promise<void> {
  const current = gallery.value;
  if (current === null) return;
  try {
    await setGalleryEnabled(current.id, !current.isEnabled);
    gallery.value = { ...current, isEnabled: !current.isEnabled };
  } catch (failure) {
    message.value = { text: errorMessage(failure, 'The gallery could not be updated.'), variant: 'error' };
  }
}

async function remove(item: GalleryItem): Promise<void> {
  if (!window.confirm('Delete this photo? This cannot be undone.')) return;
  try {
    await removeGalleryItem(props.galleryId, item.id);
    items.value = items.value.filter((row) => row.id !== item.id);
    if (gallery.value?.coverItemId === item.id) gallery.value = { ...gallery.value, coverItemId: null };
  } catch (failure) {
    message.value = { text: errorMessage(failure, 'The photo could not be deleted.'), variant: 'error' };
  }
}

async function makeCover(item: GalleryItem): Promise<void> {
  const current = gallery.value;
  if (current === null || current.coverItemId === item.id) return;
  if (!window.confirm('Use this image as the gallery cover?')) return;
  try {
    await setGalleryCover(current.id, item.id);
    gallery.value = { ...current, coverItemId: item.id, coverImageUrl: item.imageUrl };
  } catch (failure) {
    message.value = { text: errorMessage(failure, 'The cover could not be changed.'), variant: 'error' };
  }
}

async function onUploaded(): Promise<void> {
  try {
    await loadItems();
  } catch {
    message.value = { text: 'The photo list could not be refreshed.', variant: 'error' };
  }
}

onMounted(() => void load());
</script>

<template>
  <div>
    <AsyncBlock
      :status="status"
      loading-label="Loading gallery"
      error-message="This gallery could not be loaded right now."
      @retry="load()"
    >
      <template v-if="gallery">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
          <h1 class="section-heading mb-0">
            {{ gallery.title }}
          </h1>
          <RouterLink
            :to="`/gallery/e${gallery.showId}/${gallery.id}`"
            class="font-semibold"
          >
            Go To Gallery
          </RouterLink>
        </div>

        <div
          v-if="auth.isAdmin"
          class="mb-4"
        >
          <BaseButton
            :variant="gallery.isEnabled ? 'primary' : 'danger'"
            @click="toggleEnabled"
          >
            {{ gallery.isEnabled ? 'Disable' : 'Enable' }}
          </BaseButton>
        </div>

        <p
          v-if="isOwner && !auth.isAdmin && !gallery.isEnabled"
          class="mb-4 text-muted"
        >
          This gallery is disabled until an admin reviews it. It will not appear in the event gallery list for others
          until an admin enables it.
        </p>

        <BaseAlert
          v-if="!canManage"
          variant="error"
        >
          <p>You can't manage this gallery.</p>
        </BaseAlert>

        <template v-else>
          <GalleryUploader
            class="mb-8"
            :gallery-id="gallery.id"
            @uploaded="onUploaded"
          />

          <section
            v-if="items.length > 0"
            aria-labelledby="gallery-items-heading"
            class="space-y-3"
          >
            <h2
              id="gallery-items-heading"
              class="item-title"
            >
              Current gallery items
            </h2>
            <p class="text-muted">
              Drag photos to reorder. Choose one as the cover; that image appears as the gallery thumbnail when enabled.
            </p>
            <div class="flex flex-wrap items-center gap-3">
              <BaseButton
                variant="primary"
                :loading="saving"
                loading-label="Saving order"
                @click="save"
              >
                Save order
              </BaseButton>
              <p
                v-if="message"
                role="status"
                :class="message.variant === 'error' ? 'text-danger' : 'text-success'"
              >
                {{ message.text }}
              </p>
            </div>
            <ReorderableList
              :items="items"
              :item-label="itemLabel"
              label="Gallery photos"
              @reorder="reorder"
            >
              <template #default="{ item, index }">
                <div class="flex flex-wrap items-center gap-3">
                  <img
                    :src="resizedImageUrl(item.imageUrl, LEGACY_IMAGE_SIZES.galleryManageItem)"
                    alt=""
                    loading="lazy"
                    width="160"
                    height="115"
                    class="aspect-[320/230] w-32 rounded-sm object-cover"
                  >
                  <div class="min-w-40 flex-1">
                    <label
                      :for="`gallery-item-title-${item.id}`"
                      class="mb-1 block text-sm font-medium"
                    >Title for photo {{ index + 1 }}</label>
                    <input
                      :id="`gallery-item-title-${item.id}`"
                      v-model="item.title"
                      type="text"
                      maxlength="256"
                      class="form-control w-full"
                    >
                  </div>
                  <div class="flex gap-2">
                    <BaseButton
                      size="sm"
                      :variant="gallery.coverItemId === item.id ? 'primary' : 'default'"
                      :aria-pressed="gallery.coverItemId === item.id ? 'true' : 'false'"
                      :aria-label="`Cover for photo ${index + 1}`"
                      @click="makeCover(item)"
                    >
                      Cover
                    </BaseButton>
                    <BaseButton
                      size="sm"
                      variant="danger"
                      :aria-label="`Delete photo ${index + 1}`"
                      @click="remove(item)"
                    >
                      Delete
                    </BaseButton>
                  </div>
                </div>
              </template>
            </ReorderableList>
          </section>
        </template>
      </template>
    </AsyncBlock>
  </div>
</template>
