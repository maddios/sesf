import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/vue';
import { axe } from 'vitest-axe';
import { ApiError } from '../../api/client';
import JudgingApp from '../JudgingApp.vue';
import { fakeApi, sheet } from './fixtures';

describe('JudgingApp', () => {
  beforeEach(() => {
    vi.stubGlobal(
      'ResizeObserver',
      class {
        observe(): void {}
        unobserve(): void {}
        disconnect(): void {}
      },
    );
  });

  it('renders the scoresheet without accessibility violations', async () => {
    const { container } = render(JudgingApp, { props: { api: fakeApi() } });

    expect(await screen.findByText('Question 1')).toBeTruthy();
    expect(screen.getByRole('status').textContent).toContain('saved automatically');
    // aria-allowed-role: SurveyJS renders <legend role="presentation"> inside its rating fieldsets.
    expect(await axe(container, { rules: { region: { enabled: false }, 'aria-allowed-role': { enabled: false } } })).toHaveNoViolations();
  }, 20000);

  it('explains a load failure', async () => {
    render(JudgingApp, { props: { api: fakeApi({ load: vi.fn().mockRejectedValue(new ApiError(403, { errors: ['You must be a judge for this event.'] })) }) } });

    expect((await screen.findByRole('alert')).textContent).toContain('You must be a judge for this event.');
  });

  it('says when the judging type has no scoresheet', async () => {
    render(JudgingApp, { props: { api: fakeApi({ load: vi.fn(async () => ({ ...sheet(), form: [] })) }) } });

    expect((await screen.findByRole('alert')).textContent).toContain('no scoresheet');
  });
});
