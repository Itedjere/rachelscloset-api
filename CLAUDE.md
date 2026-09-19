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

### platform_settings
`id, key (unique), value, updated_at`

Seeded with `firstOrCreate`, so re-running never undoes an admin. Keys are
constants on the model: subscription price monthly/yearly, term lengths, grace
days, review proof threshold and minimum steps, default suspension days.

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

---

## 6. Ground rules

- Follow the data model above exactly. **Flag a new column or table before
  adding it**, then record the outcome here.
- **Do not add a dependency without asking.** One is agreed for a later section:
  `endroid/qr-code`. Nothing else — including `laravel/boost`.
- One section at a time. After each, summarise what was built and what to test
  manually, then stop.
- Comments explain **why**, not what. The ported code is heavily commented for
  exactly this reason, and it is the main thing that made it portable.
- Money is `decimal(14,2)` holding naira. Flutterwave takes naira directly, so
  there is no kobo conversion anywhere.

---

## 7. Build sections

**Ported from BizyFarmers:** 1 ✅ · 2 notifications + push + prune ·
3 files + `FileAccess` + recorder/player · 4 payments (Flutterwave, sandbox,
`ConfirmPayment`, webhooks) · 5 orders + escrow + payouts + refunds · 6 admin
shell, staff roles, settings, suspensions, public Blade site.

**New work:** 7 garment types + admin step library with voice notes ·
8 templates + arrow reordering · 9 orders assembled from steps, snapshotted ·
10 photo proof · 11 measurements + consent + claim flow · 12 completion + escrow
release · 13 two-way reviews + proof gate · 14 subscriptions · 15 Fashion House
directory · 16 QR + business card · 17 admin dashboard.

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
```

Seeded accounts, all with PIN **482917**:

| Role | Phone |
| --- | --- |
| Admin | 08030000001 |
| Tailor (Mama Ngozi Couture) | 08030000002 |
| Customer (no email, on purpose) | 08030000003 |

Tests run against the **`rachelscloset_test`** MySQL database, not SQLite — the
schema uses MySQL enums and there is no SQLite driver on this machine.

```
php artisan test
./vendor/bin/pint --test
```

**This machine kills dev servers for memory.** It is HP Sure Click
(`Br-uxendm.exe`) holding about 2.4GB, not anything in this project. Check what
is actually holding memory before blaming the build.
