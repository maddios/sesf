# Design tokens

Defined in `resources/css/app.css` (`@theme`), so each token is also a Tailwind utility
(`bg-brand`, `text-muted`, `max-w-site`, `shadow-card`, `p-panel`...). Source values: the legacy
site (`docs/design-baseline/tokens.json`); changed only where WCAG 2.2 AA required it.

## Colour
| Token | Value | Use |
|---|---|---|
| `brand` / `brand-dark` / `brand-tint` | #2a6496 / #1d4a70 / #e8f0f7 | headings, links, focus ring (6.3:1 on white) / hover / soft fill |
| `surface` / `panel` / `subtle` | #f8f8fa / #fff / #f2f2f4 | page background / header + content panels / hover and placeholder fill |
| `text` / `heading` / `title` / `muted` | #333 / #222 / #444 / #666 | body / section headings / page title / secondary text (legacy #777 and #888 were below 4.5:1) |
| `border` / `border-strong` | #e0e0e0 / #8c8c8c | decorative rules / form-control borders (3:1 non-text contrast) |
| `primary` / `primary-hover` | #006dcc / #0044cc | primary button (white text 5.2:1) |
| `default` / `default-hover` / `default-border` | #f5f5f5 / #e6e6e6 / #c4c4c4 | default button |
| `danger` / `danger-hover` | #c9302c / #a82824 | danger button; legacy #da4f49 darkened (4.0:1 to 5.3:1 with white) |
| `{success,danger,info,warning}-{bg,fg,border}` | e.g. #dff0d8 / #2b542c | alerts: legacy Bootstrap 3 backgrounds with darker foregrounds (all >= 4.5:1) |

## Type
- One stack: `--font-sans` = system UI stack (no webfonts; legacy Helvetica button stack removed).
- Body 16px, line-height 1.5.
- `text-h1` 2.25rem/1.2, class `.page-title` (bold, #444): legacy h1 38.5px.
- `.section-heading`: 17px semibold uppercase with a 2px `border` rule below (legacy h2 "EVENTS").
- `text-h3` 1.5rem, class `.item-title` (brand blue, semibold): legacy h3 24.5px.
- `text-h4` 0.875rem (legacy h4 14px); `text-nav` 1rem uppercase +0.04em (nav);
  `text-utility` 13px (LOGIN / CREATE ACCOUNT); `text-footer` 12px uppercase.

## Layout, radius, shadow
- `max-w-site` 1070px: header, content panel and footer share it (legacy container).
  Between 768px and 1102px they sit 1rem from the edges; below 768px they span the screen.
- Spacing: Tailwind 0.25rem scale, plus `gutter` (1rem, mobile padding) and `panel` (2.5rem, desktop panel padding).
- Radii: `rounded-sm` 4px (buttons, inputs, tiles), `rounded-md` 6px (panels, cards, video).
- Shadows: `shadow-header` (header bar), `shadow-panel` (content panel), `shadow-card` (cards), `shadow-overlay` (menus, modals).
- Breakpoint that matters: `md` 768px (hamburger menu below it, as the legacy 767px break).

## Base rules
- `body`: surface background, text colour, system font.
- Links: brand blue, underlined (legacy italic blue content links are gone); nav, buttons and tiles opt out with `no-underline`.
- Focus: every `:focus-visible` gets a 2px solid brand outline, offset 2px (>= 5.8:1 on white and surface).
- `prefers-reduced-motion: reduce` turns animations and transitions off.
- `.form-control`: inputs/selects (1px `border-strong`, 4px radius, danger border when `aria-invalid`).

## Components (`resources/js/components/ui/`)
BaseButton (primary/default/danger/link; sm/md/lg; loading), BaseCard, BaseAlert, TabNav, BaseModal,
BaseAccordion, DropdownMenu, BasePagination (`?page=` in the hash query), SafeImage + PlaceholderImage
(broken images become a neutral tile with the name as text), BaseSpinner.

## Admin (`resources/css/admin.css`)
The admin copies the legacy admin instead of the public site look: dark top bar (#5a6064 to #4a4f52), light grey
menu (#f4f5f5, current item #d9e4ec), gradient bars for page headers, table headers and the pager (#f3f4f4 to
#e2e4e5, text #4f5559), 13px table text, striped rows (#f6f7f7), and Bootstrap 2 buttons, labels, wells and alerts for
the former /admin2 pages. On desktop the admin root font size is 14px (denser rem-based spacing). Legacy colours that
failed WCAG AA were darkened in the same hue (btn-info #23819f to #1b6f8a, label-warning #a35a00, label-success
#3d7a3e, links #005580); each is noted in the stylesheet.
