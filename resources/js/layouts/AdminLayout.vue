<script setup lang="ts">
/**
 * Admin shell for /#/admin/...: top bar (menu button on mobile, title, Back to site, user menu), a sidebar with
 * the registry pages grouped like the legacy admin menu, a breadcrumb, and the page in <main>. Also mounts the
 * shared toast region and confirm dialog. On mobile the sidebar is a collapsible panel (closed after navigating).
 * Focus moves to <main> after each page change.
 */
import { computed, nextTick, ref, watch } from 'vue';
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router';
import DropdownMenu, { type DropdownMenuItem } from '../components/ui/DropdownMenu.vue';
import AdminToastRegion from '../components/admin/AdminToastRegion.vue';
import AdminConfirmHost from '../components/admin/AdminConfirmHost.vue';
import ImpersonationBanner from '../components/admin/ImpersonationBanner.vue';
import { findEntry, navGroups } from '../admin/registry';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const sidebarOpen = ref(false);
const main = ref<HTMLElement | null>(null);

const groups = computed(() => navGroups(auth.userLevel));
const current = computed(() => findEntry(route.meta.adminKey));

const crumbs = computed(() => {
  const items: Array<{ label: string; to?: string }> = [{ label: 'Admin', to: '/admin' }];
  if (current.value !== undefined) {
    if (current.value.group !== current.value.label) items.push({ label: current.value.group });
    items.push({ label: current.value.label });
  } else {
    items[0] = { label: 'Admin' };
    items.push({ label: 'Dashboard' });
  }
  return items;
});

const userItems = computed<DropdownMenuItem[]>(() => [
  ...(auth.user?.profileUrl ? [{ id: 'profile', label: 'My profile', href: auth.user.profileUrl.startsWith('#') ? auth.user.profileUrl : `#${auth.user.profileUrl}` }] : []),
  { id: 'site', label: 'Back to site', to: '/' },
  { id: 'logout', label: 'Sign out', separator: true },
]);

const userLabel = computed(() => {
  const name = auth.user?.firstName.trim() ?? '';
  return name === '' ? 'Account' : name;
});

async function onUserSelect(id: string): Promise<void> {
  if (id === 'logout') {
    await auth.logout();
    await router.push('/');
  }
}

watch(
  () => route.path,
  async (path, previous) => {
    sidebarOpen.value = false;
    if (previous === undefined || path === previous) return;
    await nextTick();
    main.value?.focus({ preventScroll: true });
  },
);

function focusMain(): void {
  main.value?.focus({ preventScroll: true });
}
</script>

<template>
  <div class="flex min-h-screen flex-col bg-surface text-text">
    <a
      href="#main"
      class="sr-only z-50 rounded-sm bg-panel px-4 py-2 font-semibold text-brand shadow-overlay focus:not-sr-only focus:fixed focus:left-4 focus:top-4"
      @click.prevent="focusMain"
    >Skip to main content</a>

    <ImpersonationBanner v-if="auth.isLoggedIn" />

    <header class="sticky top-0 z-30 flex items-center gap-3 border-b border-border bg-panel px-4 py-2 shadow-header">
      <button
        type="button"
        class="inline-flex items-center gap-2 rounded-sm border border-default-border px-3 py-1.5 text-sm font-medium hover:bg-subtle md:hidden"
        aria-controls="admin-sidebar"
        :aria-expanded="sidebarOpen ? 'true' : 'false'"
        @click="sidebarOpen = !sidebarOpen"
      >
        <span aria-hidden="true">{{ sidebarOpen ? '✕' : '☰' }}</span>
        Menu
      </button>
      <RouterLink
        to="/admin"
        class="text-lg font-semibold text-heading no-underline hover:text-brand"
      >
        SESF Admin
      </RouterLink>
      <div class="ml-auto flex items-center gap-3">
        <a
          href="#/"
          class="text-sm no-underline hover:underline"
        >Back to site</a>
        <DropdownMenu
          :label="userLabel"
          :items="userItems"
          trigger-class="inline-flex items-center rounded-sm px-2 py-1 text-sm text-text hover:bg-subtle"
          @select="onUserSelect"
        />
      </div>
    </header>

    <div class="flex flex-1 flex-col md:flex-row">
      <nav
        id="admin-sidebar"
        aria-label="Admin"
        class="border-b border-border bg-panel md:block md:w-56 md:shrink-0 md:border-r md:border-b-0"
        :class="sidebarOpen ? 'block' : 'hidden'"
      >
        <ul class="space-y-4 p-4">
          <li>
            <RouterLink
              to="/admin"
              class="block rounded-sm px-2 py-1 text-sm no-underline"
              :class="route.name === 'admin' ? 'bg-brand-tint font-semibold text-brand-dark' : 'text-text hover:bg-subtle'"
              :aria-current="route.name === 'admin' ? 'page' : undefined"
            >
              Dashboard
            </RouterLink>
          </li>
          <li
            v-for="block in groups"
            :key="block.group"
          >
            <p class="px-2 text-xs font-semibold uppercase tracking-[0.04em] text-muted">
              {{ block.group }}
            </p>
            <ul class="mt-1 space-y-0.5">
              <li
                v-for="entry in block.entries"
                :key="entry.key"
              >
                <RouterLink
                  :to="`/admin/${entry.path}`"
                  class="flex items-center gap-2 rounded-sm px-2 py-1 text-sm no-underline"
                  :class="current?.key === entry.key ? 'bg-brand-tint font-semibold text-brand-dark' : 'text-text hover:bg-subtle'"
                  :aria-current="current?.key === entry.key ? 'page' : undefined"
                >
                  <span
                    v-if="entry.icon"
                    aria-hidden="true"
                  >{{ entry.icon }}</span>
                  {{ entry.label }}
                </RouterLink>
              </li>
            </ul>
          </li>
        </ul>
      </nav>

      <main
        id="main"
        ref="main"
        tabindex="-1"
        class="min-w-0 flex-1 p-4 md:p-6"
      >
        <nav
          aria-label="Breadcrumb"
          class="mb-3 text-sm text-muted"
        >
          <ol class="flex flex-wrap items-center gap-1">
            <li
              v-for="(crumb, index) in crumbs"
              :key="index"
              class="flex items-center gap-1"
            >
              <span
                v-if="index > 0"
                aria-hidden="true"
              >/</span>
              <RouterLink
                v-if="crumb.to && index < crumbs.length - 1"
                :to="crumb.to"
              >
                {{ crumb.label }}
              </RouterLink>
              <span
                v-else
                :aria-current="index === crumbs.length - 1 ? 'page' : undefined"
              >{{ crumb.label }}</span>
            </li>
          </ol>
        </nav>
        <div class="rounded-md bg-panel p-4 shadow-panel md:p-6">
          <RouterView />
        </div>
      </main>
    </div>

    <AdminToastRegion />
    <AdminConfirmHost />
  </div>
</template>
