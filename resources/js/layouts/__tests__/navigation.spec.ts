import { describe, it, expect } from 'vitest';
import { accountMenuItems, navSectionFor, profilePath, ADMIN_URL, JUDGING_URL } from '../navigation';
import type { AuthUser } from '../../stores/auth';

const user: AuthUser = {
  userId: 7,
  userLevel: 1,
  typeId: 1,
  email: 'fan@example.test',
  firstName: 'Shark',
  profileUrl: '#/SharkF/u7',
  slideOutName: 'Shark F.',
  fullAddress: '',
};

describe('navSectionFor', () => {
  it.each([
    ['/events', 'events'],
    ['/SoutheastSharkfest2026/e36', 'events'],
    ['/SoutheastSharkfest2026/e36/directions', 'events'],
    ['/members', 'members'],
    ['/cars', 'members'],
    ['/ShawnB/u44', 'members'],
    ['/ShawnB/u44/settings', 'members'],
    ['/car/78', 'members'],
    ['/gallery', 'gallery'],
    ['/gallery/e36', 'gallery'],
    ['/', null],
    ['/login', null],
    ['/privacy-policy', null],
  ])('%s -> %s', (path, section) => {
    expect(navSectionFor(path)).toBe(section);
  });
});

describe('profilePath', () => {
  it.each([
    ['#/SharkF/u7', '/SharkF/u7'],
    ['/SharkF/u7/', '/SharkF/u7'],
    ['SharkF/u7', '/SharkF/u7'],
    ['', '/member/u7'],
  ])('%s -> %s', (profileUrl, expected) => {
    expect(profilePath({ profileUrl, userId: 7 })).toBe(expected);
  });
});

describe('accountMenuItems', () => {
  it('a regular member gets profile, settings, billing and logout', () => {
    expect(accountMenuItems(user).map((item) => item.id)).toEqual(['profile', 'settings', 'billing', 'logout']);
    expect(accountMenuItems(user)[1]?.to).toBe('/SharkF/u7/settings');
    expect(accountMenuItems(user)[2]?.to).toBe('/SharkF/u7/billing');
  });

  it('a judge also gets Judging', () => {
    const items = accountMenuItems({ ...user, userLevel: 2, typeId: 2 });
    expect(items.map((item) => item.id)).toEqual(['profile', 'settings', 'billing', 'judging', 'logout']);
    expect(items.find((item) => item.id === 'judging')?.href).toBe(JUDGING_URL);
  });

  it('an admin gets Admin and Judging', () => {
    const items = accountMenuItems({ ...user, userLevel: 3, typeId: 3 });
    expect(items.map((item) => item.id)).toEqual(['profile', 'settings', 'billing', 'admin', 'judging', 'logout']);
    expect(items.find((item) => item.id === 'admin')?.href).toBe(ADMIN_URL);
    expect(items.at(-1)?.separator).toBe(true);
  });
});
