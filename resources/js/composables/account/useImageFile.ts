import { onBeforeUnmount, ref, shallowRef } from 'vue';

/** Image types the server accepts for profile and car photos, and the 12 MB ceiling. */
export const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'] as const;
export const MAX_IMAGE_BYTES = 12 * 1024 * 1024;

/** Returns the user-facing problem with a file, or null when it may be uploaded. */
export function imageFileProblem(file: File): string | null {
  if (!(IMAGE_TYPES as readonly string[]).includes(file.type)) {
    return 'Choose a JPEG, PNG, GIF or WebP image.';
  }
  if (file.size > MAX_IMAGE_BYTES) {
    return 'Choose an image of 12 MB or smaller.';
  }
  return null;
}

/**
 * Holds the chosen file and an object-URL preview of it. The preview URL is revoked when replaced, cleared
 * or when the component unmounts. jsdom has no createObjectURL, so the preview is simply absent there.
 */
export function useImageFile() {
  const file = shallowRef<File | null>(null);
  const previewUrl = ref<string | null>(null);
  const problem = ref<string | null>(null);

  function revoke(): void {
    if (previewUrl.value !== null && typeof URL.revokeObjectURL === 'function') {
      URL.revokeObjectURL(previewUrl.value);
    }
    previewUrl.value = null;
  }

  /** Validates and stores a file. Returns false (and sets `problem`) when it is rejected. */
  function choose(next: File | null): boolean {
    revoke();
    file.value = null;
    problem.value = null;
    if (next === null) {
      return false;
    }
    const issue = imageFileProblem(next);
    if (issue !== null) {
      problem.value = issue;
      return false;
    }
    file.value = next;
    previewUrl.value = typeof URL.createObjectURL === 'function' ? URL.createObjectURL(next) : null;
    return true;
  }

  function clear(): void {
    choose(null);
  }

  onBeforeUnmount(revoke);

  return { file, previewUrl, problem, choose, clear };
}
