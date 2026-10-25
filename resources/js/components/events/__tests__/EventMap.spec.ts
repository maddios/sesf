import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { axe } from 'vitest-axe';
import EventMap from '../EventMap.vue';
import { directionsHref } from '../directions';
import { loadGoogleMaps, type MapsLibrary } from '../mapsLoader';
import type { EventAddress } from '../../../api/events';

vi.mock('../mapsLoader', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../mapsLoader')>();
  return { ...actual, loadGoogleMaps: vi.fn() };
});

const ADDRESS: EventAddress = {
  address1: '136 E Francis St',
  address2: '',
  city: 'Williamsburg',
  state: 'VA',
  zip: '23185',
  cityState: 'Williamsburg, VA',
  fullAddress: '136 E Francis St, Williamsburg, VA 23185',
  latitude: 37.2707,
  longitude: -76.7075,
};

const NO_COORDS: EventAddress = { ...ADDRESS, latitude: null, longitude: null };

function library() {
  const addListener = vi.fn();
  const open = vi.fn();
  const lib = {
    Map: vi.fn(function () {
      return {};
    }),
    Marker: vi.fn(function () {
      return { addListener };
    }),
    InfoWindow: vi.fn(function () {
      return { open };
    }),
  };
  return { lib: lib as unknown as MapsLibrary, lib_: lib, addListener, open };
}

beforeEach(() => {
  vi.mocked(loadGoogleMaps).mockReset();
  (window as Window & { __SESF__?: unknown }).__SESF__ = undefined;
});

afterEach(() => {
  (window as Window & { __SESF__?: unknown }).__SESF__ = undefined;
});

describe('directionsHref', () => {
  it('routes to coordinates when present, else to the address text', () => {
    expect(directionsHref(ADDRESS)).toBe('https://www.google.com/maps/dir/?api=1&destination=37.2707%2C-76.7075');
    expect(directionsHref(NO_COORDS)).toBe(
      'https://www.google.com/maps/dir/?api=1&destination=136%20E%20Francis%20St%2C%20Williamsburg%2C%20VA%2023185',
    );
    expect(directionsHref({ ...NO_COORDS, fullAddress: '' })).toBe('');
  });
});

describe('EventMap', () => {
  it('shows the address and directions link without a Maps key, and never loads the script', async () => {
    const { container } = render(EventMap, { props: { title: 'Williamsburg Inn', address: ADDRESS } });
    const address = container.querySelector('address');
    expect(address?.textContent).toContain('136 E Francis St');
    expect(address?.textContent).toContain('Williamsburg, VA 23185');
    const link = screen.getByRole('link', { name: /get directions to williamsburg inn/i });
    expect(link.getAttribute('href')).toContain('destination=37.2707%2C-76.7075');
    expect(link.getAttribute('target')).toBe('_blank');
    expect(container.querySelector('[role="region"]')).toBeNull();
    expect(loadGoogleMaps).not.toHaveBeenCalled();
  });

  it('renders a marker map once the configured key loads', async () => {
    (window as Window & { __SESF__?: unknown }).__SESF__ = { mapsKey: 'AIzaSyFAKEKEY_123456' };
    const { lib, addListener, lib_ } = library();
    vi.mocked(loadGoogleMaps).mockResolvedValue(lib);
    render(EventMap, { props: { title: 'Williamsburg Inn', address: ADDRESS } });
    expect(screen.getByRole('region', { name: 'Map of Williamsburg Inn' })).toBeTruthy();
    expect(loadGoogleMaps).toHaveBeenCalledWith('AIzaSyFAKEKEY_123456');
    await waitFor(() => expect(lib_.Marker).toHaveBeenCalledTimes(1));
    expect(lib_.Map).toHaveBeenCalledWith(expect.any(HTMLElement), expect.objectContaining({ zoom: 14 }));
    expect(addListener).toHaveBeenCalledWith('click', expect.any(Function));
  });

  it('opens an info window with plain text when the marker is clicked', async () => {
    (window as Window & { __SESF__?: unknown }).__SESF__ = { mapsKey: 'AIzaSyFAKEKEY_123456' };
    const { lib, addListener, open, lib_ } = library();
    vi.mocked(loadGoogleMaps).mockResolvedValue(lib);
    render(EventMap, { props: { title: '<b>Inn</b>', address: ADDRESS } });
    await waitFor(() => expect(addListener).toHaveBeenCalled());
    const handler = addListener.mock.calls[0]?.[1] as () => void;
    handler();
    expect(open).toHaveBeenCalledTimes(1);
    const info = (lib_.InfoWindow.mock.calls as unknown as Array<[{ content: HTMLElement }]>)[0]?.[0];
    expect(info?.content.querySelector('b')).toBeNull();
    expect(info?.content.textContent).toContain('<b>Inn</b>');
  });

  it('removes the map and keeps the address when the script fails', async () => {
    (window as Window & { __SESF__?: unknown }).__SESF__ = { mapsKey: 'AIzaSyFAKEKEY_123456' };
    vi.mocked(loadGoogleMaps).mockRejectedValue(new Error('Google Maps script failed to load'));
    const { container } = render(EventMap, { props: { title: 'Inn', address: ADDRESS } });
    await waitFor(() => expect(container.querySelector('[role="region"]')).toBeNull());
    expect(screen.getByRole('link', { name: /get directions/i })).toBeTruthy();
  });

  it('does not try the map when there are no coordinates', () => {
    (window as Window & { __SESF__?: unknown }).__SESF__ = { mapsKey: 'AIzaSyFAKEKEY_123456' };
    const { container } = render(EventMap, { props: { title: 'Inn', address: NO_COORDS } });
    expect(container.querySelector('[role="region"]')).toBeNull();
    expect(loadGoogleMaps).not.toHaveBeenCalled();
  });

  it('has no accessibility violations', async () => {
    const { container } = render(EventMap, { props: { title: 'Inn', address: ADDRESS } });
    expect(await axe(container)).toHaveNoViolations();
  });
});
