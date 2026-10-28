import { describe, it, expect, afterEach } from 'vitest';
import { screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { defineComponent, h } from 'vue';
import AgendaPage from '../AgendaPage.vue';
import AdminConfirmHost from '../../../../components/admin/AdminConfirmHost.vue';
import AdminToastRegion from '../../../../components/admin/AdminToastRegion.vue';
import { renderAt, jsonResponse } from '../../../../test/helpers';
import { listBody, stubAdminFetch, type SeenRequest } from '../../../../test/admin';
import { useToast } from '../../../../composables/admin/useToast';
import { useConfirm } from '../../../../composables/admin/useConfirm';
import type { AgendaItem } from '../agenda';

function item(overrides: Partial<AgendaItem> = {}): AgendaItem {
  return {
    id: 1,
    show_id: 4,
    show_name: 'Spring Show',
    name: 'Opening remarks',
    text: '<p>Welcome</p>',
    type_id: 1,
    type: 'Agenda',
    is_visible: true,
    sort_order: 1,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

const Page = defineComponent({ render: () => [h(AgendaPage), h(AdminConfirmHost), h(AdminToastRegion)] });

const ROWS = [item(), item({ id: 2, name: 'Judging begins', type_id: 2, type: 'Judging', is_visible: false, sort_order: 2 })];

function stubAgenda(rows: AgendaItem[] = ROWS, extra: (request: SeenRequest) => Response | undefined = () => undefined) {
  return stubAdminFetch((request) => {
    const handled = extra(request);
    if (handled !== undefined) return handled;
    if (request.method === 'GET' && request.path === '/admin-api/agenda') return jsonResponse(200, listBody(rows));
    if (request.path === '/admin-api/lookups/events') return jsonResponse(200, { data: [{ id: 4, label: 'Spring Show (2026)' }] });
    return undefined;
  });
}

afterEach(() => {
  useToast().clear();
  useConfirm().settle(false);
  document.body.style.overflow = '';
});

describe('AgendaPage', () => {
  it('asks for an event first and does not fetch items', async () => {
    const { seen } = stubAgenda();
    const { container } = await renderAt(Page, '/admin/agenda');
    expect(await screen.findByText('Choose an event to see and arrange its agenda.')).toBeTruthy();
    expect(seen.some((r) => r.path === '/admin-api/agenda')).toBe(false);
    expect((screen.getByRole('button', { name: 'New agenda item' }) as HTMLButtonElement).disabled).toBe(true);
    expect(await axe(container)).toHaveNoViolations();
  });

  it('lists one event in position order with its actions, and passes axe', async () => {
    const { seen } = stubAgenda();
    const { container } = await renderAt(Page, '/admin/agenda?f.show_id=4');
    const list = await screen.findByRole('list', { name: 'Agenda items' });
    expect(await within(list).findByText('Opening remarks')).toBeTruthy();
    expect(within(list).getByText('Hidden')).toBeTruthy();
    expect(within(list).getByRole('button', { name: 'Edit Judging begins' })).toBeTruthy();
    const request = seen.find((r) => r.path === '/admin-api/agenda')!;
    expect(request.params.get('filter[show_id]')).toBe('4');
    expect(request.params.get('sort')).toBe('sort_order');
    expect(await axe(container)).toHaveNoViolations();
  });

  it('saves a new order for the event when an item is moved', async () => {
    const { seen } = stubAgenda(ROWS, (r) =>
      r.method === 'POST' && r.path === '/admin-api/agenda/reorder' ? jsonResponse(200, { data: { show_id: 4, ids: [2, 1] } }) : undefined,
    );
    const user = userEvent.setup();
    await renderAt(Page, '/admin/agenda?f.show_id=4');
    await user.click(await screen.findByRole('button', { name: 'Move Opening remarks down' }));

    await waitFor(() => expect(seen.some((r) => r.method === 'POST' && r.path === '/admin-api/agenda/reorder')).toBe(true));
    expect(seen.find((r) => r.path === '/admin-api/agenda/reorder')?.body).toEqual({ show_id: 4, ids: [2, 1] });
    expect(await screen.findByText('Agenda order saved.')).toBeTruthy();
  });

  it('filters by type through the URL and turns reordering off while filtered', async () => {
    const { seen } = stubAgenda();
    const user = userEvent.setup();
    const { router } = await renderAt(Page, '/admin/agenda?f.show_id=4');
    await screen.findByRole('list', { name: 'Agenda items' });
    await user.click(screen.getByRole('combobox', { name: 'Type' }));
    await user.click(await screen.findByRole('option', { name: 'Judging' }));
    await waitFor(() => expect(router.currentRoute.value.query['f.type_id']).toBe('2'));
    await waitFor(() => expect(seen.filter((r) => r.path === '/admin-api/agenda').at(-1)?.params.get('filter[type_id]')).toBe('2'));
    expect(await screen.findByText('Clear the type filter to change the order.')).toBeTruthy();
    expect((screen.getByRole('button', { name: 'Move Opening remarks down' }) as HTMLButtonElement).disabled).toBe(true);
  });

  it('reports a refused order and reloads the list', async () => {
    const { seen } = stubAgenda(ROWS, (r) =>
      r.method === 'POST' && r.path === '/admin-api/agenda/reorder'
        ? jsonResponse(422, { message: 'Invalid.', errors: { ids: ['The list must name every item of this event exactly once.'] } })
        : undefined,
    );
    const user = userEvent.setup();
    await renderAt(Page, '/admin/agenda?f.show_id=4');
    await user.click(await screen.findByRole('button', { name: 'Move Opening remarks down' }));

    expect(await screen.findByText('Please correct the highlighted fields.')).toBeTruthy();
    await waitFor(() => expect(seen.filter((r) => r.method === 'GET' && r.path === '/admin-api/agenda').length).toBeGreaterThan(1));
  });

  it('creates an item in the chosen event and shows a toast', async () => {
    const { seen } = stubAgenda(ROWS, (r) =>
      r.method === 'POST' && r.path === '/admin-api/agenda' ? jsonResponse(201, { data: item({ id: 3, name: 'Lunch' }) }) : undefined,
    );
    const user = userEvent.setup();
    await renderAt(Page, '/admin/agenda?f.show_id=4');
    await user.click(await screen.findByRole('button', { name: 'New agenda item' }));
    const dialog = await screen.findByRole('dialog', { name: 'New agenda item' });
    await user.type(within(dialog).getByLabelText('Name (required)'), 'Lunch');
    await user.type(within(dialog).getByLabelText('Description (HTML)'), '<b>Tacos</b>');
    await user.click(within(dialog).getByRole('button', { name: 'Create item' }));

    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(seen.find((r) => r.method === 'POST' && r.path === '/admin-api/agenda')?.body).toEqual({
      show_id: 4,
      name: 'Lunch',
      text: '<b>Tacos</b>',
      type_id: 1,
      is_visible: true,
    });
    expect(screen.getByRole('status', { name: 'Notifications' }).textContent).toContain('Agenda item “Lunch” created.');
  });

  it('maps a server name error onto the field', async () => {
    stubAgenda(ROWS, (r) =>
      r.method === 'PUT' && r.path === '/admin-api/agenda/1'
        ? jsonResponse(422, { message: 'Invalid.', errors: { name: ['The name field is required.'] } })
        : undefined,
    );
    const user = userEvent.setup();
    await renderAt(Page, '/admin/agenda?f.show_id=4');
    await user.click(await within(await screen.findByRole('list', { name: 'Agenda items' })).findByRole('button', { name: 'Edit Opening remarks' }));
    const dialog = await screen.findByRole('dialog', { name: 'Edit Opening remarks' });
    await user.click(within(dialog).getByRole('button', { name: 'Save changes' }));

    expect(await within(dialog).findByText('The name field is required.')).toBeTruthy();
    expect(within(dialog).getByLabelText('Name (required)').getAttribute('aria-invalid')).toBe('true');
  });

  it('deletes only after the typed confirmation', async () => {
    const { seen } = stubAgenda(ROWS, (r) =>
      r.method === 'DELETE' && r.path === '/admin-api/agenda/2' ? new Response(null, { status: 204 }) : undefined,
    );
    const user = userEvent.setup();
    await renderAt(Page, '/admin/agenda?f.show_id=4');
    await user.click(await screen.findByRole('button', { name: 'Delete Judging begins' }));
    const dialog = await screen.findByRole('dialog', { name: 'Delete agenda item' });
    const confirm = within(dialog).getByRole('button', { name: 'Delete' }) as HTMLButtonElement;
    expect(confirm.disabled).toBe(true);
    await user.type(within(dialog).getByLabelText(/Type DELETE/), 'DELETE');
    await user.click(confirm);

    await waitFor(() => expect(seen.some((r) => r.method === 'DELETE')).toBe(true));
    expect(await screen.findByText('Agenda item “Judging begins” deleted.')).toBeTruthy();
  });
});
