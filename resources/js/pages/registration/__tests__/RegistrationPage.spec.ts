import { describe, it, expect } from 'vitest';
import { screen, within, waitFor, fireEvent } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { jsonResponse, requestBody, callsTo, type RouteHandler } from '../../../test/helpers';
import { registrationRaw, setupRegistration, wait, REGISTRATION_URL, QUOTE_URL, SAVE_URL, QUOTE_RAW } from './registrationFixtures';

const PATH = '/SoutheastSharkfest2026/e36/register';

/** Save handler that answers a cancellation (cancel: true) differently from a normal save. */
function saveHandler(saved: unknown = registrationRaw(), cancelled: unknown = registrationRaw({}, { is_cancelled: 1 })): RouteHandler {
  return (init) => {
    const body = requestBody(init) as { registration: { cancel: boolean } };
    return jsonResponse(200, body.registration.cancel ? cancelled : saved);
  };
}

async function openSection(name: string) {
  return screen.findByRole('group', { name });
}

describe('RegistrationPage: loading and prefilled values', () => {
  it('renders the stepped sections with the saved selections and the saved total', async () => {
    await setupRegistration();
    expect(await screen.findByRole('heading', { level: 1, name: 'Register' })).toBeTruthy();
    for (const step of ['1. Cars', '2. Guests', '3. T-shirts', '4. Extras', '5. Approximate arrival', '6. Donation', '7. Registration and cancellation policy']) {
      expect(await openSection(step)).toBeTruthy();
    }
    expect((screen.getByRole('checkbox', { name: 'Blu (Blue Car)' }) as HTMLInputElement).checked).toBe(true);
    expect((screen.getByLabelText('Judging type') as HTMLSelectElement).value).toBe('21');
    expect((screen.getByLabelText('First name') as HTMLInputElement).value).toBe('Pat');
    expect((screen.getByLabelText('Size') as HTMLSelectElement).value).toBe('1');
    expect((screen.getByRole('spinbutton', { name: 'Quantity' }) as HTMLInputElement).value).toBe('1');
    expect((screen.getByLabelText('Donation amount') as HTMLSelectElement).value).toBe('10');
    expect(screen.queryByRole('checkbox', { name: 'Add Hidden' })).toBeNull();
    const summary = screen.getByRole('region', { name: 'Summary' });
    expect(within(summary).getByText('Total').nextElementSibling?.textContent).toBe('$160.00');
    expect(screen.getByRole('link', { name: 'Back to Southeast Sharkfest 2026' }).getAttribute('href')).toContain('/SoutheastSharkfest2026/e36');
  });

  it('hides the Extras step when the event has none and renumbers the steps after it', async () => {
    await setupRegistration({ handlers: { [REGISTRATION_URL]: () => jsonResponse(200, registrationRaw({ extras: [] }, { extras: {} })) } });
    expect(await openSection('3. T-shirts')).toBeTruthy();
    expect(screen.queryByRole('group', { name: /Extras/ })).toBeNull();
    expect(screen.queryByText('There are no extras for this event.')).toBeNull();
    for (const step of ['4. Approximate arrival', '5. Donation', '6. Registration and cancellation policy']) {
      expect(await openSection(step)).toBeTruthy();
    }
  });

  it('links to add a new car and returns to this page', async () => {
    await setupRegistration();
    const link = await screen.findByRole('link', { name: 'Add a new car' });
    expect(link.getAttribute('href')).toContain('/car/new?return=');
    expect(decodeURIComponent(link.getAttribute('href') ?? '')).toContain(PATH);
  });

  it('shows the load error with a retry that recovers', async () => {
    let calls = 0;
    await setupRegistration({
      handlers: {
        [REGISTRATION_URL]: () => {
          calls += 1;
          return calls === 1 ? jsonResponse(500, {}) : jsonResponse(200, registrationRaw());
        },
      },
    });
    const alert = await screen.findByRole('alert');
    expect(within(alert).getByText('Your registration could not be loaded right now.')).toBeTruthy();
    await fireEvent.click(within(alert).getByRole('button', { name: 'Try again' }));
    expect(await screen.findByRole('group', { name: '1. Cars' })).toBeTruthy();
  });

  it('has no accessibility violations on the loaded form', async () => {
    const { view } = await setupRegistration();
    await screen.findByRole('group', { name: '1. Cars' });
    expect(await axe(view.container)).toHaveNoViolations();
  });
});

describe('RegistrationPage: live summary', () => {
  it('asks the quote endpoint after edits, debounced, and shows its totals', async () => {
    let quotes = 0;
    await setupRegistration({
      handlers: {
        [QUOTE_URL]: () => {
          quotes += 1;
          return jsonResponse(200, QUOTE_RAW);
        },
      },
    });
    await screen.findByRole('group', { name: '1. Cars' });
    await wait(600);
    const before = quotes;
    const user = userEvent.setup();
    await user.type(screen.getByLabelText('First name'), 'xyz');
    await wait(700);
    expect(quotes - before).toBe(1);
    const summary = screen.getByRole('region', { name: 'Summary' });
    await waitFor(() => expect(within(summary).getByText('Total').nextElementSibling?.textContent).toBe('$170.00'), { timeout: 2000 });
    expect(within(summary).getByText('Concours registration')).toBeTruthy();
  });

  it('does not ask for a quote while the form is incomplete, and shows the saved totals without an error', async () => {
    let quotes = 0;
    await setupRegistration({
      handlers: {
        [QUOTE_URL]: () => {
          quotes += 1;
          return jsonResponse(400, { cars: { status: 'error', response: { need_a_car: ['You need to select at least one car'] } } });
        },
      },
    });
    const cars = await screen.findByRole('group', { name: '1. Cars' });
    await wait(600);
    const before = quotes;
    const user = userEvent.setup();
    await user.click(within(cars).getByRole('checkbox', { name: /Blu/ }));
    await wait(700);
    expect(quotes - before).toBe(0);
    expect(screen.queryByText(/Totals could not be updated/)).toBeNull();
    const summary = screen.getByRole('region', { name: 'Summary' });
    expect(within(summary).getByText('Total')).toBeTruthy();
  });

  it('says so when totals cannot be updated', async () => {
    await setupRegistration({ handlers: { [QUOTE_URL]: () => jsonResponse(500, {}) } });
    await screen.findByRole('group', { name: '1. Cars' });
    expect(await screen.findByText(/Totals could not be updated/, {}, { timeout: 2000 })).toBeTruthy();
  });
});

describe('RegistrationPage: building a registration', () => {
  it('removes a saved guest, adds a new one, picks extras and submits the right body, then goes to billing', async () => {
    const { fetchMock, router } = await setupRegistration({ handlers: { [SAVE_URL]: saveHandler() } });
    const user = userEvent.setup();
    const guestOne = await openSection('2. Guests');
    await user.click(within(guestOne).getByRole('button', { name: 'Remove guest 1' }));
    expect(within(guestOne).queryByRole('group', { name: 'Guest 1' })).toBeNull();
    await user.click(within(guestOne).getByRole('button', { name: 'Add guest' }));
    const newGuest = within(guestOne).getByRole('group', { name: 'Guest 1' });
    await user.type(within(newGuest).getByLabelText('First name'), 'Ann');
    await user.type(within(newGuest).getByLabelText('Last name'), 'Lee');
    await user.selectOptions(within(newGuest).getByLabelText('Guest type'), '1');

    const extras = await openSection('4. Extras');
    await user.click(within(extras).getByRole('checkbox', { name: 'Add Pin' }));
    const dinner = within(extras).getByRole('spinbutton', { name: 'Quantity' });
    await user.clear(dinner);
    await user.type(dinner, '2');

    await user.click(screen.getByRole('checkbox', { name: /I have read and understood/ }));
    await user.click(screen.getByRole('button', { name: 'Update registration' }));

    await waitFor(() => expect(router.currentRoute.value.path).toMatch(/\/billing\/12$/));
    const body = callsTo(fetchMock, SAVE_URL)[0] as { registration: Record<string, unknown> };
    expect(body.registration).not.toHaveProperty('cancel');
    expect(body.registration.guests).toEqual([
      { id: 5, first: 'Pat', last: 'Guest', type: 2, deleted: true },
      { first: 'Ann', last: 'Lee', type: 1 },
    ]);
    expect(body.registration.extras).toEqual({
      '4': { show_extra_id: 4, num_extras: 2 },
      '5': { show_extra_id: 5, num_extras: 1 },
      '6': { show_extra_id: 6, num_extras: 0 },
    });
    expect(body.registration.arrival_date).toEqual({ data: '2026-04-30' });
  });

  it('keeps guests to the first and last name rules and flags a guest email that matches the member', async () => {
    await setupRegistration({ handlers: { [SAVE_URL]: saveHandler() } });
    const user = userEvent.setup();
    const guests = await openSection('2. Guests');
    await user.click(within(guests).getByRole('button', { name: 'Add guest' }));
    const newGuest = within(guests).getByRole('group', { name: 'Guest 2' });
    await user.type(within(newGuest).getByLabelText('First name'), '<bad>');
    await user.type(within(newGuest).getByLabelText('Email (optional)'), 'FAN@example.test');
    await user.click(within(newGuest).getByLabelText('Last name'));
    await user.click(within(newGuest).getByLabelText('Guest type'));
    await user.tab();
    expect(await within(newGuest).findByText(/may only use letters/)).toBeTruthy();
    expect(within(newGuest).getByText(/different from your own/)).toBeTruthy();
    expect(within(newGuest).getByLabelText('First name').getAttribute('aria-invalid')).toBe('true');
  });

  it('blocks submit with inline messages until the policy box is ticked, and focuses the first problem', async () => {
    const { fetchMock } = await setupRegistration({ handlers: { [SAVE_URL]: saveHandler() } });
    const user = userEvent.setup();
    await screen.findByRole('group', { name: '1. Cars' });
    await user.click(screen.getByRole('button', { name: 'Update registration' }));
    expect(await screen.findByText('Check the highlighted fields before saving.')).toBeTruthy();
    expect(screen.getByText(/Confirm that you have read/)).toBeTruthy();
    await waitFor(() => expect(document.activeElement?.id).toBe('registration-section-cancel'));
    expect(callsTo(fetchMock, SAVE_URL)).toHaveLength(0);
  });

  it('maps per-section server errors to their sections and focuses the first one', async () => {
    await setupRegistration({
      handlers: {
        [SAVE_URL]: () =>
          jsonResponse(400, {
            cars: { response: ['Choose at least one car.'], status: 400 },
            guest: { '0': { response: ['That guest is already registered.'], status: 400 } },
            arrival: { response: ['Arrival date is closed.'], status: 400 },
            general: { response: ['Something else is wrong.'], status: 400 },
          }),
      },
    });
    const user = userEvent.setup();
    await user.click(await screen.findByRole('checkbox', { name: /I have read and understood/ }));
    await user.click(screen.getByRole('button', { name: 'Update registration' }));
    const cars = await openSection('1. Cars');
    expect(await within(cars).findByText('Choose at least one car.')).toBeTruthy();
    expect(within(await openSection('2. Guests')).getByText('That guest is already registered.')).toBeTruthy();
    expect(within(await openSection('5. Approximate arrival')).getByText('Arrival date is closed.')).toBeTruthy();
    expect(screen.getByText('Something else is wrong.')).toBeTruthy();
    await waitFor(() => expect(document.activeElement?.id).toBe('registration-section-cars'));
  });

  it('shows a general message when saving fails for another reason', async () => {
    await setupRegistration({ handlers: { [SAVE_URL]: () => jsonResponse(500, {}) } });
    const user = userEvent.setup();
    await user.click(await screen.findByRole('checkbox', { name: /I have read and understood/ }));
    await user.click(screen.getByRole('button', { name: 'Update registration' }));
    expect(await screen.findByText('Your registration could not be saved. Please try again.')).toBeTruthy();
  });

  it('keeps the form editable for a brand new registration and labels the button Register', async () => {
    await setupRegistration({
      handlers: {
        [REGISTRATION_URL]: () => jsonResponse(200, registrationRaw({}, { id: null, cars: [], guests: [], shirts: [], extras: {}, arrival_date: { data: '' }, arrival_time: '', donation: 0 })),
        [SAVE_URL]: () => jsonResponse(200, registrationRaw({}, { id: null })),
      },
    });
    expect(await screen.findByRole('button', { name: 'Register' })).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Cancel registration' })).toBeNull();
    expect((screen.getByLabelText('Day') as HTMLSelectElement).value).toBe('');
  });
});

const ADMIN_PATH = `${PATH}?user_id=9`;
const ADMIN_SAVE = 'POST /event/registration?show_id=36&user_id=9';
const ADMIN_REGISTRATION = 'GET /event/registration?show_id=36&user_id=9';
const ADMIN_QUOTE = 'POST /event/registration/quote?show_id=36&user_id=9';

function adminHandlers(save: RouteHandler = saveHandler()): Record<string, RouteHandler> {
  return {
    [ADMIN_REGISTRATION]: () => jsonResponse(200, registrationRaw({ targetUser: { id: 9, first_name: 'Pat', last_name: 'Member', email: 'pat@example.test' } })),
    [ADMIN_QUOTE]: () => jsonResponse(200, QUOTE_RAW),
    [ADMIN_SAVE]: save,
  };
}

describe('RegistrationPage: closed registration', () => {
  it('is read only for a member once registration has closed', async () => {
    await setupRegistration({ info: { regOpen: false } });
    expect(await screen.findByText(/Registration for this event is closed/)).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Update registration' })).toBeNull();
    expect((screen.getByRole('checkbox', { name: 'Blu (Blue Car)' }) as HTMLInputElement).disabled).toBe(true);
  });

  it('stays editable for an administrator when registration has closed', async () => {
    await setupRegistration({ auth: 'admin', info: { regOpen: false } });
    expect(await screen.findByRole('button', { name: 'Update registration' })).toBeTruthy();
    expect((screen.getByRole('checkbox', { name: 'Blu (Blue Car)' }) as HTMLInputElement).disabled).toBe(false);
  });
});

describe('RegistrationPage: cancellation', () => {
  it('lets a member contact the organizers instead of cancelling, and never sends cancel', async () => {
    const { fetchMock } = await setupRegistration({ handlers: { [SAVE_URL]: saveHandler() } });
    expect(await screen.findByRole('button', { name: 'Contact us' })).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Cancel registration' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Reinstate registration' })).toBeNull();
    const user = userEvent.setup();
    await user.click(screen.getByRole('checkbox', { name: /I have read and understood/ }));
    await user.click(screen.getByRole('button', { name: 'Update registration' }));
    await waitFor(() => expect(callsTo(fetchMock, SAVE_URL)).toHaveLength(1));
    expect(callsTo(fetchMock, SAVE_URL)[0]).not.toHaveProperty('registration.cancel');
  });

  it('shows a cancelled registration read only to a member, with a contact notice', async () => {
    await setupRegistration({
      handlers: { [REGISTRATION_URL]: () => jsonResponse(200, registrationRaw({}, { is_cancelled: 1 })) },
    });
    expect(await screen.findByText('This registration was cancelled; please contact us.')).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Update registration' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Contact us' })).toBeNull();
    expect((screen.getByRole('checkbox', { name: 'Blu (Blue Car)' }) as HTMLInputElement).disabled).toBe(true);
  });

  it('lets an administrator acting for a member cancel after a confirm and reinstate, sending cancel each time', async () => {
    const { fetchMock } = await setupRegistration({ auth: 'admin', path: ADMIN_PATH, handlers: adminHandlers() });
    const user = userEvent.setup();
    await user.click(await screen.findByRole('button', { name: 'Cancel registration' }));
    const dialog = await screen.findByRole('dialog', { name: 'Cancel this registration?' });
    expect(callsTo(fetchMock, ADMIN_SAVE)).toHaveLength(0);
    await user.click(within(dialog).getByRole('button', { name: 'Cancel registration' }));
    expect(await screen.findByRole('button', { name: 'Reinstate registration' })).toBeTruthy();
    expect(screen.getByText('This registration is cancelled. You can reinstate it below.')).toBeTruthy();
    expect(callsTo(fetchMock, ADMIN_SAVE)[0]).toMatchObject({ registration: { cancel: true } });

    await user.click(screen.getByRole('button', { name: 'Reinstate registration' }));
    await user.click(within(await screen.findByRole('dialog', { name: 'Reinstate this registration?' })).getByRole('button', { name: 'Reinstate registration' }));
    await waitFor(() => expect(callsTo(fetchMock, ADMIN_SAVE)).toHaveLength(2));
    expect(callsTo(fetchMock, ADMIN_SAVE)[1]).toMatchObject({ registration: { cancel: false } });
    expect(await screen.findByRole('button', { name: 'Cancel registration' })).toBeTruthy();
  });

  it('keeps the admin dismissal path: Keep registration closes without sending', async () => {
    const { fetchMock } = await setupRegistration({ auth: 'admin', path: ADMIN_PATH, handlers: adminHandlers() });
    const user = userEvent.setup();
    await user.click(await screen.findByRole('button', { name: 'Cancel registration' }));
    await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Keep registration' }));
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(callsTo(fetchMock, ADMIN_SAVE)).toHaveLength(0);
  });

  it('shows an admin cancellation failure inside the dialog', async () => {
    await setupRegistration({ auth: 'admin', path: ADMIN_PATH, handlers: adminHandlers(() => jsonResponse(500, {})) });
    const user = userEvent.setup();
    await user.click(await screen.findByRole('button', { name: 'Cancel registration' }));
    await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Cancel registration' }));
    expect(await screen.findByText('The registration could not be updated. Please try again.')).toBeTruthy();
  });
});

describe('RegistrationPage: administrator and drafts', () => {
  it('does not show one member their predecessor\'s unsaved guest on the same show', async () => {
    const first = await setupRegistration({ handlers: { [SAVE_URL]: saveHandler() } });
    const user = userEvent.setup();
    await user.clear(await screen.findByLabelText('First name'));
    await user.type(screen.getByLabelText('First name'), 'Zed');
    await wait(50);
    first.view.unmount();
    first.auth.clear();

    await setupRegistration({ pinia: first.pinia, userId: 8 });
    expect(await screen.findByLabelText('First name')).toBeTruthy();
    expect((screen.getByLabelText('First name') as HTMLInputElement).value).toBe('Pat');
    expect(screen.queryByText('We restored your unsaved changes from earlier.')).toBeNull();
  });

  it('names the member when an administrator edits for them', async () => {
    const targetUser = { id: 9, first_name: 'Pat', last_name: 'Member', email: 'pat@example.test' };
    await setupRegistration({
      auth: 'admin',
      path: `${PATH}?user_id=9`,
      handlers: {
        'GET /event/registration?show_id=36&user_id=9': () => jsonResponse(200, registrationRaw({ targetUser })),
        'POST /event/registration/quote?show_id=36&user_id=9': () => jsonResponse(200, QUOTE_RAW),
      },
    });
    expect(await screen.findByText('You are viewing the registration for Pat Member.')).toBeTruthy();
  });

  it('restores an unsaved guest name after leaving the page and coming back', async () => {
    const first = await setupRegistration({ handlers: { [SAVE_URL]: saveHandler() } });
    const user = userEvent.setup();
    await user.clear(await screen.findByLabelText('First name'));
    await user.type(screen.getByLabelText('First name'), 'Zed');
    await wait(50);
    first.view.unmount();

    await setupRegistration({ pinia: first.pinia });
    expect(await screen.findByText('We restored your unsaved changes from earlier.')).toBeTruthy();
    expect((screen.getByLabelText('First name') as HTMLInputElement).value).toBe('Zed');
  });

  it('clears the draft once the registration is saved', async () => {
    const first = await setupRegistration({ handlers: { [SAVE_URL]: saveHandler() } });
    const user = userEvent.setup();
    await user.type(await screen.findByLabelText('Last name'), 'X');
    await user.click(screen.getByRole('checkbox', { name: /I have read and understood/ }));
    await user.click(screen.getByRole('button', { name: 'Update registration' }));
    await waitFor(() => expect(first.router.currentRoute.value.path).toMatch(/billing/));
    first.view.unmount();
    await setupRegistration({ pinia: first.pinia });
    expect(await screen.findByLabelText('Last name')).toBeTruthy();
    expect(screen.queryByText('We restored your unsaved changes from earlier.')).toBeNull();
  });
});

describe('RegistrationPage: keyboard and screen reader support', () => {
  it('moves through the remove and add buttons with names that say what they act on', async () => {
    await setupRegistration({ handlers: { [SAVE_URL]: saveHandler() } });
    const user = userEvent.setup();
    await user.click(await screen.findByRole('button', { name: 'Add shirt' }));
    expect(await screen.findByRole('button', { name: 'Remove shirt 2' })).toBeTruthy();
    await fireEvent.click(screen.getByRole('button', { name: 'Remove shirt 2' }));
    expect(screen.queryByRole('button', { name: 'Remove shirt 2' })).toBeNull();
    expect(screen.getByRole('button', { name: 'Remove guest 1' })).toBeTruthy();
  });

  it('names the total region for screen readers', async () => {
    await setupRegistration();
    expect(await screen.findByRole('heading', { level: 2, name: 'Summary' })).toBeTruthy();
  });
});
