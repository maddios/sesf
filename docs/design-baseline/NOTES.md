# Legacy design baseline (anonymous, captured from http://localhost:9980)

Shots: `shots/desktop` (1280x900) and `shots/mobile` (390x844), full-page PNG unless noted. Not committed (gitignored). Tokens: `tokens.json`. Capture script: `scripts/capture.mjs`.

## Page inventory
| Shot (desktop/ + mobile/) | Real URL |
|---|---|
| 01-home | /#/ |
| 02-events | /#/events |
| 03-login | /#/login |
| 04-forgot-password | /#/forgot-password |
| 05-register | /#/register |
| 06-privacy-policy | /#/privacy-policy |
| 07-members | /#/members |
| 08-cars | /#/cars |
| 09-gallery | /#/gallery |
| 10-event-2026-info | /#/SoutheastSharkfest2026/e36 (labelled "(Ended)"; newest event, no active one) |
| 11-event-2026-judging | /#/SoutheastSharkfest2026/e36/judging |
| 12-event-2026-schedule | /#/SoutheastSharkfest2026/e36/schedule |
| 13-event-2026-directions | /#/SoutheastSharkfest2026/e36/directions |
| 14-event-2026-whos-coming | /#/SoutheastSharkfest2026/e36/members |
| 15-event-2026-sponsors | /#/SoutheastSharkfest2026/e36/sponsors |
| 16-event-2026-cars | /#/SoutheastSharkfest2026/e36/cars |
| 17-event-2025-past | /#/SoutheastSharkfest2025/e35 |
| 18-member-profile | /#/ShawnB/u44 |
| 19-car-profile | /#/car/78 |
| desktop/20-hover-nav-item (viewport clip) | hover on EVENTS in navbar, / |
| desktop/21-focus-login-input, 22-focus-login-button | /#/login focus states (no submit) |
| mobile/20-mobile-nav-open | NOT CAPTURED: no hamburger exists (see Broken) |

Event list has 2003 to 2026 events (e2 to e36); gallery pages exist per event (/#/gallery/eNN).

## Visual identity (computed values)
- Font: no webfonts loaded (0 @font-face rules, document.fonts empty). Body uses system stack `-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial`. Bootstrap 3 buttons/inputs use `"Helvetica Neue", Helvetica, Arial`. Two stacks in use.
- Body 16px / 20px line-height, text #333, page background #f8f8fa.
- Headings: h1 38.5px bold #444; h2 16px semibold #222; h3 24.5px semibold #2a6496 (brand blue); h4 14px semibold #222.
- Brand blue #2a6496 (headings, navbar brand). Links: #777 grey in nav, italic blue in content.
- Primary button #006dcc (hover #0044cc), radius 4px, 14px text, white. Default button #f5f5f5, #333 text. Danger #da4f49.
- Inputs: 1px #ccc border, radius 4px, 14px, inset shadow; focus border rgba(82,168,236,.8) plus 8px glow (Bootstrap 3 default focus).
- Alerts (Bootstrap 3 palette): success bg #dff0d8 text #468847; danger bg #f2dede text #b94a48; info bg #d9edf7 text #3a87ad.
- Navbar: 112px tall (desktop), transparent bg, links 16px uppercase #777, active item bg #e5e5e5 with inset shadow, hover text #333. Logo SESF at top left; LOGIN / CREATE ACCOUNT utility links sit top right above the nav row.
- Container 1070px wide (154-ish px gutters at 1280). Footer: 38px tall, 12px #888 text, centred.
- Breakpoints (from CSS @media): max 480, 539, 600, 767, 768, 979, 1099; min 540, 768, 980, 1200.

## Nav structure
Desktop: [SESF logo] | EVENTS, MEMBERS, GALLERY, FORUM (external dev host), SHOP (external dev host), dropdown "Welcome" (logged-in only, hidden for anonymous). Utility: LOGIN, CREATE ACCOUNT.

## Page types and section order
- Home: YouTube embed (2025 overview video, 16:9) > EVENTS block (sponsor logos "SPONSORED BY" > latest event title "Southeast Sharkfest 2026 (Ended)" > blurb > "more info" > "Show all events") > ADDITIONAL SPONSORS (logo grid, "See all sponsors") > SESF PAST VIDEOS (carousel with dots and arrow) > DONATE (copy block + PayPal button) > footer.
- Events list: stacked event cards (title, "(Ended)" tag, blurb, "Event Info" / "Gallery" links) for 2003 to 2026, newest first.
- Event page: title + tab bar (Event Info, Judging, Schedule, Directions, Who's Coming, Sponsors, Cars); body content per tab.
- Members / cars: list or grid of member names and car rows; profile pages show name/car details.
- Gallery: per-event list linking to /#/gallery/eNN.
- Login: "SIGN IN" email+password, sign-in code option, "NEW TO SESF?" register CTA, forgot-password link.
- Register: multi-section form (user info, address, password, Turnstile captcha, privacy checkbox).
- Privacy policy: long text page.

## Broken or dated (for the refresh)
- Hidden "Something went wrong loading the page." text exists in the DOM of most pages (login, register, events, etc.); visible only on /#/SoutheastSharkfest2026/e36/directions (see pageChecks in tokens.json).
- Mobile (390px): no hamburger; the nav is a single horizontal row that overflows, cutting off SHOP.
- Several sponsor/logo images are broken (alt text shows, e.g. "Angry Ass", "Auto Rescue", "BMW of Midlothian"); home sponsor block shows raw alt text.
- Mixed font stacks (system stack plus Helvetica Neue); no webfont loaded.
- Dev hosts in production nav (dev-forum, dev-shop); blank-text nav links.
- Dated Bootstrap 3 look: gradient/bevel shadows on buttons, Helvetica-based buttons, 2015-era layout.
- Home is long and ad-like: sponsor logos above the event title, empty space between blocks.
- Some computed sizes look off: btn-danger measured 20px tall on /#/SoutheastSharkfest2026 (probably a flat bootstrap button with line-height issue); body h2 22px tall in nav context.
- Event past/present state is cosmetic only ("(Ended)" tag); no active event on the site.
- Video carousel renders empty on the first slide in mobile shot.
