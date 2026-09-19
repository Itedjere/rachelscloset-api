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

### orders, payments, payouts, webhook_events

`orders` — `reference` (unique, speakable: no 0/O/1/I, because it gets read
down a phone line), customer, tailor, garment type, `amount`, `deposit_amount`,
`escrow` (per order — §3 calls escrow optional), the status machine
(`pending_payment → in_progress → ready → collected → completed`, plus
`cancelled` and `disputed`), `collection_deadline`, and `steps_total` /
`steps_completed` / `steps_with_photo` which only Section 9 writes.

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

**New work:** 3.5 design language ✅ · 7 garment types + admin step library with voice notes ✅ ·
8 templates + arrow reordering ✅ · 9 orders assembled from steps, snapshotted ✅ ·
10 photo proof ✅ · 11 measurements + consent + claim flow · 12 completion + escrow release ✅ · 13 two-way reviews + proof gate · 14 subscriptions · 15 Fashion House
directory · 16 QR + business card · 17 admin dashboard.

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
has is Section 11's problem.

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
