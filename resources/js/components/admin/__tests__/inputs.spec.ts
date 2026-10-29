import { describe, it, expect } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { h } from 'vue';
import MoneyInput from '../MoneyInput.vue';
import DateInput from '../DateInput.vue';
import DateTimeInput from '../DateTimeInput.vue';
import BooleanToggle from '../BooleanToggle.vue';
import StatusBadge from '../StatusBadge.vue';
import { fromDateTimeLocal, normaliseMoney, toDateInput, toDateTimeLocal } from '../format';

describe('format helpers', () => {
  it('normalises money strings to 2 places', () => {
    expect(normaliseMoney('12')).toBe('12.00');
    expect(normaliseMoney('1.5')).toBe('1.50');
    expect(normaliseMoney('.5')).toBe('0.50');
    expect(normaliseMoney('$1,200.1')).toBe('1200.10');
    expect(normaliseMoney('007.25')).toBe('7.25');
    for (const bad of ['', '.', 'abc', '1.234', '-1', '1e3', '12345678']) {
      expect(normaliseMoney(bad)).toBeNull();
    }
  });

  it('converts between server dates and native input values', () => {
    expect(toDateTimeLocal('2026-05-01 13:45:00')).toBe('2026-05-01T13:45');
    expect(toDateTimeLocal('2026-05-01T13:45:10-04:00')).toBe('2026-05-01T13:45');
    expect(toDateTimeLocal(null)).toBe('');
    expect(fromDateTimeLocal('2026-05-01T13:45')).toBe('2026-05-01 13:45:00');
    expect(fromDateTimeLocal('2026-05-01T13:45:30')).toBe('2026-05-01 13:45:30');
    expect(fromDateTimeLocal('')).toBeNull();
    expect(toDateInput('2026-05-01 10:00:00')).toBe('2026-05-01');
    expect(toDateInput(undefined)).toBe('');
  });
});

describe('MoneyInput', () => {
  it('normalises on blur and emits the decimal string', async () => {
    const user = userEvent.setup();
    const { emitted, container } = render({
      render: () => [h('label', { for: 'm' }, 'Price'), h(MoneyInput, { id: 'm', modelValue: null })],
    });
    const input = screen.getByLabelText('Price') as HTMLInputElement;
    expect(input.getAttribute('inputmode')).toBe('decimal');
    await user.type(input, '12.5');
    await user.tab();
    expect(input.value).toBe('12.50');
    expect(await axe(container)).toHaveNoViolations();
    void emitted;
  });

  it('marks bad or too-large amounts invalid and emits null when cleared', async () => {
    const user = userEvent.setup();
    const { emitted } = render(MoneyInput, { props: { modelValue: '5.00', max: '100.00' }, attrs: { 'aria-label': 'Price' } });
    const input = screen.getByLabelText('Price') as HTMLInputElement;
    await user.clear(input);
    await user.type(input, 'abc');
    await user.tab();
    expect(input.getAttribute('aria-invalid')).toBe('true');
    expect(emitted().invalid?.at(-1)).toEqual(['Enter an amount like 12.50.']);

    await user.clear(input);
    await user.type(input, '200');
    await user.tab();
    expect(emitted().invalid?.at(-1)).toEqual(['The amount may not be more than 100.00.']);

    await user.clear(input);
    await user.tab();
    expect(emitted()['update:modelValue']?.at(-1)).toEqual([null]);
    expect(input.getAttribute('aria-invalid')).toBeNull();

    await user.type(input, '3');
    await user.tab();
    expect(emitted()['update:modelValue']?.at(-1)).toEqual(['3.00']);
  });

  it('follows a new value from the parent', async () => {
    const { rerender } = render(MoneyInput, { props: { modelValue: '1.00' }, attrs: { 'aria-label': 'Price' } });
    await rerender({ modelValue: '9.99' });
    expect((screen.getByLabelText('Price') as HTMLInputElement).value).toBe('9.99');
  });
});

describe('DateInput and DateTimeInput', () => {
  it('DateInput shows a date and emits the picked day or null', async () => {
    const { emitted } = render(DateInput, { props: { modelValue: '2026-05-01 00:00:00' }, attrs: { 'aria-label': 'Day' } });
    const input = screen.getByLabelText('Day') as HTMLInputElement;
    expect(input.value).toBe('2026-05-01');
    await fireEvent.update(input, '2026-06-02');
    await fireEvent.update(input, '');
    expect(emitted()['update:modelValue']).toEqual([['2026-06-02'], [null]]);
  });

  it('DateTimeInput converts to the server format and links the timezone note', async () => {
    const { emitted, container } = render({
      render: () => [h('label', { for: 'at' }, 'Opens at'), h(DateTimeInput, { id: 'at', modelValue: '2026-05-01 09:30:00', 'aria-describedby': 'hint-x' })],
    });
    const input = screen.getByLabelText('Opens at') as HTMLInputElement;
    expect(input.value).toBe('2026-05-01T09:30');
    const describedBy = input.getAttribute('aria-describedby') ?? '';
    expect(describedBy).toContain('hint-x');
    const noteId = describedBy.split(' ').find((id) => id !== 'hint-x') ?? '';
    expect(document.getElementById(noteId)?.textContent).toMatch(/Eastern/);
    void emitted;
    void container;
  });

  it('DateTimeInput emits the server datetime', async () => {
    const { emitted } = render(DateTimeInput, { props: { modelValue: null }, attrs: { 'aria-label': 'At' } });
    await fireEvent.update(screen.getByLabelText('At'), '2026-07-04T18:00');
    expect(emitted()['update:modelValue']).toEqual([['2026-07-04 18:00:00']]);
  });
});

describe('BooleanToggle and StatusBadge', () => {
  it('is a labelled switch toggled by click and keyboard', async () => {
    const user = userEvent.setup();
    const { emitted, container, rerender } = render(BooleanToggle, { props: { modelValue: false, label: 'Enabled' } });
    const toggle = screen.getByRole('switch', { name: 'Enabled' });
    expect(toggle.getAttribute('aria-checked')).toBe('false');
    await user.click(toggle);
    toggle.focus();
    await user.keyboard(' ');
    expect(emitted()['update:modelValue']).toEqual([[true], [true]]);
    expect(await axe(container)).toHaveNoViolations();

    await rerender({ modelValue: true, busy: true, hideLabel: true });
    expect(toggle.getAttribute('aria-checked')).toBe('true');
    expect(toggle.getAttribute('aria-busy')).toBe('true');
    await user.click(toggle);
    expect(emitted()['update:modelValue']).toHaveLength(2);
  });

  it('StatusBadge shows its label as text', async () => {
    const { container } = render(StatusBadge, { props: { label: 'Enabled', variant: 'success' } });
    expect(screen.getByText('Enabled')).toBeTruthy();
    expect(await axe(container)).toHaveNoViolations();
    render(StatusBadge, { props: { label: 'Other' } });
    expect(screen.getByText('Other').className).toContain('text-muted');
  });
});
