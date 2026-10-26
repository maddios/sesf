import type { Component } from 'vue';

/**
 * Admin pages registry. Each entry becomes a child route of /admin (hash URL /#/admin/<path>) and a link in the
 * AdminLayout sidebar under its group. To add a resource, APPEND ONE LINE to ADMIN_REGISTRY inside its group block
 * (keep blocks in ADMIN_GROUPS order). See docs/admin-conventions.md.
 *
 * minLevel: 3 = admins only, 2 = judges and admins (the API route must then live in routes/web/admin/judging/).
 */

/** Sidebar groups, in display order (legacy admin menu, then the former /admin2 reports). */
export const ADMIN_GROUPS = ['Members', 'Event', 'Registration', 'Sponsors', 'Extras', 'Reports'] as const;

export type AdminGroup = (typeof ADMIN_GROUPS)[number];

export interface AdminRegistryEntry {
  /** Unique id; the route name is `admin-<key>`. */
  key: string;
  label: string;
  group: AdminGroup;
  /** Path under /admin, no leading slash (e.g. "sponsors"). */
  path: string;
  component: () => Promise<{ default: Component }>;
  minLevel: 2 | 3;
  /** Optional short glyph shown before the label (decorative). */
  icon?: string;
}

// prettier-ignore
export const ADMIN_REGISTRY: AdminRegistryEntry[] = [
  // Members
  { key: 'users', label: 'Users', group: 'Members', path: 'users', component: () => import('../pages/admin/users/UsersPage.vue'), minLevel: 3 },
  { key: 'cars', label: 'Cars', group: 'Members', path: 'cars', component: () => import('../pages/admin/cars/CarsPage.vue'), minLevel: 3 },
  // Event
  { key: 'events', label: 'Events', group: 'Event', path: 'events', component: () => import('../pages/admin/events/EventsPage.vue'), minLevel: 3 },
  { key: 'agenda', label: 'Agenda', group: 'Event', path: 'agenda', component: () => import('../pages/admin/agenda/AgendaPage.vue'), minLevel: 3 },
  { key: 'locations', label: 'Locations', group: 'Event', path: 'locations', component: () => import('../pages/admin/locations/LocationsPage.vue'), minLevel: 3 },
  { key: 'extras', label: 'Extras', group: 'Event', path: 'extras', component: () => import('../pages/admin/extras/ExtrasPage.vue'), minLevel: 3 },
  { key: 'extra-registrations', label: 'Extra registrations', group: 'Event', path: 'extra-registrations', component: () => import('../pages/admin/extra-registrations/ExtraRegistrationsPage.vue'), minLevel: 3 },
  // Registration
  { key: 'registrations', label: 'Registrations', group: 'Registration', path: 'registrations', component: () => import('../pages/admin/registrations/RegistrationsPage.vue'), minLevel: 3 },
  { key: 'registration-guests', label: 'Registration guests', group: 'Registration', path: 'registration-guests', component: () => import('../pages/admin/registration-guests/RegistrationGuestsPage.vue'), minLevel: 3 },
  // Sponsors
  { key: 'sponsors', label: 'Sponsors', group: 'Sponsors', path: 'sponsors', component: () => import('../pages/admin/sponsors/SponsorsPage.vue'), minLevel: 3 },
  // Extras
  // Reports
];

/** Entries the given user level may open, grouped in ADMIN_GROUPS order (empty groups left out). */
export function navGroups(userLevel: number, entries: AdminRegistryEntry[] = ADMIN_REGISTRY): Array<{ group: AdminGroup; entries: AdminRegistryEntry[] }> {
  return ADMIN_GROUPS.map((group) => ({
    group,
    entries: entries.filter((entry) => entry.group === group && userLevel >= entry.minLevel),
  })).filter((block) => block.entries.length > 0);
}

export function findEntry(key: unknown, entries: AdminRegistryEntry[] = ADMIN_REGISTRY): AdminRegistryEntry | undefined {
  return entries.find((entry) => entry.key === key);
}
