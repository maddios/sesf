import { describe, it, expect, afterEach, vi } from 'vitest';
import {
  fetchAgenda,
  fetchCarShows,
  fetchEventInfo,
  fetchEventSponsors,
  fetchLocations,
  fetchSchedule,
  toCarShow,
  toEventInfo,
} from '../events';
import { jsonResponse, stubFetch } from '../../test/helpers';
import { AGENDA_RAW, CAR_SHOW_RAW, INFO_RAW, LOCATIONS_RAW, SCHEDULE_PDF, SPONSORS_RAW } from '../../pages/events/__tests__/eventFixtures';

afterEach(() => vi.unstubAllGlobals());

describe('event normalisers', () => {
  it('turns a legacy car show row into typed values', () => {
    const show = toCarShow(CAR_SHOW_RAW);
    expect(show).toEqual({
      id: 36,
      name: 'Southeast Sharkfest 2026',
      shortName: 'sesf26',
      shortDescription: 'We are pleased to announce the date...',
      shortDescriptionHtml: '<p>We are pleased to announce <em>the date</em>...</p>',
      path: '/SoutheastSharkfest2026/e36',
      imageUrl: '/images/shows/36.jpg',
      dateStart: '2026-04-30',
      dateEnd: '2026-05-03',
      registrationOpens: '2026-01-15 09:30:00',
      registrationCloses: '2026-04-20 23:59:59',
      ended: true,
      registrationOpen: false,
      locationLabel: 'Richmond, VA',
      numRegisteredMembers: 12,
      numRegisteredCars: 9,
    });
  });

  it('drops rows without an id and handles missing location', () => {
    expect(toCarShow({ name: 'no id' })).toBeNull();
    expect(toCarShow({ id: 1, primaryLocation: null })?.locationLabel).toBeNull();
    expect(toCarShow({ id: 1, primaryLocation: { address: { cityState: '  ' } } })?.locationLabel).toBeNull();
    expect(toCarShow({ id: 1, description: 'Only long', imageURL: '../etc' })).toMatchObject({
      shortDescription: 'Only long',
      imageUrl: '',
    });
  });

  it('maps event info, hash profile URLs and agenda counts', () => {
    const info = toEventInfo(INFO_RAW);
    expect(info).toMatchObject({
      id: 36,
      path: '/SoutheastSharkfest2026/e36',
      memberPath: '/Shark%20F/u7',
      registrationOpen: true,
      hasSchedule: true,
      agendaCounts: { agenda: 2, judging: 1 },
      showDates: [{ name: 'Check-in', date: '2026-04-30' }],
    });
    expect(toEventInfo({ id: 'x' })).toBeNull();
    expect(toEventInfo({ id: 2, profileURL: 'javascript:x', memberProfileURL: 'nope', showDates: 'bad', agendaTypes: null })).toMatchObject({
      path: '/event/e2',
      memberPath: '',
      showDates: [],
      agendaCounts: { agenda: 0, judging: 0 },
    });
  });
});

describe('event fetchers', () => {
  it('requests the car show lists by is_active', async () => {
    const fetchMock = stubFetch({
      'GET /events/car-shows?is_active=1': () => jsonResponse(200, [CAR_SHOW_RAW, null]),
      'GET /events/car-shows?is_active=0': () => jsonResponse(200, {}),
    });
    expect((await fetchCarShows(true)).map((s) => s.id)).toEqual([36]);
    expect(await fetchCarShows(false)).toEqual([]);
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it('loads event info and rejects a non-object response', async () => {
    stubFetch({ 'GET /event/info?show_id=36': () => jsonResponse(200, INFO_RAW), 'GET /event/info?show_id=5': () => jsonResponse(200, []) });
    expect((await fetchEventInfo(36)).name).toBe('Southeast Sharkfest 2026');
    await expect(fetchEventInfo(5)).rejects.toThrow('not an event');
  });

  it('sorts agenda items by sort_order and skips rows without an id', async () => {
    stubFetch({
      'GET /event/agenda?show_id=36&type_id=1': () =>
        jsonResponse(200, [{ id: 3, sort_order: 2, name: 'b', text: '' }, { name: 'no id' }, ...AGENDA_RAW.slice(0, 1)]),
    });
    const items = await fetchAgenda(36, 1);
    expect(items.map((i) => i.name)).toEqual(['Welcome', 'b']);
    expect(items[0]).toMatchObject({ typeId: 1, isPdf: false });
  });

  it('normalises locations, coordinates and sponsors (unsafe URLs dropped)', async () => {
    stubFetch({
      'GET /event/locations?show_id=36': () => jsonResponse(200, LOCATIONS_RAW),
      'GET /event/sponsors?show_id=36': () => jsonResponse(200, SPONSORS_RAW),
    });
    const [location] = await fetchLocations(36);
    expect(location?.address).toMatchObject({ latitude: 37.2707, longitude: -76.7075 });
    const { sponsors, showId } = await fetchEventSponsors(36);
    expect(showId).toBe(36);
    expect(sponsors.map((s) => [s.name, s.url, s.typeId])).toEqual([
      ['Odometer Gears', 'https://odometer.example/', 1],
      ['Gold Garage', '', 2],
      ['Mystery Co', '', 9],
    ]);
  });

  it('keeps a schedule PDF path and rejects unsafe PDF URLs', async () => {
    stubFetch({ 'GET /event/schedule?show_id=36': () => jsonResponse(200, SCHEDULE_PDF) });
    expect(await fetchSchedule(36)).toEqual({ html: '', isPdf: true, pdfUrl: '/images/shows/schedule36.pdf' });
  });

  it('treats a non-object schedule as empty', async () => {
    stubFetch({ 'GET /event/schedule?show_id=36': () => jsonResponse(200, null) });
    expect(await fetchSchedule(36)).toEqual({ html: '', isPdf: false, pdfUrl: '' });
  });
});
