import { describe, it, expect, afterEach } from 'vitest';
import { screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { defineComponent, h } from 'vue';
import JudgingPage from '../JudgingPage.vue';
import AdminConfirmHost from '../../../../components/admin/AdminConfirmHost.vue';
import AdminToastRegion from '../../../../components/admin/AdminToastRegion.vue';
import { renderAt, jsonResponse } from '../../../../test/helpers';
import { ADMIN_SESSION, stubAdminFetch, type SeenRequest } from '../../../../test/admin';
import { useToast } from '../../../../composables/admin/useToast';
import { useConfirm } from '../../../../composables/admin/useConfirm';
import type { JudgingEntry, JudgingShow, UnjudgedRegistration } from '../judging';

const SHOW: JudgingShow = { id: 4, name: 'Spring Show', date_end: '2026-05-01', is_judging_released: false };

const ENTRY: JudgingEntry = {
  id: 31,
  car_id: 31,
  registration_id: 7,
  invoice_id: 101,
  year: '1966',
  body: 'Coupe',
  model: 'Mustang',
  judging_type_id: 2,
  judging_type: 'Super Clean',
  owner_name: 'Ada Lovelace',
  owner_email: 'ada@example.test',
  num_questions: 10,
  num_answered: 8,
  score: null,
  judging_url: '/judging/31',
  answers_url: '/judging/answers/31',
  qr_url: '/judging/qr/31',
};

const UNJUDGED: UnjudgedRegistration[] = [
  { registration_id: 7, invoice_id: 55, name: 'Grace Hopper', cars: [{ car_id: 9, body_id: 2, name: '1966 Mustang' }] },
];

const Page = defineComponent({ render: () => [h(JudgingPage), h(AdminConfirmHost), h(AdminToastRegion)] });

const noContent = () => new Response(null, { status: 204 });

type Handler = (request: SeenRequest) => Response | undefined;

function stubJudging(extra: Handler = () => undefined, session = ADMIN_SESSION) {
  return stubAdminFetch((request) => {
    const handled = extra(request);
    if (handled !== undefined) return handled;
    const { method, path } = request;
    if (method === 'GET' && path === '/admin-api/judging/shows') return jsonResponse(200, { data: [SHOW] });
    if (method === 'GET' && path === '/admin-api/judging/shows/4/entries') return jsonResponse(200, { data: [ENTRY] });
    if (method === 'GET' && path === '/admin-api/judging/shows/4/unjudged') return jsonResponse(200, { data: UNJUDGED });
    if (method === 'GET' && path === '/admin-api/judging/shows/4/judges') return jsonResponse(200, { data: [] });
    if (method === 'GET' && path === '/admin-api/judging/shows/4/judges/eligible') return jsonResponse(200, { data: [] });
    return undefined;
  }, session);
}

afterEach(() => {
  useToast().clear();
  useConfirm().settle(false);
  document.body.style.overflow = '';
});

describe('JudgingPage', () => {
  it('loads the events and the entries of the selected event, and passes axe', async () => {
    const { seen } = stubJudging();
    const { container } = await renderAt(Page, '/admin/judging?show=4');
    expect(await screen.findByText('Ada Lovelace')).toBeTruthy();
    expect((screen.getByLabelText('Event') as HTMLSelectElement).value).toBe('4');
    expect(screen.getByRole('link', { name: 'Draw winners' }).getAttribute('href')).toBe('/judging_draw/4');
    expect(screen.getByRole('link', { name: 'QR sheet (PDF)' }).getAttribute('href')).toBe('/judging_qr_code/show/4');
    expect(screen.getByRole('link', { name: 'Questions for car 101 (opens in a new tab)' }).getAttribute('href')).toBe('/judging/31');
    expect(screen.getByText('(2 missing)')).toBeTruthy();
    expect(seen.find((r) => r.path.endsWith('/entries'))!.params.get('judging_type_id')).toBeNull();
    expect(await axe(container)).toHaveNoViolations();
  });

  it('sends the judging type filter to the entries request', async () => {
    const { seen } = stubJudging();
    const user = userEvent.setup();
    await renderAt(Page, '/admin/judging?show=4');
    await screen.findByText('Ada Lovelace');
    await user.selectOptions(screen.getByLabelText('Filter by type'), 'Clean');
    await waitFor(() => {
      const last = seen.filter((r) => r.path.endsWith('/entries')).at(-1)!;
      expect(last.params.get('judging_type_id')).toBe('1');
    });
  });

  it('removes an entry with DELETE after confirmation', async () => {
    const { seen } = stubJudging((request) =>
      request.method === 'DELETE' && request.path === '/admin-api/judging/shows/4/entries/31' ? noContent() : undefined,
    );
    const user = userEvent.setup();
    await renderAt(Page, '/admin/judging?show=4');
    await user.click(await screen.findByRole('button', { name: 'Remove car 101 from judging' }));
    const dialog = await screen.findByRole('dialog', { name: 'Remove from judging' });
    await user.click(within(dialog).getByRole('button', { name: 'Remove' }));
    expect(await screen.findByText('Car 101 removed from judging.')).toBeTruthy();
    expect(seen.some((r) => r.method === 'DELETE' && r.path === '/admin-api/judging/shows/4/entries/31')).toBe(true);
  });

  it('adds a car with the chosen registration, car and type, and maps a 422 message', async () => {
    const { seen } = stubJudging((request) => {
      if (request.path === '/admin-api/judging/shows/4/types') return jsonResponse(200, { data: [{ id: 2, name: 'Super Clean' }] });
      if (request.method === 'POST' && request.path === '/admin-api/judging/shows/4/entries') {
        return jsonResponse(422, {
          message: 'This judging type is not offered for this car.',
          errors: { judging_type_id: ['Not offered for this body.'] },
        });
      }
      return undefined;
    });
    const user = userEvent.setup();
    await renderAt(Page, '/admin/judging?show=4');
    await screen.findByText('Ada Lovelace');
    await user.selectOptions(screen.getByLabelText('Registration'), '7');
    await user.selectOptions(screen.getByLabelText('Car'), '9');
    await user.selectOptions(await screen.findByLabelText('Judging type'), '2');
    await user.click(screen.getByRole('button', { name: 'Add to judging' }));
    expect(await screen.findByText('Please correct the highlighted fields.')).toBeTruthy();
    expect(screen.getByText('Not offered for this body.')).toBeTruthy();
    expect(seen.find((r) => r.method === 'POST' && r.path === '/admin-api/judging/shows/4/entries')!.body).toEqual({ registration_id: 7, car_id: 9, judging_type_id: 2 });
    expect(seen.find((r) => r.path === '/admin-api/judging/shows/4/types')!.params.get('body_id')).toBe('2');
  });

  it('shows the event judges and results sections to admins only', async () => {
    const judgeSession = { ...ADMIN_SESSION, user_level: 2, type_id: 2 };
    const { seen } = stubJudging(undefined, judgeSession);
    await renderAt(Page, '/admin/judging?show=4');
    await screen.findByText('Ada Lovelace');
    expect(screen.queryByRole('heading', { name: 'Event judges' })).toBeNull();
    expect(screen.queryByRole('heading', { name: 'Results' })).toBeNull();
    expect(seen.some((r) => r.path.includes('/judges'))).toBe(false);
  });

  it('shows the admin sections to admins', async () => {
    stubJudging();
    await renderAt(Page, '/admin/judging?show=4');
    expect(await screen.findByRole('heading', { name: 'Event judges' })).toBeTruthy();
    expect(screen.getByRole('heading', { name: 'Results' })).toBeTruthy();
  });

  it('releases results with PUT and shows the 409 message when emailing before release', async () => {
    const { seen } = stubJudging((request) => {
      if (request.method === 'PUT' && request.path === '/admin-api/judging/shows/4/release') {
        return jsonResponse(200, { data: { id: 4, is_judging_released: true } });
      }
      if (request.method === 'POST' && request.path === '/admin-api/judging/shows/4/results-mail') {
        return jsonResponse(409, { message: 'Results must be released before they are emailed.' });
      }
      return undefined;
    });
    const user = userEvent.setup();
    await renderAt(Page, '/admin/judging?show=4');
    await screen.findByText('Ada Lovelace');
    await user.click(screen.getByRole('checkbox', { name: 'Results released' }));
    await waitFor(() => expect(seen.some((r) => r.method === 'PUT')).toBe(true));
    expect(seen.find((r) => r.method === 'PUT')!.body).toEqual({ released: true });
    await user.click(screen.getByRole('button', { name: 'Send results emails' }));
    const dialog = await screen.findByRole('dialog', { name: 'Send results emails' });
    await user.click(within(dialog).getByRole('button', { name: 'Send emails' }));
    expect(await screen.findByRole('alert')).toBeTruthy();
    expect(screen.getByText('Results must be released before they are emailed.')).toBeTruthy();
  });
});
