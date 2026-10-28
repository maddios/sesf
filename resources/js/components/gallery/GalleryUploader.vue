<script setup lang="ts">
/**
 * Drag-and-drop / tap-to-choose photo upload for one gallery (replaces the legacy Dropzone). Files upload one at a
 * time with a progress bar each; finished files leave the list, failed ones stay with their error. Emits
 * `uploaded` once the queue is empty so the page can reload the items. Cancel aborts the queue.
 */
import { computed, ref } from 'vue';
import BaseButton from '../ui/BaseButton.vue';
import { errorMessage, uploadGalleryPhoto } from '../../api/gallery';
import { ApiError } from '../../api/client';

const props = defineProps<{ galleryId: number }>();
const emit = defineEmits<{ uploaded: [] }>();

interface QueuedFile {
  key: number;
  file: File;
  progress: number;
  error: string;
}

const queue = ref<QueuedFile[]>([]);
const dragging = ref(false);
const running = ref(false);
const input = ref<HTMLInputElement | null>(null);
let nextKey = 0;
let controller: AbortController | null = null;

const status = computed(() => {
  const pending = queue.value.filter((row) => row.error === '').length;
  return running.value ? `Uploading, ${pending} ${pending === 1 ? 'photo' : 'photos'} left.` : '';
});

function failure(error: unknown): string {
  if (error instanceof ApiError && error.status === 403) return 'You are not allowed to upload to this gallery.';
  return errorMessage(error, 'Upload failed. Please try again.');
}

function add(files: FileList | File[] | null): void {
  for (const file of Array.from(files ?? [])) {
    const isImage = file.type.startsWith('image/');
    queue.value.push({ key: nextKey++, file, progress: 0, error: isImage ? '' : 'Only image files can be uploaded.' });
  }
  void run();
}

async function run(): Promise<void> {
  if (running.value) return;
  running.value = true;
  controller = new AbortController();
  let uploadedAny = false;
  try {
    let row = queue.value.find((entry) => entry.error === '');
    while (row !== undefined && !controller.signal.aborted) {
      const current = row;
      try {
        await uploadGalleryPhoto(props.galleryId, current.file, (fraction) => (current.progress = fraction), controller.signal);
        queue.value = queue.value.filter((entry) => entry.key !== current.key);
        uploadedAny = true;
      } catch (error) {
        if (controller.signal.aborted) break;
        current.error = failure(error);
      }
      row = queue.value.find((entry) => entry.error === '');
    }
  } finally {
    running.value = false;
    controller = null;
    if (uploadedAny) emit('uploaded');
  }
}

function cancel(): void {
  controller?.abort();
  queue.value = [];
}

function onDrop(event: DragEvent): void {
  dragging.value = false;
  add(event.dataTransfer?.files ?? null);
}

function onChoose(event: Event): void {
  const target = event.target as HTMLInputElement;
  add(target.files);
  target.value = '';
}
</script>

<template>
  <section
    aria-labelledby="gallery-upload-heading"
    class="space-y-3"
  >
    <h2
      id="gallery-upload-heading"
      class="item-title"
    >
      Add photos
    </h2>
    <p>Tap or drag photos here to add them. On phones, tap to choose from your library.</p>
    <div
      class="flex min-h-32 cursor-pointer flex-col items-center justify-center rounded-sm border-2 border-dashed p-6 text-center"
      :class="dragging ? 'border-brand bg-brand-tint' : 'border-border'"
      data-testid="gallery-dropzone"
      @click="input?.click()"
      @dragover.prevent="dragging = true"
      @dragleave="dragging = false"
      @drop.prevent="onDrop"
    >
      <label
        for="gallery-upload-input"
        class="cursor-pointer font-medium text-brand underline"
        @click.stop
      >Choose photos</label>
      <span class="text-sm text-muted">or drop them here</span>
      <input
        id="gallery-upload-input"
        ref="input"
        type="file"
        accept="image/*"
        multiple
        class="sr-only"
        @change="onChoose"
      >
    </div>
    <p
      class="sr-only"
      role="status"
      aria-live="polite"
    >
      {{ status }}
    </p>
    <ul
      v-if="queue.length > 0"
      class="space-y-2"
      aria-label="Upload queue"
    >
      <li
        v-for="row in queue"
        :key="row.key"
        class="text-sm"
      >
        <div class="flex justify-between gap-3">
          <span class="truncate">{{ row.file.name }}</span>
          <span
            v-if="row.error"
            class="text-danger"
            role="alert"
          >{{ row.error }}</span>
        </div>
        <progress
          v-if="!row.error"
          class="w-full"
          max="100"
          :value="Math.round(row.progress * 100)"
          :aria-label="`Uploading ${row.file.name}`"
        />
      </li>
    </ul>
    <BaseButton
      v-if="queue.length > 0"
      variant="danger"
      size="sm"
      @click="cancel"
    >
      Cancel
    </BaseButton>
  </section>
</template>
