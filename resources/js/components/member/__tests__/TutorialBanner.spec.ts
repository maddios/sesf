import { describe, it, expect, afterEach, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { createPinia, setActivePinia, type Pinia } from 'pinia';
import TutorialBanner from '../TutorialBanner.vue';
import { SESSION_PAYLOAD, jsonResponse } from '../../../test/helpers';
import { stubAdminFetch, type SeenRequest } from '../../../test/admin';
import { toAuthUser, useAuthStore } from '../../../stores/auth';

let pinia: Pinia;

function stub(state: unknown, onPost: () => Response = () => jsonResponse(200, '')) {
  return stubAdminFetch((request: SeenRequest) => {
    if (request.path !== '/tutorial/tutorial') return undefined;
    return request.method === 'GET' ? jsonResponse(200, state) : onPost();
  }, SESSION_PAYLOAD);
}

function signIn(): void {
  useAuthStore(pinia).setUser(toAuthUser(SESSION_PAYLOAD));
}

beforeEach(() => {
  pinia = createPinia();
  setActivePinia(pinia);
});

afterEach(() => {
  document.body.innerHTML = '';
});

describe('TutorialBanner', () => {
  it('shows the legacy welcome copy with profile and garage links on first login', async () => {
    stub({ show_login_welcome: true, id: 3 });
    signIn();
    const { container } = render(TutorialBanner, { global: { plugins: [pinia] } });

    expect(await screen.findByText('Hi Shark, Welcome to the new home of the SESF!')).toBeTruthy();
    expect(screen.getByRole('link', { name: 'member profile' }).getAttribute('href')).toBe('/fan/u7');
    expect(screen.getByRole('link', { name: 'garage' }).getAttribute('href')).toBe('/fan/u7/cars');
    expect(screen.getByText(/configure all personal settings and upload a\s+profile picture/)).toBeTruthy();
    expect(screen.getByText(/clicking the x on the top right corner of this yellow box!/)).toBeTruthy();
    expect(await axe(container)).toHaveNoViolations();
  });

  it('stays hidden once dismissed, for anonymous visitors and when the API fails', async () => {
    const { seen } = stub({ show_login_welcome: false, id: 3 });
    render(TutorialBanner, { global: { plugins: [pinia] } });
    expect(seen.some((r) => r.path === '/tutorial/tutorial')).toBe(false);

    signIn();
    await waitFor(() => expect(seen.some((r) => r.path === '/tutorial/tutorial')).toBe(true));
    expect(screen.queryByRole('region', { name: 'Welcome' })).toBeNull();
  });

  it('closes on the x and saves show_login_welcome = 0', async () => {
    const { seen } = stub({ show_login_welcome: true, id: 3 });
    signIn();
    const user = userEvent.setup();
    render(TutorialBanner, { global: { plugins: [pinia] } });

    await user.click(await screen.findByRole('button', { name: 'Close the welcome tutorial' }));

    expect(screen.queryByRole('region', { name: 'Welcome' })).toBeNull();
    await waitFor(() => expect(seen.filter((r) => r.method === 'POST' && r.path === '/tutorial/tutorial').map((r) => r.body)).toEqual([{ show_login_welcome: 0 }]));
  });

  it('comes back when saving the dismissal fails', async () => {
    stub({ show_login_welcome: true, id: 3 }, () => jsonResponse(500, {}));
    signIn();
    const user = userEvent.setup();
    render(TutorialBanner, { global: { plugins: [pinia] } });

    await user.click(await screen.findByRole('button', { name: 'Close the welcome tutorial' }));

    expect(await screen.findByRole('region', { name: 'Welcome' })).toBeTruthy();
  });

  it('hides when the member signs out', async () => {
    stub({ show_login_welcome: true, id: 3 });
    signIn();
    render(TutorialBanner, { global: { plugins: [pinia] } });
    await screen.findByRole('region', { name: 'Welcome' });

    useAuthStore(pinia).clear();

    await waitFor(() => expect(screen.queryByRole('region', { name: 'Welcome' })).toBeNull());
  });
});
