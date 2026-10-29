import { watch, type InjectionKey, type Ref, inject, provide, shallowRef } from 'vue';
import { fetchCarShows, fetchEventInfo, type CarShow, type EventInfo } from '../../api/events';
import { useAsyncData, type AsyncData } from '../useAsyncData';

/** Car shows for the Current (active) or Past (inactive) list. */
export function useCarShows(isActive: boolean): AsyncData<CarShow[]> {
  return useAsyncData(() => fetchCarShows(isActive));
}

/**
 * Loads a resource for the event in the route. Reloads when the id changes (moving between two events
 * keeps the same component instance).
 */
export function useEventData<T>(eventId: () => number, loader: (id: number) => Promise<T>): AsyncData<T> {
  const data = useAsyncData(() => loader(eventId()));
  watch(eventId, () => {
    void data.reload();
  });
  return data;
}

/** Event info for the layout (name, tab visibility, registration state). */
export function useEventInfo(eventId: () => number): AsyncData<EventInfo> {
  return useEventData(eventId, fetchEventInfo);
}

export interface EventContext {
  eventId: Readonly<Ref<number>>;
  info: Readonly<Ref<EventInfo | null>>;
}

export const EVENT_CONTEXT: InjectionKey<EventContext> = Symbol('event-context');

/** The layout shares the event id and loaded info with its tab pages. */
export function provideEventContext(context: EventContext): void {
  provide(EVENT_CONTEXT, context);
}

/** Pages under the event layout read the id and info from here (a fallback keeps pages usable alone). */
export function useEventContext(fallbackId = 0): EventContext {
  return inject(EVENT_CONTEXT, { eventId: shallowRef(fallbackId), info: shallowRef(null) });
}
