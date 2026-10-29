import { describe, it, expect } from 'vitest';
import { screen, waitFor } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { jsonResponse, stubFetch, callsTo } from '../../../test/helpers';
import { mountAccount } from '../../../components/account/__tests__/harness';
import CarFormPage from '../CarFormPage.vue';

const BODIES = [{ id: 2, name: 'Coupe' }];
const TYPES = [{ id: 4, type: 'Street' }];

function baseHandlers(extra: Record<string, (init: RequestInit) => Response | Promise<Response>> = {}) {
  return {
    'GET /car/body': () => jsonResponse(200, BODIES),
    'GET /car/type': () => jsonResponse(200, TYPES),
    ...extra,
  };
}

const CAR = { id: 5, nickname: 'Red Bug', model: 'Beetle', year: 1971, VIN: 'ABC123', body: { id: 2, name: 'Coupe' }, type: { id: 4, name: 'Street' }, shows: [] };

describe('CarFormPage create', () => {
  it('shows field errors and does not send an invalid car', async () => {
    const user = userEvent.setup();
    const fetchMock = stubFetch(baseHandlers());
    await mountAccount(CarFormPage, { path: '/car/new' });
    await screen.findByRole('option', { name: 'Coupe' });
    await user.type(screen.getByLabelText('Nickname'), 'x');
    await user.click(screen.getByRole('button', { name: 'Add car and continue' }));
    expect(await screen.findByText('Nickname must be 2 to 16 characters.')).toBeTruthy();
    expect(screen.getByText('Choose a body style.')).toBeTruthy();
    expect(screen.getByText('Choose a car type.')).toBeTruthy();
    expect(callsTo(fetchMock, 'PUT /car/profile')).toHaveLength(0);
  });

  it('creates the car and moves on to the photo step', async () => {
    const user = userEvent.setup();
    const fetchMock = stubFetch(baseHandlers({ 'PUT /car/profile': () => jsonResponse(200, { id: 44 }) }));
    const { router } = await mountAccount(CarFormPage, { path: '/car/new' });
    await screen.findByRole('option', { name: 'Coupe' });
    await user.type(screen.getByLabelText('Nickname'), 'Red Bug');
    await user.selectOptions(screen.getByLabelText('Body style'), '2');
    await user.selectOptions(screen.getByLabelText('Type'), '4');
    await user.type(screen.getByLabelText('VIN'), 'abc123');
    await user.click(screen.getByRole('button', { name: 'Add car and continue' }));
    await waitFor(() => expect(router.currentRoute.value.path).toBe('/car/44/new-image'));
    expect(callsTo(fetchMock, 'PUT /car/profile')[0]).toMatchObject({ nickname: 'Red Bug', body: { id: 2 }, type: { id: 4 }, VIN: 'abc123' });
  });

  it('shows the server messages for a rejected create', async () => {
    const user = userEvent.setup();
    stubFetch(baseHandlers({ 'PUT /car/profile': () => jsonResponse(400, ['Year is not valid']) }));
    await mountAccount(CarFormPage, { path: '/car/new' });
    await screen.findByRole('option', { name: 'Coupe' });
    await user.type(screen.getByLabelText('Nickname'), 'Red Bug');
    await user.selectOptions(screen.getByLabelText('Body style'), '2');
    await user.selectOptions(screen.getByLabelText('Type'), '4');
    await user.click(screen.getByRole('button', { name: 'Add car and continue' }));
    expect(await screen.findByText('Year is not valid')).toBeTruthy();
  });

  it('shows a retry when the options cannot be loaded', async () => {
    stubFetch({ 'GET /car/body': () => new Response('', { status: 500 }), 'GET /car/type': () => jsonResponse(200, TYPES) });
    await mountAccount(CarFormPage, { path: '/car/new' });
    expect(await screen.findByText('The car form could not be loaded.')).toBeTruthy();
  });
});

describe('CarFormPage edit', () => {
  it('loads the car and saves changes with its id', async () => {
    const user = userEvent.setup();
    const fetchMock = stubFetch(
      baseHandlers({
        'GET /car/profile?car_id=5': () => jsonResponse(200, CAR),
        'PUT /car/profile': () => new Response('', { status: 200 }),
      }),
    );
    await mountAccount(CarFormPage, { props: { carId: 5 }, path: '/car/5/settings' });
    expect((await screen.findByLabelText<HTMLInputElement>('Nickname')).value).toBe('Red Bug');
    expect((screen.getByLabelText('Body style') as HTMLSelectElement).value).toBe('2');
    expect((screen.getByLabelText('Body style') as HTMLSelectElement).disabled).toBe(false);
    await user.clear(screen.getByLabelText('Model'));
    await user.type(screen.getByLabelText('Model'), 'Super Beetle');
    await user.click(screen.getByRole('button', { name: 'Save car' }));
    expect(await screen.findByText('Car saved.')).toBeTruthy();
    expect(callsTo(fetchMock, 'PUT /car/profile')[0]).toMatchObject({ car_id: 5, model: 'Super Beetle' });
  });

  it('locks body, type, year, model and VIN for a car registered for a show', async () => {
    const user = userEvent.setup();
    const fetchMock = stubFetch(
      baseHandlers({
        'GET /car/profile?car_id=5': () => jsonResponse(200, { ...CAR, shows: [{ name: 'Fall show' }] }),
        'PUT /car/profile': () => new Response('', { status: 200 }),
      }),
    );
    await mountAccount(CarFormPage, { props: { carId: 5 }, path: '/car/5/settings' });
    await screen.findByText(/registered for a show/);
    for (const label of ['Body style', 'Type', 'Year', 'Model', 'VIN']) {
      expect((screen.getByLabelText(label) as HTMLInputElement | HTMLSelectElement).disabled).toBe(true);
    }
    expect((screen.getByLabelText('Nickname') as HTMLInputElement).disabled).toBe(false);
    await user.click(screen.getByRole('button', { name: 'Save car' }));
    await screen.findByText('Car saved.');
    expect(callsTo(fetchMock, 'PUT /car/profile')[0]).toMatchObject({ car_id: 5 });
  });

  it('reports a missing car', async () => {
    stubFetch(baseHandlers({ 'GET /car/profile?car_id=99': () => new Response('', { status: 404 }) }));
    await mountAccount(CarFormPage, { props: { carId: 99 }, path: '/car/99/settings' });
    expect(await screen.findByText('This car does not exist or is not in your garage.')).toBeTruthy();
  });

  it('offers a retry when the car cannot be loaded', async () => {
    const user = userEvent.setup();
    let attempt = 0;
    stubFetch(
      baseHandlers({
        'GET /car/profile?car_id=5': () => {
          attempt += 1;
          return attempt === 1 ? new Response('', { status: 500 }) : jsonResponse(200, CAR);
        },
      }),
    );
    await mountAccount(CarFormPage, { props: { carId: 5 }, path: '/car/5/settings' });
    await user.click(await screen.findByRole('button', { name: 'Try again' }));
    expect(await screen.findByLabelText('Nickname')).toBeTruthy();
  });

  it('validates the VIN and year before sending', async () => {
    const user = userEvent.setup();
    const fetchMock = stubFetch(baseHandlers({ 'GET /car/profile?car_id=5': () => jsonResponse(200, CAR) }));
    await mountAccount(CarFormPage, { props: { carId: 5 }, path: '/car/5/settings' });
    await screen.findByLabelText('VIN');
    await user.clear(screen.getByLabelText('VIN'));
    await user.type(screen.getByLabelText('VIN'), 'AB');
    await user.type(screen.getByLabelText('Year'), '1971');
    await user.click(screen.getByRole('button', { name: 'Save car' }));
    expect(await screen.findByText('VIN must be 5 to 7 letters or digits.')).toBeTruthy();
    expect(callsTo(fetchMock, 'PUT /car/profile')).toHaveLength(0);
  });
});
