import { describe, it, expect } from 'vitest';
import { render } from '@testing-library/vue';
import { axe } from 'vitest-axe';
import SafeHtml from '../SafeHtml.vue';

describe('SafeHtml', () => {
  it('renders server-sanitized markup as elements', () => {
    const { container } = render(SafeHtml, { props: { html: '<p>Doors <strong>open</strong></p>' } });
    expect(container.querySelector('p strong')?.textContent).toBe('open');
  });

  it('renders an empty wrapper for empty html', () => {
    const { container } = render(SafeHtml, { props: { html: '' } });
    expect(container.querySelector('.event-html')?.textContent).toBe('');
  });

  it('has no accessibility violations', async () => {
    const { container } = render(SafeHtml, { props: { html: '<h3>Note</h3><p>Text</p>' } });
    expect(await axe(container)).toHaveNoViolations();
  });
});
