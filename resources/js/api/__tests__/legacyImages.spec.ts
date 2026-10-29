import { describe, expect, it } from 'vitest';
import { LEGACY_IMAGE_SIZES, resizedImageSrcset, resizedImageUrl } from '../legacyImages';

describe('resizedImageUrl', () => {
  it('builds the legacy mode 4 URL', () => {
    expect(resizedImageUrl('/images/cars/3/profile.jpg?123', LEGACY_IMAGE_SIZES.memberCar)).toBe(
      '/images/4/300/220/images/cars/3/profile.jpg?123',
    );
  });

  it('adds the gravity for mode 2', () => {
    expect(resizedImageUrl('/images/gallery/5/5/4/5541.jpg', LEGACY_IMAGE_SIZES.galleryItem)).toBe(
      '/images/2/320/230/5/images/gallery/5/5/4/5541.jpg',
    );
  });

  it('keeps zero sides as the legacy templates wrote them', () => {
    expect(resizedImageUrl('/images/sponsor/4.jpg', LEGACY_IMAGE_SIZES.agendaSponsor)).toBe('/images/4/0/60/images/sponsor/4.jpg');
    expect(resizedImageUrl('/images/members/9/profile.jpg', LEGACY_IMAGE_SIZES.memberPhoto)).toBe(
      '/images/4/228/0/images/members/9/profile.jpg',
    );
  });

  it('leaves empty, external and blob sources alone', () => {
    expect(resizedImageUrl('', LEGACY_IMAGE_SIZES.eventCard)).toBe('');
    expect(resizedImageUrl('https://cdn.example.test/a.jpg', LEGACY_IMAGE_SIZES.eventCard)).toBe('https://cdn.example.test/a.jpg');
    expect(resizedImageUrl('blob:http://localhost/1', LEGACY_IMAGE_SIZES.eventCard)).toBe('blob:http://localhost/1');
  });
});

describe('resizedImageSrcset', () => {
  it('pairs the legacy gallery thumbnail with a 2x variant', () => {
    expect(resizedImageSrcset('/images/gallery/1/2/12.jpg?9', LEGACY_IMAGE_SIZES.galleryItem)).toBe(
      '/images/2/320/230/5/images/gallery/1/2/12.jpg?9 1x, /images/2/640/460/5/images/gallery/1/2/12.jpg?9 2x',
    );
    expect(resizedImageSrcset('/images/gallery/1/2/12.jpg', LEGACY_IMAGE_SIZES.gallerySubCover)).toBe(
      '/images/2/200/140/5/images/gallery/1/2/12.jpg 1x, /images/2/400/280/5/images/gallery/1/2/12.jpg 2x',
    );
  });

  it('is empty for sources it cannot resize', () => {
    expect(resizedImageSrcset('', LEGACY_IMAGE_SIZES.galleryItem)).toBe('');
    expect(resizedImageSrcset('https://cdn.example.test/a.jpg', LEGACY_IMAGE_SIZES.galleryItem)).toBe('');
  });
});
