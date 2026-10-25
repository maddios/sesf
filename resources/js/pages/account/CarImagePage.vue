<script setup lang="ts">
/** Car photo (legacy car/:id/new-image): the current photo and an uploader. Reached after adding a car. */
import { computed, ref, shallowRef, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { fetchCarDetail, uploadCarImage, type CarDetail } from '../../api/account';
import { ApiError } from '../../api/client';
import BaseAlert from '../../components/ui/BaseAlert.vue';
import BaseSpinner from '../../components/ui/BaseSpinner.vue';
import BaseButton from '../../components/ui/BaseButton.vue';
import ImageUploadForm from '../../components/account/ImageUploadForm.vue';

const props = defineProps<{ carId: number }>();

type Status = 'loading' | 'ready' | 'notFound' | 'error';

const status = ref<Status>('loading');
const car = shallowRef<CarDetail | null>(null);
const stamp = ref(Date.now());

/** `silent` refreshes the photo without the loading state, so the upload notice stays on screen. */
async function load(silent = false): Promise<void> {
  if (!silent) {
    status.value = 'loading';
  }
  try {
    car.value = await fetchCarDetail(props.carId);
    stamp.value = Date.now();
    status.value = 'ready';
  } catch (failure) {
    if (silent) {
      return;
    }
    car.value = null;
    status.value = failure instanceof ApiError && failure.status === 404 ? 'notFound' : 'error';
  }
}

watch(() => props.carId, () => void load(), { immediate: true });

const currentSrc = computed(() => {
  const url = car.value?.imageUrl ?? '';
  return url === '' ? '' : `${url}?v=${stamp.value}`;
});

function upload(file: File): Promise<void> {
  return uploadCarImage(props.carId, file);
}
</script>

<template>
  <section class="mx-auto max-w-2xl space-y-6">
    <h1 class="page-title">
      Add a photo
    </h1>

    <BaseSpinner
      v-if="status === 'loading'"
      label="Loading car"
      class="py-6 text-brand"
    />
    <p v-else-if="status === 'notFound'">
      This car does not exist or is not in your garage.
    </p>
    <BaseAlert
      v-else-if="status === 'error'"
      variant="error"
    >
      <p>The car could not be loaded.</p>
      <BaseButton
        variant="link"
        @click="load()"
      >
        Try again
      </BaseButton>
    </BaseAlert>

    <template v-else>
      <ImageUploadForm
        input-id="car-image-file"
        :current-src="currentSrc"
        current-alt="The current photo of this car"
        upload-label="Upload photo"
        success-message="The photo was uploaded."
        :upload="upload"
        @uploaded="load(true)"
      />
      <p>
        <RouterLink :to="`/car/${carId}`">
          {{ car?.nickname ? `Go to ${car.nickname}` : 'Skip for now and view the car' }}
        </RouterLink>
      </p>
    </template>
  </section>
</template>
