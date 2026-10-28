<script setup lang="ts">
/** Small stroke icons for admin buttons (decorative: the button carries the accessible name). */
export type AdminIconName =
  | 'edit'
  | 'trash'
  | 'plus'
  | 'x'
  | 'check'
  | 'eye'
  | 'eye-off'
  | 'mail'
  | 'key'
  | 'login'
  | 'user-plus'
  | 'filter'
  | 'search'
  | 'image'
  | 'up'
  | 'down'
  | 'download'
  | 'external'
  | 'calendar'
  | 'users'
  | 'power'
  | 'refresh';

defineProps<{ name: AdminIconName }>();

const PATHS: Record<AdminIconName, string> = {
  edit: 'M4 20h4L18.5 9.5a2.1 2.1 0 0 0-3-3L5 17v3Z M14 7.5l2.5 2.5',
  trash: 'M4 7h16 M9 7V4h6v3 M6 7l1 13h10l1-13 M10 11v6 M14 11v6',
  plus: 'M12 5v14 M5 12h14',
  x: 'M6 6l12 12 M18 6L6 18',
  check: 'M5 12.5l4.5 4.5L19 7.5',
  eye: 'M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z',
  'eye-off': 'M3 3l18 18 M10.6 5.1A10 10 0 0 1 12 5c6.4 0 10 7 10 7a17 17 0 0 1-3.2 4.2 M6.6 6.6A17 17 0 0 0 2 12s3.6 7 10 7a9.6 9.6 0 0 0 5.4-1.6 M9.9 9.9a3 3 0 0 0 4.2 4.2',
  mail: 'M3 6h18v12H3Z M3 7l9 6 9-6',
  key: 'M14.5 9.5a4.5 4.5 0 1 1-1.3-3.2 M13.2 12.8 21 20.6 M17.5 17l2-2 M15 14.5l2-2',
  login: 'M10 17l5-5-5-5 M15 12H3 M14 4h5a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-5',
  'user-plus': 'M15 19v-1a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v1 M8.5 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z M19 8v6 M16 11h6',
  filter: 'M3 5h18l-7 8v6l-4-2v-4L3 5Z',
  search: 'M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14Z M20 20l-4-4',
  image: 'M3 5h18v14H3Z M3 16l5-5 4 4 3-3 6 6 M15.5 9.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z',
  up: 'M12 19V5 M6 11l6-6 6 6',
  down: 'M12 5v14 M6 13l6 6 6-6',
  download: 'M12 4v11 M7 10l5 5 5-5 M4 20h16',
  external: 'M14 4h6v6 M20 4l-9 9 M18 14v6H4V6h6',
  calendar: 'M4 6h16v14H4Z M4 10h16 M8 3v4 M16 3v4',
  users: 'M16 19v-1a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v1 M9 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z M22 19v-1a4 4 0 0 0-3-3.9 M16 4.1a3.5 3.5 0 0 1 0 6.8',
  power: 'M12 3v9 M6.3 6.3a8 8 0 1 0 11.4 0',
  refresh: 'M20 11a8 8 0 0 0-14.6-4.5L4 8 M4 4v4h4 M4 13a8 8 0 0 0 14.6 4.5L20 16 M20 20v-4h-4',
};
</script>

<template>
  <svg
    aria-hidden="true"
    focusable="false"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="2"
    stroke-linecap="round"
    stroke-linejoin="round"
    class="admin-icon"
  ><path :d="PATHS[name]" /></svg>
</template>
