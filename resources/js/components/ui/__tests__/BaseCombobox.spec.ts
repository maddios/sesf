import { describe, it, expect } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { h, ref } from 'vue';
import BaseCombobox, { type ComboboxOption } from '../BaseCombobox.vue';

const SIZES: ComboboxOption[] = [
  { value: 's', label: 'Small' },
  { value: 'm', label: 'Medium' },
  { value: 'l', label: 'Large', disabled: true },
  { value: 'xl', label: 'Extra large', description: 'Runs big' },
];

function renderStatic(initial: string | null = null, extra: Record<string, unknown> = {}) {
  const value = ref<string | number | null>(initial);
  const result = render({
    setup: () => () => [
      h('label', { for: 'size' }, 'Size'),
      h(BaseCombobox, {
        id: 'size',
        options: SIZES,
        modelValue: value.value,
        'onUpdate:modelValue': (next: string | number | null) => {
          value.value = next;
        },
        ...extra,
      }),
    ],
  });
  return { ...result, value, input: screen.getByRole('combobox', { name: 'Size' }) as HTMLInputElement };
}

describe('BaseCombobox', () => {
  it('shows the chosen label and opens like a select on click, with the current option active', async () => {
    const user = userEvent.setup();
    const { input } = renderStatic('m');
    expect(input.value).toBe('Medium');
    await user.click(input);
    expect(input.getAttribute('aria-expanded')).toBe('true');
    expect(screen.getAllByRole('option')).toHaveLength(4);
    expect(input.getAttribute('aria-activedescendant')).toMatch(/-opt-1$/);
    expect(screen.getByText('Runs big')).toBeTruthy();
  });

  it('filters as you type and picks with Enter', async () => {
    const user = userEvent.setup();
    const { input, value } = renderStatic();
    await user.type(input, 'ext');
    expect(screen.getAllByRole('option').map((o) => o.textContent?.trim())).toEqual(['Extra largeRuns big']);
    await user.keyboard('{Enter}');
    expect(value.value).toBe('xl');
    expect(input.value).toBe('Extra large');
    expect(input.getAttribute('aria-expanded')).toBe('false');
  });

  it('moves with the arrows, skips disabled options, jumps with Home/End and closes on Escape', async () => {
    const user = userEvent.setup();
    const { input, value } = renderStatic();
    input.focus();
    await user.keyboard('{ArrowDown}');
    expect(input.getAttribute('aria-expanded')).toBe('true');
    expect(input.getAttribute('aria-activedescendant')).toMatch(/-opt-0$/);
    await user.keyboard('{ArrowDown}{ArrowDown}');
    expect(input.getAttribute('aria-activedescendant')).toMatch(/-opt-3$/);
    await user.keyboard('{Home}');
    expect(input.getAttribute('aria-activedescendant')).toMatch(/-opt-0$/);
    await user.keyboard('{End}');
    expect(input.getAttribute('aria-activedescendant')).toMatch(/-opt-3$/);
    await user.keyboard('{Escape}');
    expect(input.getAttribute('aria-expanded')).toBe('false');
    expect(value.value).toBeNull();
  });

  it('picks with the mouse and clears when clearable', async () => {
    const user = userEvent.setup();
    const { input, value } = renderStatic(null, { clearable: true });
    await user.click(screen.getByRole('button', { name: 'Show options' }));
    await user.click(screen.getByRole('option', { name: 'Small' }));
    expect(value.value).toBe('s');
    await user.click(screen.getByRole('button', { name: 'Clear Small' }));
    expect(value.value).toBeNull();
    expect(input.value).toBe('');
  });

  it('restores the chosen label when the user leaves with edited text', async () => {
    const user = userEvent.setup();
    const { input, value } = renderStatic('s');
    await user.clear(input);
    await user.type(input, 'zz');
    expect(await screen.findByText('No matches')).toBeTruthy();
    await user.tab();
    expect(input.value).toBe('Small');
    expect(value.value).toBe('s');
  });

  it('searches remotely, shows loading and resolves an unknown value', async () => {
    const user = userEvent.setup();
    const calls: string[] = [];
    const search = async (term: string) => {
      calls.push(term);
      return SIZES.filter((o) => o.label.toLowerCase().includes(term.toLowerCase()));
    };
    const resolve = async (v: string | number) => SIZES.find((o) => o.value === v) ?? null;
    const { input } = renderStatic('xl', { options: [], search, resolve, debounceMs: 0 });
    await waitFor(() => expect(input.value).toBe('Extra large'));
    await user.click(input);
    await waitFor(() => expect(screen.getAllByRole('option')).toHaveLength(4));
    expect(calls[0]).toBe('');
    await user.clear(input);
    await user.type(input, 'med');
    await waitFor(() => expect(screen.getAllByRole('option')).toHaveLength(1));
    expect(calls.at(-1)).toBe('med');
  });

  it('passes axe while open', async () => {
    const user = userEvent.setup();
    const { container, input } = renderStatic('m', { clearable: true });
    await user.click(input);
    expect(await axe(container)).toHaveNoViolations();
    expect(await axe(document.body, { rules: { region: { enabled: false } } })).toHaveNoViolations();
  });
});
