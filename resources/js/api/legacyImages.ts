/**
 * Legacy JitImage resize URLs (/images/{mode}/{w}/{h}[/{g}]/{path}), served by LegacyImageController.
 * Each preset is the size the legacy Angular template used for that spot. Every size, including the
 * 2x srcset variants, must be listed in config('sesf.legacy_images.sizes'), or the server answers 404.
 */
export interface LegacyImageSize {
  mode: 1 | 2 | 4;
  width: number;
  height: number;
  gravity?: number;
}

const fit = (width: number, height: number): LegacyImageSize => ({ mode: 4, width, height });
const crop = (width: number, height: number): LegacyImageSize => ({ mode: 2, width, height, gravity: 5 });

export const LEGACY_IMAGE_SIZES = {
  galleryItem: crop(320, 230),
  gallerySubCover: crop(200, 140),
  galleryManageItem: fit(320, 230),
  galleryLightbox: fit(1070, 800),
  galleryEvent: fit(459, 367),
  eventCard: fit(240, 240),
  homeSponsor: fit(250, 65),
  homeSponsorPrimary: fit(1070, 800),
  eventSponsor: fit(150, 60),
  agendaSponsor: fit(0, 60),
  memberPhoto: fit(228, 0),
  memberCar: fit(300, 220),
  attendedShow: fit(100, 100),
  directoryCard: fit(300, 220),
  garageCar: fit(362, 272),
  carProfile: fit(652, 407),
} satisfies Record<string, LegacyImageSize>;

/**
 * Resize URL for a root-relative image path ("/images/cars/3/profile.jpg?123", as returned by toImageUrl).
 * Empty stays empty; anything that is not under /images/ (an external https URL, a blob: preview) is
 * returned unchanged.
 */
export function resizedImageUrl(src: string, size: LegacyImageSize): string {
  if (!src.startsWith('/images/')) {
    return src;
  }
  const gravity = size.mode === 2 ? `/${size.gravity ?? 5}` : '';
  return `/images/${size.mode}/${size.width}/${size.height}${gravity}${src}`;
}

/**
 * "url 1x, url 2x" for a sharp thumbnail on high-density screens; empty when src is not under /images/. Only the
 * gallery presets have their 2x size listed on the server.
 */
export function resizedImageSrcset(src: string, size: LegacyImageSize): string {
  if (!src.startsWith('/images/')) {
    return '';
  }
  const double = { ...size, width: size.width * 2, height: size.height * 2 };
  return `${resizedImageUrl(src, size)} 1x, ${resizedImageUrl(src, double)} 2x`;
}
