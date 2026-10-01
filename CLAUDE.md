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
- **Frontend:** React 19 + TypeScript + Vite for the signed-in app; **Blade for
  the public site**. Plain CSS design system, no Tailwind, no component library,
  no state library, **no animation or carousel library**
- **Auth:** Sanctum in **bearer-token mode**, not SPA cookie sessions
- **Payments:** Flutterwave only
- **Hosting target:** shared hosting. **No queue, no jobs, no worker** — see §5
- **Repos:** `rachelscloset-api/` and the sibling `rachelscloset-web/`
  (scaffolded in Section 2; **one** stylesheet, not BizyFarmers' two)
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
| **Arrows always; drag only beside them, from a grip** | Touch DnD fights page scroll on a 5-inch screen, and "press and hold" has no affordance a non-reader can decode. So a drag starts only on a visible grip, the only element with `touch-action: none`, and every draggable list keeps up/down arrows that need no gesture. See `useReorder` |
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

### orders, payments, payouts, webhook_events

`orders` — `reference` (unique, speakable: no 0/O/1/I, because it gets read
down a phone line), customer, tailor, garment type, `amount`, `deposit_amount`,
`escrow` (per order — §3 calls escrow optional), the status machine
(`pending_payment → in_progress → ready → collected → completed`, plus
`cancelled` and `disputed`), `collection_deadline`, and `steps_total` /
`steps_completed` / `steps_with_photo` which only Section 9 writes.

`orders.collection_reminded_days` — how near the deadline she was last
reminded, counting down. The analogue of `subscriptions.last_reminder_days`,
nullable with no default because null means never reminded and a default of 0
would read as "already told her it is overdue".

`payments` — carries `purpose enum('order','subscription')` and a nullable
`subscription_id` from birth so Section 14 needs no ALTER on a table holding
real money. **`provider_reference` is unique**; that is the idempotency.

`payouts` — one per order (unique `order_id`, so a double release is a
constraint violation). No commission column.

`webhook_events` — raw payload, signature validity, outcome. Written even for a
bad signature, because somebody probing the endpoint is worth seeing.

`tailor_profiles` gains `bank_code`, `bank_account_number`,
`bank_account_name`, `transfer_recipient`, all nullable: a payout stays
`pending` until they exist rather than failing.

### tailor_customer_links, measurement_sets, measurement_values, claim_tokens

`tailor_customer_links` — `unique(tailor_id, customer_id)`, `status`
(granted|revoked), `granted_at`, `revoked_at`.

**Consent is per tailor, never global.** One switch reading "share my
measurements" would mean a customer wanting a second opinion from one tailor
has handed her file to every tailor on the platform, with no way to tell which
of them looked. Revoked rows are kept rather than deleted, because "she took
this back" is a different fact from "this never happened", and keeping it
makes re-granting one tap.

`measurement_sets` — `customer_id`, `recorded_by`, `photo_url`, `label`,
`notes`, `taken_on`.

**The photograph is the record**, not a fallback. A tailor writes numbers in a
paper book with a biro, and asking somebody who reads poorly to transcribe
twelve of them into labelled boxes produces either wrong numbers or none. A
set is never edited: a body changes, and last year's numbers are how you know
by how much, so a new measuring is a new row.

`measurement_values` — `measurement_set_id`, `label`, `value`, `unit`,
`position`. Optional, for the tailor who does want to type them. `value` is a
**string**: tailors write "38", "38 1/2" and "38-39", and a decimal column
turns a working record into a form somebody fails to fill in. `label` is free
text, not an enum — an agbada needs none of bust/waist/hip.

`claim_tokens` — `user_id`, `token_hash` (unique), `code_hash`, `purpose`
(claim|pin_reset), `expires_at`, `used_at`, `issued_by`.

**Flagged and agreed in Section 11**, since the plan's data model did not name
it. Both credentials are stored hashed and returned in plaintext exactly once,
at issue; six digits is a million guesses, safe only because the endpoint is
throttled and the row expires in 48 hours. The `purpose` column is there so
PIN reset reuses this table rather than growing a second parallel mechanism.

### reviews

`order_id`, `direction` (customer_to_tailor|tailor_to_customer), `author_id`,
`subject_id`, `rating`, `body`, `status` (published|held),
`proof_ratio_snapshot`, `published_at`, `approved_by`, `approved_at`.
`unique(order_id, direction)`.

**Two-way**, because the platform has two problems: a tailor who does not
start, and a customer who will not collect. A directory that rated only
tailors would ask them to carry all the risk of meeting a stranger.

`unique(order_id, direction)` is the analogue of
`payments.provider_reference` — the constraint is the rule, so a double
submit is a violation rather than a second opinion.

**`proof_ratio_snapshot` is snapshotted even when the gate passes.** It is the
basis on which a decision was made, and an order's photographs can be added
to afterwards. Same reasoning as an order snapshotting its step labels.

### portfolio_items

`tailor_id`, `order_id` (nullable), `uploaded_by`, `path`, `caption`,
`position`, `hidden_at`.

**Two sources, one table.** The tailor uploads her own so a profile is not
empty on the day she joins; the customer uploads to a finished order — she
wore the dress to a party and photographed it — and those land in the tailor's
gallery. The second source is the good one: a garment being *worn* is what a
prospective customer wants to see, it is evidence in a way a studio shot is
not, and it needs no consent machinery because the customer choosing to upload
IS the consent.

Deliberately not `order_step_photos`, which Section 10 confined to the two
people on an order — those are one customer's cloth photographed
mid-construction and they stay private.

`hidden_at` is the tailor's control over her own shopfront: she cannot delete
a customer's photograph, but she can take it off her gallery. A public page
somebody else can post to unconditionally is not one anybody would print on a
business card.

**Public files.** These are the only uploads `FileAccess` does not know about
— `/api/files/portfolio/...` is refused. They are served by their own
unauthenticated route, because a stranger scanning a QR code has no account.
Still resolved to a *visible* row, so a guessed path finds nothing and hiding
a photograph takes it offline rather than merely off the page.

### subscriptions, subscription_terms

`subscriptions` — one per tailor. `current_period_end`, `grace_ends_at`,
`status`, `last_reminder_days`.

**THE TIMESTAMPS ARE THE TRUTH; `status` IS A LABEL.** Every decision — above
all "is she in the directory" — compares timestamps, so a status left stale by
a cron that stopped cannot list a tailor whose term ended. `scopeCovering()`
is the only correct way to ask this in a query; if you are writing
`where('status','active')`, that is the bug the class exists to prevent. There
is a test that sets the label to `active` on an expired term and asserts she
is still absent from the directory.

`subscription_terms` — the ledger, never edited. **`payment_id` is UNIQUE**,
and that is the whole idempotency story: a replayed webhook, a refreshed
return page, and the two racing collapse onto one term. The direct analogue of
`payments.provider_reference` and `reviews.order_id`.

`days` and `amount` are **snapshotted**, so changing a price or a term length
never moves days somebody already bought. `payment_id` is nullable so an admin
can grant days without a charge — MySQL permits many NULLs in a unique index,
which is exactly the behaviour wanted.

`payments` gained a nullable `plan` column. It has carried `purpose` and
`subscription_id` since Section 4 against this section; the plan was the one
thing missing, and inferring it from the amount breaks the moment two prices
match or one changes mid-payment.

### disputes, and orders.received_at

`disputes` — `order_id`, `raised_by` (nullOnDelete: the record of the
complaint outlives the account), `reason`, `status enum(open|resolved)`,
`outcome enum(refunded|released|withdrawn)`, `refunded_amount`,
`resolution_note`, `resolved_by`, `resolved_at`, timestamps. Indexed on
`(order_id, status)` and on `status` alone, which is the admin queue.

**One open dispute per order is not expressible here** — MySQL has no partial
unique index — so it is enforced by a lock and a re-check in
`DisputeController`, and the migration says so.

`orders.received_at` — nullable, after `collected_at`. **They are two
different events**: the tailor handing the garment over, and the customer
having it. For a garment collected in the shop they are the same instant and
both are written at once; for one posted, `received_at` stays null until she
says it arrived, and `ESCROW_RECEIPT_BACKSTOP_DAYS` (21) stops a silent
customer stranding the tailor's money.

### platform_settings
`id, key (unique), value, updated_at`

Seeded with `firstOrCreate`, so re-running never undoes an admin. Keys are
constants on the model: subscription price monthly/yearly, term lengths, grace
days, review proof threshold and minimum steps, default suspension days,
notification retention (read/unread), `support_phone` (the number a
locked-out person rings — the only non-numeric row) and `notifications_pruned_at`.

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
  `minishlink/web-push` (Section 2) and `endroid/qr-code` (Section 11, for the
  claim QR; Section 16 widens it to printed cards). Nothing else — including
  `laravel/boost`.
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

**New work:** 3.5 design language ✅ · 7 garment types + admin step library with voice notes ✅ ·
8 templates + arrow reordering ✅ · 9 orders assembled from steps, snapshotted ✅ ·
10 photo proof ✅ · 11 measurements + consent + claim flow ✅ · 12 completion + escrow release ✅ · 13 two-way reviews + proof gate ✅ · 14 subscriptions ✅ · 15 Fashion House
directory ✅ · 16 QR + business card ✅ · 17 admin dashboard ✅.

**After 17, unnumbered:** PIN reset ✅ · disputes + the receipt clock ✅ ·
collection reminders ✅ · adding a customer from the shop floor ✅.

### Adding a customer from the shop floor — done

`POST /api/customers` (`CustomerController`), `FoundCustomerResource`, the
"Nobody has that number yet — add her" step on the new-order screen, and the
claim invite on the order page. 9 new tests, 430 total. No schema change.

**The hole it closes:** Section 11 built claiming, but nothing ever created a
profile to claim — unclaimed customers existed only in test factories. A
tailor could not take an order from anybody not already on the platform, and
the lookup's 404 said creating one "comes with the measurements section".

- **A name and a number, no PIN.** The same unclaimed profile the rest of the
  code already understands: it cannot sign in and has consented to nothing.
- **Nothing records which tailor added her, deliberately, and that is why no
  column was needed.** Every rule about an unclaimed profile is already per
  measurement set (`recorded_by`) or per invite (`issued_by`), because two
  tailors may each meet the same walk-in.
- **An existing customer is returned, not refused, and not renamed.** That is
  what the tailor wanted, it makes a double tap harmless, and one tailor's
  spelling must not overwrite the name she chose. The unique index settles a
  race; the loser gets the winner's row.
- **A number held by a tailor or admin is refused**, so an order cannot end up
  addressed to somebody's staff account.
- **The invite is on the order page, not only on the new-order screen.** An
  unclaimed customer cannot pay or follow the tracker, so the order page shows
  the tailor the QR / WhatsApp / code card for as long as that is true —
  `OrderResource` now says `claimed` for each party so it can.

**The spoken code, given a door.** The six digits are a claim code, NOT her
PIN, and two things made that route unusable:

- **Nothing linked to `/claim`.** The sign-in page now has "My tailor read me
  six numbers", and the invite card gives the tailor the whole sentence to
  say, address included (`claim_page` in the invite response).
- **Two rows of six boxes on one screen** — the numbers she was read and the
  numbers she chooses — told apart only by labels. The code route is now two
  steps: `POST /api/claim/check` answers with her own name, and only then does
  she see the PIN boxes. It consumes nothing and refuses with the claim's one
  message. **It shares the named `claim` rate limiter with `POST /api/claim`**
  (`AppServiceProvider`): a second door onto the same six-digit guess must not
  be a second budget of guesses. Tested.
- Side effect worth knowing: the code route used to skip the consent sentence
  ("this lets her keep seeing your measurements"), because it had no preview
  to name the tailor from. Step two now shows it, as the link route always did.

### Forgot PIN — a number to ring, and the same two steps

`/forgot`, `PlatformSetting::SUPPORT_PHONE` (seeded 08152070480), `POST
/api/reset/check`, `App\Support\WhatsApp`. 18 new tests, 448 total.

- **There is still no self-service reset, and this does not add one.** The
  reset is admin-issued for the reasons in the PIN reset section. What was
  missing was the door: nothing on sign-in mentioned a forgotten PIN, and
  nothing anywhere said who to ring. "Forgot your PIN?" now leads to a page
  whose whole content is that number — tap to call, or WhatsApp — and "I have
  my six numbers".
- **The number is a setting, not a constant**: a changed SIM must not need a
  deploy, or the platform advertises a dead line to exactly the people who
  cannot get in. It is the first non-numeric row on the admin Settings screen
  (`phone: true`), validated as a Nigerian number and stored normalised. It is
  in the public `/api/config`, because the only person who needs it cannot
  sign in; unset is `null` and the page falls back to "contact Rachel's
  Closet" rather than showing an empty button.
- **The reset page got the claim page's two-step split**, with `/reset/check`
  sharing a named `reset` limiter (6/min, tighter than claim's 8, because the
  code opens an account with money in it). The admin's reset card now gives
  the whole sentence to say, address included (`reset_page`).

**A bug this found, pre-existing in four places: every `wa.me` link to a
person was wrong.** Phones are stored `0803…` and wa.me needs `234803…`;
stripping non-digits gave `wa.me/0803…`, which opens a chat with nobody. That
was the "Message her on WhatsApp" button on a tailor's public page — the one
a customer taps after scanning a printed card — as well as both dispute
contacts and the admin's reset link. `App\Support\WhatsApp::to()` is now the
only way to build one, and `PinResetTest` had been asserting the broken form.

### The tracker, redesigned — and a pass over the customer's side

`OrderTracker.tsx` rebuilt; the old `.tracker-*` row styles are gone.

- **A summary that answers "has she started?" in one glance**: a ring with
  the count in it, "Now: {stage}", and **the date the last stage was
  finished**. That date is the honest answer — a tracker silent for a week
  says so instead of looking merely unfinished. Before payment it says what
  happens once it is paid.
- **One card per stage, on a line.** Finished: filled green tick, the date,
  the photographs, and a solid green segment below. Current — the first gap,
  even when later stages were ticked out of order: ringed, gently pulsing,
  lit, with instructions and a "Now"/"Next" badge. Upcoming: dashed and
  quiet, but **keeping its voice note**, because listening is how a stage is
  understood. Instructions show only on the current stage; a future stage
  gets no camera slot, which would invite a photo of work not started.
- **The tailor gets "Mark as done" on the current card** as well as the
  circle — the labelled button is what somebody new finds first. Three words,
  `nowrap`: "I have finished this" wrapped onto three lines.
- **Measured at a 310px viewport** (cheap Androids are ~320): the card was
  left 175px, so the phone layout takes its room from gutters and never from
  the 48px marks.
- **No end cap.** A "Ready to collect" marker below the list duplicated the
  stage most arrangements already end with.

On the way through:

- **Garments is closed to customers** at the route (`RoleRoute`) and on the
  dashboard, not only in the sidebar. And server-side: `PUT`/`DELETE
  /garment-types/{id}/steps` now require tailor or admin — before, a
  customer's save created an arrangement owned by somebody who sews nothing.
  Reading stays open; her tracker plays the same recordings.
- **The sidebar has a Measurements section** for customers, before Account.
- **Three endpoints returned a raw storage path as `avatar_url`** — the
  consent screen (both row kinds), the admin People list and order reviews —
  so those avatars were broken images. All go through `StoredFile::url` now,
  pinned by `AvatarUrlTest`.
- **`PinInput` dropped digits typed quickly.** Each box rebuilt the PIN from
  the `value` of the last render, so two keystrokes before a re-render lost
  one, and the focus handler shoved the caret back a box: six correct digits
  became three and sign-in said "wrong PIN". It now reads a ref updated on
  every keystroke. Found by the browser tool typing at machine speed;
  verified the same way.

### Sections 4 and 5 — orders and the money spine — partly done

`orders`, `payments`, `payouts`, `webhook_events`, bank columns on
`tailor_profiles`. Flutterwave v3 gateway, `SandboxGateway`,
`PaymentGatewayManager`, `ConfirmPayment`, `OrderController`,
`PaymentController`, the webhook endpoint. 34 new tests, 141 total.

**4 and 5 are one section, not two.** `payments.order_id` is a foreign key and
`ConfirmPayment` is about orders from end to end — there is no coherent
payments-only unit to build.

- **Flutterwave v3, verified not assumed.** As of September 2026 v3 is the
  stable production API with no deprecation announced; v4 is public beta and
  changes the auth model to OAuth client credentials. This resolves the open
  risk in the plan file. When v4 lands it is `FlutterwaveGateway` that changes.
- **No commission, anywhere.** `payouts` has no commission column, unlike
  BizyFarmers. The subscription is the revenue and the Flutterwave charge is
  absorbed; `gross` and `net` differ only after a refund.
- **`provider_reference` is unique, and that is the whole idempotency story.**
  A replayed webhook, a browser returning twice, and the two racing each other
  all collapse onto one row. Tested.
- **Money is compared with `bccomp` on decimal strings.** Casting naira to a
  float to compare it is how a payment of exactly the right amount reads as a
  penny short.
- **`status` is not mass-assignable on `Order`,** so no request body can move
  an order through its states. Transitions use `forceFill`, which makes each
  one visible at the call site. A test asserts a `status` in the create body is
  ignored. **This bit me while building it**: `update(['status' => …])` failed
  silently four times before the tests caught it.
- **The tailor opens the order, not the customer.** They are in the shop
  together when measurements are taken; a customer-initiated quote-and-accept
  round trip would be for a conversation that already happened out loud.
- **`ready` is the state BizyFarmers has no equivalent of** and the one the
  brief was missing. It starts the collection deadline, which is what makes
  "the customer never came back" something the platform can act on.
- **A payer with no email gets a synthesised one.** Flutterwave requires the
  field and most people here have no address. It is
  `{phone}@no-mail.rachelscloset.com.ng` — a domain that resolves nowhere, so
  nothing pretends a receipt will arrive.
- **The sandbox gateway cannot run outside local.** `PaymentGatewayManager`
  requires both the flag and the environment, because its webhook handler
  checks no signature — a `.env` copied to a server would otherwise turn the
  endpoint into one that takes unsigned instructions from anybody.

**The order UI is built**: list with status filters, the create form, the
detail page with pay and the state transitions, and the return-from-provider
page. Driven end to end against the sandbox gateway — deposit paid, order into
progress, payout recorded, tailor notified, ready, collected.

**Finding a customer is by exact phone number, never a search.** A tailor able
to browse or name-search every customer would have a directory of other
people's clients, which she does not need and they did not agree to. The phone
is the username; the customer is in the shop and reads it out. A number nobody
has is added on the spot — see "Adding a customer from the shop floor".

### Money out — release, refunds, bank details

`TransferGateway` + `FlutterwaveTransfers` + `SandboxTransfers` +
`TransferManager`, `ReleasePayout`, `RefundOrder`, `BankAccountController`,
`payouts:release-due` on the 06:00 schedule, and the UI for all of it.
17 more tests, 158 total.

- **Escrow release never depends on a cron.** `Order::escrowReleaseDue()` is
  computed from `collected_at` and a setting, so the tailor can release her own
  money the moment it is due. The scheduled sweep is a courtesy — if it stops,
  she taps a button instead of being stranded. Same reasoning as a suspension
  lapsing on use.
- **The customer confirming is the fast path.** Once the person who paid says
  the garment is right there is nothing left to wait for, so confirming
  completes and releases at once. The waiting period is the fallback for
  silence.
- **Release is independent of everything except the order.** Tested against a
  suspended tailor: money owed is owed. §3 says a lapse hides her from the
  directory and nothing else.
- **No bank details is `pending`, not `failed`.** Completing an order must
  never fail because somebody has not finished a form. The money stays
  recorded as owed with a reason attached.
- **A transfer reference is derived from the payout id alone, with no
  timestamp.** Flutterwave rejects a duplicate reference, so a retry after a
  timeout cannot pay twice — the failure we least want is the one where we are
  unsure whether the money left.
- **Bank details are resolved twice**, once to show her the name and again on
  save. Trusting the name the client posts back would make the confirmation
  step theatre. The stored `transfer_recipient` is checked against the stored
  account before any transfer, so an account cannot be edited after
  verification and silently paid.
- **Refunds are admin-only and partial by default**, and refuse once a payout
  has been released — taking money back from the customer after paying the
  tailor means the platform pays twice. That is a conversation, not a button.

### The admin order screens

`Admin\OrderController` (list, search, detail) and the refund UI. 8 more tests,
166 total.

- **A deliberate widening of the ordinary scoping.** `OrderController` is
  scoped to the two people on an order and an admin is on none of them, so
  refunding would have meant guessing with somebody else's money. `Admin  OrderController` exposes every order and its payments. It is **not** a
  general bypass — measurement photographs stay closed to admins without a
  specific permission and a real dispute, per FileAccess.
- **Search takes the two things somebody ringing up can actually give you**: a
  reference, or either party's phone number. A term that is not a valid
  Nigerian number stays a reference search rather than also sweeping the user
  table — there is a test for that.
- **A refund is confirmed in two steps.** It moves real money and cannot be
  undone from the screen, so the resulting figures — what the customer gets
  back, what the tailor will then be owed — are shown before anything is sent.
  Same reasoning as resolving a bank account before paying into it.
- **Every reason the server would refuse is said up front**, in the panel,
  instead of after a click: already paid out, or nothing paid yet.

**Not built yet, and deliberately:** only `order_paid`, `payout_released` and
`order_refunded` notify — `order_ready` and `collection_reminder` come with the
sections that trigger them.

### The app shell

`Layout` is a header, a grouped sidebar and the page.

- **The header carries only what is true on every screen** — the brand, the
  theme, the bell and the avatar. Navigation lives in the sidebar, so the
  header cannot slowly fill with links as the remaining sections land, which is
  exactly what it was starting to do.
- **The sidebar lists only routes that exist.** Sketching the rest of the plan
  in it — Orders, Measurements, Reviews — would teach people the navigation
  lies. Each section adds its own entry as it lands.
- **Below 900px it becomes a drawer** and a menu button appears in the header.
  That button is the one thing added to the header on a small screen; a docked
  sidebar on a 5-inch phone leaves nothing for the page.
- **The avatar opens a menu** with the account links and sign out, closing on
  Escape, on a click elsewhere and on navigating. It uses `pointerdown` rather
  than `click` for the outside dismiss, because a click fires after release —
  by which time a link outside the menu has already begun navigating.

### Collection reminders — done

`orders:remind-collection` on the 09:30 schedule, `CollectionReminder`,
`orders.collection_reminded_days`, `COLLECTION_REMINDED_AT`. 18 new tests, 410
total.

**The brief's second problem, finally acted on.** "Customer won't collect or
can't pay" was answered by "`ready` state, collection deadline, reminders,
optional deposit" — and `ready` has existed since Section 5, the deadline
since Section 5, and `collection_reminder` has been mapped in
`NotificationCategories` since Section 2. Nothing ever sent it. A tailor's shop
filled with finished garments and her only recourse was to keep ringing.

- **A courtesy, like the other three scheduled commands.** The deadline is a
  date on a row and the admin dashboard counts overdue orders by comparing it,
  whether or not this runs. Same shape as `payouts:release-due` and
  `subscriptions:remind`, same reason.
- **`collection_reminded_days` is the exact analogue of
  `subscriptions.last_reminder_days`** and exists for the same failure: a cron
  firing twice in a day is the ordinary way these are misconfigured. It counts
  down, and the command sends the smallest milestone still owed, so four days
  of downtime produce one nearest warning rather than a stale burst.
- **ZERO IS TERMINAL, and this is the decision that matters most.** An
  uncollected garment never resolves itself the way a lapsed subscription
  does — nothing ever moves it out of `ready`. A command with no last milestone
  would notify the same two people every night indefinitely, which is how
  somebody learns to ignore all of them, including the ones about her next
  order. Said once, then the order sits on the admin dashboard where a person
  can ring. There is a test that runs the command seven nights in a row.
- **The deadline day is not overdue, and this was a bug I shipped and caught.**
  With milestones `[3, 1, 0]`, `$left` reaches 0 at midnight on the day she may
  still collect, so the message told her the garment "was due" on the very day
  it was due. Overdue now begins the day after: the list is `[3, 1]` and zero
  is assigned only when `$left < 0`.
- **NO MESSAGE SAYS "TOMORROW".** Every one names the date. A command that is
  explicitly allowed to miss a night cannot use a relative word — it is a lie
  waiting for the first time the cron does not run — and a date is what she
  would write down anyway. Pinned by a test that sweeps every milestone for
  "tomorrow" and "today".
- **One class, two audiences.** At the overdue milestone the tailor is told as
  well, and told something different: the customer's phone number. Same answer
  the dispute screen gives — when a thing is stuck, the platform's job is to
  make the phone call possible. She is not nudged at three and one days, where
  it would be noise about an order already on her own list.
- **"Won't collect OR CAN'T PAY" is one problem, not two.** An outstanding
  balance is named in the message, days ahead. Somebody who owes money and does
  not know it walks to the shop, finds out at the counter and goes home again.
- **A fourth health row**, and the dashboard test now asserts the *count* of
  them on purpose: a command added to the schedule without a heartbeat is one
  that can stop without anybody noticing, which is the failure that panel
  exists to prevent.

### Disputes and the receipt clock — done

`disputes`, `orders.received_at`, `ESCROW_RECEIPT_BACKSTOP_DAYS`. The customer
side, the admin queue, and the freeze that ties them to the money. 29 new
tests, 392 total.

**THE PLATFORM DOES NOT ADJUDICATE.** Raising a dispute freezes the money and
puts both phone numbers in front of a person; the calls decide, and the screen
carries out what was agreed. That is how the business already resolves these,
and a structured evidence exchange with uploads and categories would be
inventing a process nobody asked for and few of these users could work. The
whole customer-facing surface is one button and one sentence, because the next
thing that happens is somebody telephoning her.

- **`Order::escrowReleaseDue()` is the single place the platform asks whether
  money may move**, so adding one `hasOpenDispute()` check there froze all four
  release routes at once — the nightly sweep, the tailor's own button, the
  customer's "I am happy", and the order's completion. That is the payoff of
  Section 5's decision to compute release rather than schedule it.
- **Raising a dispute does not move the order's status.** The `disputed` state
  has been in the enum since Section 4 and is still unused: the order genuinely
  is collected, and rewriting its state would lose that. The freeze lives in
  `escrowReleaseDue()`, which is the only thing it needs to reach.
- **`received_at` exists because a parcel in the post is not a garment in her
  hands.** The escrow clock used to run from `collected_at`, which is the
  tailor tapping a button — fine in a shop, wrong for a customer four hundred
  kilometres away who has a tracking number and a week to wait. The clock now
  runs from receipt, with `ESCROW_RECEIPT_BACKSTOP_DAYS` (21) so a customer who
  never taps anything cannot strand the tailor's money for ever.
- **The tailor declares which, and this was a design flaw I nearly shipped.**
  Making *every* order wait for a receipt tap would have taken a local tailor
  from three days to twenty-one — exactly the downside that was rejected when
  this was chosen. Two pre-existing `PayoutTest` tests caught it. `POST
  /orders/{order}/collected` now takes `posted`, and sets `received_at` to
  `now()` when she handed it over: a customer standing in the shop has the
  garment the moment it is tapped.
- **Only the customer may raise one, and only once she has the garment.** A
  garment still being made is a conversation with the tailor, and the tracker
  is what that conversation is for. Admins can raise one *on her behalf* after
  a phone call — recorded against her, not against the admin, with
  `wasOpenedByStaff()` so the screen can say it was taken down over the phone
  rather than implying she tapped a button. She may be ringing precisely
  because she cannot work the app.
- **Not once a payout has been released.** There is nothing left to freeze, and
  clawing it back means the platform pays twice — which `RefundOrder` already
  refuses. She can still ring; that is a conversation, not a button.
- **One open dispute per order cannot be expressed in MySQL** (a partial unique
  index is not available), so it is a `lockForUpdate` and a re-check in the
  controller, with the migration saying so rather than pretending otherwise.
  Two taps on a slow connection is the ordinary way a second one would appear.
- **Three outcomes, because they are the three things that can happen to the
  money**: it goes back, it goes on, or nothing moves. A partial refund is the
  first with an amount, and the balance goes to the tailor **in the same act** —
  one branch covers the split that most real negotiations end in.
  `Dispute::WITHDRAWN` is for the call where the parcel turned up after all.
- **Both parties are told the same thing.** There is no version of the outcome
  each side hears separately, and the admin's note is shown to both. The tailor
  is also told the moment a dispute opens: she is about to get a phone call,
  and finding out from the platform first is kinder than being ambushed by it.
- **Both phone numbers are in the admin payload with `wa.me` links, and the
  held amount beside them.** Settling one is two calls; that is the whole point
  of the screen, so the numbers are the loudest thing on each card and dial on
  tap. The complaint itself is quieter than they are.
- **Open disputes lead the admin dashboard's attention list**, above held
  reviews and failed payouts: it is the only row where money is frozen and two
  people are waiting on a call only a person can make.

**A latent CSS bug this surfaced**: `.confirm-panel` is capped at the viewport
by `.lightbox-inner` but had no `overflow`, so a cap with nothing to scroll
simply hides the bottom. The settle panel measured 1095px in a 695px window and
its "Do it" button could not be reached at all. The pause and PIN-reset panels
share the class and were one short screen away from the same thing.

**Still open, and now buildable:** §3 promises admins reach measurement
photographs with `measurements.view` *and* a real dispute. `MeasurementAccess`
still returns a flat no for admins; a real dispute now exists to test against.

### PIN reset — done

The hole the rest of the design would otherwise leave. 17 new tests, 363
total.

Sign-in is a phone number and six digits; nothing on this platform sends an
SMS or requires an email address, so the ordinary "reset link in your inbox"
does not exist. Without a replacement, a tailor who forgets her PIN is locked
out of her own business permanently.

**All three channels cost nothing per use, and that is the point rather than a
happy accident.** A recovery route with a per-message bill is one that gets
switched off when money is tight, which is exactly when somebody can least
afford to lose her livelihood to a forgotten number.

- six digits read down an ordinary phone call — the admin's airtime, not a
  platform bill;
- a `wa.me` deep link from the admin's **own** WhatsApp. Not the Business
  API: no per-conversation fee, no Meta account, no approval;
- a QR on screen, for the rare case they are in the same room.

Nothing here uses SMS (₦3–5 a message), the WhatsApp Business API (billed per
conversation), or email (near-free and useless, since most tailors have no
address).

- **Admin-issued, and that is a security decision** rather than an
  organisational one. A claim code opens a profile nobody has used; a reset
  code opens an account with orders, money and measurements in it. A tailor
  able to reset her own customer's PIN could take that account over, and she
  is the person with the motive.
- **Four hours, not forty-eight.** A claim code accommodates "I will do it
  when I get home"; a reset is issued while an admin is on the phone with the
  person who needs it, so there is nothing to accommodate.
- **Every other session dies on reset.** If she forgot her PIN the old tokens
  are hers and worthless; if somebody else had got in — which is a reason to
  reset — leaving their session alive would make the whole thing theatre.
  Tokens are deleted rather than left to expire, because Sanctum tokens here
  do not expire. Any other outstanding reset code is spent at the same time.
- **She is told, and it cannot be switched off.** `pin_reset` sits in the
  ACCOUNT category, mapped in Section 2 against this section. It is the one
  message that tells somebody their account was taken over, so a setting able
  to silence it would silence exactly the warning that matters.
- **A claim token will not work on the reset endpoint, or the reverse.**
  Tested both ways.

**A testing note worth keeping:** `Sanctum::actingAs` fakes the guard for the
remainder of a test, so a later bearer-token request is answered as the acting
user and a "this token should now be dead" assertion passes without proving
anything. Issue tokens directly and call `$this->app['auth']->forgetGuards()`
before re-checking — the guard also caches the user it resolved on the
previous request in the same test.

### Section 17 — the admin dashboard — done

The last section. 24 new tests, 346 total.

**Two questions, in order: is anything waiting, and is anything quietly
broken.** The numbers come third, because a figure nobody acts on is
decoration. An attention row with nothing to do is omitted rather than shown
as zero — a list of zeroes trains somebody to stop reading the list.

**The health panel is a promise from Section 2 coming due.** CLAUDE.md has
said since then that `notifications_pruned_at` "exists so a cron that has
quietly stopped shows on the admin dashboard rather than being discovered when
the disk fills". It now does, and the other two scheduled commands gained the
same heartbeat: `payouts_released_at` and `subscriptions_reminded_at`.

Nothing on this platform is load-bearing on cron — escrow release is computed,
expiry is computed, pruning is housekeeping — which is deliberate and is
*exactly* what would let a dead schedule go unnoticed until somebody's money
was late. Three states, not two: **never run** is normal on a machine just set
up; **stale** is not. A dry run stamps nothing, because it proves the command
can be invoked rather than that the schedule is alive.

**Suspensions, finally.** `EnsureUserIsActive` has cut live sessions since
Section 1, and a fixed term has lapsed on use rather than on a schedule — but
nothing could ever start one, and every suspension tested in this project so
far was written by hand in tinker. The People screen is that missing half, and
suspension is deliberately the only power on it: no editing names, no reading
measurements, no changing a phone number that is also a username.

- **Fixed-term by default**, because an indefinite suspension is one nobody
  gets round to lifting and the middleware already knows how to let one lapse.
- **Not yourself, and not another admin.** The first is a way to lock everyone
  out by accident; the second is a disagreement between colleagues that a
  button should not settle.
- **She is told, and told why.** `account_suspended` is in the ACCOUNT
  category, which cannot be switched off — Section 2 said so in as many words.
  The admin's reason is carried through to her, because a suspension with no
  explanation becomes a phone call.
- **Listing people is a deliberate widening**, like the admin order screens.
  Everywhere else it is refused precisely so nobody can build a directory of
  another person's customers; this is the only place that lists everybody.

**A CSS bug worth remembering:** `.health li` (specificity 0-1-1) beat
`.health--stale` (0-1-0), so the shorthand's `transparent` silently ate every
state colour — the entire point of the panel. Found by reading computed styles
rather than by looking, which is the only way that particular failure shows
up.

### Section 14 — subscriptions — done

Prepaid terms, not recurring billing. 23 new tests, 322 total.

- **She buys days; nothing ever charges her again.** One ordinary Flutterwave
  charge, exactly the one the order flow already makes. This is the decision
  CLAUDE.md §5 is built on: expiry becomes a computed fact, so nothing has to
  happen at the moment a term ends and a dead cron is a missed courtesy rather
  than a broken invariant. It also means bank transfer, USSD and Opay all
  work, where a recurring card charge fails silently on many Nigerian cards.
- **Terms stack.** A yearly bought over a running monthly starts when that one
  ends, so upgrading is just "buy a yearly", renewing early loses nothing, and
  there is no proration to get wrong. Verified live: 30 + 365 = 395 days, the
  second term beginning exactly where the first ended.
- **One term per payment, enforced by the index.** `StartTerm` checks first so
  the ordinary replay is a quiet no-op rather than an exception in a log
  nobody reads, but the unique constraint is the actual rule. Confirmed three
  times against the live API: one term.
- **The directory is what she buys**, and `TailorRanking` is the only place
  that asks. A lapse hides her from the listing and changes nothing else — not
  her orders, not her money, not her measurements, and not the page a printed
  card points at, which still resolves to the "not currently listed" state
  Section 16 built for exactly this.
- **Grace is not a lapse.** Seven days, because a Nigerian bank transfer
  settling a day late must not delist somebody who paid on time, and the
  difference between "her money is in transit" and "she has stopped paying" is
  not one we can see.
- **`directory_requires_subscription` is a setting, defaulting to on.** The
  cold start is real: a directory that requires payment is empty on launch day
  and nobody pays to join an empty platform. Turning it off runs an
  introductory period with everyone listed, without a deploy and without
  anything else about subscriptions changing.
- **Reminders at T−7/−3/−1, and they are a courtesy.** `subscriptions:remind`
  at 09:00 — morning rather than the small hours, because a 3am notification
  is one that gets swiped away. `last_reminder_days` stops a cron firing twice
  from telling her twice, and the code sends the *smallest* milestone still
  owed so four days of downtime produce "one day left" rather than a stale
  "seven days left" followed by three more. A dashboard banner says the same
  thing to anybody who signs in, which is what makes the cron optional.
- **An admin can grant days** without a payment — a goodwill month, a founding
  tailor — and those stack like any other term.

**Not built, deliberately:** auto-renew. The plan allows it later as a pure
optimisation permitted to fail silently. It is not in v1 and nothing here
assumes it.

### Section 16 — QR and the business card — done

The card a tailor sends to a print shop, and the rule that the address on it
must never die. 9 new tests, 299 total.

- **SVG and a matrix, never a raster.** A PNG writer needs GD or Imagick and
  the plan is explicit that this must not be able to fail for an environment
  reason on shared hosting. `QrCode::svg()` serves the screen; `matrix()`
  returns the raw grid and the browser draws it.
- **Different correction levels for different jobs**, which is why the level
  is a parameter. A screen QR is read by a cheap camera pointed at a glossy
  display at an angle, so High buys a first-time scan and screen pixels are
  free. Print has no reflections, and there the denser code is what fails:
  at card size every extra module is smaller, and ink spread closes the gaps.
  Medium, as the plan says.
- **The card is drawn on a canvas, not laid out in HTML.** The primary action
  is "save it as a picture" and a canvas already does that — the alternative
  is an HTML-to-canvas dependency. Rasterising an SVG through an `<img>` was
  the other option and loses the webfonts, which is most of what makes the
  card look like this platform.
- **Two sides, doing different jobs.** The front is who she is — the thing
  handed across a counter — and carries a "See my work →" cue so the back is
  not a surprise. The back is the scan side and gives the code a whole face,
  which took it from 24mm to **27.8mm** with 0.68mm modules. A code that will
  not scan is the only way this card can actually fail, so that size is the
  point of having a back at all.
- **Two themes, and the code never inverts.** White or deep violet, her
  choice, chosen from swatches painted in the card's own ground rather than
  from two words. On the dark card the QR sits on a white tile: an inverted
  code is read by some scanners and silently refused by others, and a card
  that works on one phone and not another is the worst outcome for an object
  that cannot be recalled. Verified in the browser — 1089 of 1089 modules
  correct in both themes, quiet zone pure white.
- **The QR is drawn module by module from the grid.** No fetch, no scaling,
  every module on a whole pixel. Verified against the server's matrix in the
  browser: 1089 of 1089 correct, 7px modules, quiet zone pure white. At
  85×55mm that is a 24mm code with 0.59mm modules.
- **Pure black on white**, never the ink colour. Contrast is what a scanner
  reads, and a tinted code is the classic way to make one that does not work.
- **Saving is primary, printing secondary.** A tailor sends the file to a
  print shop on WhatsApp; home printing is not how this works. Printing uses
  a `@media print` block and the browser's own "Save as PDF" — no PDF
  library, which would be tens of megabytes for worse typography.
- **The card carries the platform.** Without "Rachel's Closet" on it the card
  shows a web address with no indication of what it is, and asks somebody to
  trust an unexplained square.

**A 404 on a printed card is a permanent physical failure**, and this section
changed Section 15's behaviour because of it. A tailor who is not listed no
longer gets a dead page at her own address: it renders a quiet "not currently
listed" state with her name and a link back to the directory, which is the one
outcome that still helps somebody standing there with a card and a phone. She
stays out of the listing and the sitemap, and the page is `noindex`. Section
14 attaches a lapsed subscription to exactly that flag.

`App\Services\Claims\QrCode` moved to `App\Services\Qr\QrCode`: it serves
two sections now and the old namespace had become a lie.

### Section 15 — the Fashion House directory — done

The public answer to "tailors lack visibility". 33 new tests, 289 total.

- **Blade, and reachable without an account.** This is the page a QR code
  printed on cardboard resolves to, and the reason the public site was built
  server-rendered back in Section 3.5. `/tailors` filters by state and
  searches business names; `/t/{slug}` is one tailor, with her gallery,
  rating and published reviews. Plus `sitemap.xml`, `robots.txt` and
  LocalBusiness structured data, because being found is the entire point.
- **The slug never moves.** `TailorProfile::slugFor()` has said so since
  Section 1; this is the section that makes it matter.
- **A suspended tailor vanishes from the public site** and nothing else about
  her account changes. Her orders and her money are untouched.
- **Ranking is a Bayesian-adjusted average**, in one service with its inputs
  in `platform_settings`, because the plan names this as where gaming will
  happen. Each tailor starts with five notional reviews at the middle of the
  scale, so a rating only moves as real evidence accumulates.
- **The prior is the middle of the scale, NOT the platform mean.** The
  textbook choice is the mean, and it is wrong here: in a population where
  everybody is rated 4.8, a mean-based prior assumes a brand-new tailor is 4.8
  too, so her first manufactured five-star review leaves her at 4.8 and she
  outranks a house with twenty-six real ones. That is the exact failure the
  formula exists to prevent, and it failed the first time it was tested.
- **The newcomer allowance is sized against a real review.** It has to clear a
  badly-rated tailor and stay *under* what one genuine five-star review earns
  — against a prior of weight 5 at 3.5 that is 0.25, so the default is 0.2.
  Anything at or above 0.25 makes an empty profile outrank somebody a real
  customer praised. A test pins the ordering.
- **Proximity is a filter, not a score.** We hold a state, not a coordinate,
  so "near me" narrows the list rather than quietly reshuffling it — and a
  boost would be meaningless on searches that already name a state.
- **The gallery and lightbox are the landing page's**, reused unchanged. Any
  `[data-lightbox-open]` button joins the set `motion.js` already drives, so
  the profile page got keyboard navigation and Escape for free. The markup
  moved to `partials/lightbox.blade.php` rather than being written twice.

**An admin settings screen**, which Section 6 promised and never delivered:
every number the seeder calls "an operational judgement" is now editable,
grouped, saved per field on blur. Only an allowlist is writable — the table
also holds machinery like `notifications_pruned_at`, and exposing all of it
would let an admin edit a cron's bookkeeping while looking for a price.

**Two things worth knowing about the environment**, neither a bug:

- `php artisan serve` is single-threaded, so a gallery of images can queue
  behind the page. It is not a problem on real hosting.
- Chrome does not deliver IntersectionObserver callbacks or load
  `loading="lazy"` images while a window is occluded
  (`document.visibilityState === 'hidden'`). Reveals and images then appear
  broken while they are not. Worth remembering before debugging the reveal
  machinery again.

**Also fixed:** `.pill` was used on the Section 11 consent screen and had never
been defined, so it rendered as bare text.

### Section 13 — two-way reviews and the proof gate — done

22 new tests, 258 total. The settings the gate reads — `review_proof_threshold`
(80) and `review_proof_min_steps` (3) — were seeded in Section 1 and finally
have something to govern, and `review_received` was mapped into
`NotificationCategories` in Section 2 against this section existing.

**The gate exists for exactly one failure**: a tailor inventing orders with a
confederate and giving herself five stars. What stops that is not moderation
but evidence — a real order carries photographs, because Section 10 put a
camera on every stage. Three deliberate narrowings, each because the broader
version does harm:

- **Only four and five stars.** A complaint always publishes. Holding a
  one-star review reads as censorship, and a tailor who did no photo work is
  precisely the one whose bad reviews most need to be seen. Gating both ends
  of the scale would turn an anti-inflation measure into a reputation filter.
- **Only reviews of a tailor.** A tailor rating a customer cannot inflate what
  the directory ranks on, so gating it would be ceremony.
- **Only orders with enough stages to judge.** Two stages with one photograph
  is 50% and means nothing either way.

- **A held review moves nothing and announces nothing.** Letting it touch the
  average would defeat the gate entirely — the rating would rise the moment
  the review was written. And telling a tailor about a review she cannot read,
  which may never appear, would also leak its rating.
- **The author sees her own held review**, so she is not left wondering
  whether it saved. Nobody else does.
- **There is no reject.** An admin releases a held review or leaves it held.
  A button that permanently destroys somebody's opinion of a business is not
  one this platform needs; the gate is for invented orders, not inconvenient
  praise. The admin screen shows the numbers the gate looked at, so the
  decision can be reviewed rather than merely taken.
- **Reviewing opens at collection, not completion.** She knows whether the
  garment is right the moment it is in her hands, and escrow may run for days
  after that — waiting would collect reviews when nobody remembers the
  fitting.
- **`avg_rating` is recomputed from scratch** from published reviews only, and
  `orders_completed` counts finished orders rather than reviews: a customer
  who never gets round to reviewing has still had a garment made.

**The proof ratio is capped at 100.** A tailor photographs a stage as she
finishes the sleeve and ticks the circle later, so mid-order there can be more
photographed stages than completed ones and the raw division reads as 150%.
By review time the order is collected and the two are equal, so this changes
no decision — it exists because a percentage over 100 on the admin screen
reads as a bug. Found in live dev data, not by reasoning about it.

**A CSS bug fixed on the way through:** `.field` styled `input` but not
`textarea`, so every textarea rendered inline and unthemed — a small white box
beside its label. It had gone unnoticed because a full-width input was what
made that layout work at all, and this section added the first two textareas.

**Still not built: disputes.** `Order::DISPUTED` remains a status nothing
sets. The plan's admin route into measurement photographs needs one, so that
rule stays closed — see Section 11.

### Section 11 — measurements, consent and the claim flow — done

The most sensitive data on the platform, and the flow that turns a profile a
tailor typed in on the shop floor into somebody's own account. 40 new tests,
236 total. `endroid/qr-code` installed — the second and last pre-agreed
dependency.

**`MeasurementAccess` is the whole section.** One ladder, in order: her own
body; an admin (no — see below); an unclaimed profile, where only the tailor
who recorded it may look; a tailor with a live order; a tailor with a granted
link; otherwise no.

- **Every refusal is 404, never 403.** A 403 confirms the row exists, and the
  existence of a row here says a named person has been measured by somebody.
- **`FileAccess` takes `MeasurementAccess` as a dependency** rather than
  restating the rule. If the two could drift, a tailor who cannot open the
  record could still stream the photograph by knowing its path — the whole
  consent rule defeated by a URL. Every test asserts both doors at once.
- **A plain admin gets nothing.** The plan allows a narrow way in —
  `measurements.view` plus a real dispute — and neither exists: there is no
  staff permission system, and nothing in any section opens a dispute, though
  `Order::DISPUTED` is reserved. Until both are built the honest answer is no,
  which is the safe direction to be wrong in. Section 13 is where disputes
  would naturally land.
- **A finished order does not keep granting.** "She was once my customer, so I
  keep her measurements forever" turns one job into an indefinite claim on
  somebody's body, and it accumulates without anybody deciding to.
- **Revoking does not strip a tailor mid-order, and the screen says so.** The
  cloth is cut and she has the numbers on paper; taking the record away
  protects nobody and ruins the garment. A control that quietly does less than
  it claims is worse than no control, so the consent screen prints the caveat
  only when it is actually true.
- **The consent screen lists tailors with a live order too**, even where no
  link exists — a list of only links would be a lie by omission.
- **Only the customer may delete a set.** Not the tailor who recorded it: a
  record of somebody's body is hers, and the history is the point.

**The claim flow.** Three channels, all free, two of which send nothing at all,
because the tailor and customer are standing together: a QR on her screen, a
`wa.me` deep link from the tailor's own WhatsApp, and six digits read down a
phone call. The link identifies a row on its own; the code does not, so it is
only meaningful beside the phone number it was issued for.

- **Claiming grants the inviting tailor a link**, which is the moment the plan
  calls "cross-tailor history begins when a real person consents". The claim
  screen says so in as many words *above* the button, and it is one tap to
  take back from her own screen afterwards.
- **Re-issuing expires whatever came before.** Two live codes for one account
  is two chances to guess, and re-issuing means she lost the first anyway.
- **Claiming signs her in.** Setting a PIN proves who she is as well as typing
  it into the sign-in form would; `adoptSession` on the web client exists for
  exactly this and nothing else.
- **`App\Rules\Pin` applies unchanged**, including the rule against digits
  taken out of her own phone number — which needs the number, so it is
  re-validated once the row is resolved.

**A pre-existing 500 fixed on the way through:** Laravel's default for an
unauthenticated guest is `route('login')`, which does not exist here and never
will — sign-in is a React page on another origin. Any API request without a
bearer token *and* without an `Accept: application/json` header threw before
the JSON handler ran, so it came back 500 instead of 401. Not hypothetical:
`GET /api/files/{path}` returns a file, which is exactly the kind of URL a
browser fetches directly. `redirectGuestsTo` now returns null for `api/*`.

**Deferred at the time, built later:** PIN reset over the same three
channels, using `claim_tokens.purpose`. See its own section above.

### Section 10 — photo proof — done

`order_step_photos`, a camera button on every tracker row, and the counter
Section 13's review gate will read. 15 new tests, 196 total.

- **`order_id` is denormalised onto the photo**, as the plan specifies. The
  proof ratio is "how much of this order's work was shown", and without the
  column that is a join through `order_steps` on every review decision and
  every order page. Nothing but the controller writes a row and it copies the
  value off the step it was handed, so the two cannot disagree.
- **`steps_with_photo` counts stages, not photographs.** Three pictures of the
  same sleeve is one stage proved. Recomputed from scratch by
  `RecalculateOrderProgress` like the other two counters — deleting one of two
  photographs on a stage correctly leaves that stage still proved.
- **Only the two people on the order may look.** Unlike a library voice note,
  which is the same recording for everybody, this is one customer's cloth on
  one tailor's table, so `FileAccess` resolves the path to the order rather
  than to the uploader — the customer never uploaded it and must still see it.
  Admins are deliberately *not* excluded: proof of work is exactly the dispute
  material BizyFarmers' blanket admin rule was written for. Measurements
  remain the only exception.
- **Proof can be withdrawn while the work is happening, and not after.** The
  same window in which she may tick a stage off. A blurred picture of the
  wrong sleeve is an ordinary mistake; a photograph removed after the customer
  has the garment is evidence leaving a record she may be about to review.
- **Three photographs per stage, capped.** Storage is a real limit here, not a
  theoretical one — the plan puts a thousand orders at roughly 3GB against a
  shared-hosting quota. The cap plus the existing 1600px downscale in
  `image.ts` is the storage policy the plan asked to be decided before launch.
- **HEIC is not accepted.** Browsers cannot display it, and a photograph
  nobody can open is not proof of anything.
- **The camera opens, not a file picker** — `capture="environment"` on the
  input. The work and the phone are in the same hands, and navigating a file
  system is the kind of reading this platform exists to avoid.
- **No notification per photograph.** The stage-completed notice already
  fires, and `notifications:prune` exists because this table grows fastest of
  any; a second row per stage would double the noise to say something she can
  already see.

`OrderStepResource` now shapes a step in one place. Section 9 had that shape
duplicated between the tracker endpoint and the order resource, which was
survivable at six identical lines and stopped being so the moment photographs
had to appear in both.

**A gap this closed:** `steps_with_photo` was never exposed by `OrderResource`
at all — Section 9 had nothing to put in it, so nothing noticed.

### Section 9 — the tracker — done

`order_steps`, assembled from the tailor's arrangement when the order is
created. The tap-to-complete checklist §1 calls the product. 15 new tests, 181
total.

- **Everything is snapshotted** — label, instructions and the path to the
  recording are copied onto `order_steps` and never read back from the library.
  Not an optimisation, a correctness rule: the customer *read* those words and
  played that recording, and an admin tidying the wording next month must not
  reach back and rewrite the timeline she already saw. `production_step_id` is
  kept as provenance only, nullable and null-on-delete, precisely because
  nothing reads through it. Two tests hold the line: renaming a library step
  leaves an existing order untouched, and replacing a recording leaves the
  order's copy playable.
- **Assembled at creation, not at payment.** Seeing which stages her garment
  will pass through is most of what she is deciding on. Making her pay first to
  find out is the opacity this platform exists to remove, so the checklist is
  on the order page beside the Pay button, read-only.
- **A step retired between the arrangement being saved and the order being
  opened is still copied.** The tailor arranged her work that way; an admin
  tidying the library is not a reason to silently drop a stage from a garment
  being made now.
- **Ticking the last stage is what makes an order ready.** There is no separate
  "mark ready" button where a checklist exists — it would be asking twice, and
  would let her declare a garment ready with stages untouched. It survives only
  for an order whose garment type had an empty arrangement.
- **Un-ticking is allowed**, because a mis-tap on a five-inch screen is
  ordinary and the alternative is a tailor with a wrong timeline she cannot
  fix. The customer is told only on the transition *into* done, so correcting a
  slip does not buzz her phone again.
- **The last tick sends one notification, not two.** The order-is-ready notice
  covers that moment and says more; most arrangements end with a stage called
  something like "Ready to collect", so both would read as the same sentence
  twice.
- **Counters are recomputed from scratch**, never adjusted, so nothing can
  drift into "7 of 6 done". `RecalculateOrderProgress` is the only thing that
  writes them — including at assembly, so a new order reads "0 of 10" on the
  list rather than "0 of 0" for the whole stretch where she is wondering
  whether anything is happening.
- **`steps_total`, `steps_completed` and `steps_with_photo` got attribute
  defaults on the model**, for the same reason `status` has one: a schema
  default is on the row, not on the object, so a freshly created model read
  them back as null and wrote that null straight into a NOT NULL column. Found
  by a test, not in production.

The UI is `OrderTracker.tsx`: a 48px circle per stage rather than a checkbox,
the voice note on the row rather than behind a tap, and the tick applied
optimistically so it lands under her thumb before the request returns.

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
- **Arrows first**, for the reasons in the plan — and the play button on each
  row is the point: a tailor arranges the list by listening. Dragging was
  added later *beside* the arrows, never instead of them (web `2549b35`): a
  grip starts the drag, so the rest of the row still scrolls the page, and a
  drag near an edge scrolls too. It commits through the same whole-array PUT.
- **Retire, never delete.** `restrictOnDelete` on the template item's step
  makes the database enforce it. A retired step cannot be added to anything new
  but stays put where it already is.
- **The schema cannot express "one default per garment type"** — MySQL allows
  any number of rows where part of a unique key is NULL. It is enforced in
  `StepTemplate::defaultFor()`, the only thing that creates one, and the
  migration says so rather than pretending otherwise.

**A correction to Section 3's `FileAccess` note:** the step-voice-note resolver
matched only a step's *current* recording. Replacing one leaves the old file on
disk with nothing pointing at it, so it was unreachable — correct at the time,
because nothing referenced an old path yet. **Section 9 widened that resolver
to accept any path an `order_step` holds.** Closed, with a test.

### Section 3.5 — the design language — done

Sits between 3 and 4 because the alternative was retrofitting it across twenty
pages later. Reference the product owner gave: `html.awaikenthemes.com/ellora`.

`public/css/design-system.css` (tokens, type, motion primitives),
`public/css/public.css`, `public/js/motion.js`, a Blade public layout, the
landing page, a `/styleguide` route, and `config/gallery.php`.

- **The public site is Blade, not React.** A QR code printed on cardboard points
  at a tailor's profile; somebody scanning it in a market must get a readable
  page, not a spinner waiting on a bundle. A blank page on a printed card is a
  permanent physical failure — the card cannot be reissued. It is also how the
  directory gets indexed, which is the whole answer to "tailors lack
  visibility". The two halves share a design language, not a renderer.
- **Zero third-party scripts, measured against the reference.** Ellora loads 18
  scripts and 9 stylesheets — jQuery, Bootstrap, Swiper, GSAP + ScrollTrigger +
  SplitText, WOW, Isotope, Parallaxie, magnific-popup, SmoothScroll and more.
  19 of those files measure **1.12MB before a single image**. Our whole landing
  page including all CSS and JS is **~90KB uncompressed**. Scroll reveals are one
  IntersectionObserver; the carousel is `scroll-snap`; the lightbox is a native
  `<dialog>`; split text is ~30 lines; parallax is one rAF loop that idles when
  nothing parallaxed is on screen.
- **`prefers-reduced-motion` kills all of it, and the page stays complete.** Not
  an accessibility checkbox: on the phones this is actually read on, six things
  animating at once judders, so the people most likely to enable it are the ones
  whose hardware most needs it.
- **Bodoni Moda for display, Inter for everything.** A didone is what makes this
  read as a fashion house rather than as software. The app uses the same two
  families so the halves look related.
- **The photography is the decision that mattered most.** The first stock set
  considered was generic Western fashion — a blonde with shopping bags, a stack
  of jeans — and it made the page look like a template for somebody else's
  business. Replaced with Pexels photographs of Nigerian tailoring and Nigerian
  dress: agbada, lace, coral beads, a Butterfly machine in an Abuja workshop.
  **These are placeholders.** Swapping in Rachel's own work is one line per image
  in `config/gallery.php` and is the highest-value change available to the site.

**Tokens are duplicated, deliberately.** `public/css/design-system.css` is
canonical; `rachelscloset-web/src/styles/app.css` mirrors the `:root` blocks.
The two repos deploy separately and a broken import between them would take
down a page a printed QR code points at. Change one, change both.

Fixes found only by opening it in a browser, all of one shape — a token or a
value that was right in the abstract and wrong on screen:

- **`--violet-ink`, `--violet-deep` and `--ink-invert` were inverting in dark
  mode**, but the hero, band, CTA and footer are dark in *both* themes, so all
  four turned near-white with dark-on-dark text. `--on-dark` now never flips;
  `--ink-invert` is only for text on a filled control, which genuinely does.
- **A `.frame` with no ratio modifier has no height**, so the image's
  `height:100%` resolved against auto and a portrait rendered at full intrinsic
  size inside a much shorter box. The hero looked like an empty coloured block.
- **The parallax had the sign inverted and fed back on itself**, and a
  full-bleed hero has no fixed edge to read movement against anyway. `.drift`
  moves a photograph inside a frame that holds still, which is where it reads.
- **`grid-auto-columns: minmax(0, …)` let carousel cards shrink to share the
  visible width**, so the track never overflowed, the arrows did nothing, and
  the cards were 210px instead of 365px — which is what was breaking tailor
  names onto two lines.
- **Frame ratios must match the source orientation.** A landscape workshop
  photograph in a `tall` 3:4 frame crops to somebody's forearm. Orientations are
  now documented in `config/gallery.php`.
- **Carousels drag with a mouse.** Scroll-snap gives finger-swipe for free but a
  mouse cannot drag a scroll container; pointer events cover that case, with
  snapping switched off for the duration or it fights every move.

### Brand assets

`public/brand/mark.svg` is the source of truth for the shape: an interlocking
RC in Zilla Slab on a lilac tile.

- **A monogram, not a needle or a hanger.** It cannot be mistaken for another
  business the way a generic tick or spool can. Earlier studies — a thread tick,
  a curled tick, a spool — all read as somebody else's logo or turned to mush.
- **The C passes behind the R's bowl and in front of its leg.** That change of
  order at the second crossing is the entire interlock: letters that merely
  overlap read as stacked. Mechanically it is the C drawn twice, the second time
  clipped to one rectangle covering the lower crossing. The same rectangle is
  used by the SVG `clipPath` and by `imagesetclip` in the generator.
- **Both letters are outlined paths, not live text**, so the mark renders
  identically with no webfont and no network: as a favicon, in an email, at the
  centre of a printed QR code. `mark-mono.svg` cannot stack two colours, so it
  is one path with `fill-rule="evenodd"` — the overlap cancels to negative
  space, which is how single-colour monograms have always done it and survives
  being stamped or embroidered. Note an SVG loaded through `<img>` is isolated
  and cannot see the page's `currentColor`; it must be inlined to take it.
- **Below ~20px the two letters run together**, so the 16px entry in
  `favicon.ico` carries the R alone. That is what a multi-size `.ico` is for.
- **`--` is illegal inside an XML comment.** Heavily commented SVGs are silently
  invalid and render as a broken image; the comments here use en dashes.
- **`php artisan brand:build` renders every raster asset** from the same
  geometry — `.ico` (16/32/48), the home-screen icons, a maskable icon, the
  social card and the PNG lockups — so they cannot drift from the SVG. GD, not
  Imagick, which the host does not have. `resources/brand/ZillaSlab-Bold.ttf`
  (SIL OFL 1.1) is committed because the command needs it.
- **The stitches were dropped when the C arrived.** Two letters plus a row of
  stitching crowded the tile and the stitches became noise below 48px. The
  interlock is the distinguishing feature now and carries it alone.

**Reset `figure` and `blockquote`.** The browser's own stylesheet gives them
`margin: 1em 40px`. Eighty pixels of side margin nobody wrote is invisible in a
wide layout and ruinous in a narrow one — it was taking a third of the width off
every testimonial card on a phone, which reads as the card being too narrow
rather than as a margin. Now reset globally in design-system.css.

**Never put a moving reveal on a child of a horizontal scroll container.** A
container with `overflow-x: auto` computes `overflow-y` to `auto` as well, so
the 40px translate on each un-revealed card inflated the track's scrollable
height and clipped every card by exactly that much. Worse, cards off to the
right had never intersected the viewport, so the first swipe revealed a blank
card that only then faded in. The reveal now sits on the carousel itself,
outside the scroll container, and the per-card stagger is opacity alone —
opacity does not affect layout, so it cannot inflate anything.

**`backdrop-filter` creates a containing block for `position: fixed`
descendants.** The mobile navigation drawer lives inside the masthead and is
sized `top: 78px; bottom: 0`. With the frosted filter on the masthead itself,
that resolved against the 78px bar instead of the viewport and computed to zero
height — so the drawer worked at the very top of the page, where the masthead is
transparent, and silently died the moment you scrolled. Measured: 617px tall
transparent, 0px once solid. The filter now lives on a `::before`, which confines
the effect to a child with no descendants to trap. Anything else fixed inside
that header depends on it staying there. `transform`, `filter`, `perspective`
and `will-change` of those do the same thing.

**Not built yet, and deliberately:** the tailor directory and profile pages are
Section 15, so the landing page's tailors are illustrative and labelled as such
on the page. The tracker shown under "How it works" is a static mock of what
Section 9 builds.

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
- **The switches shown are per role; the switches stored are not.**
  `NotificationCategories::OFFERED` lists, per role, only groups something
  actually sends her, worded for her side — a tailor was once offered "Work on
  your clothes", which only a customer ever receives. `OPTIONAL` stays the full
  stored shape for everybody, so hiding a switch never resets a choice.
  Measurements is offered to nobody until something sends a
  `measurement_access_*` alert; admins are offered nothing.
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

**Flutterwave, locally.** `FLUTTERWAVE_SANDBOX=true` swaps in a gateway that
always succeeds, so the order flow can be clicked through with no network and
no keys. It only takes effect in `local` — see `PaymentGatewayManager`. Set it
`false` to use real sandbox keys.

**`FRONTEND_URL` must match the port Vite actually took.** It is the return URL
a payer comes back to. Something already holds 5174 on this machine, so Vite
falls back to 5175 and `.env` is set to match; if that changes, this changes.

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

The **public site** is Blade at `http://localhost:8001/`, with the design
language at `/styleguide`. It needs no build step — edit the Blade file and
reload.

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
