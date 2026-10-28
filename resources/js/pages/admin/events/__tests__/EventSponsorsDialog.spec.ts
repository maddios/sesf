import { describe, it, expect, afterEach } from 'vitest';
import { screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { defineComponent, h } from 'vue';
import EventSponsorsDialog from '../EventSponsorsDialog.vue';
import AdminToastRegion from '../../../../components/admin/AdminToastRegion.vue';
import { renderAt, jsonResponse } from '../../../../test/helpers';
import { stubAdminFetch, type SeenRequest } from '../../../../test/admin';
import { useToast } from '../../../../composables/admin/useToast';
import type { Event, EventSponsor } from '../events';

function event(): Event {
  return {
    id: 1, name: 'Spring Show', short_name: 'spring', description: null, short_description: null, schedule: null,
    is_schedule_pdf: false, price_car: null, price_adult: null, price_child: null, date_start: null, date_end: null,
    date_active: null, date_registration_deactivation: null, is_active: true, is_judging_released: false, waiver_id: null, has_image: false,
    image_url: '', primary_sponsor_id: null, registrations_count: 0, created_at: null, updated_at: null,
  };
}

function link(id: number, sponsorId: number, name: string, sortOrder: number): EventSponsor {
  return { id, sponsor_id: sponsorId, name, URL: null, is_enabled: true, sort_order: sortOrder, type_id: null, type: null };
}

const TIER_LABEL: Record<number, string> = { 1: 'M', 2: 'Gold', 3: 'Silber', 4: 'Bronzit' };

const ACME = link(11, 5, 'Acme', 1);
const BOLT = link(12, 6, 'Bolt', 2);

/** The dialog, open, for event 1, with the shared toast host. */
const Host = defineComponent({
  render: () => [h(EventSponsorsDialog, { open: true, event: event(), 'onUpdate:open': () => undefined }), h(AdminToastRegion)],
});

/**
 * A tiny fake backend for the event's sponsors. `state` is mutated by the handlers so reloads see the new list.
 */
function stubSponsors(extra: (r: SeenRequest) => Response | undefined = () => undefined) {
  const state = { rows: [ACME, BOLT], primary: 5 as number | null };
  const stub = stubAdminFetch((request) => {
    const handled = extra(request);
    if (handled !== undefined) return handled;
    if (request.method === 'GET' && request.path === '/admin-api/events/1/sponsors') {
      return jsonResponse(200, { data: state.rows, primary_sponsor_id: state.primary });
    }
    if (request.method === 'GET' && request.path === '/admin-api/events/1/sponsor-candidates') {
      return jsonResponse(200, { data: [{ id: 9, label: 'Free Co' }] });
    }
    if (request.method === 'PUT' && request.path === '/admin-api/events/1/sponsors/order') {
      const ids = (request.body as { ids: number[] }).ids;
      state.rows = ids.map((id) => state.rows.find((row) => row.id === id) as EventSponsor).map((row, index) => ({ ...row, sort_order: index + 1 }));
      return jsonResponse(200, { data: state.rows, primary_sponsor_id: state.primary });
    }
    if (request.method === 'PUT' && request.path === '/admin-api/events/1/sponsors/11') {
      const typeId = (request.body as { type_id: number | null }).type_id;
      state.rows = state.rows.map((row) => (row.id === 11 ? { ...row, type_id: typeId, type: typeId === null ? null : TIER_LABEL[typeId] } : row));
      return jsonResponse(200, { data: state.rows.find((row) => row.id === 11) });
    }
    if (request.method === 'PUT' && request.path === '/admin-api/events/1/primary-sponsor') {
      state.primary = (request.body as { sponsor_id: number | null }).sponsor_id;
      return jsonResponse(200, { data: { primary_sponsor_id: state.primary } });
    }
    return undefined;
  });
  return { ...stub, state };
}

afterEach(() => {
  useToast().clear();
});

describe('EventSponsorsDialog', () => {
  it('lists the event sponsors in order with the primary marked, and passes axe', async () => {
    stubSponsors();
    await renderAt(Host, '/admin/events');
    const dialog = await screen.findByRole('dialog', { name: 'Sponsors for Spring Show' });
    const list = await within(dialog).findByRole('list', { name: 'Sponsor order' });
    const items = within(list).getAllByRole('listitem');
    expect(items[0].textContent).toContain('Acme');
    expect(items[0].textContent).toContain('Primary');
    expect(items[1].textContent).not.toContain('Primary');
    expect(within(dialog).getByRole('button', { name: 'Clear primary: Acme' }).getAttribute('aria-pressed')).toBe('true');
    expect(await axe(dialog)).toHaveNoViolations();
  });

  it('shows an empty message when no sponsor is attached', async () => {
    stubAdminFetch((request) =>
      request.path === '/admin-api/events/1/sponsors' ? jsonResponse(200, { data: [], primary_sponsor_id: null }) : undefined,
    );
    await renderAt(Host, '/admin/events');
    expect(await screen.findByText('No sponsors are attached to this event yet.')).toBeTruthy();
  });

  it('adds a sponsor picked from the candidates', async () => {
    const { seen } = stubSponsors((r) =>
      r.method === 'POST' && r.path === '/admin-api/events/1/sponsors' ? jsonResponse(201, { data: link(13, 9, 'Free Co', 3) }) : undefined,
    );
    const user = userEvent.setup();
    await renderAt(Host, '/admin/events');
    const dialog = await screen.findByRole('dialog', { name: 'Sponsors for Spring Show' });
    await user.type(within(dialog).getByRole('combobox', { name: 'Add a sponsor' }), 'free');
    await user.click(await screen.findByRole('option', { name: 'Free Co' }));
    await user.click(within(dialog).getByRole('button', { name: 'Add sponsor' }));

    await waitFor(() => expect(seen.some((r) => r.method === 'POST' && r.path === '/admin-api/events/1/sponsors')).toBe(true));
    expect(seen.find((r) => r.method === 'POST' && r.path === '/admin-api/events/1/sponsors')?.body).toEqual({ sponsor_id: 9, type_id: null });
    expect(await screen.findByText('Sponsor “Free Co” added.')).toBeTruthy();
  });

  it('adds a sponsor with the chosen tier', async () => {
    const { seen } = stubSponsors((r) =>
      r.method === 'POST' && r.path === '/admin-api/events/1/sponsors' ? jsonResponse(201, { data: link(13, 9, 'Free Co', 3) }) : undefined,
    );
    const user = userEvent.setup();
    await renderAt(Host, '/admin/events');
    const dialog = await screen.findByRole('dialog', { name: 'Sponsors for Spring Show' });
    await user.type(within(dialog).getByRole('combobox', { name: 'Add a sponsor' }), 'free');
    await user.click(await screen.findByRole('option', { name: 'Free Co' }));
    await user.selectOptions(within(dialog).getByRole('combobox', { name: 'Tier for the new sponsor' }), '1');
    await user.click(within(dialog).getByRole('button', { name: 'Add sponsor' }));

    await waitFor(() => expect(seen.some((r) => r.method === 'POST' && r.path === '/admin-api/events/1/sponsors')).toBe(true));
    expect(seen.find((r) => r.method === 'POST' && r.path === '/admin-api/events/1/sponsors')?.body).toEqual({ sponsor_id: 9, type_id: 1 });
  });

  it('changes the tier of a sponsor and reflects it after saving', async () => {
    const { seen } = stubSponsors();
    const user = userEvent.setup();
    await renderAt(Host, '/admin/events');
    const dialog = await screen.findByRole('dialog', { name: 'Sponsors for Spring Show' });
    await user.selectOptions(await within(dialog).findByRole('combobox', { name: 'Tier for Acme' }), '2');

    await waitFor(() => expect(seen.some((r) => r.method === 'PUT' && r.path === '/admin-api/events/1/sponsors/11')).toBe(true));
    expect(seen.find((r) => r.method === 'PUT' && r.path === '/admin-api/events/1/sponsors/11')?.body).toEqual({ type_id: 2 });
    expect(await screen.findByText('Tier for “Acme” saved.')).toBeTruthy();
    await waitFor(() => expect((within(dialog).getByRole('combobox', { name: 'Tier for Acme' }) as HTMLSelectElement).value).toBe('2'));
  });

  it('clears the tier of a sponsor with "No tier"', async () => {
    const { seen } = stubSponsors();
    const user = userEvent.setup();
    await renderAt(Host, '/admin/events');
    const dialog = await screen.findByRole('dialog', { name: 'Sponsors for Spring Show' });
    await user.selectOptions(await within(dialog).findByRole('combobox', { name: 'Tier for Acme' }), '');

    await waitFor(() => expect(seen.some((r) => r.method === 'PUT' && r.path === '/admin-api/events/1/sponsors/11')).toBe(true));
    expect(seen.find((r) => r.method === 'PUT' && r.path === '/admin-api/events/1/sponsors/11')?.body).toEqual({ type_id: null });
  });

  it('reorders this event only: sends the link ids in their new order', async () => {
    const { seen } = stubSponsors();
    const user = userEvent.setup();
    await renderAt(Host, '/admin/events');
    const dialog = await screen.findByRole('dialog', { name: 'Sponsors for Spring Show' });
    await within(dialog).findByRole('list', { name: 'Sponsor order' });
    await user.click(within(dialog).getByRole('button', { name: 'Move Bolt up' }));

    await waitFor(() => expect(seen.some((r) => r.method === 'PUT' && r.path.endsWith('/sponsors/order'))).toBe(true));
    expect(seen.find((r) => r.method === 'PUT')?.body).toEqual({ ids: [12, 11] });
    expect(await screen.findByText('Sponsor order saved.')).toBeTruthy();
  });

  it('a refused reorder shows the message and reloads the saved order', async () => {
    const { seen } = stubSponsors((r) =>
      r.method === 'PUT' && r.path.endsWith('/sponsors/order')
        ? jsonResponse(422, { message: 'Invalid.', errors: { ids: ['The list must name every item of this event exactly once.'] } })
        : undefined,
    );
    const user = userEvent.setup();
    await renderAt(Host, '/admin/events');
    const dialog = await screen.findByRole('dialog', { name: 'Sponsors for Spring Show' });
    await within(dialog).findByRole('list', { name: 'Sponsor order' });
    await user.click(within(dialog).getByRole('button', { name: 'Move Bolt up' }));

    expect(await screen.findByText('Please correct the highlighted fields.')).toBeTruthy();
    await waitFor(() => expect(seen.filter((r) => r.method === 'GET' && r.path === '/admin-api/events/1/sponsors').length).toBeGreaterThan(1));
  });

  it('sets the primary sponsor and clears it again', async () => {
    const { seen } = stubSponsors();
    const user = userEvent.setup();
    await renderAt(Host, '/admin/events');
    const dialog = await screen.findByRole('dialog', { name: 'Sponsors for Spring Show' });
    await user.click(await within(dialog).findByRole('button', { name: 'Make primary: Bolt' }));

    await waitFor(() => expect(seen.some((r) => r.path.endsWith('/primary-sponsor'))).toBe(true));
    expect(seen.find((r) => r.path.endsWith('/primary-sponsor'))?.body).toEqual({ sponsor_id: 6 });
    expect(await within(dialog).findByRole('button', { name: 'Clear primary: Bolt' })).toBeTruthy();

    await user.click(within(dialog).getByRole('button', { name: 'Clear primary: Bolt' }));
    await waitFor(() => expect(seen.filter((r) => r.path.endsWith('/primary-sponsor')).at(-1)?.body).toEqual({ sponsor_id: null }));
  });

  it('removes a sponsor from the event', async () => {
    const { seen } = stubSponsors((r) => (r.method === 'DELETE' && r.path === '/admin-api/events/1/sponsors/12' ? new Response(null, { status: 204 }) : undefined));
    const user = userEvent.setup();
    await renderAt(Host, '/admin/events');
    const dialog = await screen.findByRole('dialog', { name: 'Sponsors for Spring Show' });
    await user.click(await within(dialog).findByRole('button', { name: 'Remove Bolt' }));

    await waitFor(() => expect(seen.some((r) => r.method === 'DELETE')).toBe(true));
    expect(await screen.findByText('Sponsor “Bolt” removed.')).toBeTruthy();
  });
});
