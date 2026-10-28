/** Shared API fixtures for tests. */

/** Trimmed copy of a real legacy /home/events?limit=1 row (PHP sends ids as strings, ended as a boolean). */
export const LEGACY_EVENT = {
  id: '36',
  short_name: 'sesf26',
  name: 'Southeast Sharkfest 2026',
  description: 'We are pleased to announce...',
  short_description: 'We are pleased to announce the date...',
  date_start: '2026-04-30',
  date_end: '2026-05-03',
  is_active: '1',
  has_image: '1',
  imageURL: 'images/shows/36.jpg',
  profileURL: '#/SoutheastSharkfest2026/e36',
  shortDescription: 'We are pleased to announce the date...',
  shortDescriptionHtml: 'We are pleased to announce <strong>the date</strong>...',
  regOpen: false,
  ended: true,
  primary_sponsors: [
    { id: 26, name: 'Odometer Gears', URL: 'http://www.odometergears.com/', imageURL: 'images/sponsor/26.jpg', shortDescription: 'Gears.', pivot: { type_id: 1 } },
    { id: 'x', name: 'Broken row' },
  ],
};
