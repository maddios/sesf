import { fetchHomeEvents, fetchHomeSponsors, type HomeEvent, type HomeSponsors } from '../api/home';
import { useAsyncData, type AsyncData } from './useAsyncData';

/** Latest event(s) for the home page EVENTS block (GET /home/events?limit=1). */
export function useHomeEvents(limit = 1): AsyncData<HomeEvent[]> {
  return useAsyncData(() => fetchHomeEvents(limit));
}

/** Additional sponsors of the newest show (GET /event/sponsors?limit=6&all=true). */
export function useHomeSponsors(limit = 6): AsyncData<HomeSponsors> {
  return useAsyncData(() => fetchHomeSponsors(limit));
}
