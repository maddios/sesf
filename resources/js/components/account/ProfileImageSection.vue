<script setup lang="ts">
/** Profile photo for the accordion: shows the current photo (cache-busted after an upload) and the uploader. */
import { onMounted, ref } from 'vue';
import { uploadMemberImage } from '../../api/account';
import { GENERIC_AVATAR_URL, fetchMemberProfile } from '../../api/members';
import ImageUploadForm from './ImageUploadForm.vue';

const props = defineProps<{ userId: number }>();

const currentSrc = ref<string>(GENERIC_AVATAR_URL);
const stamp = ref<number>(Date.now());
const loadError = ref(false);

async function loadCurrent(): Promise<void> {
  try {
    const profile = await fetchMemberProfile(props.userId);
    currentSrc.value = profile.hasImage && profile.imageUrl !== '' ? profile.imageUrl : GENERIC_AVATAR_URL;
    loadError.value = false;
  } catch {
    loadError.value = true;
  }
}

/** The cache-bust query makes the browser fetch the new photo instead of the cached one. */
function bustedSrc(): string {
  return currentSrc.value === GENERIC_AVATAR_URL ? currentSrc.value : `${currentSrc.value}?v=${stamp.value}`;
}

async function onUploaded(): Promise<void> {
  stamp.value = Date.now();
  await loadCurrent();
}

onMounted(() => void loadCurrent());
</script>

<template>
  <div class="space-y-3">
    <p
      v-if="loadError"
      class="text-sm text-danger-fg"
    >
      The current photo could not be loaded.
    </p>
    <ImageUploadForm
      input-id="profile-image-file"
      :current-src="bustedSrc()"
      current-alt="Your current profile photo"
      upload-label="Upload photo"
      success-message="Your profile photo was updated."
      :upload="uploadMemberImage"
      @uploaded="onUploaded"
    />
  </div>
</template>
