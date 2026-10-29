<script setup lang="ts">
/**
 * /gallery/create/{eventId}/{galleryId?}. Admins name a new gallery (a sub-gallery when galleryId is set).
 * Members are sent straight to their own album, which the server creates (hidden) on first visit.
 */
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import BaseAlert from '../../components/ui/BaseAlert.vue';
import BaseButton from '../../components/ui/BaseButton.vue';
import { fetchEventInfo } from '../../api/events';
import { createGallery, errorMessage, fetchGalleryInfo } from '../../api/gallery';
import { useAuthStore } from '../../stores/auth';

const props = defineProps<{ eventId: number; galleryId?: number }>();

const auth = useAuthStore();
const router = useRouter();

const eventName = ref('');
const parentTitle = ref('');
const title = ref('');
const saving = ref(false);
const error = ref('');

async function create(): Promise<void> {
  saving.value = true;
  error.value = '';
  try {
    const gallery = await createGallery(
      auth.isAdmin
        ? { showId: props.eventId, title: title.value, parentId: props.galleryId }
        : { showId: props.eventId },
    );
    await router.replace(`/gallery/manage/${gallery.id}`);
  } catch (failure) {
    error.value = errorMessage(failure, 'The gallery could not be created.');
  } finally {
    saving.value = false;
  }
}

onMounted(() => {
  void fetchEventInfo(props.eventId).then((info) => (eventName.value = info.name)).catch(() => undefined);
  if (props.galleryId !== undefined) {
    void fetchGalleryInfo(props.galleryId).then((gallery) => (parentTitle.value = gallery?.title ?? '')).catch(() => undefined);
  }
  if (!auth.isAdmin) void create();
});
</script>

<template>
  <div class="max-w-xl">
    <h1 class="section-heading">
      {{ eventName || 'Gallery' }}<span v-if="parentTitle"> — {{ parentTitle }}</span>
    </h1>

    <BaseAlert
      v-if="error"
      variant="error"
      class="mb-4"
    >
      <p>{{ error }}</p>
    </BaseAlert>

    <template v-if="!auth.isAdmin">
      <p class="mb-2 text-muted">
        Your album is created at the top level with the same name style as the member list (first name and last
        initial). It stays hidden until an admin enables it.
      </p>
      <p
        v-if="saving"
        class="text-muted"
      >
        Setting up your album…
      </p>
    </template>

    <form
      v-else
      class="space-y-4"
      @submit.prevent="create"
    >
      <div>
        <label
          for="gallery-title"
          class="mb-1 block font-medium"
        >Title</label>
        <input
          id="gallery-title"
          v-model="title"
          type="text"
          required
          maxlength="256"
          class="form-control w-full"
        >
      </div>
      <BaseButton
        type="submit"
        variant="primary"
        :loading="saving"
        loading-label="Creating gallery"
      >
        Create
      </BaseButton>
    </form>
  </div>
</template>
