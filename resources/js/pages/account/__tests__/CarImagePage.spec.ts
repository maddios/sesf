import { describe, it, expect } from 'vitest';
import { screen, waitFor } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { jsonResponse, stubFetch } from '../../../test/helpers';
import { mountAccount } from '../../../components/account/__tests__/harness';
import CarImagePage from '../CarImagePage.vue';

const CAR = { id: 5, nickname: 'Red Bug', imageURL: 'images/cars/5.jpg', shows: [] };

describe('CarImagePage', () => {
  it('shows the current photo and uploads the new one as multipart with car_id', async () => {
    const user = userEvent.setup();
    const fetchMock = stubFetch({
      'GET /car/profile?car_id=5': () => jsonResponse(200, CAR),
      'POST /car/profile-image': () => new Response('', { status: 200 }),
    });
    const { container } = await mountAccount(CarImagePage, { props: { carId: 5 }, path: '/car/5/new-image' });
    await waitFor(() => expect(container.querySelector('img')?.getAttribute('src')).toMatch(/^\/images\/cars\/5\.jpg\?v=/));
    await user.upload(container.querySelector('input[type="file"]') as HTMLInputElement, new File(['x'], 'car.png', { type: 'image/png' }));
    await user.click(screen.getByRole('button', { name: 'Upload photo' }));
    expect(await screen.findByText('The photo was uploaded.')).toBeTruthy();
    const upload = fetchMock.mock.calls.find(([url]) => url === '/car/profile-image');
    expect(((upload?.[1] as RequestInit).body as FormData).get('car_id')).toBe('5');
  });

  it('offers a skip link to the car when there is no photo yet', async () => {
    stubFetch({ 'GET /car/profile?car_id=5': () => jsonResponse(200, { ...CAR, imageURL: '' }) });
    await mountAccount(CarImagePage, { props: { carId: 5 }, path: '/car/5/new-image' });
    expect((await screen.findByRole('link', { name: 'Go to Red Bug' })).getAttribute('href')).toBe('/car/5');
  });

  it('reports a car that is not in the garage, and a load failure', async () => {
    stubFetch({ 'GET /car/profile?car_id=6': () => new Response('', { status: 404 }) });
    await mountAccount(CarImagePage, { props: { carId: 6 }, path: '/car/6/new-image' });
    expect(await screen.findByText('This car does not exist or is not in your garage.')).toBeTruthy();
  });

  it('shows the load error alert', async () => {
    stubFetch({ 'GET /car/profile?car_id=7': () => new Response('', { status: 500 }) });
    await mountAccount(CarImagePage, { props: { carId: 7 }, path: '/car/7/new-image' });
    expect(await screen.findByText('The car could not be loaded.')).toBeTruthy();
  });
});
