# Essayist Launch Plan and Marketing Copy

## Working Definition

**Essayist is a writer-first Nostr relay where readers get access by supporting approved writers.**

During the first launch period, the relay operator fee is waived. Readers do not pay the platform first. They support writers from the founding pack. Writers are added to the founding pack through invitation or review.

The launch is designed to test one question:

**Will readers support a curated pool of longform writers in exchange for access to a writer-first relay?**

---

# 1. The Simple Explanation

## Short Version

Essayist is a writer-first publishing relay on Nostr. During launch, the relay operator fee is waived. To get access, readers support at least one approved writer from the founding pack. Writers can apply or be invited if they already have a body of longform work. If there is enough real support, Essayist opens more broadly.

## Even Shorter Version

Essayist is a Nostr relay for longform writing where access begins by supporting writers.

## One-Line Pitch

**Essayist is a publishing relay where the first money goes to writers.**

## Reader Explanation

Essayist starts with a simple rule: support a writer, get access. During launch, there is no operator fee. Readers choose an approved writer from the founding pack, make a small support payment, and receive access to the relay.

## Writer Explanation

Essayist is assembling a founding pack of longform writers. Approved writers become visible support recipients during the launch period. Readers who want access support writers from that pack directly.

---

# 2. Core Launch Principle

The launch should repeat one idea consistently:

**The first money should go to writers.**

This explains:

* why the operator fee is waived;
* why writers are curated first;
* why readers support writers before getting access;
* why the first cohort is a founding pack rather than an open free-for-all;
* why the launch measures support, not just attention.

---

# 3. Minimal Product Scope

Do not build the full staged economy first.

The first implementation should include only what is needed to test real interest.

## Required

* Essayist landing page.
* Nostr login or signup (already built).
* Writer self-request flow (logged-in writers can request `ROLE_ESSAYIST_AUTHOR`).
* Pre-check for existing articles before allowing request.
* Manual writer approval (admin reviews and runs `user:elevate`).
* Essayist feed page (gated by `ROLE_ESSAYIST_SUPPORTER`).
* Public feed of approved writers' articles.
* Contribution page where a reader chooses an approved writer.
* Payment or zap flow to that writer.
* Manual or semi-automatic receipt verification.
* Access flag once support is verified.
* Basic admin view for pending writer requests and access grants.

## Optional but Useful

* Public campaign progress counters.
* Pledge option for readers not ready to pay.
* Curator or pack proposal form.
* Writer spotlight posts.
* Launch FAQ.
* Email capture for non-Nostr users.

## Defer

* Algorithmic allocation.
* Full monthly payout engine.
* Cashu mint.
* Complex ledger.
* Automatic cartel detection.
* Generic receipt upload.
* Many access tiers.
* Public audit system.
* Multiple themed packs at launch.

The first version only needs to answer:

**Did this reader support an approved writer?**

If yes, grant access.

---

# 4. Launch Model

## Phase 1: Public Writer Signup

The landing page at `decentnewsroom.com/essayist` is live from day one. Writers self-apply; readers see the page but the supporter access flow is marked **coming soon**.

The self-serve signup on the landing page is the primary recruitment channel from the start.

### How it works

- The landing page is **publicly accessible** with no login gate.
- Writers log in with their Nostr key and click **"Request Writer Access"**.
- The system checks eligibility (≥ 3 deduplicated kind 30023 articles known to DN, Lightning address in profile) and assigns `ROLE_ESSAYIST_CANDIDATE` if both pass.
- Moderators review candidates in `/admin/essayist` and promote approved writers to `ROLE_ESSAYIST_AUTHOR` via `user:elevate`.
- Approved writers can immediately publish kind 30023 articles to the Essayist relay (`wss://essayist.decentnewsroom.com`).
- The reader/supporter section on the landing page is visible but shows **"Coming soon — reader access is launching shortly"**. No payment flow is active yet.
- The relay WebSocket URL is not yet linked or published.

### What this achieves

- **Writers** can discover and apply publicly without needing a private invitation.
- **Relay content** accumulates a real body of essays before reader access opens.
- **Reader interest** can be gauged by landing page visits and sign-ins before the supporter flow is built.

### Writer Criteria

A writer applying during Phase 1 must satisfy:

* a Nostr pubkey (logged in to DN);
* **at least 3 deduplicated longform articles** (kind 30023) known to Decent Newsroom — counted by unique `(pubkey, slug)` pairs;
* a working Lightning address (`lud16`) in their Nostr profile;
* articles must be **genuine Nostr content** — full essays published natively, not excerpts or summaries linking out to an external site.

Moderators reserve the right to reject a candidate or downgrade an already-approved writer at any time.

### Target

* 5 to 10 approved writers before opening reader access.
* 10 to 25 approved writers for a strong Phase 2 launch.

### Transition to Phase 2

Once enough approved writers have published content and the supporter access flow is ready, the "coming soon" section is replaced with the live reader signup. Existing writers retain their roles.

---

## Phase 2: Reader Supporter Access

The supporter access flow goes live. Readers can support an approved writer and receive `ROLE_ESSAYIST_SUPPORTER`, unlocking the gated Decent Newsroom feed page (`/essayist/feed`).

- Access grant remains manual during this phase (admin verifies payment, runs `user:elevate <npub> ROLE_ESSAYIST_SUPPORTER`).
- Relay URL (`wss://essayist.decentnewsroom.com`) is published in the UI; NIP-11 discovery activated.
- Automated supporter role grant can be enabled once zap receipt detection is working.

### Goal

Measure actual reader support: do people pay to access writer-first content?

### Strong Signals

* writer logs in and self-applies;
* writer passes eligibility and is approved;
* reader signs in with Nostr;
* reader chooses a writer to support;
* reader makes a support payment.

### Weak Signals

* likes;
* reposts;
* anonymous visits;
* vague waitlist signup.

## Phase 3: Launch Decision

After running the public interest test for 14 to 21 days, choose one of four outcomes:

### Launch

Enough writers and readers exist. Open Essayist access broadly.

### Small Pilot

Some interest, not enough for a full launch. Run a private pilot with founding writers and first supporters.

### Reposition

Writers are engaged but readers are not paying. Reframe as a follow-pack, magazine, or discovery project first.

### Pause

Neither side shows enough interest. Publish what was learned and do not overbuild.

---

# 5. Suggested Validation Thresholds

For a small but meaningful launch:

* 10 approved writers.
* 25 reader sign-ins.
* 10 readers choosing at least one writer to support.
* 5 actual support payments.

For a stronger launch:

* 25 approved or pending writers.
* 50 reader sign-ins.
* 20 readers choosing writers.
* 10 actual support payments.

The exact numbers can change. The important thing is to define a threshold before interpreting the results.

---

# 6. Landing Page Structure

## Hero

Headline, subheadline, and two primary calls to action.

Primary reader CTA:

**Browse Founding Writers**

Primary writer CTA:

**Apply as a Writer**

## What Essayist Is

A short explanation of the model.

## How Launch Works

Explain the three steps:

1. Browse approved writers.
2. Support one directly.
3. Get access during launch.

## Founding Writers

Show the first writer cards or pack preview.

Each card should show:

* name;
* npub or short identifier;
* topic/language;
* short bio;
* recent articles;
* support button;
* status: founding writer, launch qualifying, under review.

## Writer Applications

Explain who should apply and what they need.

## Why This Exists

Explain the publishing problem and the writer-first premise.

## FAQ

Answer objections clearly.

---

# 7. Landing Page Copy

## Hero Option A

### Headline

**A writer-first relay for essays on Nostr.**

### Subheadline

Essayist is launching with a simple rule: during the first launch period, the operator fee is waived and access begins by supporting approved writers from the founding pack.

### CTA Buttons

**Browse Founding Writers**

**Apply as a Writer**

## Hero Option B

### Headline

**The first money should go to writers.**

### Subheadline

Essayist is a Nostr publishing relay for longform writing. During launch, readers get access by supporting approved writers directly. Writers with an existing body of work can apply to join the founding pack.

### CTA Buttons

**Support a Writer**

**Join the Founding Pack**

## Hero Option C

### Headline

**Support writers. Enter the relay.**

### Subheadline

Essayist is testing a writer-first model for publishing on Nostr. Readers support approved essayists from the founding pack. Writers bring existing work. The relay starts with contribution instead of empty signups.

### CTA Buttons

**See the Writer Pack**

**Submit Your Work**

---

# 8. “How It Works” Copy

## Version 1

### 1. Browse the founding pack

Essayist starts with a curated pool of approved writers. The founding pack gives readers a visible list of people they can support.

### 2. Support a writer

During launch, the operator fee is waived. To get access, support at least one approved writer directly.

### 3. Get access

Once your support is verified, your pubkey receives access for the launch period.

### 4. Help decide what comes next

If enough readers support enough writers, Essayist opens more broadly and the founding pack becomes the first layer of a larger writer network.

## Version 2

Essayist starts small. Writers apply or are invited into the founding pack. Readers choose someone from the pack to support. Verified support grants access during launch. The goal is to prove that a publishing relay can begin with money flowing to writers instead of to the platform.

---

# 9. Writer Application Copy

## Section Headline

**Apply to join the founding writer pack**

## Body

Essayist is looking for writers with an existing body of longform work.

The founding pack is the first set of writers readers can support to unlock access during launch. Accepted writers will be listed publicly and may be included in follow packs by topic, language, or editorial focus.

## Application Requirements

To apply, you need:

* a Nostr account (logged in);
* at least 3 longform articles published on Nostr (kind 30023) that Decent Newsroom has already discovered;
* a Lightning address set in your Nostr profile.

## Note on Review

The founding pack is manually reviewed. Articles must be genuine Nostr content — full essays published natively, not excerpts that link out to another site. Moderators reserve the right to reject candidates or downgrade approved writers at any time.

## Writer CTA

**Apply for Review**

---

# 10. Reader Copy

## Section Headline

**Get access by supporting a writer**

## Body

During launch, Essayist is not charging an operator fee. Instead, readers qualify by supporting one approved writer from the founding pack.

Choose a writer, make a support payment, and receive access for the launch period.

This is the core experiment: can a publishing relay begin with direct support for writers rather than platform fees, ads, or engagement farming?

## Reader CTA

**Browse Writers**

---

# 11. FAQ Copy

## What is Essayist?

Essayist is a writer-first Nostr relay for longform publishing. It is launching with a contribution model where readers get access by supporting approved writers.

## Is this a paywall?

Not in the usual platform-first sense. During launch, the operator fee is waived. Access begins by supporting writers directly.

## Who receives the money?

During launch, the support payment goes to the approved writer, publication, or recipient selected by the reader.

## Why not just make the relay free?

A free launch would measure curiosity, not commitment. Essayist is testing whether readers will support writers as the condition for entering a writer-first publishing space.

## Why is there a founding pack?

A contribution-based relay needs eligible recipients before readers can participate. The founding pack is the first curated pool of writers available for support.

## Can anyone become a founding writer?

Writers can apply, but the founding pack is reviewed. Applicants should have an existing body of longform work and a working payment endpoint.

## Why require existing articles?

The requirement prevents the founding pack from being filled with accounts created only to capture launch payments. Essayist should begin with people already writing.

## What happens after launch?

If enough readers and writers participate, Essayist can open broader access, add more packs, activate operator fees, and introduce staged writer eligibility.

## Are follow packs the whitelist?

Not exactly. The whitelist determines who can receive qualifying support. Follow packs make approved or recommended writers visible and portable.

---

# 12. Public Announcement Copy

## Announcement Option A

I’m testing a writer-first Nostr relay called Essayist.

The launch premise is simple: the first money should go to writers.

Phase 1 is live: writers can now apply publicly at `decentnewsroom.com/essayist`.

If you have at least 3 genuine longform essays already known to Decent Newsroom and a Lightning address in your Nostr profile, you can request writer access. Reader/supporter access is coming soon after the first group of writers is approved and publishing.

This is not a normal waitlist. The first step is getting real writers and real essays onto the relay before opening the supporter flow.

## Announcement Option B

Essayist is a small experiment in writer-first publishing on Nostr.

Instead of launching with an empty relay and asking people to pay the platform, I’m starting with writers in public.

Writers can now apply directly at `decentnewsroom.com/essayist`. If you already publish real essays on Nostr and have a Lightning address in your profile, you can request access to publish on Essayist.

Reader access is not live yet. That opens after the first approved writers are in place and publishing.

## Announcement Option C

Launching soon: Essayist.

A Nostr relay for essays where the first phase is public writer signup.

Writers can apply now. Reader/supporter access is coming soon, once there is a real founding pack and real essays on the relay.

The test is still the same: can a writer-first relay begin with real writing before there is a platform economy around it?

---

# 13. Public Writer Outreach

## Short Version

Essayist is open for writer applications.

If you already publish genuine longform essays on Nostr, Decent Newsroom knows at least 3 of them, and your profile has a Lightning address, you can apply at `decentnewsroom.com/essayist`.

Writer applications are public. Reader/supporter access comes next, after the first group of approved writers is publishing.

## Longer Version

I’m preparing a small public launch experiment called Essayist.

It is a writer-first relay for longform writing on Nostr. The first phase is not a reader paywall. The first phase is getting credible writers onto the relay publicly, so there is real work there before supporter access opens.

If you already publish real essays natively on Nostr — not excerpts, not link-dumps — and Decent Newsroom has discovered at least 3 of them, you can request writer access on the landing page. Moderators review applications and approve writers who fit the editorial standard.

The goal is not exclusivity. The goal is to build a credible founding pack in public, then open the supporter flow once there is something worth supporting.

---

# 14. Social Posts

## Post 1: Teaser

I’m working on Essayist: a writer-first Nostr relay for longform work.

Phase 1 is simple:

Writers first.

Public writer signup opens first. Reader/supporter access comes after there is a real founding pack and real essays on the relay.

More soon.

## Post 2: Writer Call

Looking for founding writers for Essayist.

Essayist is a writer-first Nostr relay opening with public writer signup.

If DN already knows at least 3 of your longform Nostr essays and your profile has a Lightning address, you can apply at `decentnewsroom.com/essayist`.

The standard is simple: real essays on Nostr, not excerpts, not link-dumps.

## Post 3: Reader Heads-Up

Essayist is opening in two steps.

Step 1: public writer signup.
Step 2: reader/supporter access.

The supporter side is not live yet. First I want real writers and real essays on the relay. Then I’ll open the support flow.

## Post 4: Editorial Standard

Essayist is not for article excerpts that just point elsewhere.

To get approved, writers need genuine Nostr essays already known to Decent Newsroom.

The goal is to build a relay of native longform writing, not a feed of outbound links.

## Post 5: Launch Logic

Essayist starts with a constraint:

No empty platform economy.

First, open writer signup publicly.
Then, approve real essayists and let them publish.
Then, open reader/supporter access.
Only then decide whether the relay should grow.

The point is not to collect signups. The point is to test support.

---

# 15. Email / Newsletter Copy

## Subject Options

* Introducing Essayist: a writer-first relay for longform work
* The first money should go to writers
* Writers wanted for Essayist
* Public writer signup is open for Essayist
* Essayist is opening in two steps

## Email Body

I’m opening a public writer-signup phase for Essayist.

Essayist is a writer-first Nostr relay for longform publishing. The idea is still the same: the first money should go to writers. But the rollout is now in two steps.

First, writers apply publicly and begin publishing. Then, once there is a credible founding pack and real essays on the relay, the supporter/reader flow opens.

If you already publish genuine longform essays on Nostr, Decent Newsroom knows at least 3 of them, and your profile has a Lightning address, you can request writer access at `decentnewsroom.com/essayist`.

Moderators review applications. The standard is native Nostr writing — not excerpts, not link-dumps, not placeholder accounts.

Reader access is marked coming soon for now. I want to open that only after the writer side is real.

---

# 16. Launch Timeline

## Week 0: Preparation

Tasks:

* create Essayist landing page with writer self-request flow;
* define eligibility criteria (done: 3 articles + Lightning address);
* create admin review view (`/admin/essayist`);
* deploy `strfry-essayist` relay (`--profile essayist`);
* write FAQ copy;
* prepare public announcement posts.

## Week 1: Public Writer Signup Opens

Publish the landing page and announce publicly.

Goals:

* first writers discover the page and self-apply;
* admin reviews first candidates and approves genuine writers;
* relay begins accumulating real content.

Marketing focus:

* "Writers wanted for the Essayist founding pack — apply at decentnewsroom.com/essayist."

Do not open reader supporter access until at least 5 approved writers have published articles.

## Week 2–3: Build the Founding Pack

Continue reviewing and approving writers.

Goals:

* reach 10+ approved writers with published essays on the relay;
* confirm articles are genuine Nostr content (not excerpts/link-dumps);
* prepare the reader access flow.

## Week 4: Reader Access Opens

Replace the "coming soon" section with the live supporter flow.

Goals:

* first readers sign in and choose writers to support;
* admin manually verifies payments and grants `ROLE_ESSAYIST_SUPPORTER`;
* publish relay URL for clients that want direct access.

Marketing focus:

* "Would you support a writer to access a writer-first relay?"

## Week 5–6: Decision Point

Review the data.

Questions:

* Are there enough credible writers?
* Did readers choose anyone to support?
* Did any sats move?
* Which copy confused people?
* Is there enough signal for a full launch?

Possible decisions:

* open a full public launch;
* continue recruiting writers;
* reposition as a follow-pack discovery project;
* pause.

---

# 17. Metrics to Track

## Writer Metrics

* invited writers;
* accepted writers;
* writer applications;
* approved writers;
* rejected or pending writers;
* payment endpoints verified;
* topics/languages covered;
* articles submitted.

## Reader Metrics

* landing page visits;
* Nostr sign-ins;
* writer card clicks;
* selected writers;
* pledge amounts;
* support payments;
* access grants;
* repeat visits.

## Support Metrics

* number of supported writers;
* total sats sent;
* average support amount;
* concentration by writer;
* support by pack;
* number of supporters per writer.

## Decision Metrics

The most important metric is not traffic.

The most important metric is:

**How many people took an action that cost them something?**

That cost can be time, reputation, or money.

---

# 18. Three-Sentence Public Explanation

Essayist is a writer-first Nostr relay for longform work. During launch, the relay operator fee is waived; to get access, readers support an approved writer from the founding pack. The goal is to test whether a publishing network can begin with direct support for writers instead of empty signups, ads, or engagement farming.

---

# 19. Strongest Public Framing

Essayist should not be framed as “a Medium clone on Nostr.”

Better:

**Essayist is a writer-first relay for longform work.**

Better still:

**Essayist tests whether access to a publishing relay can begin with supporting writers directly.**

Avoid overclaiming. The launch is an experiment. That makes it easier to explain, easier to trust, and easier to stop if the signal is not there.

---

# 20. Immediate Next Steps

1. Choose the exact founding writer criteria.
2. Finalize the public writer-call copy.
3. Publish the landing page with writer self-signup and reader "coming soon".
4. Approve the first 5 to 10 credible writers.
5. Create the founding writer pack page.
6. Publish the public writer call.
7. Open reader/supporter access only after the first pack exists.
8. Run the test for 14 to 21 days.
9. Decide based on support behavior, not compliments.

The first test should be small and direct:

**Can Essayist assemble credible writers, and will readers support them?**

---

# 21. Technical Implementation Notes

> This section bridges the marketing plan above with the actual state of the Newsroom codebase. Read it before estimating scope.

---

## What is already built and can be reused directly

### Role-based author whitelist

The founding writer pack is managed entirely through the role system:

- `ROLE_ESSAYIST_AUTHOR` is assigned to approved writers via `user:elevate <npub> ROLE_ESSAYIST_AUTHOR`.
- The relay write-policy checks whether an incoming author pubkey holds this role.
- The founding writer list is not published as a public kind 39089 event; it remains proprietary to the instance.
- No separate `EssayistApplication` entity or `FollowPackSource` integration is needed.

### Writer article history check

`ArticleRepository` can count deduplicated kind 30023 articles per pubkey grouped by `(pubkey, slug)` to get the unique article count. The eligibility threshold is **3 deduplicated articles**. This check is done server-side in the landing page controller (non-blocking, cached briefly per user). No new queries needed beyond what `ArticleRepository` already supports.

### Eligibility requirements

To request writer access (`ROLE_ESSAYIST_CANDIDATE`), a logged-in user must satisfy **both**:

1. **≥ 3 deduplicated articles** (kind 30023) known to Decent Newsroom — grouped by `(pubkey, slug)` so republished revisions don't inflate the count
2. **Lightning address present** — `User::$lud16` must be non-empty (populated from kind 0 metadata on login)

These are checked in the landing page controller on every page load. The self-request button is rendered disabled with a clear explanation if either check fails.

**Content quality — moderator review (not automated):**

Passing the automated checks does not guarantee approval. Moderators review each candidate's article history before granting `ROLE_ESSAYIST_AUTHOR`. Disqualifying content includes:

- Excerpts or summaries that redirect the reader to an external site (e.g., RSS-imported snippets)
- Bot-generated or AI-only posts
- Link-dump articles with minimal original content

Moderators may also **downgrade** an already-approved writer (removing `ROLE_ESSAYIST_AUTHOR` and optionally restoring `ROLE_ESSAYIST_CANDIDATE`, or removing both) if published content no longer meets the standard. There is no automatic enforcement; this is an editorial discretion reserved by the relay operator.

### All writer metadata is already in the `User` entity

When a writer logs in via NIP-07/NIP-46, their `User` record is created/updated with:

- **pubkey** (hex) — account identifier
- **lud16** — Lightning address for zaps/payments (required by the model)
- **metadata** (from kind 0) — name, display_name, picture, nip05, about, etc. (cached in Redis)

The admin reviewing a pending request sees the pubkey, article count, and publication time; the `user:elevate` command needs only the pubkey. No dedicated "bio", "topics", or "payment endpoint" fields are required — they flow from the existing profile metadata and the articles already published.

### ZapButton component

`ZapButton.php` (Twig Live Component, `Molecules/`) generates NIP-57 zap request events and BOLT11 invoices via `LNURLResolver` and `NostrSigner`. It supports zap splits. It can be placed on a writer card on the Essayist landing page without modification.

**Limitation:** the component has no server-side receipt detection. It shows an invoice and lets the user click "Mark as paid" manually. There is no callback or webhook that fires when a payment lands. See the zap receipt gap below.

### Lightning address storage

`User::$lud16` already stores the lightning address for each logged-in user. This is automatically populated when the user logs in via NIP-07/NIP-46 (from kind 0 metadata) and is the only payment endpoint needed for the Essayist model.

### Nostr login

`NostrAuthenticator` (NIP-07 / NIP-46) is already implemented. Readers and writers can log in with their Nostr key. No separate login flow is needed.

### Admin dashboard pattern

The existing admin dashboard (`/admin`) follows a consistent pattern of sections with entity lists and action forms. An Essayist section (writer applications, access grants) should be added here rather than built as a separate admin area.

### User role grant

The `user:elevate` CLI command sets roles on the `User` entity. `ROLE_ESSAYIST_SUPPORTER` can be added to the `RolesEnum` without a new entity, letting admins grant access the same way they grant `ROLE_ADMIN`. This role gates the Decent Newsroom relay feed page during the pre-public-launch phase.

---

## Critical gaps that block the launch model

### 1. Zap receipt detection is not working

The project backlog explicitly notes: "Apparently no zaps are ever found on relays. Review."

The `ZapButton` component generates invoices and shows them to the user but does not verify payment server-side. The `markAsPaid` action on the component is a user self-report — it does not check a relay or any external endpoint.

**Impact on Essayist:** the plan's core access gate ("verified support grants access") requires knowing whether sats actually moved. Without receipt detection, the only options are:

- Admin manually verifies each payment and runs `user:elevate` to grant access.
- Reader provides a payment preimage or screenshot and admin approves.
- The plan is reframed to not depend on payment verification at launch.

**Recommendation:** for the minimal product, make access granting a manual admin step. The landing page shows the invoice; the admin sees the application queue and clicks "Approve access" once they are satisfied. This removes the relay/receipt dependency without changing the user-facing pitch.

Fix receipt detection separately as part of the existing backlog item before automating the gate.

### 2. Relay access enforcement mechanism — now implemented

The Essayist relay is a dedicated strfry instance in `docker/strfry-essayist/`:

- **`strfry.conf`** — port 7779, NIP-11 info declares `name = "Essayist"`.
- **`write-policy.sh`** — rejects all writes except from pubkeys with `ROLE_ESSAYIST_AUTHOR`. Accepted kinds: 30023 (published longform articles only). Identity and draft events (0, 3, 10002, 30024) should be fetched from the author's own relays.
- **`GET /api/internal/essayist/writer/{pubkey}`** — internal API called by the write policy on every incoming `EVENT`. Checks whether the hex pubkey belongs to a user with `ROLE_ESSAYIST_AUTHOR`. Protected by a shared bearer token (`ESSAYIST_POLICY_TOKEN` env var).
- **Docker profile `essayist`** — activate with `docker compose --profile essayist up -d`. Not running by default.

The main strfry relay (port 7777) is unchanged and continues to be read-only for all content.

**Read access — Phase 1 (writer signup, supporter access coming soon):**

During Phase 1, the relay URL is not linked in the UI, but the relay is live and writers with `ROLE_ESSAYIST_AUTHOR` can publish to it. Reads are served server-side via the Decent Newsroom feed page (`/essayist/feed`), gated by `ROLE_ESSAYIST_SUPPORTER`. No clients connect directly to the relay yet.

**Phase 2 (reader access opens):** the relay URL is published in the UI, NIP-11 discovery is activated, and clients can subscribe directly. `ROLE_ESSAYIST_SUPPORTER` continues to gate the curated DN feed page.

**Adding a writer** once approved:
1. Run: `docker compose exec php bin/console user:elevate <npub> ROLE_ESSAYIST_AUTHOR`
2. The strfry relay will accept their events on the next incoming `EVENT` (no restart needed).

#### Public subdomain — `essayist.decentnewsroom.com`

The Essayist relay is exposed publicly at:

```
wss://essayist.decentnewsroom.com
```

Caddy routes the host `essayist.decentnewsroom.com` directly to `strfry-essayist:7779` via the `@essayistRelay` matcher in `frankenphp/Caddyfile`. No separate nginx or proxy config is needed.

**Environment variables:**

| Variable | Default (dev) | Production value |
|---|---|---|
| `ESSAYIST_RELAY_DOMAIN` | `essayist.localhost` | `essayist.decentnewsroom.com` |
| `ESSAYIST_POLICY_TOKEN` | `changeme` | random 32-byte hex secret |

**Production `.env` excerpt:**
```dotenv
ESSAYIST_RELAY_DOMAIN=essayist.decentnewsroom.com
ESSAYIST_POLICY_TOKEN="<output of: php -r 'echo bin2hex(random_bytes(32));'>"
```

**DNS:** add a CNAME record `essayist.decentnewsroom.com → decentnewsroom.com` (same host, Caddy handles routing by `Host:` header). TLS is handled automatically by Caddy/Let's Encrypt when `SERVER_NAME` includes the wildcard `*.decentnewsroom.com`, or by the upstream proxy.

**Local development:**

```
ws://essayist.localhost
```

Browsers resolve `*.localhost` without a hosts-file entry in modern operating systems.

### 3. Writer self-request flow (simplified)

Writers do not fill out a form. Instead:

1. A logged-in writer visits `/essayist` landing page.
2. If they have no `ROLE_ESSAYIST_AUTHOR` or `ROLE_ESSAYIST_CANDIDATE`, a button appears: **"Request Writer Access"**.
3. Clicking it runs two eligibility checks:
   - Does our DB contain **at least 3 deduplicated articles** (kind 30023, grouped by `(pubkey, slug)`) from this pubkey?
   - Does the user's profile have a **Lightning address** (`lud16` populated in `User`)?
4. If **both pass** → system assigns `ROLE_ESSAYIST_CANDIDATE` to the user immediately (via PHP controller action) and they see confirmation: "Your request is in the queue."
5. If **either fails** → the button is disabled and explains which requirement is missing (no articles / not enough articles / no Lightning address).

Admin reviews candidates in `/admin/essayist` by looking at users with `ROLE_ESSAYIST_CANDIDATE`. For each candidate:
- View their article count and publication history
- Approve by running: `docker compose exec php bin/console user:elevate <npub> ROLE_ESSAYIST_AUTHOR`

The `user:elevate` command handles role transitions (removes CANDIDATE, adds AUTHOR).

---

## Suggested minimal technical scope

The following covers the "Required" items from Section 3 with the fewest new parts:

1. **`ROLE_ESSAYIST_CANDIDATE`, `ROLE_ESSAYIST_AUTHOR` and `ROLE_ESSAYIST_SUPPORTER` in `RolesEnum`** — three enum cases:
   - `ROLE_ESSAYIST_CANDIDATE`: assigned when a logged-in writer self-requests access (if they have published articles). Queued for admin review.
   - `ROLE_ESSAYIST_AUTHOR`: granted to approved writers via `user:elevate` (replaces `ROLE_ESSAYIST_CANDIDATE`). Controls relay write access.
   - `ROLE_ESSAYIST_SUPPORTER`: granted to readers who support an approved writer. Gates the Decent Newsroom feed page during the pre-public-launch phase.

2. **Relay write-policy endpoint: `/api/internal/essayist/writer/{pubkey}`** — queries the `User` entity to check if the given pubkey has `ROLE_ESSAYIST_AUTHOR`. Returns `{"approved": true/false}` based on role. Protected by bearer token (`ESSAYIST_POLICY_TOKEN`). ✅ Already implemented.

3. **Landing page: `/essayist`** — publicly accessible page with:
   - Hero section (headline, subheadline, CTAs)
   - Writer self-request button (visible to logged-in users without the role)
   - Pre-check logic (both conditions must pass for the button to be active):
     1. `ArticleRepository::countDeduplicatedByPubkey(pubkey, kind: 30023) >= 3`
     2. `$user->getLud16() !== null` (Lightning address present in profile)
   - If eligible: "Request Writer Access" button is active
   - If not eligible: button is disabled with an explanation of which check(s) failed
   - FAQ section (reuse existing copy from docs)
   - Link to `/essayist/feed` for approved readers

4. **Feed page: `/essayist/feed`** — a Symfony controller that:
   - Requires `ROLE_ESSAYIST_SUPPORTER` (redirect unauthenticated users to landing)
   - Queries `strfry-essayist` (via `NostrClient` pointed at `ws://strfry-essayist:7779`) for kind 30023 events
   - Renders with existing `CardList` organism + article cards
   - Pagination support

5. **Writer request tracking** — built into the role system:
   - No separate log table needed; state is tracked via `ROLE_ESSAYIST_CANDIDATE`
   - When a writer clicks "Request Access", the controller checks for articles and (if present) assigns the role via PHP
   - Admin views users with `ROLE_ESSAYIST_CANDIDATE` in `/admin/essayist`
   - Admin approval runs `user:elevate <npub> ROLE_ESSAYIST_AUTHOR` (which removes CANDIDATE and adds AUTHOR)

6. **Admin section** — add `/admin/essayist` with two simple views:
   - Pending writer candidates (query users with `ROLE_ESSAYIST_CANDIDATE`, display npub, article count, approval button)
   - Approved writers (query users with `ROLE_ESSAYIST_AUTHOR`, display npub, approval date, action to remove role)
   - Readers with access (query users with `ROLE_ESSAYIST_SUPPORTER`, display npub, access date, action to revoke)

7. **No form or log table needed.** State is tracked entirely by the role system. All writer metadata is already in `User` (npub, lud16, metadata).

**Explicitly defer until receipt detection works:** automated role grant on payment. Until then, admin manually verifies each payment and runs `user:elevate` to grant `ROLE_ESSAYIST_SUPPORTER`.

**Relay URL advertising:** do not add `wss://essayist.decentnewsroom.com` to any NIP-65 relay list or link it in the UI until Phase 1 public launch begins.

---

## Writer Self-Request Flow (Detailed)

### Landing Page (`/essayist`)

When a logged-in writer without `ROLE_ESSAYIST_AUTHOR` or `ROLE_ESSAYIST_CANDIDATE` visits the landing page:

```
┌─────────────────────────────────────────┐
│ Essayist Landing Page                   │
├─────────────────────────────────────────┤
│ Hero Section                            │
│ "A writer-first relay for essays"       │
│ "Support writers. Enter the relay."      │
│                                         │
│ [Browse Founding Writers] [Request Access] │
├─────────────────────────────────────────┤
│ How It Works (static copy)              │
│ Why Essayist Exists (static copy)       │
│ FAQ (static copy)                       │
└─────────────────────────────────────────┘
```

**States:**

- **Unauthenticated user**: **[Request Access]** button links to login.
- **Authenticated user without either role**: Button state depends on eligibility checks:
  - **Eligibility check 1**: `ArticleRepository::countDeduplicatedByPubkey(pubkey)` ≥ 3 (kind 30023, grouped by `pubkey + slug`)
  - **Eligibility check 2**: `User::$lud16` is not null/empty
  - If **both pass**: button is active — on click → POST to `/essayist/request-access` → assigns `ROLE_ESSAYIST_CANDIDATE` → shows "Your request has been submitted for review."
  - If **check 1 fails**: button is disabled, tooltip: "You need at least 3 articles known to Decent Newsroom. Publish your work on Nostr and check back once it has been discovered."
  - If **check 2 fails**: button is disabled, tooltip: "Add a Lightning address to your Nostr profile first. This is required to receive reader support."
  - If **both fail**: show the most actionable message (no articles takes priority).
- **Authenticated user with `ROLE_ESSAYIST_CANDIDATE`**: Shows "Your request is pending review. Check back soon."
- **Authenticated user with `ROLE_ESSAYIST_AUTHOR`**: Shows "You are approved. [Start publishing]" (link to editor).

### Admin Review Flow

Admin visits `/admin/essayist`:

```
┌───────────────────────────────────────────────────────────────────┐
│ Admin: Essayist Writers                                           │
├─────────────────────────────── ──────────────────────────────────┤
│ Pending Candidates           │ Approved Writers                  │
│ (ROLE_ESSAYIST_CANDIDATE)    │ (ROLE_ESSAYIST_AUTHOR)           │
├──────────────────────────────┼───────────────────────────────────┤
│ npub:abc123…                 │ npub:xyz789…                      │
│ Articles: 7                  │ Approved: 2 weeks ago             │
│ Requested: 3 days ago        │ [Details] [Revoke]                │
│ [Details] [Approve]          │                                   │
│                              │ npub:def456…                      │
│ npub:def456…                 │ Approved: 1 day ago               │
│ Articles: 0                  │ [Details] [Revoke]                │
│ Requested: 1 day ago         │                                   │
│ [Can't approve - no articles]│                                   │
└──────────────────────────────┴───────────────────────────────────┘
```

**Admin workflow:**
1. View pending candidates in the left panel
2. Click **[Details]** to see article list, check that articles are genuine Nostr content (not excerpts/links)
3. Click **[Approve]** to run: `user:elevate <npub> ROLE_ESSAYIST_AUTHOR`
   - System removes `ROLE_ESSAYIST_CANDIDATE` and adds `ROLE_ESSAYIST_AUTHOR`
   - Writer immediately gains relay write access
   - Candidate moves from left panel to right panel
4. Click **[Reject]** to remove the `ROLE_ESSAYIST_CANDIDATE` role entirely, without promoting
5. For already-**approved** writers: click **[Downgrade]** or **[Revoke]** to remove `ROLE_ESSAYIST_AUTHOR`
   - Downgrade: removes AUTHOR, restores CANDIDATE (pending re-review)
   - Revoke: removes AUTHOR entirely (no relay access, not in queue)

Admin clicks **[Approve]** → backend runs `user:elevate <npub> ROLE_ESSAYIST_AUTHOR` → updates log entry with timestamp and admin pubkey → relays the change.

---

## Integration with the Unfold subdomain system

The `UnfoldBundle` supports hosted subdomains (`essayist.example.com`). If Essayist is meant to have its own domain identity, it can be configured as an Unfold site rather than a section of the main app. This would affect the routing design of the landing page and writer cards. Decide this before building the landing page.

---

## Writer whitelist is not published

The founding writer list is stored only as user roles (`ROLE_ESSAYIST_AUTHOR`) in the local database. It is not published as a public Nostr event (kind 39089), which keeps the curation proprietary to your instance and prevents easy cloning by competitors.

---

## NIP-11 relay information

The Essayist relay publishes a NIP-11 relay information document at `https://essayist.decentnewsroom.com` (HTTP `GET` with `Accept: application/nostr+json`). The document is generated automatically by strfry from `docker/strfry-essayist/strfry.conf`:

```json
{
  "name": "Essayist",
  "description": "A writer-first relay for longform work on Nostr. Publishing requires approval. Reading is open.",
  "pubkey": "d475ce4b3977507130f42c7f86346ef936800f3ae74d5ecf8089280cdc1923e9",
  "contact": "decentnewsroom.com",
  "supported_nips": [1, 2, 9, 11, 12, 15, 16, 20, 22],
  "software": "strfry",
  "limitation": {
    "payment_required": false,
    "auth_required": false,
    "restricted_writes": true
  }
}
```

`restricted_writes: true` signals to clients that write access requires prior approval. Readers (subscribers) are unrestricted at the relay layer.

> **Phase 1 note:** during Phase 1 (writer signup), the relay URL is not linked in the UI and not published in any NIP-65 relay list. The NIP-11 document is technically fetchable at `https://essayist.decentnewsroom.com`, but not advertised. Writer access via the relay is live; reader access is served through the gated DN feed page. The relay URL is published and NIP-11 discovery activated at Phase 2 (reader access launch).

