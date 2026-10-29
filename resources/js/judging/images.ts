export const MAX_WIDTH = 2048;
export const MAX_HEIGHT = 1536;
const JPEG_QUALITY = 0.88;

/**
 * Shrinks a photo to fit 2048 x 1536 before upload, so judges on weak event Wi-Fi do not send
 * 5-10 MB originals. Anything that cannot be decoded here is sent as is; the server re-encodes
 * every upload and applies EXIF orientation itself.
 */
export async function resizeForUpload(file: File): Promise<File> {
  if (!file.type.startsWith('image/') || file.type === 'image/svg+xml' || typeof createImageBitmap !== 'function') {
    return file;
  }
  try {
    const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
    const scale = Math.min(MAX_WIDTH / bitmap.width, MAX_HEIGHT / bitmap.height);
    if (!(scale < 1)) {
      bitmap.close();
      return file;
    }
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(bitmap.width * scale);
    canvas.height = Math.round(bitmap.height * scale);
    canvas.getContext('2d')?.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    bitmap.close();
    const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', JPEG_QUALITY));
    if (blob === null) {
      return file;
    }
    return new File([blob], file.name.replace(/\.[^/.]+$/, '') + '.jpg', { type: 'image/jpeg' });
  } catch {
    return file;
  }
}
