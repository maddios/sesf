import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/vue';
import { axe } from 'vitest-axe';
import HomePage from '../HomePage.vue';

describe('HomePage', () => {
  it('renders the welcome heading', () => {
    render(HomePage);
    expect(screen.getByRole('heading', { level: 1, name: /welcome to southeast sharkfest/i })).toBeTruthy();
  });

  it('has no detectable accessibility violations', async () => {
    const { container } = render(HomePage);
    const results = await axe(container);
    expect(results).toHaveNoViolations();
  });
});
