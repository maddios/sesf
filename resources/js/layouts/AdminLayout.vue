<script setup lang="ts">
/**
 * Admin shell for /#/admin/...: the legacy admin's dark top bar (menu button on mobile, title, Back to site, user
 * menu), a sidebar with the registry pages grouped like the legacy admin menu, a breadcrumb, and the page in
 * <main>. Also mounts the shared toast region and confirm dialog, and sets body.admin-ui (admin styles in
 * resources/css/admin.css, including dialogs teleported to <body>). Below 768px the sidebar is a drawer over the
 * page (backdrop, Escape and navigating close it, focus moves into it when opened and back to the Menu button when
 * closed). The header is sticky. Focus moves to <main> after each page change.
 */
import '../../css/admin.css';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router';
import DropdownMenu, { type DropdownMenuItem } from '../components/ui/DropdownMenu.vue';
import AdminToastRegion from '../components/admin/AdminToastRegion.vue';
import AdminConfirmHost from '../components/admin/AdminConfirmHost.vue';
import ImpersonationBanner from '../components/admin/ImpersonationBanner.vue';
import { findEntry, navGroups } from '../admin/registry';
import { USER_TYPE_ADMIN, useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const sidebarOpen = ref(false);
const main = ref<HTMLElement | null>(null);
const menuButton = ref<HTMLButtonElement | null>(null);
const sidebar = ref<HTMLElement | null>(null);

const groups = computed(() => navGroups(auth.userLevel));
/** Judges (level 2) see only their judging pages: no dashboard link or crumb. */
const isAdmin = computed(() => auth.userLevel >= USER_TYPE_ADMIN);
const current = computed(() => findEntry(route.meta.adminKey));

const crumbs = computed(() => {
  const items: Array<{ label: string; to?: string }> = [isAdmin.value ? { label: 'Admin', to: '/admin' } : { label: 'Admin' }];
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

async function toggleSidebar(): Promise<void> {
  sidebarOpen.value = !sidebarOpen.value;
  if (!sidebarOpen.value) return;
  await nextTick();
  sidebar.value?.querySelector<HTMLElement>('a')?.focus();
}

function closeSidebar(): void {
  if (!sidebarOpen.value) return;
  sidebarOpen.value = false;
  menuButton.value?.focus();
}

onMounted(() => document.body.classList.add('admin-ui'));
onBeforeUnmount(() => document.body.classList.remove('admin-ui'));

function focusMain(): void {
  main.value?.focus({ preventScroll: true });
}
</script>

<template>
  <div
    class="flex min-h-screen flex-col bg-panel text-text"
    @keydown.esc="closeSidebar"
  >
    <a
      href="#main"
      class="sr-only z-50 rounded-sm bg-panel px-4 py-2 font-semibold text-brand shadow-overlay focus:not-sr-only focus:fixed focus:left-4 focus:top-4"
      @click.prevent="focusMain"
    >Skip to main content</a>

    <ImpersonationBanner v-if="auth.isLoggedIn" />

    <header class="admin-topbar sticky top-0 z-40 flex h-12 items-center gap-2 px-2 md:h-11 md:px-0">
      <button
        ref="menuButton"
        type="button"
        class="admin-topbar-link inline-flex h-10 items-center gap-2 rounded-sm px-3 text-sm md:hidden"
        aria-controls="admin-sidebar"
        :aria-expanded="sidebarOpen ? 'true' : 'false'"
        @click="toggleSidebar"
      >
        <span aria-hidden="true">{{ sidebarOpen ? '✕' : '☰' }}</span>
        Menu
      </button>
      <RouterLink
        to="/admin"
        class="admin-topbar-title truncate no-underline md:w-56 md:px-4"
      >
        SESF Admin
      </RouterLink>
      <div class="ml-auto flex h-full shrink-0 items-stretch">
        <a
          href="#/"
          class="admin-topbar-link hidden items-center px-3 text-sm no-underline sm:flex"
        >Back to site</a>
        <DropdownMenu
          :label="userLabel"
          :items="userItems"
          trigger-class="admin-topbar-link inline-flex h-full min-h-10 items-center px-3 text-sm"
          @select="onUserSelect"
        />
      </div>
    </header>

    <div class="flex flex-1 flex-col md:flex-row">
      <div
        v-if="sidebarOpen"
        class="fixed inset-0 z-40 bg-black/40 md:hidden"
        aria-hidden="true"
        data-testid="admin-sidebar-backdrop"
        @click="closeSidebar"
      />
      <nav
        id="admin-sidebar"
        ref="sidebar"
        aria-label="Admin"
        class="admin-sidebar max-md:fixed max-md:inset-y-0 max-md:left-0 max-md:z-50 max-md:w-72 max-md:max-w-[85vw] max-md:overflow-y-auto max-md:shadow-overlay md:sticky md:top-11 md:block md:max-h-[calc(100vh-2.75rem)] md:w-56 md:shrink-0 md:self-start md:overflow-y-auto"
        :class="sidebarOpen ? 'block' : 'hidden'"
      >
        <div class="admin-topbar flex h-12 items-center justify-between px-4 md:hidden">
          <span class="admin-topbar-title">Admin menu</span>
          <button
            type="button"
            class="admin-topbar-link h-10 rounded-sm px-3 text-2xl leading-none"
            aria-label="Close menu"
            @click="closeSidebar"
          >
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <ul class="py-2">
          <li v-if="isAdmin">
            <RouterLink
              to="/admin"
              class="admin-nav-link"
              :class="route.name === 'admin' ? 'is-current' : ''"
              :aria-current="route.name === 'admin' ? 'page' : undefined"
            >
              Dashboard
            </RouterLink>
          </li>
          <li
            v-for="block in groups"
            :key="block.group"
            class="mt-2"
          >
            <p class="admin-nav-group">
              {{ block.group }}
            </p>
            <ul>
              <li
                v-for="entry in block.entries"
                :key="entry.key"
              >
                <RouterLink
                  :to="`/admin/${entry.path}`"
                  class="admin-nav-link"
                  :class="current?.key === entry.key ? 'is-current' : ''"
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
        class="min-w-0 flex-1 px-3 pt-2 pb-8 md:px-5"
      >
        <nav
          aria-label="Breadcrumb"
          class="mb-2 text-xs text-muted"
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
        <div class="@container/admin-panel">
          <RouterView />
        </div>
      </main>
    </div>

    <AdminToastRegion />
    <AdminConfirmHost />
  </div>
</template>
