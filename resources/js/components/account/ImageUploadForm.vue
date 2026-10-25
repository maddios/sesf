<script setup lang="ts">
/**
 * Photo picker with a preview. The file is checked (type and 12 MB limit) before anything is sent; the
 * upload runs through `upload` as a multipart FormData body, never JSON. Busy disables the controls.
 */
import { computed, ref } from 'vue';
import BaseAlert from '../ui/BaseAlert.vue';
import SafeImage from '../ui/SafeImage.vue';
import FormField from '../FormField.vue';
import ResponseBanner from '../ResponseBanner.vue';
import SubmitButton from '../SubmitButton.vue';
import { IMAGE_TYPES, useImageFile } from '../../composables/account/useImageFile';
import { useSaveState } from '../../composables/account/useSaveState';

const props = withDefaults(
  defineProps<{
    /** Id of the file input, used for its label and hint. */
    inputId: string;
    /** The photo on the server now, shown until a new one is chosen. Empty for none. */
    currentSrc?: string;
    currentAlt: string;
    uploadLabel: string;
    successMessage: string;
    upload: (file: File) => Promise<void>;
  }>(),
  { currentSrc: '' },
);

const emit = defineEmits<{ uploaded: [] }>();

const chosen = useImageFile();
const saveState = useSaveState('The photo could not be uploaded. Please try again.');
const fileInput = ref<HTMLInputElement | null>(null);

const accept = IMAGE_TYPES.join(',');
const shownSrc = computed(() => chosen.previewUrl.value ?? props.currentSrc);
const fileErrors = computed(() => (chosen.problem.value === null ? [] : [chosen.problem.value]));

function onChange(event: Event): void {
  const target = event.target as HTMLInputElement;
  saveState.reset();
  chosen.choose(target.files?.[0] ?? null);
}

async function onSubmit(): Promise<void> {
  const file = chosen.file.value;
  if (file === null) {
    return;
  }
  const ok = await saveState.run(() => props.upload(file), props.successMessage);
  if (ok) {
    chosen.clear();
    if (fileInput.value !== null) {
      fileInput.value.value = '';
    }
    emit('uploaded');
  }
}
</script>

<template>
  <form
    class="space-y-4"
    novalidate
    @submit.prevent="onSubmit"
  >
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
      <SafeImage
        :src="shownSrc || null"
        :alt="chosen.previewUrl.value ? 'Preview of the photo to upload' : currentAlt"
        img-class="h-40 w-40 rounded-md border border-border object-cover"
        width="160"
        height="160"
      />
      <div class="min-w-0 flex-1">
        <FormField
          :id="inputId"
          v-slot="{ inputProps }"
          label="Choose a photo"
          hint="JPEG, PNG, GIF or WebP, 12 MB or smaller."
          :errors="fileErrors"
        >
          <input
            v-bind="inputProps"
            ref="fileInput"
            type="file"
            class="form-control w-full text-sm"
            :accept="accept"
            :disabled="saveState.busy.value"
            @change="onChange"
          >
        </FormField>
      </div>
    </div>

    <ResponseBanner
      :messages="saveState.messages.value"
      variant="error"
    />
    <BaseAlert
      v-if="saveState.success.value"
      variant="success"
    >
      {{ saveState.success.value }}
    </BaseAlert>

    <SubmitButton
      :busy="saveState.busy.value"
      :disabled="chosen.file.value === null"
      busy-label="Uploading photo"
    >
      {{ uploadLabel }}
    </SubmitButton>
  </form>
</template>
