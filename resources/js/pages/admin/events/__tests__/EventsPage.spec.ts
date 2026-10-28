import { describe, it, expect, afterEach } from 'vitest';
import { screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { defineComponent, h } from 'vue';
import EventsPage from '../EventsPage.vue';
import AdminConfirmHost from '../../../../components/admin/AdminConfirmHost.vue';
import AdminToastRegion from '../../../../components/admin/AdminToastRegion.vue';
import { renderAt, jsonResponse } from '../../../../test/helpers';
import { listBody, stubAdminFetch, stubXhr, type SeenRequest } from '../../../../test/admin';
import { useToast } from '../../../../composables/admin/useToast';
import { useConfirm } from '../../../../composables/admin/useConfirm';
import { toCreateInput, toInput, type Event } from '../events';

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
    waiver_id: null,
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
    await user.click(screen.getByRole('combobox', { name: 'Status' }));
    await user.click(await screen.findByRole('option', { name: 'Active' }));
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
    await user.click(within(dialog).getByRole('combobox', { name: /Clone eligible bodies/ }));
    await user.click(await screen.findByRole('option', { name: 'Last Year' }));
    await user.click(within(dialog).getByRole('button', { name: 'Create event' }));

    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(seen.some((r) => r.path === '/admin-api/events/clone-sources')).toBe(true);
    const body = seen.find((r) => r.method === 'POST' && r.path === '/admin-api/events')?.body as Record<string, unknown>;
    expect(body.clone_from_show_id).toBe(7);
    expect(body).not.toHaveProperty('is_active');
    expect(screen.getByRole('status', { name: 'Notifications' }).textContent).toContain('Event “New Show” created.');
  });

  it('maps a 422 on the clone source onto its combobox', async () => {
    stubEvents(undefined, (r) =>
      r.method === 'POST' && r.path === '/admin-api/events'
        ? jsonResponse(422, { message: 'Bodies missing.', errors: { clone_from_show_id: ['The selected event has no eligible car bodies configured (car_body_show is empty).'] } })
        : undefined,
    );
    const user = userEvent.setup();
    await renderAt(Page, '/admin/events');
    await user.click(await screen.findByRole('button', { name: 'New event' }));
    const dialog = await screen.findByRole('dialog', { name: 'New event' });
    const clone = within(dialog).getByRole('combobox', { name: /Clone eligible bodies/ }) as HTMLInputElement;
    await user.type(within(dialog).getByLabelText('Name (required)'), 'X');
    await user.type(within(dialog).getByLabelText('Short name (required)'), 'x');
    await user.click(clone);
    await user.click(await screen.findByRole('option', { name: 'Last Year' }));
    await user.click(within(dialog).getByRole('button', { name: 'Create event' }));

    expect(await within(dialog).findByText(/no eligible car bodies/)).toBeTruthy();
    expect(clone.getAttribute('aria-invalid')).toBe('true');
  });

  it('edits the three texts with the rich text editor, keeps legacy markup and maps a too-long 422', async () => {
    const legacySchedule = '<table border="1"><tbody><tr><td valign="top">Fri</td><td>Check-in</td></tr></tbody></table><p>Bring <b>shades</b></p>';
    const { seen } = stubEvents([event({ short_description: 'Short legacy text', schedule: legacySchedule })], (r) =>
      r.method === 'PUT' && r.path === '/admin-api/events/1'
        ? jsonResponse(422, { message: 'Too long.', errors: { description: ['The description is too long: 1,200 of 1,000 characters, counting its formatting (HTML). Remove some text or formatting.'] } })
        : undefined,
    );
    const user = userEvent.setup();
    await renderAt(Page, '/admin/events');
    await user.click(await within(table()).findByRole('button', { name: 'Edit Spring Show' }));
    const dialog = await screen.findByRole('dialog', { name: 'Edit Spring Show' });

    expect((await within(dialog).findByRole('textbox', { name: 'Short description' })).textContent).toBe('Short legacy text');
    const schedule = await within(dialog).findByRole('textbox', { name: 'Schedule' });
    expect(schedule.querySelectorAll('.rte-opaque')).toHaveLength(1);
    expect(within(dialog).getByText('4 / 1,000 characters, formatting included')).toBeTruthy();

    const switches = within(dialog).getAllByRole('switch', { name: 'Visual editor' });
    expect(switches).toHaveLength(3);
    await user.click(switches[1]!);
    const raw = within(dialog).getByRole('textbox', { name: 'Description (HTML)' });
    await user.clear(raw);
    await user.type(raw, '<p>New <strong>bold</strong> text</p>');
    await user.click(within(dialog).getByRole('button', { name: 'Save changes' }));

    const body = (await waitFor(() => {
      const put = seen.find((r) => r.method === 'PUT' && r.path === '/admin-api/events/1');
      expect(put).toBeTruthy();
      return put!.body;
    })) as Record<string, unknown>;
    expect(body.description).toBe('<p>New <strong>bold</strong> text</p>');
    expect(body.schedule).toBe(legacySchedule);
    expect(body.short_description).toBe('Short legacy text');
    expect(await within(dialog).findByText(/The description is too long: 1,200 of 1,000 characters/)).toBeTruthy();
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
  it('releases judging results from the row action', async () => {
    const { seen } = stubEvents(undefined, (r) =>
      r.method === 'PUT' && r.path === '/admin-api/judging/shows/1/release' ? jsonResponse(200, { data: { id: 1, is_judging_released: true } }) : undefined,
    );
    const user = userEvent.setup();
    await renderAt(Page, '/admin/events');
    await user.click(await within(table()).findByRole('button', { name: 'Release judging results for Spring Show' }));
    await waitFor(() => expect(seen.find((r) => r.path === '/admin-api/judging/shows/1/release')?.body).toEqual({ released: true }));
    expect(await screen.findByText('Judging results for “Spring Show” released.')).toBeTruthy();
  });

  it('sends judging results for a released event after confirming', async () => {
    const { seen } = stubEvents([event({ is_judging_released: true })], (r) =>
      r.method === 'POST' && r.path === '/admin-api/judging/shows/1/results-mail' ? jsonResponse(200, { data: { sent: 3 } }) : undefined,
    );
    const user = userEvent.setup();
    await renderAt(Page, '/admin/events');
    await user.click(await within(table()).findByRole('button', { name: 'Send judging results for Spring Show' }));
    const dialog = await screen.findByRole('dialog', { name: 'Send judging results' });
    expect(seen.some((r) => r.path.endsWith('/results-mail'))).toBe(false);
    await user.click(within(dialog).getByRole('button', { name: 'Send results' }));
    expect(await screen.findByText('Judging results for “Spring Show” sent (3).')).toBeTruthy();
  });

  it('uploads the event image through the image modal', async () => {
    stubEvents();
    const xhrs = stubXhr();
    const user = userEvent.setup();
    await renderAt(Page, '/admin/events');
    await user.click(await within(table()).findByRole('button', { name: 'Upload image for Spring Show' }));
    const dialog = await screen.findByRole('dialog', { name: 'Image for Spring Show' });
    await user.upload(within(dialog).getByLabelText('Choose an image'), new File(['x'], 's.png', { type: 'image/png' }));
    await user.click(within(dialog).getByRole('button', { name: 'Upload' }));
    expect(xhrs[0]?.url).toBe('/admin-api/events/1/image');
    xhrs[0]!.respond(200, { data: event({ has_image: true }) });
    expect(await screen.findByText('Image for “Spring Show” uploaded.')).toBeTruthy();
  });

  it('uploads a schedule PDF and reports a rejected file', async () => {
    stubEvents();
    const xhrs = stubXhr();
    const user = userEvent.setup();
    await renderAt(Page, '/admin/events');
    await user.click(await within(table()).findByRole('button', { name: 'Upload schedule PDF for Spring Show' }));
    await user.upload(screen.getByLabelText('Schedule PDF file'), new File(['%PDF-'], 'schedule.pdf', { type: 'application/pdf' }));
    await waitFor(() => expect(xhrs[0]?.url).toBe('/admin-api/events/1/schedule-pdf'));
    xhrs[0]!.respond(200, { data: event({ is_schedule_pdf: true }) });
    expect(await screen.findByText('Schedule PDF for “Spring Show” uploaded.')).toBeTruthy();

    await user.click(within(table()).getByRole('button', { name: 'Upload schedule PDF for Spring Show' }));
    await user.upload(screen.getByLabelText('Schedule PDF file'), new File(['<html>'], 'fake.pdf', { type: 'application/pdf' }));
    await waitFor(() => expect(xhrs[1]).toBeDefined());
    xhrs[1]!.respond(422, { message: 'The file must be a PDF.', errors: { file: ['The file must be a PDF.'] } });
    expect(await screen.findByText('Please correct the highlighted fields.')).toBeTruthy();
  });

  it('shows the id and links each event to its expense tracker', async () => {
    stubEvents();
    await renderAt(Page, '/admin/events');
    expect(await within(table()).findByRole('columnheader', { name: /ID/ })).toBeTruthy();
    const link = await within(table()).findByRole('link', { name: 'Expenses for Spring Show' });
    expect(link.getAttribute('href')).toContain('/admin/expenses?show_id=1');
  });

  it('sends the waiver switch trimmed, blank as null', () => {
    expect(toInput({ name: 'A', short_name: 'a', waiver_id: ' tpl ' }).waiver_id).toBe('tpl');
    expect(toInput({ name: 'A', short_name: 'a', waiver_id: '' }).waiver_id).toBeNull();
  });

  it('leaves the clone source out when none is chosen', () => {
    expect(toCreateInput({ name: 'A', short_name: 'a', clone_from_show_id: '' })).not.toHaveProperty('clone_from_show_id');
  });
});
