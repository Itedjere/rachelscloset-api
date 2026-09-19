# CLAUDE.md — Rachel's Closet

The single source of truth for building Rachel's Closet. Read it fully before
writing code. The approved design notes live at
`C:\Users\itedj\.claude\plans\drifting-napping-hickey.md`; this file records what
is actually true of the code, and is kept current as sections land.

> This replaced the Laravel skeleton's `CLAUDE.md`, which was a bootstrap stub
> telling an agent to install `laravel/boost`. Boost is **not** installed and
> should not be without asking — see §6.

---

## 1. What Rachel's Closet is

**Rachel's Closet Fashion House** (`rachelscloset.com.ng`) connects tailors with
customers in Nigeria.

**The problem is opacity, not payment.** A customer hands over cloth and money
and then has no idea whether work has started until she walks back to the shop
and finds out it has not. The tap-to-complete production checklist — where a
tailor ticks off each stage and the customer is notified — **is the product**.
Escrow, the directory and reviews are supporting structure.

Six problems from the brief, and what addresses each:

| Problem | Addressed by |
| --- | --- |
| Tailor promises a date, hasn't started | Step tracker + per-step notification |
| Customer won't collect or can't pay | `ready` state, collection deadline, reminders, optional deposit |
| Remote customer cannot be measured | Saved measurement history, plus a **paid measurement job** for a first order |
| Nearby tailor resents measuring for a rival | That measurement becomes a paid job she is hired for |
| Trust on upfront payment | Optional platform-held escrow |
| Tailors lack visibility | Fashion House directory, portfolio, QR business card |

### The constraint that outranks everything

**Many tailors read poorly.** That is why every step in the library carries an
admin-recorded voice note, why a measurement is a *photograph of a handwritten
book* rather than a form, and why the interface is built around tapping and
listening rather than typing and reading. Where that conflicts with tidiness,
tidiness loses.

It is also why sign-in is a **phone number and a six-digit PIN**. Nothing in this
project sends an SMS or requires an email address.

---

## 2. Tech stack

Same as BizyFarmers, deliberately — it is proven on the same hosting, and large
parts of it are ported rather than rewritten.

- **Backend:** Laravel 13, PHP 8.3, Eloquent, MySQL 8
- **Frontend:** React 19 + TypeScript + Vite, plain CSS design system
  (no Tailwind, no component library, no state library)
- **Auth:** Sanctum in **bearer-token mode**, not SPA cookie sessions
- **Payments:** Flutterwave only
- **Hosting target:** shared hosting. **No queue, no jobs, no worker** — see §5
- **Repos:** `rachelscloset-api/` and the sibling `rachelscloset-web/`
  (scaffolded in Section 2)
- **Local ports:** API on **8001**, web on **5174** (BizyFarmers holds 8000/5173)

---

## 3. Decisions already taken

Do not relitigate these without asking.

| Decision | Why |
| --- | --- |
| **Phone + 6-digit PIN** | A numeric keypad asks nothing of a poor reader. Six, not four: four is 10,000 guesses against a username that is a guessable phone number |
| The PIN lives in the `password` column | It *is* the password, only typed differently. A second column would mean two code paths for hashing, resetting and throttling |
| **Email optional; phone required and unique** | Most tailors have never had an address. The phone is the username |
| **Measurements are consent-gated** | A tailor sees them only with a granted link or a live order. Body measurements are the most sensitive data here |
| **Admins get no blanket bypass on measurement photos** | A deliberate break from BizyFarmers' `FileAccess`. Needs `measurements.view` *and* a real dispute |
| **Subscriptions are prepaid terms, not recurring billing** | See §5 |
| **A lapse hides her from the directory and nothing else** | Never hold a customer's data or a half-sewn order hostage to a tailor's bill |
| **The platform absorbs the Flutterwave charge** on escrow | The subscription is the revenue. Charging twice pushes tailors off-platform |
| **No drag-and-drop.** Up/down arrows | Touch DnD fights page scroll on a 5-inch screen, and "press and hold" has no affordance a non-reader can decode |
| **Photo-proof gate applies only to 4- and 5-star reviews** | It exists to stop rating inflation. A complaint held in moderation reads as censorship |
| **Rachel's Closet is the operator only** | It does not trade in the directory |

### Onboarding without email or SMS

A customer is invited by one of three free channels, because the tailor and
customer are usually together when measurements are taken:

1. **QR on the tailor's screen** — the customer scans it. Nothing is sent.
2. **WhatsApp share** — a `wa.me` deep link from *her own* WhatsApp. This is not
   the Business API and costs nobody anything.
3. **A spoken 6-digit claim code**, for when they are not together.

The same three are the **PIN reset route**, which is what stops a tailor with no
email address being permanently locked out.

---

## 4. Data model

Built so far. Tables for later sections are in the plan file.

### users
`id, name, email (nullable, unique), email_verified_at, password (nullable),
role (customer|tailor|admin), phone (unique), avatar_url, status
(active|suspended), suspended_until, remember_token, timestamps`

- **`email` is nullable and `phone` is not.** The phone is the username. It is
  stored normalised by `App\Rules\NigerianPhone`, and normalisation runs
  *before* the unique rule — otherwise the check compares unlike things and one
  number written two ways becomes two accounts.
- **`password` is nullable**, holding a bcrypt hash of the PIN. Null means a
  profile a tailor created from the shop floor that the customer has not yet
  claimed. An unclaimed profile cannot be signed into and has consented to
  nothing.
- `status` carries an **attribute default as well as a schema default**, because
  a schema default is invisible on a model that was just created — the row has
  it, the object does not.

### tailor_profiles
`id, user_id (unique), business_name, slug (unique), bio, location, state,
whatsapp_phone, avg_rating, orders_completed, timestamps`

- **`slug` is the address of her public profile and it is printed on a QR code
  on cardboard.** Changing it does not merely break a bookmark; it turns cards
  already in somebody's purse into a dead end with no way to reissue them.
  Generated once, never moved by a rename.
- `state` is separate from `location` so "find a tailor near me" is an indexed
  equality check rather than a LIKE over one string. Distance is the problem
  this platform exists to solve.
- `avg_rating` and `orders_completed` are denormalised and **recomputed from
  scratch**, never adjusted, so a deleted review cannot leave them drifting.

### notifications
`id, user_id, type, payload (json), read_at, created_at`

A plain table, not Laravel's polymorphic one: every notification here is
addressed to exactly one user, so the morph columns would be a constant cost
paid for a case that never arises. The payload is JSON because the shape differs
per type and none of it is ever queried — it is read back whole and rendered.

- **`created_at` only.** A notification is never edited.
- Indexed on `(user_id, read_at)` for the badge and the list, and on
  `created_at` alone because `notifications:prune` sweeps unread rows by age
  across all users.
- It grows fastest of any table here: the step tracker writes a row per stage,
  so one nine-step garment is nine rows, not one. Hence the prune.

### push_subscriptions
`id, user_id, endpoint (unique, 500), public_key, auth_token, device_label,
last_used_at, timestamps`

- **`endpoint` is unique across the table, not per user.** It identifies the
  browser, and one phone shared between several people is ordinary here — the
  second person must not start receiving the first person's order alerts.
- 500 characters because Chrome's FCM endpoints run past 200, and varchar(255)
  is already at MySQL's comfortable index limit.
- `device_label` is a guess from the user agent, for telling the shop phone from
  the one at home when unhooking it. Never identification.

### users.notification_preferences
`json, nullable`

Which groups a person wants **pushed**. A column rather than a table because it
is read on the way to sending every alert, when the user row is already loaded.
Null means "not chosen yet", treated as all on — so a group added in a later
section is not silently muted for everyone who saved preferences before it
existed.

BizyFarmers' equivalent governed *email*. Here it governs push, because nothing
in this project requires an address and most tailors have never had one.

### platform_settings
`id, key (unique), value, updated_at`

Seeded with `firstOrCreate`, so re-running never undoes an admin. Keys are
constants on the model: subscription price monthly/yearly, term lengths, grace
days, review proof threshold and minimum steps, default suspension days,
notification retention (read/unread) and `notifications_pruned_at`.

`notifications_pruned_at` is written by the prune command and read by nobody to
make a decision. It exists so a cron that has quietly stopped shows on the admin
dashboard rather than being discovered when the disk fills.

---

## 5. Two constraints that shape the architecture

**There is no queue and no jobs layer, on purpose.** The shared host cannot run
a worker, and a cron-driven one quietly stops. Anything that looks like it wants
background work should first be redesigned so it does not.

**Therefore subscriptions are prepaid terms.** A tailor *buys* 30 or 365 days
with one ordinary Flutterwave charge. Expiry becomes a computed fact rather than
an event — nothing has to happen at the moment a term ends — so a dead cron is a
missed reminder, not a broken invariant. It also means bank transfer, USSD and
Opay all work, where a recurring card charge fails silently on many Nigerian
debit cards. The reminder job is a courtesy; a sign-in banner backs it up.

The same reasoning already appears in `EnsureUserIsActive`: a fixed-term
suspension lapses **when the account is next used**, not on a schedule.

`QUEUE_CONNECTION` is therefore **`sync`**, not `database`. The Laravel skeleton
ships `database`, which would let anything marked `ShouldQueue` land in a table
nothing drains — silently, which is the worst shape this failure can take.
`ClosetNotification` deliberately omits the `Queueable` trait for the same
reason, and push is sent inside the request.

---

## 6. Ground rules

- Follow the data model above exactly. **Flag a new column or table before
  adding it**, then record the outcome here.
- **Do not add a dependency without asking.** Agreed so far:
  `minishlink/web-push` (added in Section 2), and `endroid/qr-code` for a later
  section. Nothing else — including `laravel/boost`.
- One section at a time. After each, summarise what was built and what to test
  manually, then stop.
- Comments explain **why**, not what. The ported code is heavily commented for
  exactly this reason, and it is the main thing that made it portable.
- Money is `decimal(14,2)` holding naira. Flutterwave takes naira directly, so
  there is no kobo conversion anywhere.

---

## 7. Build sections

**Ported from BizyFarmers:** 1 ✅ · 2 notifications + push + prune ✅ ·
3 files + `FileAccess` + recorder/player ✅ · 4 payments (Flutterwave, sandbox,
`ConfirmPayment`, webhooks) · 5 orders + escrow + payouts + refunds · 6 admin
shell, staff roles, settings, suspensions, public Blade site.

**New work:** 7 garment types + admin step library with voice notes ✅ ·
8 templates + arrow reordering ✅ · 9 orders assembled from steps, snapshotted ·
10 photo proof · 11 measurements + consent + claim flow · 12 completion + escrow
release · 13 two-way reviews + proof gate · 14 subscriptions · 15 Fashion House
directory · 16 QR + business card · 17 admin dashboard.

### Sections 7 and 8 — the step library — done

`garment_types`, `production_steps`, `step_templates`, `step_template_items`.
Admin library screen with the voice recorder from Section 3, the arrangement
editor with arrows, and a seeded starting library of 10 Nigerian garments and
13 stages. 21 new tests, 107 total.

- **Steps are a global library; templates bind them per garment.** "Cutting"
  means the same thing on a gown as on an agbada, and a per-garment library
  would mean an admin recording the same voice note a dozen times.
- **One table for both kinds of arrangement**, discriminated by `owner_id`:
  null is the admin's default, otherwise a tailor's own. Assembling an order
  asks one question — does she have her own, else the default — and two tables
  would make that a union for nothing. `StepTemplateController::templateFor()`
  is the only thing that decides whose arrangement a request may write, so
  there is no parameter to tamper with.
- **Ordering is the whole array, PUT.** A move, an insert and a removal are one
  idempotent request; the server renumbers densely from one. There is no "move
  up" endpoint, so a retry on a flaky connection cannot corrupt the order.
- **Arrows, never drag-and-drop**, for the reasons in the plan — and the play
  button on each row is the point: a tailor arranges the list by listening.
- **Retire, never delete.** `restrictOnDelete` on the template item's step
  makes the database enforce it. A retired step cannot be added to anything new
  but stays put where it already is.
- **The schema cannot express "one default per garment type"** — MySQL allows
  any number of rows where part of a unique key is NULL. It is enforced in
  `StepTemplate::defaultFor()`, the only thing that creates one, and the
  migration says so rather than pretending otherwise.

**A correction to Section 3's `FileAccess` note:** the step-voice-note resolver
matches only a step's *current* recording. Replacing one leaves the old file on
disk but nothing points at it, so it is unreachable — correct today, because
nothing references an old path yet. **Section 9 must widen that resolver to
accept any path an `order_step` holds**, or replacing a library recording will
silence that step for every customer part-way through an order, which is the
entire reason the old file is kept.

### Section 3 — done

`UploadLimits`, `StoredFile`, `FileController`, `FileAccess`, `ProfileController`
(name, optional email, avatar), `ConfigController`. On the web: `AudioPlayer`,
`VoiceNoteRecorder`, `AttachmentView`, `Avatar`, `AvatarUpload`, `useAttachment`,
`useUploadLimit`, a parameterised `image.ts`, and a profile page. 21 new tests,
86 total.

**No new tables.** Files are path columns on records later sections own, so
Section 3 adds none. `users.avatar_url` is the only one resolvable today.

- **Uploads are never reachable by direct URL.** They live on the private disk
  and stream through `FileController`; `storage:link` is deliberately not used,
  because symlinks are unreliable on shared hosting and routing every file
  through one place is what makes access control possible at all. The API
  therefore returns the *routed* URL (`StoredFile::url()`), never the path.
- **`FileAccess` refuses in both directions.** An unlisted prefix is refused,
  and a listed prefix with no resolver is refused too. `step-voice-notes`,
  `step-photos` and `measurements` are listed and refusing now; Sections 7, 10
  and 11 replace each resolver as the owning records arrive. A section that
  forgets gets a dead 404, not an open door.
- **`ADMIN_RESTRICTED_PREFIXES` exists before any measurement can.** BizyFarmers
  short-circuits the whole method on `isAdmin()`. Here `measurements` is exempt
  from that, today, with nothing yet to leak. Relaxing a closed rule in Section
  11 is a deliberate act; remembering to close an open one eight sections later
  is not. Tested.
- **`image.ts` caps by kind, not one number.** 512px for an avatar, **1600px for
  a document**, at higher JPEG quality — BizyFarmers' single 512 would destroy
  handwriting on a photographed measurement book, which is the whole point of
  that record. This is also the downscaling half of the storage policy; the
  retention half is still open.
- **An avatar's old file is deleted on replace. A step's voice note must not
  be.** Orders snapshot the path they were told, so Section 7 replaces by
  storing a new path and leaving the old file alone. Different rules,
  deliberately — the comment in `ProfileController` says so.

Three fixes on the way in, all outside the section but all caused by it:

- **`/me` returned `{data}` and the web client read `{user}`** — a Section 2 bug
  that signed you out on every page reload. Login and `/me` genuinely are
  different shapes, so they now have two types rather than one hopeful guess.
- **`/push/config` folded into `/config`**, which now also carries the upload
  ceiling. Two endpoints answering "what is this server like" was one too many.
- **`PUT /profile` distinguishes an absent email from a blank one.** Blank means
  "clear it"; absent means "not talking about it". A partial update must not be
  able to strip the one recovery route an account has. A test caught this.

**Not built yet, and deliberately:** nothing uploads a voice note or a photo to
the server — there is no record to attach one to until Section 7. The recorder
sits on the home page so a microphone can be tested on a real phone first.

### Section 2 — done

`notifications` and `push_subscriptions` tables, `users.notification_preferences`.
`ClosetNotification` (BizyFarmers' `BizyNotification`, renamed, with `Queueable`
dropped) driving three channels: `AppDatabaseChannel` always, `WebPushChannel`
and mail on appetite. `SendPushMessage`, `NotificationController`,
`PushSubscriptionController`, `NotificationCategories`, `notifications:prune` on
the 03:15 schedule, `push:vapid`, `notifications:test`. 37 new tests, 65 total.

`rachelscloset-web` scaffolded: Vite + React 19 + TS on 5174, `lib/api.ts`,
`lib/theme.ts`, auth context, `Layout`, `NotificationBell`,
`usePushNotifications`, `sw.js`, sign-in, the notification list and the alerts
page. One CSS token set.

Three things worth knowing:

- **The in-app record is not optional and no toggle can erase it.** Preferences
  govern push (and mail), never `AppDatabaseChannel`. It is the record of what
  happened to somebody's cloth and money. The alerts page says so in as many
  words, because it is the thing people assume wrongly about a settings page.
- **An unmapped notification type falls under `ACCOUNT`, which cannot be muted.**
  A type somebody forgot to categorise reaches people rather than going quietly
  missing. `NotificationCategories::MAP` is populated ahead of the sections that
  will send those types, so the settings page governs something real — **each
  later section confirms its own type string as it lands.**
- **Push degrades to nothing, never to an error.** No VAPID keys means
  `SendPushMessage` returns early and `GET /api/push/config` reports
  `enabled: false`, so the app hides the prompt rather than offering a button
  that cannot work.

**Not built yet, and deliberately:** no concrete notification subclasses beyond
`TestAlert` — those belong to the sections that cause the events.

### Section 1 — done

Laravel 13.32 + Sanctum 4 in bearer mode, MySQL `rachelscloset`, users with
role/phone/PIN, `tailor_profiles`, `platform_settings`,
register/login/me/logout, the `role` and `active` middleware, 28 tests.

**Not built yet, and deliberately:** no React app, no password reset, no
suspension history table, no staff roles. Those belong to later sections.

---

## 8. Local development

```
php artisan serve --port=8001        # in rachelscloset-api
npm run dev                          # in rachelscloset-web, serves on 5174
```

Vite proxies `/api` to `127.0.0.1:8001`, so the browser stays on one origin —
which a service worker needs.

**Web push, locally.** Keys are already in `.env`. Regenerating them silently
breaks every device already subscribed, which is why `push:vapid` refuses to
overwrite. On this machine it needs OpenSSL pointed at a config file:

```
OPENSSL_CONF=C:/Users/itedj/.config/herd/bin/php83/extras/ssl/openssl.cnf php artisan push:vapid
```

Push works on `localhost` without HTTPS. **It will not work over a LAN address**
— to test on a real phone, tunnel it, and on an iPhone add the site to the Home
Screen first, which is the only way Safari allows web push at all.

```
php artisan notifications:test 08030000003      # sends one harmless alert
php artisan notifications:prune --dry-run       # counts, deletes nothing
```

**Uploads** live in `storage/app/private` and are only reachable through
`GET /api/files/{path}`. Nothing is served by direct URL, so a browser fetching
one needs the bearer token — which is why the web client goes through
`fetchFileObjectUrl` rather than a plain `src=`.

Seeded accounts, all with PIN **482917**:

| Role | Phone |
| --- | --- |
| Admin | 08030000001 |
| Tailor (Mama Ngozi Couture) | 08030000002 |
| Customer (no email, on purpose) | 08030000003 |

Tests run against the **`rachelscloset_test`** MySQL database, not SQLite — the
schema uses MySQL enums and there is no SQLite driver on this machine.

```
php artisan test                     # in rachelscloset-api
./vendor/bin/pint --test
```
```
npm run typecheck                    # in rachelscloset-web
npx oxlint src
```

**This machine kills dev servers for memory.** It is HP Sure Click
(`Br-uxendm.exe`) holding about 2.4GB, not anything in this project. Check what
is actually holding memory before blaming the build.
