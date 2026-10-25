<script setup lang="ts">
/**
 * Route-based tab bar (event pages: Event Info, Judging, Schedule...). Each tab is a router link, so tabs are
 * real URLs; the current one gets aria-current="page". Left/Right/Home/End move focus between tabs.
 * `exact` tabs are only active on their own path (the first "info" tab is usually a parent of the others).
 */
import { ref } from 'vue';
import { RouterLink, useRoute, useRouter, type RouteLocationRaw } from 'vue-router';

export interface TabNavItem {
  label: string;
  to: RouteLocationRaw;
  /** Active only on an exact path match. Defaults to true. */
  exact?: boolean;
}

defineProps<{
  tabs: TabNavItem[];
  /** Accessible name of the tab bar, e.g. "Event sections". */
  label: string;
}>();

const route = useRoute();
const router = useRouter();
const list = ref<HTMLElement | null>(null);

function isActive(tab: TabNavItem): boolean {
  const target = router.resolve(tab.to).path;
  if (tab.exact === false) {
    return route.path === target || route.path.startsWith(`${target}/`);
  }
  return route.path === target;
}

function onKeydown(event: KeyboardEvent): void {
  const links = Array.from(list.value?.querySelectorAll<HTMLElement>('a') ?? []);
  const index = links.indexOf(document.activeElement as HTMLElement);
  if (index === -1 || links.length === 0) {
    return;
  }
  let next: number | undefined;
  if (event.key === 'ArrowRight') {
    next = (index + 1) % links.length;
  } else if (event.key === 'ArrowLeft') {
    next = (index - 1 + links.length) % links.length;
  } else if (event.key === 'Home') {
    next = 0;
  } else if (event.key === 'End') {
    next = links.length - 1;
  }
  if (next !== undefined) {
    event.preventDefault();
    links[next]?.focus();
  }
}

</script>

<template>
  <nav
    :aria-label="label"
    class="border-b border-border"
  >
    <ul
      ref="list"
      class="-mb-px flex flex-wrap gap-x-1"
      @keydown="onKeydown"
    >
      <li
        v-for="tab in tabs"
        :key="tab.label"
      >
        <RouterLink
          :to="tab.to"
          class="block rounded-t-sm border px-3 py-2 text-sm no-underline"
          :class="isActive(tab)
            ? 'border-border border-b-panel bg-panel font-semibold text-heading'
            : 'border-transparent text-muted hover:border-b-border hover:bg-subtle hover:text-text'"
          :aria-current="isActive(tab) ? 'page' : undefined"
        >
          {{ tab.label }}
        </RouterLink>
      </li>
    </ul>
  </nav>
</template>
