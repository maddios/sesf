import { afterEach, describe, expect, it, vi } from 'vitest';
import { render, screen, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { createMemoryHistory, createRouter } from 'vue-router';
import AdminRowActions from '../AdminRowActions.vue';
import type { RowAction } from '../types';

function setup(width = 1280, extra: Partial<RowAction> = {}) {
  Object.defineProperty(window, 'innerWidth', { value: width, configurable: true });
  const edit = vi.fn();
  const enable = vi.fn();
  const send = vi.fn();
  const remove = vi.fn();
  const actions: RowAction[] = [
    { id: 'delete', label: 'Delete…', icon: 'trash', danger: true, handler: remove },
    { id: 'enable', label: 'Enable', icon: 'power', handler: enable },
    { id: 'send', label: 'Send results', icon: 'mail', disabled: true, hint: 'Release the results first.', handler: send, ...extra },
    { id: 'expenses', label: 'Expense tracker', icon: 'dollar', to: '/admin/expenses?show_id=1' },
  ];
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:path(.*)*', component: { template: '<div />' } }] });
  const utils = render(AdminRowActions, {
    props: { subject: 'Spring Show', primary: { id: 'edit', label: 'Edit', icon: 'edit', handler: edit }, actions },
    global: { plugins: [router] },
  });
  return { ...utils, edit, enable, send, remove, user: userEvent.setup() };
}

function trigger(): HTMLElement {
  return screen.getByRole('button', { name: 'Actions for Spring Show' });
}

function menu(): HTMLElement {
  return screen.getByRole('menu', { name: 'Actions for Spring Show' });
}

afterEach(() => {
  Object.defineProperty(window, 'innerWidth', { value: 1024, configurable: true });
});

describe('AdminRowActions', () => {
  it('shows a labelled primary button and an Actions menu button', async () => {
    const { user, edit, container } = setup();
    const primary = screen.getByRole('button', { name: 'Edit Spring Show' });
    expect(primary.textContent?.trim()).toBe('Edit');
    expect(trigger().textContent?.trim()).toBe('Actions');
    expect(trigger().getAttribute('aria-haspopup')).toBe('menu');
    expect(trigger().getAttribute('aria-expanded')).toBe('false');
    await user.click(primary);
    expect(edit).toHaveBeenCalledOnce();
    expect(await axe(container)).toHaveNoViolations();
  });

  it('lists items with labels, danger items last behind a separator, and passes axe while open', async () => {
    const { user } = setup();
    await user.click(trigger());
    expect(trigger().getAttribute('aria-expanded')).toBe('true');
    const names = within(menu()).getAllByRole('menuitem').map((item) => item.textContent?.trim().split('Release')[0]?.trim());
    expect(names).toEqual(['Enable', 'Send results', 'Expense tracker', 'Delete…']);
    expect(within(menu()).getByRole('separator')).toBeTruthy();
    expect(within(menu()).getByRole('menuitem', { name: 'Delete…' }).className).toContain('is-danger');
    expect(menu().closest('.admin-actions-popover')).not.toBeNull();
    expect(await axe(menu().parentElement as HTMLElement)).toHaveNoViolations();
  });

  it('follows the ARIA menu keyboard pattern and returns focus to the button', async () => {
    const { user, enable } = setup();
    trigger().focus();
    await user.keyboard('{ArrowDown}');
    const items = within(menu()).getAllByRole('menuitem');
    expect(document.activeElement).toBe(items[0]);
    await user.keyboard('{ArrowDown}');
    expect(document.activeElement).toBe(items[1]);
    await user.keyboard('{End}');
    expect(document.activeElement).toBe(items[3]);
    await user.keyboard('{ArrowDown}');
    expect(document.activeElement).toBe(items[0]);
    await user.keyboard('{ArrowUp}');
    expect(document.activeElement).toBe(items[3]);
    await user.keyboard('{Home}');
    expect(document.activeElement).toBe(items[0]);
    await user.keyboard('{Escape}');
    expect(screen.queryByRole('menu')).toBeNull();
    expect(document.activeElement).toBe(trigger());

    await user.keyboard('{ArrowUp}');
    expect(document.activeElement?.textContent).toContain('Delete…');
    await user.keyboard('{Escape}');

    await user.keyboard('{Enter}');
    expect(document.activeElement).toBe(within(menu()).getAllByRole('menuitem')[0]);
    await user.keyboard('{Enter}');
    expect(enable).toHaveBeenCalledOnce();
    expect(screen.queryByRole('menu')).toBeNull();
    expect(document.activeElement).toBe(trigger());

    await user.keyboard(' ');
    expect(screen.getByRole('menu')).toBeTruthy();
    await user.keyboard('{Tab}');
    expect(screen.queryByRole('menu')).toBeNull();
  });

  it('closes on a click outside', async () => {
    const { user } = setup();
    await user.click(trigger());
    await user.click(document.body);
    expect(screen.queryByRole('menu')).toBeNull();
  });

  it('keeps a disabled item focusable, names it by its label and gives the reason as tooltip and description', async () => {
    const { user, send } = setup();
    await user.click(trigger());
    const item = within(menu()).getByRole('menuitem', { name: 'Send results' });
    expect(item.getAttribute('aria-disabled')).toBe('true');
    expect(item.getAttribute('title')).toBe('Release the results first.');
    const description = document.getElementById(item.getAttribute('aria-describedby') ?? '');
    expect(description?.textContent).toBe('Release the results first.');
    await user.click(item);
    expect(send).not.toHaveBeenCalled();
    expect(screen.getByRole('menu')).toBeTruthy();
  });

  it('opens as a bottom sheet on phones with the hint as visible text', async () => {
    const { user, remove } = setup(390);
    await user.click(trigger());
    const sheet = menu().closest('.admin-actions-sheet');
    expect(sheet).not.toBeNull();
    expect(sheet?.textContent).toContain('Spring Show');
    expect(document.querySelector('.admin-actions-backdrop')).not.toBeNull();
    const item = within(menu()).getByRole('menuitem', { name: 'Send results' });
    expect(item.getAttribute('title')).toBeNull();
    expect(item.textContent).toContain('Release the results first.');
    expect(await axe(menu().parentElement as HTMLElement)).toHaveNoViolations();
    await user.click(within(menu()).getByRole('menuitem', { name: 'Delete…' }));
    expect(remove).toHaveBeenCalledOnce();
    expect(screen.queryByRole('menu')).toBeNull();
  });

  it('closes the sheet from the backdrop without running anything', async () => {
    const { user, remove, enable } = setup(390);
    await user.click(trigger());
    await user.click(document.querySelector('.admin-actions-backdrop') as HTMLElement);
    expect(screen.queryByRole('menu')).toBeNull();
    expect(remove).not.toHaveBeenCalled();
    expect(enable).not.toHaveBeenCalled();
    expect(document.activeElement).toBe(trigger());
  });

  it('renders router actions as links', async () => {
    const { user } = setup();
    await user.click(trigger());
    const link = within(menu()).getByRole('menuitem', { name: 'Expense tracker' });
    expect(link.tagName).toBe('A');
    expect(link.getAttribute('href')).toContain('/admin/expenses?show_id=1');
  });
});
