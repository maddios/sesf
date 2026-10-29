import { describe, it, expect } from 'vitest';
import { h } from 'vue';
import { render, screen } from '@testing-library/vue';
import { axe } from 'vitest-axe';
import ResponseBanner from '../ResponseBanner.vue';
import FormField from '../FormField.vue';
import SubmitButton from '../SubmitButton.vue';
import { flattenMessages } from '../messages';

describe('flattenMessages', () => {
  it.each<[string, Parameters<typeof flattenMessages>[0], string[]]>([
    ['null', null, []],
    ['undefined', undefined, []],
    ['a string', 'Hello', ['Hello']],
    ['a list, blanks dropped', ['One', '  ', 'Two'], ['One', 'Two']],
    ['field keyed lists, all messages kept', { email: ['Bad.', 'Required.'], password: ['Short.'] }, ['Bad.', 'Required.', 'Short.']],
  ])('%s', (_label, input, expected) => {
    expect(flattenMessages(input)).toEqual(expected);
  });
});

describe('ResponseBanner', () => {
  it('renders nothing without messages', () => {
    const { container } = render(ResponseBanner, { props: { messages: [] } });
    expect(container.innerHTML).toBe('<!--v-if-->');
  });

  it('is an alert for errors and shows a single string', () => {
    render(ResponseBanner, { props: { messages: 'Something failed.' } });
    const banner = screen.getByRole('alert');
    expect(banner.getAttribute('aria-live')).toBe('polite');
    expect(banner.textContent).toContain('Something failed.');
  });

  it('shows every message of a field-keyed object', () => {
    render(ResponseBanner, { props: { messages: { email: ['Bad email.'], password: ['Too short.', 'Wrong.'] } } });
    expect(screen.getByRole('alert').textContent).toContain('Bad email.');
    expect(screen.getByRole('alert').textContent).toContain('Too short.');
    expect(screen.getByRole('alert').textContent).toContain('Wrong.');
  });

  it('uses a status region for non-error variants', () => {
    render(ResponseBanner, { props: { messages: 'Saved.', variant: 'success' } });
    expect(screen.getByRole('status').textContent).toContain('Saved.');
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('has no detectable accessibility violations', async () => {
    const { container } = render(ResponseBanner, { props: { messages: ['One.'] } });
    expect(await axe(container)).toHaveNoViolations();
  });
});

describe('FormField', () => {
  function renderField(props: { errors?: string[]; hint?: string }) {
    return render(FormField, {
      props: { id: 'f1', label: 'Email', ...props },
      slots: {
        default: (slotProps: { inputProps: Record<string, unknown> }) =>
          h('input', { type: 'email', ...slotProps.inputProps }),
      },
    });
  }

  it('binds the label to the control', () => {
    renderField({});
    expect(screen.getByLabelText('Email').getAttribute('id')).toBe('f1');
  });

  it('links the error text to the control with aria-describedby', () => {
    renderField({ errors: ['Enter a valid email.'] });
    const input = screen.getByLabelText('Email');
    expect(input.getAttribute('aria-invalid')).toBe('true');
    const describedBy = input.getAttribute('aria-describedby') ?? '';
    expect(describedBy).toBe('f1-error');
    expect(document.getElementById('f1-error')?.textContent).toContain('Enter a valid email.');
  });

  it('links the hint and the errors together, and marks valid fields', () => {
    renderField({ hint: 'We never share it.', errors: ['Required.'] });
    expect(screen.getByLabelText('Email').getAttribute('aria-describedby')).toBe('f1-hint f1-error');

    const { container } = renderField({ hint: 'Only a hint.' });
    const inputs = container.querySelectorAll('input');
    expect(inputs[inputs.length - 1]?.getAttribute('aria-describedby')).toBe('f1-hint');
    expect(inputs[inputs.length - 1]?.getAttribute('aria-invalid')).toBe('false');
  });

  it('has no detectable accessibility violations', async () => {
    const { container } = renderField({ errors: ['Required.'] });
    expect(await axe(container)).toHaveNoViolations();
  });
});

describe('SubmitButton', () => {
  it('is a submit button that is enabled by default', () => {
    render(SubmitButton, { slots: { default: 'Save' } });
    const button = screen.getByRole('button', { name: 'Save' });
    expect(button.getAttribute('type')).toBe('submit');
    expect((button as HTMLButtonElement).disabled).toBe(false);
    expect(button.getAttribute('aria-busy')).toBeNull();
  });

  it('is disabled with a spinner and an announced label while busy', () => {
    const { container } = render(SubmitButton, { props: { busy: true, busyLabel: 'Saving' }, slots: { default: 'Save' } });
    const button = screen.getByRole('button', { name: /Save/ }) as HTMLButtonElement;
    expect(button.disabled).toBe(true);
    expect(button.getAttribute('aria-busy')).toBe('true');
    expect(container.querySelector('svg')?.getAttribute('aria-hidden')).toBe('true');
    expect(screen.getByText('Saving')).toBeTruthy();
  });

  it('is disabled when asked to be', () => {
    render(SubmitButton, { props: { disabled: true }, slots: { default: 'Save' } });
    expect((screen.getByRole('button', { name: 'Save' }) as HTMLButtonElement).disabled).toBe(true);
  });

  it('has no detectable accessibility violations', async () => {
    const { container } = render(SubmitButton, { slots: { default: 'Save' } });
    expect(await axe(container)).toHaveNoViolations();
  });
});
