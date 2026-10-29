# Waivers

Port of the legacy waiver backend (WaiverSignService, WaiverSignController, WaiverPortalController,
Waiver::getWaiverPDF). Pages are built separately and call these JSON endpoints. The legacy HMAC
`waiver_sign/{show}/{reg?}/{guest?}?token=` links are gone; tracking links and walk-in links remain.

## Endpoints (routes/web/waivers.php, web middleware, Laravel CSRF)

| Verb | Path | Notes |
|---|---|---|
| GET | `waiver_sign/t/{tracking}/prefill` | throttle `waiver-prefill`. `{ok,first,last,email,csrf}` (full email on file); 404 unknown, 409 signed |
| POST | `waiver_sign/t/{tracking}` | throttle `waiver-sign`. `{ok,thankYouUrl}`; 400 invalid, 404, 409, 419, 500 |
| GET | `waiver_sign/s/{showId}/prefill` | walk-in: one pending row per session+show; 429 past the per-IP hourly cap |
| POST | `waiver_sign/s/{showId}` | 403 when the session holds no unsigned walk-in waiver for the show |
| GET | `waiver_sign/thank-you/{tracking}/data` | signed only: `{ok,show,eventUrl,reference,signedAt}` |
| GET | `waiver-pdf?waiverId=` | admin only (401/403), inline PDF from storage, regenerated if missing, 404 JSON |
| POST | `waiver/{showId}/login` | throttle `portal-login`; delegates to `SessionController::login` (same messages, timing, session regeneration) |
| GET | `waiver/{showId}/sign-url[?guest_id=]` | `{url,person_name,template_id}`; 401/403/404/400 as legacy |
| GET | `waiver/{showId}/status` | the member and their guests: `status` = signed, not_signed or not_started |
| GET/POST | `waiver/{showId}/logout` | redirect to `/waiver/{id}` or a safe local `redirect`; JSON when asked |

POST bodies keep the legacy widget field names: `first_name`, `last_name`, `email` (required),
`signature_mode` (draw|type), `signature_base64` (PNG data URL in both modes), `signature_text`
(typed text, Latin letters, max 80), `signing_for_minor` + `adult_first_name`/`adult_last_name`,
`geolocation{lat,lng,accuracy}` (optional), `waiver_ui_mode=embedded`.

## Pages (app/Http/Controllers/Waivers/WaiverPageController.php, resources/views/waiver)

| GET | Path | Notes |
|---|---|---|
| GET | `waiver_sign/t/{tracking}` | sign page; 404 page for an unknown link; "already on file" page when signed |
| GET | `waiver_sign/s/{showId}` | walk-in sign page; 404 for an unknown show |
| GET | `waiver_sign/thank-you/{tracking}` | shell; 404 unless signed. Data from `.../data` |
| GET | `waiver/{showId}` | portal shell (login, status, sign links); 404 for an unknown show |

The pages hold no secret: the widget (resources/js/waiver) reads its own path and calls the JSON
endpoints. The signing widget keeps the legacy behaviour: signature modes draw and type (typed name
rendered in Allura to a 600x180 PNG on the client), legacy pen options, 3 attempts with a 120 s timeout
for transient failures, the legacy messages, and sessionStorage recovery hints.

## Services (app/Services/Waivers)

- `WaiverSignService`: `createPending`, `ensurePending` (locks the owner row), `createAdhoc`,
  `adhocForSession`, `prefill`, `sign`, `writePdf`, `pdfPathForAdmin`, `blankPdf`, `pruneStaleAdhoc`.
- `WaiverMailer::sendRequests(people)` / `sendReminders(show)` for the admin app: one email per
  person (waiver) / registration per `waivers.request_dedupe_minutes` (cache lock).
- `LegalDocument`: legal text copied verbatim from legacy; edit only with legal sign-off.
- `SignatureImage`: PNG-only validation and ImageMagick re-encode. `WaiverPdf`: dompdf + storage.

## Signing

`sign()` validates and re-encodes the signature, then in one transaction locks the waiver row
(`lockForUpdate`), refuses a signed row (`WaiverAlreadySigned`, HTTP 409), stores `waiver_data`,
`signed_at`, a new 16-hex `waiver_id`, and sets `has_signed` on the registration or guest. The PDF
is written after commit; if that fails the signature stands and an admin view regenerates it.

`waiver_data` (format `sesf-2`): first_name, last_name, email, signature_png_base64,
signature_mode, signature_text?, is_minor, guardian_name?, geolocation?, user_agent (255 chars),
ip_hash (HMAC-SHA256 with the app key, the IP is not stored), signed_at, show_id. It must fit the
TEXT column (64 KB). `SignedWaiverData` also reads the legacy native shape (internal_version 1) and
the 2019 WaiverForever API shape; the latter regenerates as a labelled reconstruction without a
signature image (none was kept locally, and the provider is never contacted).

## Security decisions

- Tracking ids: 128-bit random, never logged (logs use the row id). Unknown is 404 and signed is
  409: distinguishing them reveals nothing, since an id cannot be guessed; lookup is an indexed match.
- Prefill returns the waiver's own first/last and full email. The tracking id is the bearer secret and the link is emailed to that address, so this is accepted (legacy behaviour). 404 and 409 bodies carry no email. Prefill is throttled and never logged. Walk-in (`s/{id}`) prefill has no email on file, so it is empty.
- Signatures: data URL only, size cap `sesf.waiver_signature_base64_max_chars`, strict base64,
  PNG magic + finfo + getimagesize, 2000x800 pixel cap, ImageMagick decode under resource limits
  with the PNG decoder forced, metadata stripped. Stored legacy images are re-checked before render.
- dompdf (3.1.6): remote off, PHP and JS off, only `data://` allowed, chroot `resources/views/waivers/pdf`.
- PDFs: `config('waivers.storage_dir')/{waiver_id}.pdf`, temp file + rename, mode 0640, never public/.
- Legacy PDFs (the old app wrote `public/waivers/{id}.pdf`) are read from `waivers.legacy_public_dir`
  (`WAIVERS_LEGACY_DIR`, default `storage/legacy-waivers`; dev compose mounts the store's `public/waivers` there)
  and copied into storage on first admin view (`GET waiver-pdf`, admin only). They must never be web-served:
  `public/.htaccess` answers 403 for `/waivers/*`. On nginx add the same rule to the vhost of this app AND the
  legacy app while their `public/waivers` still exists:
  `location ^~ /waivers/ { return 403; }`
- Walk-in growth: a session reuses its unsigned row; new rows cost one hit of a per-IP hourly budget
  (`waivers.adhoc_creates_per_ip_per_hour`, env `WAIVER_ADHOC_CREATES_PER_IP_PER_HOUR`, default 300, sized for
  venue Wi-Fi or carrier NAT where many walk-ins share one IP; a trip is logged); `pruneStaleAdhoc()` deletes old
  unsigned rows.
