import { USER_TYPE_ADMIN, USER_TYPE_JUDGE, type AuthUser } from '../stores/auth';
import type { DropdownMenuItem } from '../components/ui/DropdownMenu.vue';

export type NavSection = 'events' | 'members' | 'gallery';

/**
 * Which primary nav item a hash path belongs to (legacy isMainNavActive):
 * events list and event pages (/{name}/e{id}...), members/cars lists and profiles (/{name}/u{id}, /car/{id}),
 * and the gallery.
 */
export function navSectionFor(path: string): NavSection | null {
  // Before the event pattern: /gallery/e36 is a gallery page, not an event.
  if (path === '/gallery' || path.startsWith('/gallery/')) {
    return 'gallery';
  }
  if (path === '/events' || path.startsWith('/events/') || /^\/[^/]+\/e\d+(\/|$)/.test(path)) {
    return 'events';
  }
  if (
    path === '/members' ||
    path === '/cars' ||
    path.startsWith('/members/') ||
    path.startsWith('/car/') ||
    /^\/[^/]+\/u\d+(\/|$)/.test(path)
  ) {
    return 'members';
  }
  return null;
}

/**
 * Hash-route path of the member profile. The session sends profileURL as "#/Name/u7" or "/Name/u7";
 * falls back to /member/u{id} when it is missing.
 */
export function profilePath(user: Pick<AuthUser, 'profileUrl' | 'userId'>): string {
  const raw = user.profileUrl.trim().replace(/^#/, '');
  if (raw === '') {
    return `/member/u${user.userId}`;
  }
  const path = raw.startsWith('/') ? raw : `/${raw}`;
  return path.replace(/\/+$/, '');
}

/** Legacy full-page admin screens (not hash routes). */
export const ADMIN_URL = '/admin';
export const JUDGING_URL = '/admin2/judging_list';
export const LOGOUT_ITEM_ID = 'logout';

/** The "Welcome" menu: Profile, Settings, Billing, Admin (admin type), Judging (judge level and up), Logout. */
export function accountMenuItems(user: AuthUser): DropdownMenuItem[] {
  const profile = profilePath(user);
  const items: DropdownMenuItem[] = [
    { id: 'profile', label: 'Profile', to: profile },
    { id: 'settings', label: 'Settings', to: `${profile}/settings` },
    { id: 'billing', label: 'Billing', to: `${profile}/billing` },
  ];
  if (user.typeId === USER_TYPE_ADMIN || user.userLevel === USER_TYPE_ADMIN) {
    items.push({ id: 'admin', label: 'Admin', href: ADMIN_URL });
  }
  if (user.userLevel >= USER_TYPE_JUDGE) {
    items.push({ id: 'judging', label: 'Judging', href: JUDGING_URL });
  }
  items.push({ id: LOGOUT_ITEM_ID, label: 'Logout', separator: true });
  return items;
}
