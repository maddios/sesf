import { describe, it, expect } from 'vitest';
import { screen, within, waitFor, fireEvent } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { jsonResponse, callsTo } from '../../../test/helpers';
import { registrationRaw, setupRegistration } from './registrationFixtures';

const PATH = '/SoutheastSharkfest2026/e36/extra/dinner36';
const DINNER = 'GET /event/extra?extra_id=dinner36&show_id=36';
const SAVE = 'POST /event/extra?extra_id=dinner36&show_id=36';
const PIN = 'GET /event/extra?extra_id=pin36&show_id=36';
const PIN_SAVE = 'POST /event/extra?extra_id=pin36&show_id=36';

function extraRaw(index: number, numExtras: number) {
  return { ...registrationRaw().extras[index], num_extras: numExtras };
}

describe('ExtraPage', () => {
  it('shows the extra with the member quantity and saves a new quantity', async () => {
    const { fetchMock } = await setupRegistration({
      path: PATH,
      handlers: { [DINNER]: () => jsonResponse(200, extraRaw(0, 1)), [SAVE]: () => jsonResponse(200, {}) },
    });
    expect(await screen.findByRole('heading', { level: 1, name: 'Dinner' })).toBeTruthy();
    expect(screen.getByText('Saturday dinner')).toBeTruthy();
    expect(screen.getByText('Price: $40.00')).toBeTruthy();
    const quantity = screen.getByRole('spinbutton', { name: 'Quantity' }) as HTMLInputElement;
    expect(quantity.value).toBe('1');
    expect(quantity.min).toBe('0');
    expect(quantity.max).toBe('3');
    const user = userEvent.setup();
    await user.clear(quantity);
    await user.type(quantity, '2');
    await user.click(screen.getByRole('button', { name: 'Save' }));
    expect(await screen.findByText('Your extra has been saved.')).toBeTruthy();
    expect(callsTo(fetchMock, SAVE)).toEqual([{ num_extras: 2 }]);
  });

  it('keeps the quantity inside the bounds and does not send an out-of-range value', async () => {
    const { fetchMock } = await setupRegistration({
      path: PATH,
      handlers: { [DINNER]: () => jsonResponse(200, extraRaw(0, 1)), [SAVE]: () => jsonResponse(200, {}) },
    });
    const quantity = await screen.findByRole('spinbutton', { name: 'Quantity' });
    await fireEvent.update(quantity, '9');
    await fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    expect(await screen.findByText('Choose a number from 0 to 3.')).toBeTruthy();
    expect(quantity.getAttribute('aria-invalid')).toBe('true');
    expect(callsTo(fetchMock, SAVE)).toHaveLength(0);
  });

  it('uses a checkbox for a single-choice extra', async () => {
    const { fetchMock } = await setupRegistration({
      path: '/SoutheastSharkfest2026/e36/extra/pin36',
      handlers: { [PIN]: () => jsonResponse(200, extraRaw(1, 0)), [PIN_SAVE]: () => jsonResponse(200, {}) },
    });
    const box = (await screen.findByRole('checkbox', { name: 'Add Pin' })) as HTMLInputElement;
    expect(box.checked).toBe(false);
    const user = userEvent.setup();
    await user.click(box);
    await user.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(callsTo(fetchMock, PIN_SAVE)).toEqual([{ num_extras: 1 }]));
    expect(await screen.findByText('Your extra has been saved.')).toBeTruthy();
  });

  it('shows the server message when saving is refused', async () => {
    await setupRegistration({
      path: PATH,
      handlers: { [DINNER]: () => jsonResponse(200, extraRaw(0, 1)), [SAVE]: () => jsonResponse(400, ['Sold out.']) },
    });
    const user = userEvent.setup();
    await user.click(await screen.findByRole('button', { name: 'Save' }));
    expect(await screen.findByText('Sold out.')).toBeTruthy();
  });

  it('falls back to a generic message when saving fails without one', async () => {
    await setupRegistration({
      path: PATH,
      handlers: { [DINNER]: () => jsonResponse(200, extraRaw(0, 1)), [SAVE]: () => jsonResponse(500, {}) },
    });
    await fireEvent.click(await screen.findByRole('button', { name: 'Save' }));
    expect(await screen.findByText('Your extra could not be saved. Please try again.')).toBeTruthy();
  });

  it('explains when the extra is no longer offered', async () => {
    await setupRegistration({
      path: '/SoutheastSharkfest2026/e36/extra/hidden36',
      handlers: {
        'GET /event/extra?extra_id=hidden36&show_id=36': () => jsonResponse(200, registrationRaw().extras[2]),
      },
    });
    expect(await screen.findByText('This extra is no longer available.')).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Save' })).toBeNull();
  });

  it('shows a load error with retry', async () => {
    let calls = 0;
    await setupRegistration({
      path: PATH,
      handlers: {
        [DINNER]: () => {
          calls += 1;
          return calls === 1 ? jsonResponse(500, {}) : jsonResponse(200, extraRaw(0, 1));
        },
      },
    });
    const alert = await screen.findByRole('alert');
    await fireEvent.click(within(alert).getByRole('button', { name: 'Try again' }));
    expect(await screen.findByRole('heading', { level: 1, name: 'Dinner' })).toBeTruthy();
  });

  it('has no accessibility violations', async () => {
    const { view } = await setupRegistration({
      path: PATH,
      handlers: { [DINNER]: () => jsonResponse(200, extraRaw(0, 1)) },
    });
    await screen.findByRole('spinbutton', { name: 'Quantity' });
    expect(await axe(view.container)).toHaveNoViolations();
  });
});

