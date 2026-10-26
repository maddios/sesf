import { describe, it, expect } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { h } from 'vue';
import RelationSelect from '../RelationSelect.vue';
import { jsonResponse } from '../../../test/helpers';
import { stubAdminFetch } from '../../../test/admin';

const EVENTS = [
  { id: 1, label: 'Spring Show (2026)' },
  { id: 2, label: 'Summer Show (2026)' },
];

function stubLookups(fail = false) {
  return stubAdminFetch(({ path, params }) => {
    if (path !== '/admin-api/lookups/events') return undefined;
    if (fail) return jsonResponse(500, { message: 'boom' });
    if (params.has('ids')) return jsonResponse(200, { data: EVENTS.filter((e) => String(e.id) === params.get('ids')) });
    const term = (params.get('search') ?? '').toLowerCase();
    return jsonResponse(200, { data: EVENTS.filter((e) => e.label.toLowerCase().includes(term)) });
  });
}

function renderSelect(props: Record<string, unknown> = {}) {
  return render({
    render: () => [
      h('label', { for: 'ev' }, 'Event'),
      h(RelationSelect, { id: 'ev', endpoint: '/admin-api/lookups/events', modelValue: null, debounceMs: 0, ...props }),
    ],
  });
}

describe('RelationSelect', () => {
  it('resolves the label of the initial value with ?ids=', async () => {
    const { seen } = stubLookups();
    renderSelect({ modelValue: 2 });
    const input = screen.getByRole('combobox', { name: 'Event' }) as HTMLInputElement;
    await waitFor(() => expect(input.value).toBe('Summer Show (2026)'));
    expect(seen.some((r) => r.params.get('ids') === '2')).toBe(true);
  });

  it('searches as you type, moves with the arrow keys and picks with Enter', async () => {
    stubLookups();
    const user = userEvent.setup();
    const { emitted } = render(RelationSelect, { props: { endpoint: '/admin-api/lookups/events', modelValue: null, debounceMs: 0, ariaLabel: 'Event' } });
    const input = screen.getByRole('combobox', { name: 'Event' });
    await user.type(input, 'show');
    await waitFor(() => expect(screen.getAllByRole('option')).toHaveLength(2));
    expect(input.getAttribute('aria-expanded')).toBe('true');
    expect(input.getAttribute('aria-activedescendant')).toMatch(/-opt-0$/);

    await user.keyboard('{ArrowDown}');
    expect(input.getAttribute('aria-activedescendant')).toMatch(/-opt-1$/);
    await user.keyboard('{ArrowUp}{ArrowUp}');
    expect(input.getAttribute('aria-activedescendant')).toMatch(/-opt-1$/);
    await user.keyboard('{Enter}');

    expect(emitted()['update:modelValue']).toEqual([[2]]);
    expect(emitted().change).toEqual([[EVENTS[1]]]);
    expect((input as HTMLInputElement).value).toBe('Summer Show (2026)');
    expect(input.getAttribute('aria-expanded')).toBe('false');
  });

  it('picks with the mouse, clears, and closes on Escape', async () => {
    stubLookups();
    const user = userEvent.setup();
    renderSelect();
    const input = screen.getByRole('combobox', { name: 'Event' }) as HTMLInputElement;
    await user.type(input, 'spring');
    await user.click(await screen.findByRole('option', { name: 'Spring Show (2026)' }));
    expect(input.value).toBe('Spring Show (2026)');

    await user.click(screen.getByRole('button', { name: 'Clear Spring Show (2026)' }));
    expect(input.value).toBe('');

    await user.type(input, 'sum');
    await screen.findByRole('option', { name: 'Summer Show (2026)' });
    await user.keyboard('{Escape}');
    expect(input.getAttribute('aria-expanded')).toBe('false');
    await user.keyboard('{Escape}');
    expect(input.value).toBe('');
  });

  it('opens with ArrowDown, says when nothing matches and passes axe', async () => {
    stubLookups();
    const user = userEvent.setup();
    const { container } = renderSelect();
    const input = screen.getByRole('combobox', { name: 'Event' });
    await user.type(input, 'zzz');
    expect(await screen.findByText('No matches')).toBeTruthy();
    await user.keyboard('{Escape}');
    await user.keyboard('{ArrowDown}');
    await waitFor(() => expect(input.getAttribute('aria-expanded')).toBe('true'));
    expect(await axe(container)).toHaveNoViolations();
  });

  it('shows a load error inside the list', async () => {
    stubLookups(true);
    const user = userEvent.setup();
    renderSelect({ modelValue: 5 });
    const input = screen.getByRole('combobox', { name: 'Event' }) as HTMLInputElement;
    await waitFor(() => expect(input.value).toBe('#5'));
    await user.clear(input);
    await user.type(input, 'x');
    expect(await screen.findByText('Options could not be loaded.')).toBeTruthy();
  });
});
