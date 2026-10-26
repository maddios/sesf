import { describe, it, expect, afterEach, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { defineComponent, h } from 'vue';
import SponsorsPage from '../SponsorsPage.vue';
import AdminConfirmHost from '../../../../components/admin/AdminConfirmHost.vue';
import AdminToastRegion from '../../../../components/admin/AdminToastRegion.vue';
import { renderAt, jsonResponse } from '../../../../test/helpers';
import { listBody, stubAdminFetch, stubXhr, type SeenRequest } from '../../../../test/admin';
import { useToast } from '../../../../composables/admin/useToast';
import { useConfirm } from '../../../../composables/admin/useConfirm';
import { imageSrc, toInput, type Sponsor } from '../sponsors';

function sponsor(overrides: Partial<Sponsor> = {}): Sponsor {
  return {
    id: 1,
    name: 'Acme Tools',
    URL: 'https://acme.example',
    description: 'Tools',
    short_description: null,
    internal_notes: 'Call Bob',
    is_enabled: true,
    sort_order: 0,
    image_url: 'images/sponsor/1.jpg?v=1',
    has_image: true,
    shows_count: 0,
    primary_count: 0,
    in_use: false,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

/** The page plus the shared dialog/toast hosts that AdminLayout normally provides. */
const Page = defineComponent({ render: () => [h(SponsorsPage), h(AdminConfirmHost), h(AdminToastRegion)] });

type Extra = (request: SeenRequest) => Response | undefined;

function stubSponsors(rows: Sponsor[] = [sponsor(), sponsor({ id: 2, name: 'Bolt Co', URL: null, is_enabled: false, has_image: false })], extra: Extra = () => undefined) {
  return stubAdminFetch((request) => {
    const handled = extra(request);
    if (handled !== undefined) return handled;
    if (request.method === 'GET' && request.path === '/admin-api/sponsors') return jsonResponse(200, listBody(rows));
    if (request.path === '/admin-api/lookups/events') return jsonResponse(200, { data: [{ id: 4, label: 'Spring (2026)' }] });
    return undefined;
  });
}

function table() {
  return screen.getByRole('table', { name: 'Sponsors' });
}

afterEach(() => {
  useToast().clear();
  useConfirm().settle(false);
  document.body.style.overflow = '';
});

describe('SponsorsPage', () => {
  it('lists sponsors with logo, status and actions, and passes axe', async () => {
    const { seen } = stubSponsors();
    const { container } = await renderAt(Page, '/admin/sponsors');
    expect(await within(table()).findByText('Acme Tools')).toBeTruthy();
    expect(within(table()).getByRole('img', { name: 'Acme Tools logo' }).getAttribute('src')).toBe('/images/sponsor/1.jpg?v=1');
    expect(within(table()).getByText('Disabled')).toBeTruthy();
    expect(within(table()).getByRole('link', { name: 'https://acme.example' }).getAttribute('rel')).toContain('noopener');
    expect(within(table()).getByRole('button', { name: 'Edit Acme Tools' })).toBeTruthy();
    const list = seen.find((r) => r.path === '/admin-api/sponsors')!;
    expect(list.params.get('sort')).toBe('name');
    expect(list.params.get('per_page')).toBe('25');
    expect(await axe(container)).toHaveNoViolations();
  });

  it('syncs search and the status filter with the URL and refetches', async () => {
    const { seen } = stubSponsors();
    const user = userEvent.setup();
    const { router } = await renderAt(Page, '/admin/sponsors?f.is_enabled=0');
    await within(table()).findByText('Acme Tools');
    expect(seen.find((r) => r.path === '/admin-api/sponsors')?.params.get('filter[is_enabled]')).toBe('0');
    expect((screen.getByLabelText('Status') as HTMLSelectElement).value).toBe('0');

    await user.selectOptions(screen.getByLabelText('Status'), '1');
    expect(router.currentRoute.value.query['f.is_enabled']).toBe('1');
    await user.type(screen.getByRole('searchbox', { name: 'Search sponsors' }), 'acme{Enter}');
    await waitFor(() => expect(router.currentRoute.value.query.search).toBe('acme'));
    await waitFor(() => expect(seen.filter((r) => r.path === '/admin-api/sponsors').at(-1)?.params.get('search')).toBe('acme'));

    await user.click(within(table()).getByRole('button', { name: /Website/ }));
    expect(router.currentRoute.value.query.sort).toBe('URL');
  });

  it('filters by event through the RelationSelect', async () => {
    const { seen } = stubSponsors();
    const user = userEvent.setup();
    const { router } = await renderAt(Page, '/admin/sponsors');
    await within(table()).findByText('Acme Tools');
    await user.type(screen.getByRole('combobox', { name: 'Event' }), 'spr');
    await user.click(await screen.findByRole('option', { name: 'Spring (2026)' }));
    await waitFor(() => expect(router.currentRoute.value.query['f.show_id']).toBe('4'));
    await waitFor(() => expect(seen.filter((r) => r.path === '/admin-api/sponsors').at(-1)?.params.get('filter[show_id]')).toBe('4'));
  });

  it('creates a sponsor through the modal and shows a toast', async () => {
    const { seen } = stubSponsors(undefined, (r) =>
      r.method === 'POST' && r.path === '/admin-api/sponsors' ? jsonResponse(201, { data: sponsor({ id: 3, name: 'New Co' }) }) : undefined,
    );
    const user = userEvent.setup();
    await renderAt(Page, '/admin/sponsors');
    await user.click(await screen.findByRole('button', { name: 'New sponsor' }));
    const dialog = await screen.findByRole('dialog', { name: 'New sponsor' });
    await user.type(within(dialog).getByLabelText('Name (required)'), ' New Co ');
    await user.type(within(dialog).getByLabelText('Website'), 'https://new.example');
    await user.type(within(dialog).getByLabelText('Internal notes'), 'secret');
    await user.click(within(dialog).getByRole('button', { name: 'Create sponsor' }));

    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(seen.find((r) => r.method === 'POST' && r.path === '/admin-api/sponsors')?.body).toEqual({
      name: 'New Co',
      URL: 'https://new.example',
      short_description: null,
      description: null,
      internal_notes: 'secret',
      is_enabled: true,
    });
    expect(screen.getByRole('status', { name: 'Notifications' }).textContent).toContain('Sponsor “New Co” created.');
  });

  it('edits a sponsor and shows server validation errors on the fields', async () => {
    let attempts = 0;
    const { seen } = stubSponsors(undefined, (r) => {
      if (r.method !== 'PUT' || r.path !== '/admin-api/sponsors/1') return undefined;
      attempts += 1;
      return attempts === 1
        ? jsonResponse(422, { message: 'Invalid.', errors: { URL: ['The URL field must be a valid URL.'] } })
        : jsonResponse(200, { data: sponsor({ name: 'Acme 2' }) });
    });
    const user = userEvent.setup();
    await renderAt(Page, '/admin/sponsors');
    await user.click(await within(table()).findByRole('button', { name: 'Edit Acme Tools' }));
    const dialog = await screen.findByRole('dialog', { name: 'Edit Acme Tools' });
    expect((within(dialog).getByLabelText('Internal notes') as HTMLTextAreaElement).value).toBe('Call Bob');
    await user.click(within(dialog).getByRole('button', { name: 'Save changes' }));

    const url = await within(dialog).findByLabelText('Website');
    expect(url.getAttribute('aria-invalid')).toBe('true');
    expect(within(dialog).getByText('The URL field must be a valid URL.')).toBeTruthy();

    await user.click(within(dialog).getByRole('button', { name: 'Save changes' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(seen.filter((r) => r.method === 'PUT')).toHaveLength(2);
    expect(screen.getByRole('status', { name: 'Notifications' }).textContent).toContain('saved');
  });

  it('enables and disables a sponsor', async () => {
    const { seen } = stubSponsors(undefined, (r) => {
      if (r.method === 'POST' && r.path === '/admin-api/sponsors/1/disable') return jsonResponse(200, { data: sponsor({ is_enabled: false }) });
      if (r.method === 'POST' && r.path === '/admin-api/sponsors/2/enable') return jsonResponse(429, { message: 'Too Many Attempts.' });
      return undefined;
    });
    const user = userEvent.setup();
    await renderAt(Page, '/admin/sponsors');
    await user.click(await within(table()).findByRole('button', { name: 'Disable Acme Tools' }));
    await waitFor(() => expect(seen.some((r) => r.path === '/admin-api/sponsors/1/disable')).toBe(true));
    expect(await screen.findByText('Sponsor “Acme Tools” disabled.')).toBeTruthy();

    await user.click(within(table()).getByRole('button', { name: 'Enable Bolt Co' }));
    expect(await screen.findByText(/Wait a minute/)).toBeTruthy();
  });

  it('deletes only after the typed confirmation', async () => {
    const { seen } = stubSponsors(undefined, (r) => (r.method === 'DELETE' && r.path === '/admin-api/sponsors/2' ? new Response(null, { status: 204 }) : undefined));
    const user = userEvent.setup();
    await renderAt(Page, '/admin/sponsors');
    await user.click(await within(table()).findByRole('button', { name: 'Delete Bolt Co' }));
    const dialog = await screen.findByRole('dialog', { name: 'Delete sponsor' });
    const confirm = within(dialog).getByRole('button', { name: 'Delete' }) as HTMLButtonElement;
    expect(confirm.disabled).toBe(true);
    await user.type(within(dialog).getByLabelText(/Type DELETE/), 'DELETE');
    await user.click(confirm);

    await waitFor(() => expect(seen.some((r) => r.method === 'DELETE')).toBe(true));
    expect(await screen.findByText('Sponsor “Bolt Co” deleted.')).toBeTruthy();
  });

  it('cancelling the delete sends nothing', async () => {
    const { seen } = stubSponsors();
    const user = userEvent.setup();
    await renderAt(Page, '/admin/sponsors');
    await user.click(await within(table()).findByRole('button', { name: 'Delete Acme Tools' }));
    await user.click(await screen.findByRole('button', { name: 'Cancel' }));
    expect(seen.some((r) => r.method === 'DELETE')).toBe(false);
  });

  it('offers to disable a sponsor that is in use (409) instead of deleting it', async () => {
    const { seen } = stubSponsors(undefined, (r) => {
      if (r.method === 'DELETE') return jsonResponse(409, { message: 'This sponsor is used by 2 show link(s) and cannot be deleted. Disable it instead.', references: { shows: 2, primary: 0 } });
      if (r.method === 'POST' && r.path === '/admin-api/sponsors/1/disable') return jsonResponse(200, { data: sponsor({ is_enabled: false }) });
      return undefined;
    });
    const user = userEvent.setup();
    await renderAt(Page, '/admin/sponsors');
    await user.click(await within(table()).findByRole('button', { name: 'Delete Acme Tools' }));
    await user.type(await screen.findByLabelText(/Type DELETE/), 'DELETE');
    await user.click(screen.getByRole('button', { name: 'Delete' }));

    const inUse = await screen.findByRole('dialog', { name: 'Sponsor in use' });
    expect(inUse.textContent).toContain('used by 2 show link(s)');
    await user.click(within(inUse).getByRole('button', { name: 'Disable sponsor' }));
    await waitFor(() => expect(seen.some((r) => r.path === '/admin-api/sponsors/1/disable')).toBe(true));
  });

  it('reports a 409 for an already disabled sponsor as an error toast', async () => {
    stubSponsors(undefined, (r) => (r.method === 'DELETE' ? jsonResponse(409, { message: 'In use. Disable it instead.' }) : undefined));
    const user = userEvent.setup();
    await renderAt(Page, '/admin/sponsors');
    await user.click(await within(table()).findByRole('button', { name: 'Delete Bolt Co' }));
    await user.type(await screen.findByLabelText(/Type DELETE/), 'DELETE');
    await user.click(screen.getByRole('button', { name: 'Delete' }));
    expect(await screen.findByText('In use. Disable it instead.')).toBeTruthy();
  });

  it('uploads a logo through the image modal', async () => {
    stubSponsors();
    const xhrs = stubXhr();
    const user = userEvent.setup();
    await renderAt(Page, '/admin/sponsors');
    await user.click(await within(table()).findByRole('button', { name: 'Upload logo for Acme Tools' }));
    const dialog = await screen.findByRole('dialog', { name: 'Logo for Acme Tools' });
    await user.upload(within(dialog).getByLabelText('Choose an image'), new File(['x'], 'l.png', { type: 'image/png' }));
    await user.click(within(dialog).getByRole('button', { name: 'Upload' }));
    expect(xhrs[0]?.url).toBe('/admin-api/sponsors/1/image');
    xhrs[0]!.respond(200, { data: sponsor() });
    expect(await screen.findByText('Logo for “Acme Tools” uploaded.')).toBeTruthy();
  });

  it('shows the list error with a retry', async () => {
    let calls = 0;
    stubAdminFetch((r) => {
      if (r.path !== '/admin-api/sponsors') return undefined;
      calls += 1;
      return calls === 1 ? jsonResponse(500, {}) : jsonResponse(200, listBody([sponsor()]));
    });
    const user = userEvent.setup();
    await renderAt(Page, '/admin/sponsors');
    expect((await screen.findByRole('alert')).textContent).toContain('The list could not be loaded.');
    await user.click(screen.getByRole('button', { name: 'Try again' }));
    expect(await within(table()).findByText('Acme Tools')).toBeTruthy();
  });
});

describe('sponsor helpers', () => {
  it('builds the input and image URLs', () => {
    expect(toInput({ name: 5, URL: '  ', is_enabled: 'yes' })).toMatchObject({ name: '', URL: null, is_enabled: false });
    expect(imageSrc(sponsor({ image_url: '/abs.jpg' }))).toBe('/abs.jpg');
    expect(imageSrc(sponsor({ image_url: 'https://cdn.example/a.jpg' }))).toBe('https://cdn.example/a.jpg');
    expect(imageSrc(sponsor({ has_image: false }))).toBeNull();
  });
});

void vi;
