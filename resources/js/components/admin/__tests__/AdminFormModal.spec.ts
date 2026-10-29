import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { defineComponent, h, ref } from 'vue';
import AdminFormModal from '../AdminFormModal.vue';
import type { AdminField, FormValues } from '../types';
import { ApiError } from '../../../api/client';

const fields: AdminField[] = [
  { name: 'name', label: 'Name', required: true, maxlength: 10 },
  { name: 'notes', label: 'Notes', type: 'textarea', hint: 'Staff only' },
  { name: 'tier', label: 'Tier', type: 'select', section: 'Extra', options: [{ value: 'gold', label: 'Gold' }] },
  { name: 'count', label: 'Count', type: 'number' },
  { name: 'price', label: 'Price', type: 'money' },
  { name: 'day', label: 'Day', type: 'date' },
  { name: 'at', label: 'At', type: 'datetime' },
  { name: 'on', label: 'Enabled', type: 'checkbox', hint: 'Shown on the site' },
];

function harness(submit: (values: FormValues) => Promise<unknown>, initial: FormValues = { name: 'Acme' }, schema: AdminField[] = fields) {
  return defineComponent({
    setup() {
      const open = ref(false);
      const saved = ref<unknown>(null);
      return () => [
        h('button', { type: 'button', onClick: () => (open.value = true) }, 'Open form'),
        h('p', { 'data-testid': 'saved' }, JSON.stringify(saved.value)),
        h(AdminFormModal, {
          open: open.value,
          title: 'Edit thing',
          fields: schema,
          initial,
          submit,
          'onUpdate:open': (value: boolean) => (open.value = value),
          onSaved: (result: unknown) => (saved.value = result),
        }),
      ];
    },
  });
}

afterEach(() => {
  document.body.style.overflow = '';
});

describe('AdminFormModal', () => {
  it('renders every schema field type with labels and passes axe', async () => {
    const user = userEvent.setup();
    render(harness(async () => ({})));
    await user.click(screen.getByRole('button', { name: 'Open form' }));
    const dialog = await screen.findByRole('dialog', { name: 'Edit thing' });
    expect((within(dialog).getByLabelText('Name (required)') as HTMLInputElement).value).toBe('Acme');
    expect(within(dialog).getByLabelText('Notes').tagName).toBe('TEXTAREA');
    expect(within(dialog).getByRole('combobox', { name: 'Tier' })).toBeTruthy();
    expect(within(dialog).getByRole('heading', { name: 'Extra' })).toBeTruthy();
    expect((within(dialog).getByLabelText('Count') as HTMLInputElement).type).toBe('number');
    expect(within(dialog).getByLabelText('Price')).toBeTruthy();
    expect((within(dialog).getByLabelText('Day') as HTMLInputElement).type).toBe('date');
    expect((within(dialog).getByLabelText('At') as HTMLInputElement).type).toBe('datetime-local');
    expect(within(dialog).getByRole('switch', { name: 'Enabled' }).getAttribute('aria-checked')).toBe('false');
    expect(await axe(dialog)).toHaveNoViolations();
  });

  it('submits the edited values, emits saved, closes and returns focus to the opener', async () => {
    const user = userEvent.setup();
    const submit = vi.fn(async (values: FormValues) => ({ id: 1, ...values }));
    render(harness(submit));
    const opener = screen.getByRole('button', { name: 'Open form' });
    await user.click(opener);
    const name = await screen.findByLabelText('Name (required)');
    await user.clear(name);
    await user.type(name, 'Bolt');
    await user.type(screen.getByLabelText('Count'), '7');
    await user.click(screen.getByRole('combobox', { name: 'Tier' }));
    await user.click(await screen.findByRole('option', { name: 'Gold' }));
    await user.click(screen.getByRole('switch', { name: 'Enabled' }));
    await user.click(screen.getByRole('button', { name: 'Save' }));

    expect(submit).toHaveBeenCalledWith(expect.objectContaining({ name: 'Bolt', count: 7, tier: 'gold', on: true }));
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(screen.getByTestId('saved').textContent).toContain('Bolt');
    expect(document.activeElement).toBe(opener);
  });

  it('maps a 422 to field errors, focuses the first invalid field and shows unknown fields in the banner', async () => {
    const user = userEvent.setup();
    const submit = vi.fn().mockRejectedValue(
      new ApiError(422, { message: 'Invalid.', errors: { name: ['The name is too long.'], secret: ['Server-only problem.'] } }),
    );
    render(harness(submit));
    await user.click(screen.getByRole('button', { name: 'Open form' }));
    await user.click(await screen.findByRole('button', { name: 'Save' }));

    const name = screen.getByLabelText('Name (required)');
    expect(name.getAttribute('aria-invalid')).toBe('true');
    expect(screen.getByText('The name is too long.')).toBeTruthy();
    expect(document.activeElement).toBe(name);
    expect(screen.getByRole('alert').textContent).toContain('Server-only problem.');
    expect(screen.getByRole('dialog')).toBeTruthy();

    // Typing in the field clears its error.
    await user.type(name, 'x');
    expect(name.getAttribute('aria-invalid')).toBe('false');
  });

  it('asks before discarding unsaved changes', async () => {
    const user = userEvent.setup();
    render(harness(async () => ({})));
    await user.click(screen.getByRole('button', { name: 'Open form' }));
    await user.type(await screen.findByLabelText('Notes'), 'changed');

    await user.keyboard('{Escape}');
    expect(screen.getByRole('dialog')).toBeTruthy();
    expect(screen.getByText('Discard your changes?')).toBeTruthy();
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Keep editing' }));

    await user.click(screen.getByRole('button', { name: 'Keep editing' }));
    expect(screen.queryByText('Discard your changes?')).toBeNull();

    await user.click(screen.getByRole('button', { name: 'Cancel' }));
    await user.click(screen.getByRole('button', { name: 'Discard changes' }));
    expect(screen.queryByRole('dialog')).toBeNull();
  });

  it('closes at once when nothing changed', async () => {
    const user = userEvent.setup();
    render(harness(async () => ({})));
    await user.click(screen.getByRole('button', { name: 'Open form' }));
    await user.click(await screen.findByRole('button', { name: 'Close dialog' }));
    expect(screen.queryByRole('dialog')).toBeNull();
  });

  it('renders a richtext field that does not make the form dirty until it is edited', async () => {
    const user = userEvent.setup();
    const schema: AdminField[] = [{ name: 'body', label: 'Body', type: 'richtext', maxlength: 100, hint: 'Shown on the site' }];
    render(harness(async () => ({}), { body: '<center>Legacy</center><p>Text</p>' }, schema));
    await user.click(screen.getByRole('button', { name: 'Open form' }));
    const dialog = await screen.findByRole('dialog', { name: 'Edit thing' });
    const textbox = await within(dialog).findByRole('textbox', { name: 'Body' });
    expect(textbox.getAttribute('aria-describedby')).toContain('-hint');
    expect(within(dialog).getByText('34 / 100 characters, formatting included')).toBeTruthy();
    expect(await axe(dialog)).toHaveNoViolations();

    await user.click(within(dialog).getByRole('button', { name: 'Close dialog' }));
    expect(screen.queryByRole('dialog')).toBeNull();
  });

  it('accepts custom controls in the default slot', async () => {
    const submit = vi.fn(async () => ({}));
    render(AdminFormModal, {
      props: { open: true, title: 'Custom', submit, initial: { code: 'A' } },
      slots: {
        default: ({ values, fieldId, setValue }: { values: FormValues; fieldId: (n: string) => string; setValue: (n: string, v: unknown) => void }) => [
          h('label', { for: fieldId('code') }, 'Code'),
          h('input', { id: fieldId('code'), value: values.code, onInput: (e: Event) => setValue('code', (e.target as HTMLInputElement).value) }),
        ],
      },
    });
    const user = userEvent.setup();
    await user.type(screen.getByLabelText('Code'), 'B');
    await user.click(screen.getByRole('button', { name: 'Save' }));
    expect(submit).toHaveBeenCalledWith({ code: 'AB' });
  });
});
