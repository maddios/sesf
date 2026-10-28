import { describe, it, expect } from 'vitest';
import {
  eventPath,
  fetchHomeEvents,
  fetchHomeSponsors,
  legacyImageUrl,
  safeExternalUrl,
  toBool,
  toHomeEvent,
  toSponsor,
} from '../home';
import { jsonResponse, stubFetch } from '../../test/helpers';
import { LEGACY_EVENT } from '../../test/fixtures';


describe('home api normalisers', () => {
  it('maps a legacy event row', () => {
    expect(toHomeEvent(LEGACY_EVENT)).toEqual({
      id: 36,
      name: 'Southeast Sharkfest 2026',
      shortDescription: 'We are pleased to announce the date...',
      shortDescriptionHtml: 'We are pleased to announce <strong>the date</strong>...',
      imageUrl: '/images/shows/36.jpg',
      path: '/SoutheastSharkfest2026/e36',
      dateStart: '2026-04-30',
      dateEnd: '2026-05-03',
      ended: true,
      registrationOpen: false,
      primarySponsors: [
        { id: 26, name: 'Odometer Gears', url: 'http://www.odometergears.com/', logoUrl: '/images/sponsor/26.jpg', shortDescription: 'Gears.' },
      ],
    });
  });

  it('drops rows without a numeric id and tolerates missing fields', () => {
    expect(toHomeEvent({ id: 'abc' })).toBeNull();
    expect(toSponsor({ id: {} as unknown as string })).toBeNull();
    expect(toHomeEvent({ id: 5 })).toMatchObject({ name: '', path: '/event/e5', imageUrl: '', ended: false, primarySponsors: [] });
  });

  it.each([
    [true, true],
    [1, true],
    ['1', true],
    ['true', true],
    [false, false],
    [0, false],
    ['0', false],
    [null, false],
  ])('toBool(%s) is %s', (value, expected) => {
    expect(toBool(value)).toBe(expected);
  });

  it('only accepts same-origin legacy image paths', () => {
    expect(legacyImageUrl('images/sponsor/45.jpg')).toBe('/images/sponsor/45.jpg');
    expect(legacyImageUrl('/images/sponsor/45.jpg')).toBe('/images/sponsor/45.jpg');
    expect(legacyImageUrl('https://evil.test/x.jpg')).toBe('');
    expect(legacyImageUrl('images/../../etc/passwd')).toBe('');
    expect(legacyImageUrl(42)).toBe('');
  });

  it('keeps the cache-busting query on legacy image paths', () => {
    expect(legacyImageUrl('images/sponsor/45.jpg?v=1792976406')).toBe('/images/sponsor/45.jpg?v=1792976406');
    expect(legacyImageUrl('images/shows/36.jpg?1792976406')).toBe('/images/shows/36.jpg?1792976406');
    expect(legacyImageUrl('images/sponsor/45.jpg?<script>')).toBe('');
  });

  it('only accepts http(s) sponsor links', () => {
    expect(safeExternalUrl('https://blueridgebmwcca.org/')).toBe('https://blueridgebmwcca.org/');
    expect(safeExternalUrl('javascript:alert(1)')).toBe('');
    expect(safeExternalUrl('www.example.com')).toBe('');
  });

  it('builds event paths from profileURL', () => {
    expect(eventPath('#/SoutheastSharkfest2025/e35', 35)).toBe('/SoutheastSharkfest2025/e35');
    expect(eventPath('/x/e1', 1)).toBe('/x/e1');
    expect(eventPath('javascript:alert(1)', 9)).toBe('/event/e9');
  });
});

describe('home api requests', () => {
  it('GET /home/events?limit=1 returns normalised events', async () => {
    stubFetch({ 'GET /home/events?limit=1': () => jsonResponse(200, [LEGACY_EVENT]) });
    const events = await fetchHomeEvents();
    expect(events).toHaveLength(1);
    expect(events[0]?.name).toBe('Southeast Sharkfest 2026');
  });

  it('treats a non-array events body as no events', async () => {
    stubFetch({ 'GET /home/events?limit=2': () => jsonResponse(200, { oops: true }) });
    expect(await fetchHomeEvents(2)).toEqual([]);
  });

  it('GET /event/sponsors?limit=6&all=true returns sponsors and the show id', async () => {
    stubFetch({
      'GET /event/sponsors?limit=6&all=true': () =>
        jsonResponse(200, { sponsors: [{ id: 45, name: 'BMWCCA E31 Chapter', URL: 'https://www.bmwccae31.com/', imageURL: 'images/sponsor/45.jpg' }], show_id: '36' }),
    });
    expect(await fetchHomeSponsors()).toEqual({
      sponsors: [{ id: 45, name: 'BMWCCA E31 Chapter', url: 'https://www.bmwccae31.com/', logoUrl: '/images/sponsor/45.jpg', shortDescription: '' }],
      showId: 36,
    });
  });

  it('treats an unexpected sponsors body as empty', async () => {
    stubFetch({ 'GET /event/sponsors?limit=3&all=true': () => jsonResponse(200, ['nope']) });
    expect(await fetchHomeSponsors(3)).toEqual({ sponsors: [], showId: null });
  });
});
