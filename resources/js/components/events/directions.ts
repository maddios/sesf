import type { EventAddress } from '../../api/events';

/**
 * Google Maps directions URL (works without the Maps JS API). Uses coordinates when present, otherwise the
 * full address text. Empty when there is nothing to route to.
 */
export function directionsHref(address: EventAddress): string {
  const destination =
    address.latitude !== null && address.longitude !== null
      ? `${address.latitude},${address.longitude}`
      : address.fullAddress;
  return destination === '' ? '' : `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(destination)}`;
}
