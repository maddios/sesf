/* eslint-disable vue/one-component-per-file -- small inline harness components */
import { describe, it, expect } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { createMemoryHistory, createRouter, type Router } from 'vue-router';
import { defineComponent, h } from 'vue';
import BaseAccordion from '../BaseAccordion.vue';
import DropdownMenu from '../DropdownMenu.vue';
import TabNav from '../TabNav.vue';
import BasePagination from '../BasePagination.vue';

const Empty = defineComponent({ render: () => null });

async function routerAt(path: string): Promise<Router> {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: Empty }] });
  await router.push(path);
  await router.isReady();
  return router;
}

const ITEMS = [
  { id: 'fees', title: 'Registration fees' },
  { id: 'lodging', title: 'Lodging' },
  { id: 'apparel', title: 'Apparel' },
];

describe('BaseAccordion', () => {
  function renderAccordion(props: Record<string, unknown> = {}) {
    return render(BaseAccordion, {
      props: { items: ITEMS, ...props },
      slots: {
        panel: ({ item }: { item: { title: string } }) => h('p', `Panel for ${item.title}`),
        apparel: () => h('p', 'Shirts are $35'),
      },
    });
  }

  it('renders collapsed headers wired to their regions', () => {
    renderAccordion();
    const header = screen.getByRole('button', { name: 'Registration fees' });
    expect(header.getAttribute('aria-expanded')).toBe('false');
    const panel = document.getElementById(header.getAttribute('aria-controls') ?? '');
    expect(panel?.getAttribute('role')).toBe('region');
    expect(panel?.style.display).toBe('none');
    expect(screen.getAllByRole('heading', { level: 3 })).toHaveLength(3);
  });

  it('single mode: opening one panel closes the other', async () => {
    const user = userEvent.setup();
    const { emitted } = renderAccordion();
    await user.click(screen.getByRole('button', { name: 'Registration fees' }));
    expect(screen.getByRole('region', { name: 'Registration fees' }).textContent).toContain('Panel for Registration fees');
    await user.click(screen.getByRole('button', { name: 'Lodging' }));
    expect(screen.getByRole('button', { name: 'Registration fees' }).getAttribute('aria-expanded')).toBe('false');
    expect(screen.getByRole('button', { name: 'Lodging' }).getAttribute('aria-expanded')).toBe('true');
    await user.click(screen.getByRole('button', { name: 'Lodging' }));
    expect(screen.getByRole('button', { name: 'Lodging' }).getAttribute('aria-expanded')).toBe('false');
    expect(emitted().change).toEqual([[['fees']], [['lodging']], [[]]]);
  });

  it('multiple mode keeps several panels open and honours defaultOpen', async () => {
    const user = userEvent.setup();
    renderAccordion({ multiple: true, defaultOpen: ['apparel'] });
    expect(screen.getByRole('region', { name: 'Apparel' }).textContent).toContain('Shirts are $35');
    await user.click(screen.getByRole('button', { name: 'Lodging' }));
    expect(screen.getByRole('button', { name: 'Apparel' }).getAttribute('aria-expanded')).toBe('true');
    expect(screen.getByRole('button', { name: 'Lodging' }).getAttribute('aria-expanded')).toBe('true');
  });

  it('opens with Enter and Space and moves between headers with arrow keys, Home and End', async () => {
    const user = userEvent.setup();
    renderAccordion();
    const [fees, lodging, apparel] = ITEMS.map((item) => screen.getByRole('button', { name: item.title }));
    fees?.focus();
    await user.keyboard('{Enter}');
    expect(fees?.getAttribute('aria-expanded')).toBe('true');
    await user.keyboard('{ArrowDown}');
    expect(document.activeElement).toBe(lodging);
    await user.keyboard(' ');
    expect(lodging?.getAttribute('aria-expanded')).toBe('true');
    await user.keyboard('{End}');
    expect(document.activeElement).toBe(apparel);
    await user.keyboard('{ArrowDown}');
    expect(document.activeElement).toBe(fees);
    await user.keyboard('{ArrowUp}');
    expect(document.activeElement).toBe(apparel);
    await user.keyboard('{Home}');
    expect(document.activeElement).toBe(fees);
  });

  it('has no detectable accessibility violations', async () => {
    const { container } = renderAccordion({ defaultOpen: ['fees'] });
    expect(await axe(container)).toHaveNoViolations();
  });
});

describe('DropdownMenu', () => {
  async function renderMenu() {
    const router = await routerAt('/');
    const utils = render(
      defineComponent({
        emits: ['select'],
        setup(_props, { emit }) {
          return () => [
            h(DropdownMenu, {
              label: 'Welcome Shark',
              items: [
                { id: 'profile', label: 'Profile', to: '/Shark/u7' },
                { id: 'admin', label: 'Admin', href: '/admin' },
                { id: 'logout', label: 'Logout', separator: true },
              ],
              onSelect: (id: string) => emit('select', id),
            }),
            h('button', { type: 'button' }, 'Elsewhere'),
          ];
        },
      }),
      { global: { plugins: [router] } },
    );
    return { router, ...utils };
  }

  it('starts closed with the menu-button attributes', async () => {
    await renderMenu();
    const trigger = screen.getByRole('button', { name: 'Welcome Shark' });
    expect(trigger.getAttribute('aria-haspopup')).toBe('menu');
    expect(trigger.getAttribute('aria-expanded')).toBe('false');
    expect(screen.queryByRole('menu')).toBeNull();
  });

  it('opens on click with focus on the first item; Escape closes and returns focus', async () => {
    const user = userEvent.setup();
    await renderMenu();
    const trigger = screen.getByRole('button', { name: 'Welcome Shark' });
    await user.click(trigger);
    expect(trigger.getAttribute('aria-expanded')).toBe('true');
    expect(screen.getByRole('menu', { name: 'Welcome Shark' })).toBeTruthy();
    expect(document.activeElement).toBe(screen.getByRole('menuitem', { name: 'Profile' }));
    await user.keyboard('{Escape}');
    expect(screen.queryByRole('menu')).toBeNull();
    expect(document.activeElement).toBe(trigger);
  });

  it('supports ArrowDown/ArrowUp on the trigger and Up/Down/Home/End inside the menu', async () => {
    const user = userEvent.setup();
    await renderMenu();
    const trigger = screen.getByRole('button', { name: 'Welcome Shark' });
    trigger.focus();
    await user.keyboard('{ArrowUp}');
    expect(document.activeElement).toBe(screen.getByRole('menuitem', { name: 'Logout' }));
    await user.keyboard('{ArrowDown}');
    expect(document.activeElement).toBe(screen.getByRole('menuitem', { name: 'Profile' }));
    await user.keyboard('{ArrowDown}');
    expect(document.activeElement).toBe(screen.getByRole('menuitem', { name: 'Admin' }));
    await user.keyboard('{End}');
    expect(document.activeElement).toBe(screen.getByRole('menuitem', { name: 'Logout' }));
    await user.keyboard('{Home}');
    expect(document.activeElement).toBe(screen.getByRole('menuitem', { name: 'Profile' }));
    await user.keyboard('{ArrowUp}');
    expect(document.activeElement).toBe(screen.getByRole('menuitem', { name: 'Logout' }));
    await user.keyboard('{Escape}');
    trigger.focus();
    await user.keyboard('{ArrowDown}');
    expect(document.activeElement).toBe(screen.getByRole('menuitem', { name: 'Profile' }));
    await user.keyboard('{Tab}');
    expect(screen.queryByRole('menu')).toBeNull();
  });

  it('emits select for action items (Enter or Space) and navigates for route items', async () => {
    const user = userEvent.setup();
    const { router, emitted } = await renderMenu();
    const trigger = screen.getByRole('button', { name: 'Welcome Shark' });
    await user.click(trigger);
    await user.keyboard('{End}');
    await user.keyboard(' ');
    expect(emitted().select).toEqual([['logout']]);
    expect(document.activeElement).toBe(trigger);

    await user.click(trigger);
    await user.click(screen.getByRole('menuitem', { name: 'Profile' }));
    await waitFor(() => expect(router.currentRoute.value.path).toBe('/Shark/u7'));
    expect(screen.queryByRole('menu')).toBeNull();
    expect(screen.getByRole('separator', { hidden: true })).toBeTruthy();
  });

  it('closes on a click outside and toggles from the trigger', async () => {
    const user = userEvent.setup();
    await renderMenu();
    const trigger = screen.getByRole('button', { name: 'Welcome Shark' });
    await user.click(trigger);
    await user.click(screen.getByRole('button', { name: 'Elsewhere' }));
    expect(screen.queryByRole('menu')).toBeNull();
    await user.click(trigger);
    await user.click(trigger);
    expect(screen.queryByRole('menu')).toBeNull();
  });

  it('has no detectable accessibility violations when open', async () => {
    const user = userEvent.setup();
    const { container } = await renderMenu();
    await user.click(screen.getByRole('button', { name: 'Welcome Shark' }));
    expect(await axe(container)).toHaveNoViolations();
  });
});

describe('TabNav', () => {
  const TABS = [
    { label: 'Event Info', to: '/SESF2026/e36' },
    { label: 'Judging', to: '/SESF2026/e36/judging' },
    { label: 'Gallery', to: '/gallery/e36', exact: false },
  ];

  async function renderTabs(path: string) {
    const router = await routerAt(path);
    return { router, ...render(TabNav, { props: { tabs: TABS, label: 'Event sections' }, global: { plugins: [router] } }) };
  }

  it('is a labelled nav of links with aria-current on the active tab only', async () => {
    await renderTabs('/SESF2026/e36/judging');
    expect(screen.getByRole('navigation', { name: 'Event sections' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Judging' }).getAttribute('aria-current')).toBe('page');
    expect(screen.getByRole('link', { name: 'Event Info' }).getAttribute('aria-current')).toBeNull();
    expect(screen.getByRole('link', { name: 'Event Info' }).getAttribute('href')).toBe('/SESF2026/e36');
  });

  it('non-exact tabs stay active on nested paths', async () => {
    await renderTabs('/gallery/e36/12');
    expect(screen.getByRole('link', { name: 'Gallery' }).getAttribute('aria-current')).toBe('page');
  });

  it('moves focus with arrow keys, Home and End, and navigates on Enter', async () => {
    const user = userEvent.setup();
    const { router } = await renderTabs('/SESF2026/e36');
    const [info, judging, gallery] = TABS.map((tab) => screen.getByRole('link', { name: tab.label }));
    info?.focus();
    await user.keyboard('{ArrowRight}');
    expect(document.activeElement).toBe(judging);
    await user.keyboard('{End}');
    expect(document.activeElement).toBe(gallery);
    await user.keyboard('{ArrowRight}');
    expect(document.activeElement).toBe(info);
    await user.keyboard('{ArrowLeft}');
    expect(document.activeElement).toBe(gallery);
    await user.keyboard('{Home}');
    expect(document.activeElement).toBe(info);
    await user.keyboard('{ArrowRight}{Enter}');
    await waitFor(() => expect(router.currentRoute.value.path).toBe('/SESF2026/e36/judging'));
  });

  it('has no detectable accessibility violations', async () => {
    const { container } = await renderTabs('/SESF2026/e36');
    expect(await axe(container)).toHaveNoViolations();
  });
});

describe('BasePagination', () => {
  async function renderPager(path: string, props: { page: number; totalPages: number; syncQuery?: boolean }) {
    const router = await routerAt(path);
    return { router, ...render(BasePagination, { props, global: { plugins: [router] } }) };
  }

  it('renders nothing for a single page', async () => {
    await renderPager('/members', { page: 1, totalPages: 1 });
    expect(screen.queryByRole('navigation')).toBeNull();
  });

  it('shows the legacy block of five pages with First/Previous disabled on page 1', async () => {
    await renderPager('/members', { page: 1, totalPages: 12 });
    expect(screen.getByRole('navigation', { name: 'Pagination' })).toBeTruthy();
    expect((screen.getByRole('button', { name: 'First' }) as HTMLButtonElement).disabled).toBe(true);
    expect((screen.getByRole('button', { name: 'Previous' }) as HTMLButtonElement).disabled).toBe(true);
    expect(screen.getByRole('button', { name: 'Page 1' }).getAttribute('aria-current')).toBe('page');
    expect(screen.getByRole('button', { name: 'Page 5' })).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Page 6' })).toBeNull();
    expect(screen.getByRole('button', { name: 'Pages after 5' }).textContent?.trim()).toBe('...');
  });

  it('writes ?page= into the query, keeping other keys, and emits update:page', async () => {
    const user = userEvent.setup();
    const { router, emitted } = await renderPager('/members?show=36', { page: 1, totalPages: 12 });
    await user.click(screen.getByRole('button', { name: 'Page 3' }));
    expect(emitted()['update:page']).toEqual([[3]]);
    await waitFor(() => expect(router.currentRoute.value.query).toEqual({ show: '36', page: '3' }));
  });

  it('jumps between blocks and to the ends', async () => {
    const user = userEvent.setup();
    const { emitted, rerender } = await renderPager('/members', { page: 7, totalPages: 12, syncQuery: false });
    expect(screen.getByRole('button', { name: 'Pages before 6' })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Pages after 10' })).toBeTruthy();
    await user.click(screen.getByRole('button', { name: 'Pages before 6' }));
    await user.click(screen.getByRole('button', { name: 'Next' }));
    await user.click(screen.getByRole('button', { name: 'Previous' }));
    await user.click(screen.getByRole('button', { name: 'Last' }));
    await user.click(screen.getByRole('button', { name: 'First' }));
    await user.click(screen.getByRole('button', { name: 'Page 7' }));
    expect(emitted()['update:page']).toEqual([[5], [8], [6], [12], [1]]);

    await rerender({ page: 12, totalPages: 12, syncQuery: false });
    expect((screen.getByRole('button', { name: 'Next' }) as HTMLButtonElement).disabled).toBe(true);
    expect((screen.getByRole('button', { name: 'Last' }) as HTMLButtonElement).disabled).toBe(true);
  });

  it('clamps an out-of-range page', async () => {
    await renderPager('/members', { page: 99, totalPages: 3 });
    expect(screen.getByRole('button', { name: 'Page 3' }).getAttribute('aria-current')).toBe('page');
  });

  it('has no detectable accessibility violations', async () => {
    const { container } = await renderPager('/members', { page: 2, totalPages: 8 });
    expect(await axe(container)).toHaveNoViolations();
  });
});
