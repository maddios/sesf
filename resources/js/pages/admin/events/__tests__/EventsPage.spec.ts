import { describe, it, expect, afterEach } from 'vitest';
import { screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { defineComponent, h } from 'vue';
import EventsPage from '../EventsPage.vue';
import AdminConfirmHost from '../../../../components/admin/AdminConfirmHost.vue';
import AdminToastRegion from '../../../../components/admin/AdminToastRegion.vue';
import { renderAt, jsonResponse } from '../../../../test/helpers';
import { listBody, stubAdminFetch, type SeenRequest } from '../../../../test/admin';
import { useToast } from '../../../../composables/admin/useToast';
import { useConfirm } from '../../../../composables/admin/useConfirm';
import { toCreateInput, type Event } from '../events';

function event(overrides: Partial<Event> = {}): Event {
  return {
    id: 1,
    name: 'Spring Show',
    short_name: 'spring',
    description: 'Cars',
    short_description: null,
    schedule: null,
    is_schedule_pdf: false,
    price_car: '25.00',
    price_adult: null,
    price_child: null,
    date_start: '2027-05-01',
    date_end: '2027-05-02',
    date_active: null,
    date_registration_deactivation: null,
    is_active: true,
    is_judging_released: false,
    has_image: false,
    image_url: 'images/shows/generic-show.jpg',
    primary_sponsor_id: null,
    registrations_count: 0,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

/** The page plus the shared dialog/toast hosts that AdminLayout normally provides. */
const Page = defineComponent({ render: () => [h(EventsPage), h(AdminConfirmHost), h(AdminToastRegion)] });

const CLONE_SOURCES = { data: [{ id: 7, label: 'Last Year' }] };

function stubEvents(rows: Event[] = [event(), event({ id: 2, name: 'Fall Show', short_name: 'fall', is_active: false })], extra: (r: SeenRequest) => Response | undefined = () => undefined) {
  return stubAdminFetch((request) => {
    const handled = extra(request);
    if (handled !== undefined) return handled;
    if (request.method === 'GET' && request.path === '/admin-api/events') return jsonResponse(200, listBody(rows));
    if (request.path === '/admin-api/events/clone-sources') return jsonResponse(200, CLONE_SOURCES);
    if (request.path === '/admin-api/events/1/sponsors') return jsonResponse(200, { data: [], primary_sponsor_id: null });
    return undefined;
  });
}

function table() {
  return screen.getByRole('table', { name: 'Events' });
}

afterEach(() => {
  useToast().clear();
  useConfirm().settle(false);
  document.body.style.overflow = '';
});

describe('EventsPage', () => {
  it('lists events with status and registrations, and passes axe', async () => {
    const { seen } = stubEvents();
    const { container } = await renderAt(Page, '/admin/events');
    expect(await within(table()).findByText('Spring Show')).toBeTruthy();
    expect(within(table()).getByText('Inactive')).toBeTruthy();
    expect(within(table()).getByRole('button', { name: 'Sponsors for Spring Show' })).toBeTruthy();
    const list = seen.find((r) => r.path === '/admin-api/events')!;
    expect(list.params.get('sort')).toBe('date_start');
    expect(list.params.get('direction')).toBe('desc');
    expect(await axe(container)).toHaveNoViolations();
  });

  it('syncs the status filter with the URL', async () => {
    const { seen } = stubEvents();
    const user = userEvent.setup();
    const { router } = await renderAt(Page, '/admin/events?f.is_active=0');
    await within(table()).findByText('Fall Show');
    expect(seen.find((r) => r.path === '/admin-api/events')?.params.get('filter[is_active]')).toBe('0');
    await user.selectOptions(screen.getByLabelText('Status'), '1');
    expect(router.currentRoute.value.query['f.is_active']).toBe('1');
  });

  it('loads the clone sources when creating and sends the chosen one', async () => {
    const { seen } = stubEvents(undefined, (r) =>
      r.method === 'POST' && r.path === '/admin-api/events' ? jsonResponse(201, { data: event({ id: 3, name: 'New Show' }) }) : undefined,
    );
    const user = userEvent.setup();
    await renderAt(Page, '/admin/events');
    await user.click(await screen.findByRole('button', { name: 'New event' }));
    const dialog = await screen.findByRole('dialog', { name: 'New event' });
    await user.type(within(dialog).getByLabelText('Name (required)'), 'New Show');
    await user.type(within(dialog).getByLabelText('Short name (required)'), 'new');
    await user.selectOptions(within(dialog).getByLabelText(/Clone eligible bodies/), '7');
    await user.click(within(dialog).getByRole('button', { name: 'Create event' }));

    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(seen.some((r) => r.path === '/admin-api/events/clone-sources')).toBe(true);
    const body = seen.find((r) => r.method === 'POST' && r.path === '/admin-api/events')?.body as Record<string, unknown>;
    expect(body.clone_from_show_id).toBe(7);
    expect(body).not.toHaveProperty('is_active');
    expect(screen.getByRole('status', { name: 'Notifications' }).textContent).toContain('Event “New Show” created.');
  });

  it('marks the clone source required when earlier events exist, and maps a 422 onto it', async () => {
    stubEvents(undefined, (r) =>
      r.method === 'POST' && r.path === '/admin-api/events'
        ? jsonResponse(422, { message: 'Bodies missing.', errors: { clone_from_show_id: ['The selected event has no eligible car bodies configured (car_body_show is empty).'] } })
        : undefined,
    );
    const user = userEvent.setup();
    await renderAt(Page, '/admin/events');
    await user.click(await screen.findByRole('button', { name: 'New event' }));
    const dialog = await screen.findByRole('dialog', { name: 'New event' });
    const clone = within(dialog).getByLabelText(/Clone eligible bodies/) as HTMLSelectElement;
    expect(clone.required).toBe(true);
    await user.type(within(dialog).getByLabelText('Name (required)'), 'X');
    await user.type(within(dialog).getByLabelText('Short name (required)'), 'x');
    await user.selectOptions(clone, '7');
    await user.click(within(dialog).getByRole('button', { name: 'Create event' }));

    expect(await within(dialog).findByText(/no eligible car bodies/)).toBeTruthy();
    expect(clone.getAttribute('aria-invalid')).toBe('true');
  });

  it('activates and deactivates an event', async () => {
    const { seen } = stubEvents(undefined, (r) =>
      r.method === 'POST' && r.path === '/admin-api/events/2/activate' ? jsonResponse(200, { data: event({ id: 2, name: 'Fall Show', is_active: true }) }) : undefined,
    );
    const user = userEvent.setup();
    await renderAt(Page, '/admin/events');
    await user.click(await within(table()).findByRole('button', { name: 'Activate Fall Show' }));
    await waitFor(() => expect(seen.some((r) => r.path === '/admin-api/events/2/activate')).toBe(true));
    expect(await screen.findByText('Event “Fall Show” activated.')).toBeTruthy();
  });

  it('deletes only after the typed confirmation', async () => {
    const { seen } = stubEvents(undefined, (r) => (r.method === 'DELETE' && r.path === '/admin-api/events/2' ? new Response(null, { status: 204 }) : undefined));
    const user = userEvent.setup();
    await renderAt(Page, '/admin/events');
    await user.click(await within(table()).findByRole('button', { name: 'Delete Fall Show' }));
    const dialog = await screen.findByRole('dialog', { name: 'Delete event' });
    await user.type(within(dialog).getByLabelText(/Type DELETE/), 'DELETE');
    await user.click(within(dialog).getByRole('button', { name: 'Delete' }));

    await waitFor(() => expect(seen.some((r) => r.method === 'DELETE')).toBe(true));
    expect(await screen.findByText('Event “Fall Show” deleted.')).toBeTruthy();
  });

  it('reports a 409 for an event with registrations as an error toast and keeps it', async () => {
    stubEvents(undefined, (r) =>
      r.method === 'DELETE' ? jsonResponse(409, { message: 'This event has linked records (show_registration) and cannot be deleted. Deactivate it instead.', references: { show_registration: 3 } }) : undefined,
    );
    const user = userEvent.setup();
    await renderAt(Page, '/admin/events');
    await user.click(await within(table()).findByRole('button', { name: 'Delete Fall Show' }));
    await user.type(await screen.findByLabelText(/Type DELETE/), 'DELETE');
    await user.click(screen.getByRole('button', { name: 'Delete' }));
    expect(await screen.findByText(/Deactivate it instead/)).toBeTruthy();
    expect(within(table()).getByText('Fall Show')).toBeTruthy();
  });

  it('opens the sponsors of an event in the dialog', async () => {
    const { seen } = stubEvents();
    const user = userEvent.setup();
    await renderAt(Page, '/admin/events');
    await user.click(await within(table()).findByRole('button', { name: 'Sponsors for Spring Show' }));
    expect(await screen.findByRole('dialog', { name: 'Sponsors for Spring Show' })).toBeTruthy();
    await waitFor(() => expect(seen.some((r) => r.path === '/admin-api/events/1/sponsors')).toBe(true));
  });
});

describe('toCreateInput', () => {
  it('leaves the clone source out when none is chosen', () => {
    expect(toCreateInput({ name: 'A', short_name: 'a', clone_from_show_id: '' })).not.toHaveProperty('clone_from_show_id');
  });
});
