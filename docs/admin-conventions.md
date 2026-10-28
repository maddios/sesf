# Admin conventions

The admin lives in the SPA at `/#/admin/...` (AdminLayout) and talks to a JSON API under `/admin-api`.
Worked example: **sponsors** (copy these files, rename, adjust fields). Legacy `/admin` and `/admin2/*`
URLs 302 to `/#/admin/...` (`routes/web/admin_redirects.php`).

## Backend: add a resource `things`
1. **Route** `routes/web/admin/things.php` (auto-loaded by `routes/web/admin.php` inside
   `prefix('admin-api')->name('admin-api.')->middleware(['auth','admin','throttle:admin-write','audit.admin'])`).
   Judge-capable routes go in `routes/web/admin/judging/*.php` (`judge_or_admin`; add `judge_for_show` on show routes).
   Copy `routes/web/admin/sponsors.php`: index, store, show, update, destroy, POST `{id}/enable|disable|image`;
   `->whereNumber('thing')` on id routes; name them `things.*`.
2. **Controller** `app/Http/Controllers/Admin/ThingController.php` (copy `SponsorController`):
   - `index`: `AdminQuery::for($query, $request)->sortable([...], default: 'name')->searchable([...])
     ->filterable(['col' => ['eq','in'], ...])->filterUsing('show_id', fn ($q, string $v) => ...)->respond(ThingResource::class)`.
   - writes: validate with a FormRequest, save, then `AuditLog::record('thing.update', 'thing', [$id], AuditLog::diff($model, ['free_text_field']))`
     (`AuditLog::created($model)` for creates). Guarded columns (`is_enabled`...) via `forceFill`.
   - missing id: `->find($id) ?? abort(404, 'Thing not found.')`.
   - deletes that would orphan data: check references in a transaction and answer
     `409 {message, references}` ("... Disable it instead."); also catch `QueryException` 23000 as 409.
3. **Request** `app/Http/Requests/Admin/ThingRequest.php`: `authorize()` re-checks `can('admin')`; rules mirror the
   DB widths in `docs/legacy/schema-sesf_dev.sql` (`max:255`, text = 65535, smallint unsigned = 0..65535) plus
   `new Latin1Text` on latin1 text columns. Uploads: reuse `AdminImageRequest` + `App\Services\Images\ImageStore`.
4. **Resource** `app/Http/Resources/Admin/ThingResource.php`: explicit fields (admin may see internal fields such
   as `internal_notes`); ISO dates; counts that drive the UI (e.g. `in_use`).

## API contract (what the kit expects)
- List: `GET /admin-api/things?page=1&per_page=25&sort=name&direction=asc&search=x&filter[col]=v&filter[col][op]=v`
  ops `eq | like | in (a,b or op[]=) | between (2 values) | null (1/0)`; `per_page` <= 100.
  Answer `{data: [...], meta: {page, per_page, total, last_page}}`. Unknown sort/filter/operator => 422.
- Show/create/update/action: `{data: {...}}` (create 201). Delete: 204.
- Errors: 422 `{message, errors: {field: [..]}}`; 409 `{message}`; 401/403 `{message}`; 404; 419 CSRF; 429 throttle.
  `mapAdminError()` also understands legacy `400 ["msg"]` and `400 {field: ["msg"]}`.

## Permissions, audit, limits
- Admin = `users.type_id` 3 (`admin` middleware, Gate `admin`). Guests get 401 JSON, members/judges 403.
- `audit.admin` adds a request id (`X-Request-Id`) and, for any successful write that did not call
  `AuditLog::record()`, logs a generic `http.<method>` entry. Prefer explicit entries.
- Audit log: `storage/logs/audit-YYYY-MM-DD.log`, one JSON line per write (channel `audit`, not in the default
  stack). Values of sensitive fields (password, token, email, phone, address, notes...) are logged as `[redacted]`.
- `admin-write` limiter: 120 writes/min per admin; reads are not limited. CSRF applies (web group).
- Delete entries carry the deleted row's non-sensitive fields as `{old, new: null}` (`AuditLog::deleted`). Money
  columns (`Money2`) are logged as decimal strings ("12.30").
- Admin on-behalf writes through member endpoints (`event/registration`, `event/extra` with `user_id`) are audited
  as `registration.save_on_behalf` / `registration.extra_on_behalf`.
- Another administrator's email cannot be changed and a reset cannot be sent for them (403); each admin manages
  their own account.

## Impersonation ("log in as")
- The session stores `impersonator_id` and `impersonated_id` (`ImpersonationSession`). The marker counts only while
  the signed-in user is `impersonated_id`; every login clears it (`ClearImpersonationOnLogin`), and `stop` with a
  marker that does not match ends the session.
- Logging out while impersonating does not rotate the member's remember token (the real member stays signed in
  on their own devices).
- `block.impersonated` refuses, with 403 and an `impersonation.blocked` audit entry: password change and reset,
  account info (email), account deletion, Vanilla forum SSO (`login/vanilla`), waiver sign links and signing
  (`waiver/{show}/sign-url`, `waiver_sign/t/*`, `waiver_sign/s/*`), private messages (`member/message`) and
  selling a car (`member/sell-car`). Everything else the member can do is allowed and audited as
  `impersonation.write`.

## Frontend
1. **Registry**: append one line to `resources/js/admin/registry.ts` in its group block:
   `{ key: 'things', label: 'Things', group: 'Event', path: 'things', component: () => import('../pages/admin/things/ThingsPage.vue'), minLevel: 3 },`
   That creates route `/#/admin/things` (name `admin-things`), the sidebar link and the dashboard shortcut.
2. **Page** `resources/js/pages/admin/things/` (copy `pages/admin/sponsors/`):
   - `things.ts`: row type, `useAdminResource<Thing, ThingInput>('things')`, `FIELDS: AdminField[]`, `COLUMNS: AdminColumn<Thing>[]`, `toForm/toInput`.
   - `ThingsPage.vue`: `useAdminList({ fetch: api.list, defaults, filterKeys })` (state in the hash query as
     `?page&sort&search&f.<key>`) + `AdminDataTable` (slots `cell-<key>`, `actions`, `filters`, `toolbar-end`),
     `AdminFormModal` (schema or slots; 422s land on fields; dirty guard), `ImageUploadModal`, `useConfirm()`
     (`danger`, `typedConfirmation: 'DELETE'`), `useToast().success(...)` after every write.
3. Look and layout (legacy admin, `resources/css/admin.css`, loaded by AdminLayout which sets `body.admin-ui`):
   dark legacy top bar, grouped left menu (a drawer with backdrop below 768px), `AdminPageHeader` (title bar with the
   page buttons in slot `actions`, e.g. "New thing"), `AdminDataTable` (compact striped rows, sticky header row, sort
   marks, first and actions columns pinned while a wide table scrolls, `@row-click` opens the edit dialog,
   `:active-filters="list.activeFilters.value"`; the `filters` slot is a bar on desktop and a bottom sheet on phones;
   each filter is `<div class="w-48"><label for>..</label><control/></div>`), row actions as `AdminIconButton`
   (`icon`, `label` = accessible name and tooltip, `variant` danger for deletes), `AdminFormModal` (two columns from
   768px, `wide` and `section` on `AdminField`, required asterisk via `AdminFormField`, phone sheet with sticky
   Save/Cancel). BaseButton is restyled to compact Bootstrap 2 buttons inside the admin (30px, sm 24px; on touch
   36px with a 44px hit area): never add `size="lg"` or fixed big heights. Every dropdown is `components/ui/BaseCombobox.vue`
   (static `options` or async `search`/`resolve`; RelationSelect wraps it for lookups). Dialogs use `BaseModal`
   with `sheet`.
   The former /admin2 pages (check-in, T-shirts, waivers, reports, expenses, judging) use the `a2-*` classes that copy
   the old Bootstrap 2 look: `a2-page`, `a2-legend`, `a2-well`, `a2-table` + `a2-cards` (cards when the content is
   narrower than 700px; every td needs `data-label`), `a2-scroll` + `a2-sticky-col` for wide reports, `a2-label-*`
   badges, `a2-btn-*`, `a2-alert-*`, `a2-accordion` on `<details>`, `EventFilter`, `HandoffToggle`.
   Reference pages: sponsors (lists) and tshirts (admin2 look). Judges (level 2) see only the judging pages: the
   dashboard redirects them to `/admin/judging`, the menu and breadcrumb hide everything else.
4. Other kit parts (`components/admin/`): `RelationSelect` (lookup endpoints answer `{data:[{id,label}]}` for
   `?search=` and `?ids=`, see `LookupController`), `ReorderableList` (emits `reorder` with ids; Move up / Move down icon buttons, drag on desktop), `MoneyInput`
   (decimal string), `DateInput`/`DateTimeInput` (server formats, Eastern time note), `BooleanToggle`, `StatusBadge`,
   `RichTextEditor` (AdminField `type: 'richtext'`: Tiptap with a Raw/Visual switch; markup it cannot edit is kept as is;
   `maxlength` counts the HTML; render the stored HTML publicly only through `App\Support\RichText` / `HtmlSanitizer`).
   AdminLayout already mounts the toast region and the confirm host.

## Tests (required for every resource)
- Backend (class-based, `tests/Database/Admin/ThingAdminTest.php extends AdminTestCase`): authz matrix
  (anonymous 401, member 403, judge 403, admin ok) on every route; validation incl. widths (max and max+1);
  list sort/filter/paging and injection probes (`sort=name;drop table`, unknown operators => 422); delete
  conflicts (409); audit entries via `$this->auditEntries()` and no secret values in `$this->auditText()`.
  `AdminTestCase::actingAs()` flushes the session so one test can switch users. CSRF 419 and throttle are
  covered once in `AdminSecurityTest`.
- Frontend (`pages/admin/things/__tests__/ThingsPage.spec.ts`): `stubAdminFetch` + `listBody` + `stubXhr`
  from `resources/js/test/admin.ts`, `renderAt(Page, '/admin/things')`; render the page with
  `AdminConfirmHost` and `AdminToastRegion` (see the sponsors spec); assert requests, URL sync, 422 mapping,
  409 handling and `axe`.
- E2E: `e2e/12-admin-mobile.spec.ts` opens every admin page at 390x844 (no sideways scroll, one h1, menu button) and
  runs axe on all of them; add new pages to its list.
- Run: `COMPOSE_PROJECT_NAME=<you> ./scripts/test.sh tests/Database/Admin`, `npm run test`, `npm run lint`, `npm run typecheck`.
