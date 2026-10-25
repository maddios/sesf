import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/vue';
import { h } from 'vue';
import AsyncBlock from '../AsyncBlock.vue';

describe('AsyncBlock', () => {
  it('shows a labelled spinner while loading', () => {
    render(AsyncBlock, { props: { status: 'loading', loadingLabel: 'Loading things' } });
    expect(screen.getByRole('status').textContent).toContain('Loading things');
  });

  it('shows the error with a retry button that emits retry', async () => {
    const onRetry = vi.fn();
    const { emitted } = render(AsyncBlock, { props: { status: 'error', errorMessage: 'Broken' } });
    expect(screen.getByText('Broken')).toBeTruthy();
    await fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
    expect(emitted().retry).toHaveLength(1);
    expect(onRetry).not.toHaveBeenCalled();
  });

  it('renders the default slot once ready', () => {
    render(AsyncBlock, { props: { status: 'ready' }, slots: { default: () => h('p', 'Body') } });
    expect(screen.getByText('Body')).toBeTruthy();
  });
});
