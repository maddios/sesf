import { describe, it, expect, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import ImpersonationBanner from '../ImpersonationBanner.vue';
import { jsonResponse } from '../../../test/helpers';
import { stubAdminFetch, type SeenRequest } from '../../../test/admin';

function stub(impersonating: boolean, extra: (request: SeenRequest) => Response | undefined = () => undefined) {
  return stubAdminFetch((request) => {
    const handled = extra(request);
    if (handled !== undefined) return handled;
    if (request.path === '/admin-api/impersonation') {
      return jsonResponse(200, { data: { impersonating, impersonator_name: impersonating ? 'Ada Admin' : null } });
    }
    return undefined;
  });
}

afterEach(() => {
  document.body.innerHTML = '';
});

describe('ImpersonationBanner', () => {
  it('stays hidden when the session is not impersonating', async () => {
    stub(false);
    render(ImpersonationBanner);
    await waitFor(() => expect(screen.queryByRole('status')).toBeNull());
  });

  it('says whose session it is and stops the impersonation', async () => {
    const { seen } = stub(true, (request) =>
      request.method === 'POST' && request.path === '/admin-api/impersonation/stop' ? jsonResponse(200, { data: { user_id: 1 } }) : undefined,
    );
    const user = userEvent.setup();
    render(ImpersonationBanner);
    expect(await screen.findByRole('status')).toBeTruthy();
    expect(screen.getByText(/Ada Admin/)).toBeTruthy();
    await user.click(screen.getByRole('button', { name: 'Return to my account' }));
    await waitFor(() => expect(seen.some((r) => r.method === 'POST' && r.path === '/admin-api/impersonation/stop')).toBe(true));
  });
});
