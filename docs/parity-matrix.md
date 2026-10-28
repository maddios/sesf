# Legacy parity matrix

Legacy: `/var/www/sesf/dev` (Laravel 4.2 + Angular). New: `/var/www/sesf-new`.
Snapshot: HEAD `5384ba9` plus the uncommitted working tree on 2026-10-26 06:18 UTC. Judging, check-in, T-shirts,
waivers admin and reports were being written while this was taken. Rows marked *(wip)* are uncommitted.

Status: **MISSING** = no equivalent. **CHANGED** = behaviour differs, so the owner must decide.
**present** = same behaviour. **present+** = same behaviour, improved. **N/A** = dead or unreachable in legacy.
Severity: **H** = lost or broken workflow, or data loss. **M** = lost admin or member capability that has a workaround.
**L** = cosmetic, a column, or a legacy URL.

## Counts

| Status | Count |
|---|---|
| MISSING | 22 (H 6, M 5, L 11) |
| CHANGED | 21 (H 2, M 5, L 14), plus the low-severity portal sign-redirect row in section 8 |
| present | 66 (sections 2-8: 58; admin sections 9-10: about 8) |
| present+ | 30 (sections 2-8: 23; admin: about 7) |
| N/A (legacy dead code) | 4 rows in sections 2-8, plus about 14 items listed in section 11 |
| Email templates (35 legacy files) | 31 mapped, 1 MISSING, 2 dead in legacy, 1 layout (all 6 partials ported) |

## 1. MISSING and CHANGED (highest severity first)

| # | Sev | Status | Area | Legacy capability | Legacy ref | New ref / note |
|---|---|---|---|---|---|---|
| 1 | H | CHANGED | Admin events | Saving an event from the SPA sets `waiver_id` to NULL, which silently turns the event's internal waiver off. The backend accepts `waiver_id`, but the form never sends it. Legacy edited it as a text field. | config/administrator/carShow.php `waiver_id` | app/Http/Requests/Admin/EventRequest.php `fields()` (`$data['waiver_id'] ?? null`); resources/js/pages/admin/events/events.ts `EVENT_FIELDS`/`toInput` (no waiver_id) *(wip backend)* |
| 2 | H | CHANGED | Admin registrations | The backend now requires `invoice_id`, `car_ids`, `amount_received_cents` and `discount_cents`. The SPA sends only donation, arrival and cancelled, so every edit and every cancel/reinstate returns 422. The UI also lacks the legacy fields: invoice #, cars (orderable), paid, amount owed, amount received, actual received, discount and discount note. | config/administrator/CarShowRegistration.php edit_fields | app/Http/Requests/Admin/RegistrationUpdateRequest.php *(wip)* vs resources/js/pages/admin/registrations/registrations.ts `toInput`, `cancelInput` |
| 3 | H | MISSING | Gallery | Whole gallery: browse by event, gallery items, breadcrumbs, create/manage galleries, upload, show/hide, default item, remove, checked-in list. The header "Gallery" link and the gallery link on event cards both return 404 today. | controllers/GalleryController.php (12 actions); views/angular/gallery.blade.php; routes `/gallery*` | layouts/AppHeader.vue:33, components/events/EventCard.vue:16 link to non-existent routes |
| 4 | H | MISSING | Check-in | Walk-in "Add registration" at the table: member search (unregistered members), cars from the garage, guests, shirts, extras, arrival, donation, then "Create & email". | Admin/Controllers/CheckinList.php `postCreateRegistrationMeta`, `postGarageCars`, `postCreateRegistration`; Sections/checkin_list.blade.php | app/Http/Controllers/Admin/CheckinController.php has index, check-in, check-out and extras only *(wip)* |
| 5 | H | MISSING | Check-in + email | "Email billing link" (pay-link email) and its template. | CheckinList.php `postPayLinkEmail`; views/emails/member/registration_pay_link.blade.php | No mailable or template |
| 6 | H | MISSING | Admin waivers / check-in | Staff open the signing page for one party member on site: the check-in waiver modal and the waivers-list "Open" link. | CheckinList.php `postWaiverSignUrl`; WaiversList.php `openWaiverSign` | Only emailed requests and reminders: app/Http/Controllers/Admin/WaiverController.php *(wip)* |
| 7 | H | MISSING | Waiver portal | Email-code sign-in on the portal. Legacy offered password or a 6-digit code. | views/waiver/portal.blade.php:430 (`/login/email-signin/*`) | resources/views/waiver/portal.blade.php and resources/js/waiver/portal-page.ts: password only *(wip)* |
| 8 | H | MISSING | Admin events | Upload event image, upload schedule PDF, release judging toggle, "Send judging results" action and waiver toggle. Upload, schedule-pdf and release routes exist with no UI. | carShow.php actions `upload_image`, `upload_schedule_pdf`, `send_judging_results`, fields `is_judging_released`, `waiver_id`; CarShowImageUpload, CarShowScheduleUpload | routes/web/admin/events.php `image`, `schedule-pdf`, `release-judging`; EventsPage.vue has edit, activate, sponsors and delete only. Results mail is in admin judging *(wip)* |
| 9 | M | MISSING | Admin events | Expense tracker per event: income/expense lines, totals against amount received and actual received. Legacy page had no auth; new needs admin. | controllers/ExpenseTracker.php, `/expense_tracker`, carShow.php action | app/Models/Expense.php only |
| 10 | M | CHANGED | Admin (frozennode defaults) | Frozennode let admins create and delete on every model (no `action_permissions`). Lost: create user (with password), create car, create and delete registration, create guest, create extra-registration. | config/administrator/*.php (no action_permissions) | routes/web/admin/users.php, cars.php, registrations.php, registration-guests.php, extra-registrations.php have no store (registrations also have no delete) |
| 11 | M | CHANGED | Admin users | Admin set a member's password (password + confirm). Now only "send password reset email". | user.php edit_fields `password`, `confirm_password` | resources/js/pages/admin/users/users.ts (no password); UserController `sendPasswordReset` |
| 12 | M | CHANGED | Admin registration on behalf | "Register for Event" and "Edit Registration" opened the full registration editor for the member (`/#/admin/register-user/:user/:show`). Now a modal with event + cars only. The full on-behalf editor exists (`/:event/e:id/register?user_id=`), but no admin page links to it. | user.php `register_for_event`; CarShowRegistration.php `edit_registration`; views/angular/admin_register_user.blade.php | pages/admin/users/RegisterForEventModal.vue; pages/registration/RegistrationPage.vue (`userId` prop) |
| 13 | M | MISSING | Waiver portal | "Share" sign link for a guest (navigator.share or clipboard). | WaiverPortalController `getShare`; views/waiver/share.blade.php | Portal shows a sign button only |
| 14 | M | MISSING | Admin waivers | Blank waiver PDF (print). `WaiverSignService::blankPdf()` exists, but no route uses it. | WaiversList.php `downloadBlankPdf` | app/Services/Waivers/WaiverSignService.php:378 |
| 15 | M | MISSING | Admin waivers | "Open generic waiver for this event" (walk-in link) button. | Sections/waivers_list.blade.php | Walk-in list exists; no link |
| 16 | M | CHANGED | Admin waivers | Bulk "Send waiver emails" to selected rows (select all / unselect all) is now one row at a time. The "Arriving" and "Date signed" columns are missing. | waivers_list.blade.php `users[]`, `send_waivers` | pages/admin/waivers/WaiversPage.vue `requestWaiver(row)` *(wip)* |
| 17 | M | CHANGED | Admin nav / legacy URLs | `/admin2/*` redirects point to paths the registry does not use (`check-in`, `members-report`, `events-report`, `extras-report`; the registry has `checkin`, `reports/members`, `reports/event-summary`, `reports/extras`). `/admin/{Config}/{id}` deep links go to the dashboard. The header "Judging" link goes to `/#/admin/judging`, which is not registered yet. | admin2 routes, frozennode URLs | app/Http/Controllers/Admin/LegacyAdminRedirectController.php `ADMIN2_SECTIONS`; resources/js/admin/registry.ts; layouts/navigation.ts `JUDGING_URL` |
| 18 | M | MISSING | Check-in | Per-registration "Send waiver reminder" in the check-in modal. Bulk reminders exist on the Waivers page. | CheckinList.php `postWaiverReminder` | — |
| 19 | L | MISSING | Member UX | First-login tutorial banner (show and dismiss). The API is ported; the UI is not. | views/nav.blade.php:47, angular/member.blade.php `memberTutorialController` | routes/web/account.php `tutorial/tutorial`; no Vue usage |
| 20 | L | CHANGED | Check-in | T-shirts column in the check-in list. Now only on the T-shirts page. | checkin_list.blade.php | CheckinRegistrationResource has no shirts |
| 21 | L | MISSING | Admin cars | Edit "registration" (Show) relation, VIN and Profile-link columns, filters for body, type and owner. Search covers model, nickname and VIN. | car.php | pages/admin/cars/cars.ts |
| 22 | L | MISSING | Admin users | Columns Date Registered, Date Deleted and Profile Link. The API has a `date_registered` filter; the UI does not use it. | user.php columns | pages/admin/users/users.ts `USER_COLUMNS` |
| 23 | L | MISSING | Admin registrations | Date Registered column; reassign show or user. | CarShowRegistration.php | registrations.ts |
| 24 | L | MISSING | Admin guests | Reassign registration or user; Registration ID and Date Added columns. | RegistrationGuest.php | registration-guests.ts |
| 25 | L | MISSING | Admin extra-registrations | Change which extra (showExtra); Date Registered column; filter by registration id (the API supports it). | extraRegistration.php | extraRegistrations.ts (quantity only) |
| 26 | L | MISSING | Admin agenda | Filter by Type. The API supports `type_id`; the UI does not expose it. | Agenda.php filters | AgendaPage.vue `filterKeys: ['show_id']` |
| 27 | L | MISSING | Routing | `/:carName/c:carId` car alias route. | angular/main.blade.php:273 | router/routes/members.ts has `/car/:id` only |
| 28 | L | MISSING | Shell | Datadog RUM snippet and `og:image` meta. RUM config exists, but the shell never injects it. | views/partials/datadog_rum.blade.php, wrapper.blade.php:8 | config/sesf.php `datadog.rum`; resources/views/app.blade.php |
| 29 | L | CHANGED | Email | Password reset subject "SESF Password Reminder" is now "Reset your password — Southeast Sharkfest". | UserAuth.php:151 | app/Mail/ResetPasswordMail.php:50 |
| 30 | L | CHANGED | Contact | The client `$log` attachment ("include log" checkbox) is dropped. Location is kept. | home/report_page.blade.php | app/Http/Controllers/Api/ContactController.php:17 |
| 31 | L | CHANGED | Pages | `/forum` embedded Vanilla page is now an external link (`SESF_FORUM_URL`). `/about-us` placeholder ("WE'RE THE BOMB") is dropped. | views/forum, views/about_us | layouts/AppHeader.vue |
| 32 | L | CHANGED | PayPal IPN | Any verb is now POST only (GET returns 405). This is documented. | routes.php `Route::any('paypal-ipn')` | routes/web/paypal.php, docs/paypal-ipn.md |
| 33 | L | CHANGED | Mail delivery | Legacy queued mail in a DB queue drained by cron (`ProcessDbQueue`). New uses `QUEUE_CONNECTION=sync` with no worker or scheduler, so bulk waiver mail runs inside the request. | commands/ProcessDbQueue.php, services/QueuedMailer.php | .env.example:51; routes/console.php (no schedule) |
| 34 | L | CHANGED | Judging | Judge login on the judging page (`POST judging/login/{id}`) is replaced by the site login with a redirect. | CarJudging `judgingLogin` | routes/web/judging.php; router/postLogin.ts *(wip)* |
| 35 | L | CHANGED | T-shirts | The dedicated print sheet view is replaced by `window.print()` of the page. | TshirtList `printSheet`, Sections/tshirt_print.blade.php | pages/admin/tshirts/TshirtsPage.vue:153 *(wip)* |
| 36 | L | CHANGED | Admin impersonation | "Log In" as any user, including admins, is now refused for admin targets and disabled users. Accepted as an improvement. | user.php `login` | ImpersonationController |
| 37 | L | CHANGED | Admin cars | New confirm/unconfirm and car image actions (additions). Legacy had no admin car image upload. | — | routes/web/admin/cars.php |
| 38 | L | CHANGED | Admin agenda/locations/show sponsors | Order Up / Order Down actions are replaced by drag reorder. The `sort_order` edit field is gone. | Agenda.php, Locations.php, CarShowSponsors.php actions | ReorderableList.vue, `*/reorder` routes |
| 39 | L | CHANGED | Admin show sponsors | The global "Show Sponsors" list (show, sponsor, type, sort, filters show/type) is replaced by a per-event dialog that adds the primary sponsor. | CarShowSponsors.php | pages/admin/events/EventSponsorsDialog.vue |
| 40 | L | CHANGED | Admin reports | Extras summary gains export, which legacy did not have. Event-summary export keeps the SpreadsheetML .xls. | ExtraList.php | routes/web/admin/reports.php *(wip)* |
| 41 | L | CHANGED | Waiver legacy HMAC links | `waiver_sign/{show}/{reg?}/{guest?}?token=` was removed. Legacy never issued these tokens (no caller of `WaiverSignToken` creates them), so nothing is lost. | WaiverSignController `getSign`/`postSign` | docs/waivers.md |
| 42 | L | MISSING | Admin events list | Event `id` column. The new list shows name, short name, start, status and registrations. | carShow.php columns | events.ts `EVENT_COLUMNS` |
| 43 | L | MISSING | Admin car admin `type` column | Legacy defined the `type` key twice (Type, then Body), so only Body showed. New shows both. Listed for completeness only. | car.php | cars.ts |

(Rows 36 to 43 are low-impact notes, mostly accepted improvements. Their Status column decides whether they count as CHANGED or MISSING.)

## 2. Public SPA

| Capability | Status | Legacy | New |
|---|---|---|---|
| Home: overview video | present | home/common.blade.php | pages/HomePage.vue |
| Home: latest event + primary sponsors | present | home/showList.blade.php, `home/events?limit=1` | HomePage.vue, composables/useHomeEvents.ts |
| Home: additional sponsors (6) + all-sponsors link | present | home/sponsorList.blade.php | HomePage.vue |
| Home: past videos carousel (5 videos, same order) | present+ (accessible) | home/common.blade.php rn-carousel | components/home/VideoCarousel.vue |
| Home: PayPal donate (hosted button 9LUSEKNJDPB4A) | present | home/common.blade.php | HomePage.vue |
| Home: Facebook feed | N/A (commented out in legacy) | home/common.blade.php | — |
| Contact / comments & bugs modal (anonymous name+email) | present+ (rate limits) | HomeController `postReport` | components/contact/ContactModal.vue, Api/ContactController |
| Events list active/inactive + card meta (location, reg open/close, dates) | present | events/event_list.blade.php, CarShowList | pages/events/EventsPage.vue, EventCard.vue |
| Event info + agenda + locations + map | present | event/common, agenda, EventProfile `getInfo/getAgenda/getLocations` | EventInfoPage.vue, EventMap.vue |
| Event schedule (text or PDF) | present | event/schedule.blade.php | EventSchedulePage.vue |
| Event directions | present | event/directions.blade.php | EventDirectionsPage.vue |
| Event sponsors by tier | present | event/sponsors.blade.php | EventSponsorsPage.vue |
| Event news (stub in legacy) | present | event/news.blade.php ("news") | EventNewsPage.vue (empty state) |
| Event judging tab | present | `/:event/e:id/judging` | EventJudgingPage.vue |
| Event members / cars tabs | present | event/members, event/cars | MemberDirectory/CarDirectory with `showId` |
| Members directory: show + name filters, last-name search for members only, ordering, paging | present | MemberList | Api/MemberDirectoryController, pages/members/MemberDirectory.vue |
| Cars directory: filters + paging | present | CarList | Api/CarDirectoryController, CarDirectory.vue |
| Member profile (public) + attended events + car list | present | member/primary, memberInfo, carList, attendedEvents | pages/members/MemberProfilePage.vue |
| Private message modal | present+ (5/min) | MemberProfile `postMessage` | MemberContactModal.vue, Api/MessageController |
| Car profile `/car/:id` | present | CarProfile `getProfile` | pages/cars/CarProfilePage.vue |
| Privacy policy | present | privacy_policy | PrivacyPolicyPage.vue |
| Register account (+ member and admin new-account mails) | present+ (Turnstile, validation) | MemberProfile `postRegister` | pages/register/RegisterPage.vue, Api/RegistrationController |
| 404 handling | present+ | `App::missing` text | NotFoundPage.vue |

## 3. Auth

| Capability | Status | Legacy | New |
|---|---|---|---|
| Password login | present+ (throttle) | UserAuth `login` | Auth/SessionController |
| Session check (`login/authenticate`) | present | UserAuth `authenticate` | SessionController |
| Logout (any verb) | present | UserAuth `logout` | SessionController |
| Email code request / verify | present | `postEmailSigninRequest/VerifyCode` | Auth/EmailSigninController |
| Magic link consume | present | `getEmailSigninConsume` | EmailSigninController `consume` |
| Forgot / reset password (password_reminder) | present (subject changed, #29) | `ForgotPassword/ResetPassword` | Auth/PasswordResetController |
| Update password | present+ (blocked while impersonating) | `UpdatePassword` | SessionController |
| Vanilla jsConnect SSO | present+ | UserAuth `vanilla` | Auth/VanillaSsoController |
| Post-login redirect (forum, waiver, judging) | present | login.blade.php | router/postLogin.ts |
| Roles / route guards (judge 2, admin 3) | present | filters, `min_user_level` | Policies/Gates.php, router/guards.ts |

## 4. Member account

| Capability | Status | Legacy | New |
|---|---|---|---|
| Account info (requires password) | present | `postAccountInfo` | components/account/AccountInfoSection.vue |
| Address / location (+ geocode) | present+ | `getLocation/postLocation` | AddressSection.vue, Jobs/GeocodeUserAddress |
| Email preferences (3 flags) | present | `get/postPrivatePreferences` | EmailPreferencesSection.vue |
| Profile photo upload | present+ (re-encode) | `postProfileImage` | ProfileImageSection.vue, Services/Images |
| Change password | present | `login/update-password` | PasswordSection.vue |
| Delete account | present | `postDeleteAccount` | DeleteAccountSection.vue |
| Garage list / add / edit car / car photo | present | member/cars, car/new, car/:id/settings, new-image | GaragePage, CarFormPage, CarImagePage |
| Sell car | present | `postSellCar` | GaragePage.vue, Api/CarController `sell` |
| Billing: invoice list, invoice, PayPal button, check instructions | present+ | member/billing, invoice.blade.php | pages/billing/BillingPage.vue, InvoiceDocument.vue, PayPalPayment.vue |

## 5. Event registration

| Capability | Status | Legacy | New |
|---|---|---|---|
| Cars + judging type per car (single type auto-picked) | present | event/register_car.blade.php | components/registration/CarsSection.vue |
| Guests (adult/child, email) | present | register_guests | GuestsSection.vue |
| Shirts | present | register_shirts | ShirtsSection.vue |
| Extras | present | register_extras | ExtrasSection.vue |
| Arrival date / time | present | register_arrival_time | ArrivalSection.vue |
| Donation (incl. $5) | present | register_donation | DonationSection.vue |
| Cancel registration | present | register_cancel | CancellationSection.vue, CancelRegistrationModal.vue |
| Summary / totals | present+ (server quote endpoint) | registration_summary | RegistrationSummary.vue, `event/registration/quote` |
| Extra landing page by auth_key | present | `/:event/e:id/extra/:key`, `getExtra/postExtra` | pages/registration/ExtraPage.vue |
| Admin on behalf (`user_id`) | present+ (audited) | `registrationUserId` | EventRegistrationRead/WriteController |
| Invoice allocation / pricing | present+ (deadlock-safe allocator) | RegistrationPayment | Services/Registration/* |

## 6. PayPal IPN

| Capability | Status | Legacy | New |
|---|---|---|---|
| Postback verify | present+ (exact VERIFIED, before storing) | PayPalIPN | Services/PayPal/IpnVerifier |
| Status handling (paid, pending, insufficient, underfund, denied, weird, invalid, fatal) | present+ (cents math, refunds) | PayPalIPN:200-325 | IpnProcessor |
| Receiver email / invalid item_number notices | present+ (+currency, test_ipn, idempotency) | PayPalIPN:147,172 | IpnProcessor |
| Customer + admin notification mails | present (legacy pending/underfund templates were misnamed and would have failed) | emails/IPN | app/Mail/Ipn/* |

## 7. Email templates (legacy `app/views/emails`, 35 files)

| Legacy | New | Status |
|---|---|---|
| IPN/admin_notify_{denied,fatal,insufficient,invalid,paid,pending,underfund,weird} (8) | emails/ipn/admin_{same} | present |
| IPN/customer_notify_{denied,insufficient,paid} (3) | emails/ipn/customer_{same} | present |
| IPN/customer_notify_underfind | emails/ipn/customer_underfund | present+ (legacy sent `customer_notify_underfund`, a file that did not exist) |
| IPN/customer_payment_pending | emails/ipn/customer_pending | present+ (legacy sent the non-existent `customer_notify_pending`) |
| IPN/invalid_email | emails/ipn/admin_email_mismatch | present |
| IPN/paypal_invalid_invoice | emails/ipn/admin_invalid_invoice | present |
| IPN/postback_fail | emails/ipn/admin_postback_failed | present |
| IPN/customer_notify_failed | — | N/A (never sent) |
| IPN/paypal_notify_paid | — | N/A (never sent) |
| admin/new_account, admin/report_page | same paths | present |
| auth/email_signin, auth/reminder | same paths | present |
| layouts/html | same | present |
| member/new_account, member/private_message | same | present |
| member/judging_result | same *(wip)* | present |
| member/waiver_request, member/waiver_reminder | emails/waivers/request, reminder | present |
| member/registration_pay_link | — | **MISSING** (#5) |
| partials/{badge,button,footer,header,kv,raw_block} (6) | same | present |

## 8. Waivers

| Capability | Status | Legacy | New |
|---|---|---|---|
| Tracking sign page + prefill | present | WaiverSignController `getSignByTracking/getPrefillByTracking` | Waivers/WaiverPageController, WaiverSignController *(wip pages)* |
| Signature modes draw / type | present | sesf-waiver-widget.js | resources/js/waiver/sign-page.ts, typed.ts |
| Signing for a minor (guardian name) | present | widget | form.ts |
| Retries, offline detection, pending recovery | present | widget.js:752-899 | retry.ts, recovery.ts |
| Widget modes embedded / standalone | present (embedded only posted) | _widget.blade.php `waiver_ui_mode` | SignWaiverRequest |
| Geolocation | N/A (legacy never captured it; new accepts it as optional) | — | docs/waivers.md |
| Thank-you page | present | `getThankYou` | thank-you-page.ts |
| Walk-in (ad hoc per show) | present+ (per-IP budget, session reuse) | `*ByShowAdhoc` | WaiverSignController `prefillAdhoc/signAdhoc` |
| Portal password login / logout / status list / sign link | present | WaiverPortalController | WaiverPortalController, portal-page.ts |
| Portal `/waiver/{id}/sign` redirect | CHANGED (JSON `sign-url` + client redirect) | `getSign` | `sign-url` |
| Portal email-code login | **MISSING** (#7) | portal.blade.php | — |
| Portal share | **MISSING** (#13) | `getShare` | — |
| Signed PDF download (admin) | present+ (storage, regenerated on demand, legacy PDFs imported) | Waiver `getWaiverPDF`, WaiversList `downloadSignedPdf` | WaiverPdfController |
| "Regenerate missing PDFs" button | present+ (automatic on view) | WaiversList `updateWaivers` | WaiverSignService `pdfPathForAdmin` |
| Blank PDF | **MISSING** (#14) | `downloadBlankPdf` | — |
| Request / reminder mails (dedupe) | present+ | WaiversList `sendWaivers/sendWaiverReminders` | WaiverMailer, Jobs/Waivers *(wip)* |

## 9. Admin: frozennode configs (menu: user, car, carShow, Agenda, Locations, CarShowSponsors, CarShowRegistration, RegistrationGuest, sponsor, extras, extraRegistration)

| Config | Columns | Filters | Edit fields | Actions | Overall |
|---|---|---|---|---|---|
| user | present except date registered, date deleted, profile link (#22) | id/first/last/email: present (search + API) | present except password (#11) | login: present+ (#36); register_for_event: CHANGED (#12); disable / re-enable: present | CHANGED |
| car | VIN and profile link missing (#21) | model, VIN: search; body, type, user: MISSING in UI | user, type, body, nickname, year, model, VIN: present; registration: MISSING | create/delete: delete present, create MISSING (#10) | CHANGED |
| carShow | present (id dropped, #42) | — (new: is_active) | name, short_name, description, short_description, is_schedule_pdf, schedule, prices, dates, clone_from: present; is_active: present (actions); has_image, waiver_id, is_judging_released: UI MISSING (#1, #8) | upload_image, upload_schedule_pdf, send_judging_results: UI MISSING (#8); expense_tracker: MISSING (#9) | H gaps |
| Agenda | present | show: present; type: MISSING (#26) | present; sort_order replaced by drag (#38); wysiwyg is now sanitized HTML textarea | order up/down: present+ (drag) | present+ |
| Locations | present | show: present | present (+ is_primary) | order up/down: present+ | present+ |
| CarShowSponsors | present in a per-event dialog | show/type | show, sponsor, type: present | order: present+ | CHANGED (#39) |
| CarShowRegistration | present except Date Registered (#23); adds total/owed/status | id, show, user: present (search invoice, show, member) | see #2 | edit_registration: CHANGED (#12); create/delete: MISSING (#10) | H gaps |
| RegistrationGuest | present except reg id and date added (#24) | first/last/email/registration: present (search + API) | first, last, email, type: present; registration/user reassign: MISSING | delete: present; create: MISSING | CHANGED |
| sponsor | present (+logo, shows count) | show: present (+enabled) | name, description, short_description, URL, enabled, internal_notes: present | upload_image: present (ImageUploadModal); enable/disable: present+ | present+ |
| extras | present (+registrations, status) | show, type: present (+enabled) | type, show, name, description, min, max, enabled, price: present (+auth_key) | create/delete: present | present+ |
| extraRegistration | present except date (#25) | extra: API; registration: API, not in UI | num_extras: present; showExtra: MISSING (#25) | delete: present; create: MISSING | CHANGED |
| Judging, CarShowRegistrationJudging, showSponsor, Sponsors | — | — | — | — | N/A (not in the menu; reachable by URL only; Judging is replaced by admin judging *(wip)*) |

## 10. Admin: `/admin2` custom sections

| Section | Legacy capabilities | Status | New |
|---|---|---|---|
| checkin_list | list (reg #, name, paid, waivers, T-shirts, check-in), search by name or #reg, check-in with override, undo | present *(wip)* | Admin/CheckinController, pages/admin/checkin |
|  | extras quantity edit | not in legacy (removed from new check-in) | `checkin/.../extras/{id}` |
|  | waiver reminder, waiver sign URL, pay-link email, add registration, garage cars, T-shirts column | MISSING (#4, #5, #6, #18, #20) | — |
| tshirt_list | stock per type, hand-outs, ad hoc sale record/delete, availability, print | present *(wip)*; print CHANGED (#35) | Admin/ShirtInventoryController, pages/admin/tshirts |
| waivers_list | roster (registrant + guests, signed, PDF, checked in), filter signed, walk-in list, send requests, send reminders | present *(wip)*; bulk select CHANGED (#16) | Admin/WaiverController, pages/admin/waivers |
|  | open sign, generic walk-in link, blank PDF, arriving and date-signed columns | MISSING (#6, #14, #15, #16) | — |
| judging_list | entries per show (car #, owner, model, year, body, type), add/remove entry, unjudged list, judging types, judges assign/remove (admin), Questions/Answers/QR links, QR PDF, Draw Winners, judge access per show | present in API + pages *(wip)*; admin page not in the registry | routes/web/admin/judging/judging.php, routes/web/judging.php, pages/admin/judging/* |
| member_list | members filtered by show, paid and 3 preferences | present+ (+export) *(wip)* | reports/members |
| event_summary (+ export .xls) | totals (registrants, guests, paid, extras, T-shirts) + per-registration rows | present *(wip)* | reports/event-summary (+totals, export) |
| extra_list | per extra: total and paid quantities | present+ (+export) *(wip)* | reports/extras |
| Section export (`?export=1`) | SpreadsheetML .xls | present | ReportExportController |
| Access: judges see judging_list only for assigned shows | present | middleware `judge_for_show` |

## 11. Legacy dead or unreachable code (N/A, intentionally not ported)

HomeController2, UserController, AjaxHandler, AngularControl, CarController, EventController, GalleryList (not routed);
UserAuth `getLogin/getRequestPassword/getResetPassword` (not routed); EventProfile `getAttendees` (empty);
`/member/shows` (no backend method); `events/list-filters` (no caller); IPN `customer_notify_failed`,
`paypal_notify_paid` (never sent); frozennode Judging, CarShowRegistrationJudging, showSponsor, Sponsors (not in the menu);
WaiverSignToken HMAC links (never issued); `/template/*`, `/angular/*.js` (Angular plumbing). The new app still serves
some of these JSON routes for contract parity (`event/attendees`, `events/list-filters`, `member/car-list`,
`member/member-preferences`, `member/attended-events`), but no Vue page calls them.

## 12. Planned backlog checklist (from the legacy route table)

- [ ] **Gallery**: `gallery/*` (getCheckedIn, getCarShowList, getList, getBreadcrumb, getInfo, getItems, postItems,
      postCreate, postShowHide, postUploadFile, postDefaultItem, postRemoveItem) and SPA `/gallery`,
      `/gallery/e:eventId/:galleryId?`, `/gallery/create/:eventId/:galleryId?`, `/gallery/manage/:galleryId` (#3)
- [ ] **Judging** *(wip)*: register the admin judging page in `registry.ts` (minLevel 2); judging/{id}, judgingData,
      judgingSubmit, judging_answers, judging_draw, judging_qr_code (+ show sheet); release toggle and results mail;
      decide on `judging/login` (#34)
- [ ] **Check-in** *(wip)*: walk-in registration (#4), pay-link email + template (#5), on-site waiver sign (#6),
      per-row waiver reminder (#18), shirts column (#20)
- [ ] **T-shirts** *(wip)*: confirm print layout matches tshirt_print (#35)
- [ ] **Waivers admin** *(wip)*: open sign (#6), walk-in link (#15), blank PDF route (#14), bulk send (#16),
      arriving and date-signed columns
- [ ] **Waiver portal**: email-code login (#7), share (#13)
- [ ] **Reports** *(wip)*: registered; fix legacy `/admin2` redirect paths (#17)
- [ ] **Events admin UI**: waiver toggle and the null-wipe fix (#1), image upload, schedule PDF upload, judging
      release, send results (#8)
- [ ] **Registrations admin UI**: match the new backend fields (#2); link to the full on-behalf editor (#12)
- [ ] **Expense tracker** (#9)
- [ ] **Frozennode create/delete parity**: decide per model (#10); admin set password (#11)
- [ ] **Tutorial** welcome banner (#19)
- [ ] **Low**: car alias route (#27), Datadog RUM + og:image (#28), admin columns and filters (#21-#26), forum and
      about-us decision (#31), queue worker / scheduler for mail and `pruneStaleAdhoc` (#33)
