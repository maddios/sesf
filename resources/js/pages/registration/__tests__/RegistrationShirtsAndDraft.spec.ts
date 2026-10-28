import { describe, it, expect } from 'vitest';
import { screen, within, waitFor } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { jsonResponse } from '../../../test/helpers';
import { registrationRaw, setupRegistration, wait, REGISTRATION_URL, QUOTE_URL, QUOTE_RAW } from './registrationFixtures';

/** The event offers two types at its own prices; the saved shirt 8 is a type the event no longer offers. */
function perEventShirts() {
  return registrationRaw(
    {
      shirtTypes: [
        { id: 1, name: 'Mens S T-Shirt', price: '18.00' },
        { id: 2, name: 'Mens XXXL T-Shirt', price: '24.50' },
      ],
    },
    { shirts: [{ id: 8, type: 12, name: 'Mens S Polo shirt', price: '55.00' }] },
  );
}

const DETAILED_QUOTE = {
  payment: {
    ...QUOTE_RAW.payment,
    lineItems: [
      { kind: 'car', label: 'Car', detail: 'Blu (1990 Sport)', quantity: 1, unit: '100.00', amount: '100.00' },
      { kind: 'guest', label: 'Child', detail: 'Pat Guest', quantity: 1, unit: '0.00', amount: '0.00' },
      { kind: 'shirt', label: 'Shirt', detail: 'Mens XXXL T-Shirt', quantity: 1, unit: '24.50', amount: '24.50' },
    ],
  },
};

async function shirtsSection() {
  return screen.findByRole('group', { name: '3. T-shirts' });
}

describe('RegistrationPage: per-event shirts', () => {
  it('offers only the event shirts at the event price and keeps a saved shirt the event no longer offers', async () => {
    await setupRegistration({ handlers: { [REGISTRATION_URL]: () => jsonResponse(200, perEventShirts()) } });
    const section = await shirtsSection();

    const saved = within(section).getByLabelText('Size') as HTMLSelectElement;
    expect(saved.value).toBe('12');
    expect(Array.from(saved.options).map((option) => option.text.trim())).toEqual([
      'Please select',
      'Mens S T-Shirt',
      'Mens XXXL T-Shirt',
      'Mens S Polo shirt (no longer offered)',
    ]);
    expect(within(section).getByText('($55.00)')).toBeTruthy();

    const user = userEvent.setup();
    await user.click(within(section).getByRole('button', { name: 'Add shirt' }));
    const selects = within(section).getAllByLabelText('Size') as HTMLSelectElement[];
    const added = selects[1] as HTMLSelectElement;
    expect(Array.from(added.options).map((option) => option.text.trim())).toEqual(['Please select', 'Mens S T-Shirt', 'Mens XXXL T-Shirt']);
    await user.selectOptions(added, '2');
    expect(within(section).getByText('($24.50)')).toBeTruthy();

    // Changing the saved shirt to an offered type shows the event price instead of its old one.
    await user.selectOptions(saved, '1');
    expect(within(section).queryByText('($55.00)')).toBeNull();
    expect(within(section).getByText('($18.00)')).toBeTruthy();
  });

  it('names each summary line under its label (car, guest, shirt type) and passes axe', async () => {
    const { view } = await setupRegistration({ handlers: { [QUOTE_URL]: () => jsonResponse(200, DETAILED_QUOTE) } });
    const summary = await screen.findByRole('region', { name: 'Summary' });
    const shirt = await within(summary).findByText('Mens XXXL T-Shirt', {}, { timeout: 2000 });
    expect(shirt.getAttribute('title')).toBe('Mens XXXL T-Shirt');
    expect(shirt.className).toContain('truncate');
    expect(within(summary).getByText('Blu (1990 Sport)')).toBeTruthy();
    expect(within(summary).getByText('Pat Guest')).toBeTruthy();
    expect(await axe(view.container)).toHaveNoViolations();
  });
});

describe('RegistrationPage: discarding a restored draft', () => {
  async function restoredDraft() {
    let quotes = 0;
    const handlers = {
      [QUOTE_URL]: () => {
        quotes += 1;
        return jsonResponse(200, QUOTE_RAW);
      },
    };
    const first = await setupRegistration({ handlers });
    const user = userEvent.setup();
    await user.clear(await screen.findByLabelText('First name'));
    await user.type(screen.getByLabelText('First name'), 'Zed');
    await wait(50);
    first.view.unmount();

    const second = await setupRegistration({ pinia: first.pinia, handlers });
    await screen.findByText('We restored your unsaved changes from earlier.');
    return { ...second, user, quotes: () => quotes };
  }

  it('asks inline, then resets the form to the saved values, clears the draft, hides the banner and asks for totals again', async () => {
    const { user, pinia, view, quotes } = await restoredDraft();
    expect((screen.getByLabelText('First name') as HTMLInputElement).value).toBe('Zed');
    await wait(600);
    const before = quotes();

    await user.click(screen.getByRole('button', { name: 'Discard changes' }));
    const question = screen.getByRole('group', { name: 'Discard unsaved changes?' });
    await waitFor(() => expect(document.activeElement).toBe(within(question).getByRole('button', { name: 'No, keep them' })));
    expect(await axe(view.container)).toHaveNoViolations();

    await user.click(within(question).getByRole('button', { name: 'Yes, discard' }));

    expect((screen.getByLabelText('First name') as HTMLInputElement).value).toBe('Pat');
    expect(screen.queryByText('We restored your unsaved changes from earlier.')).toBeNull();
    await waitFor(() => expect(document.activeElement?.id).toBe('registration-section-cars'));
    await wait(700);
    expect(quotes() - before).toBe(1);

    // The draft is gone: coming back shows the saved registration without the banner.
    view.unmount();
    await setupRegistration({ pinia });
    expect(((await screen.findByLabelText('First name')) as HTMLInputElement).value).toBe('Pat');
    expect(screen.queryByText('We restored your unsaved changes from earlier.')).toBeNull();
  });

  it('keeps the draft when the member answers No, and returns focus to the Discard button', async () => {
    const { user } = await restoredDraft();

    await user.click(screen.getByRole('button', { name: 'Discard changes' }));
    await user.click(screen.getByRole('button', { name: 'No, keep them' }));

    expect((screen.getByLabelText('First name') as HTMLInputElement).value).toBe('Zed');
    expect(screen.getByText('We restored your unsaved changes from earlier.')).toBeTruthy();
    await waitFor(() => expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Discard changes' })));
  });

  it('resets to a blank form when there is no saved registration yet', async () => {
    const blank = () => jsonResponse(200, registrationRaw({}, { id: null, invoice_id: null, cars: [], guests: [], shirts: [], extras: {}, arrival_time: '', arrival_date: { data: null }, donation: 0 }));
    const first = await setupRegistration({ handlers: { [REGISTRATION_URL]: blank } });
    const user = userEvent.setup();
    await user.click(await screen.findByRole('button', { name: 'Add shirt' }));
    await wait(50);
    first.view.unmount();

    await setupRegistration({ pinia: first.pinia, handlers: { [REGISTRATION_URL]: blank } });
    const section = await shirtsSection();
    expect(within(section).getAllByLabelText('Size')).toHaveLength(1);

    await user.click(screen.getByRole('button', { name: 'Discard changes' }));
    await user.click(screen.getByRole('button', { name: 'Yes, discard' }));

    expect(within(section).queryAllByLabelText('Size')).toHaveLength(0);
    expect(within(section).getByText('No shirts added.')).toBeTruthy();
  });
});
