<script setup lang="ts">
/**
 * Image upload dialog: pick a file, see a preview, upload with a progress bar. The file is checked in the browser
 * first (JPEG/PNG/GIF/WebP, 12 MB, the same limits as App\Services\Images\ImageStore); the server re-checks and
 * its 422/400 messages are shown under the field. `upload(file, onProgress)` sends it (usually
 * resource.upload(id, file, { onProgress })). Emits `uploaded` with the result and closes.
 */
import { computed, ref, useId, watch } from 'vue';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import SafeImage from '../ui/SafeImage.vue';
import FormField from '../FormField.vue';
import ResponseBanner from '../ResponseBanner.vue';
import { IMAGE_TYPES, useImageFile } from '../../composables/account/useImageFile';
import { mapAdminError } from '../../composables/admin/useAdminResource';

const props = withDefaults(
  defineProps<{
    open: boolean;
    title: string;
    currentSrc?: string | null;
    currentAlt?: string;
    upload: (file: File, onProgress: (percent: number) => void) => Promise<unknown>;
  }>(),
  { currentSrc: null, currentAlt: 'Current image' },
);

const emit = defineEmits<{ 'update:open': [open: boolean]; uploaded: [result: unknown] }>();

const chosen = useImageFile();
const inputId = useId();
const progressId = useId();
const busy = ref(false);
const progress = ref(0);
const serverErrors = ref<string[]>([]);
const banner = ref<string | null>(null);

const accept = IMAGE_TYPES.join(',');
const shownSrc = computed(() => chosen.previewUrl.value ?? props.currentSrc ?? null);
const previewAlt = computed(() => {
  if (chosen.previewUrl.value !== null) return 'Preview of the image to upload';
  return shownSrc.value === null ? 'No image yet' : props.currentAlt;
});
const fieldErrors = computed(() => (chosen.problem.value !== null ? [chosen.problem.value] : serverErrors.value));

function reset(): void {
  chosen.clear();
  busy.value = false;
  progress.value = 0;
  serverErrors.value = [];
  banner.value = null;
}

watch(
  () => props.open,
  (open) => {
    if (open) reset();
  },
);

function onChange(event: Event): void {
  serverErrors.value = [];
  banner.value = null;
  chosen.choose((event.target as HTMLInputElement).files?.[0] ?? null);
}

function close(): void {
  if (!busy.value) emit('update:open', false);
}

async function submit(): Promise<void> {
  const file = chosen.file.value;
  if (file === null || busy.value) return;
  busy.value = true;
  progress.value = 0;
  serverErrors.value = [];
  banner.value = null;
  try {
    const result = await props.upload(file, (percent) => {
      progress.value = percent;
    });
    busy.value = false;
    emit('uploaded', result);
    emit('update:open', false);
  } catch (error) {
    busy.value = false;
    const mapped = mapAdminError(error, 'The image could not be uploaded. Please try again.');
    serverErrors.value = mapped.fieldErrors.file ?? [];
    banner.value = serverErrors.value.length > 0 ? null : mapped.message;
  }
}
</script>

<template>
  <BaseModal
    sheet
    :open="open"
    :title="title"
    :close-on-backdrop="!busy"
    @update:open="(value) => { if (!value) close(); }"
  >
    <form
      class="space-y-4"
      novalidate
      @submit.prevent="submit"
    >
      <div class="flex flex-col gap-4">
        <div class="h-40 w-full max-w-xs overflow-hidden rounded-md border border-border bg-subtle">
          <SafeImage
            :src="shownSrc"
            :alt="previewAlt"
            img-class="h-40 w-full object-contain"
            loading="eager"
          />
        </div>
        <div class="min-w-0">
          <FormField
            :id="inputId"
            v-slot="{ inputProps }"
            label="Choose an image"
            hint="JPEG, PNG, GIF or WebP, 12 MB or smaller. It is saved as a JPEG."
            :errors="fieldErrors"
          >
            <input
              v-bind="inputProps"
              type="file"
              class="form-control w-full text-sm"
              :accept="accept"
              :disabled="busy"
              @change="onChange"
            >
          </FormField>
        </div>
      </div>

      <div
        v-if="busy"
        class="space-y-1"
      >
        <label
          :for="progressId"
          class="block text-sm text-muted"
        >Uploading… {{ progress }}%</label>
        <progress
          :id="progressId"
          class="h-2 w-full"
          max="100"
          :value="progress"
        />
      </div>

      <ResponseBanner :messages="banner" />

      <div class="flex flex-wrap justify-end gap-2">
        <BaseButton
          :disabled="busy"
          @click="close"
        >
          Cancel
        </BaseButton>
        <BaseButton
          type="submit"
          variant="primary"
          :disabled="chosen.file.value === null"
          :loading="busy"
          loading-label="Uploading image"
        >
          Upload
        </BaseButton>
      </div>
    </form>
  </BaseModal>
</template>
