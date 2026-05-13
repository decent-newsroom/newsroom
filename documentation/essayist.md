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
* Nostr login or signup.
* Founding writer application form.
* Manual writer approval.
* Founding writer pack page.
* Public follow pack or exportable list of approved writers.
* Contribution page where a reader chooses an approved writer.
* Payment or zap flow to that writer.
* Manual or semi-automatic receipt verification.
* Access flag once support is verified.
* Basic admin view for applications, recipients, payments, and access grants.

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

## Phase 0: Pre-Public Launch — Decent Newsroom Gated Feed

Before the relay is advertised publicly, Essayist content is available exclusively through a dedicated feed page on Decent Newsroom (`decentnewsroom.com/essayist`). The raw relay URL (`wss://essayist.decentnewsroom.com`) is not published or linked anywhere during this phase.

### How it works

- Writers approved to the founding pack publish their longform articles (kind 30023) directly to the Essayist relay. The write policy enforces approval.
- Decent Newsroom reads from the Essayist relay on the **server side** and renders the feed page. Readers never hold the relay URL.
- The feed page is behind a **`ROLE_ESSAYIST_SUPPORTER` role gate**. Only logged-in users who have been granted this role can view it.
- A user earns `ROLE_ESSAYIST_SUPPORTER` by supporting an approved writer and having that support verified (manually by admin during launch; automated once zap receipt detection is fixed).

### What this achieves

- **Writers** get a real publication surface immediately, without waiting for the public launch campaign.
- **Reader access** is meaningful and scarce from day one — it costs something, even before the relay URL is public.
- **The relay** accumulates a body of content before anyone can connect to it directly. When the relay is eventually advertised, it is not empty.
- **Decent Newsroom** controls the entire read experience during this phase. There is no risk of someone scraping the relay and publishing the content elsewhere before launch.

### What this requires technically

- A relay feed page controller at `/essayist` (or `/essayist/feed`) that subscribes to `strfry-essayist` internally and renders articles using the existing card components.
- Route protected by `ROLE_ESSAYIST_SUPPORTER` (redirect to landing/support page if not granted).
- The relay subdomain (`wss://essayist.decentnewsroom.com`) remains functional but is not linked or included in NIP-11 `relay_list` events until Phase 2.

### Transition to Phase 1

Once the founding pack has enough credible writers and the first supporters have read access, the relay URL is published and the public campaign begins. Existing supporters retain their role. New supporters are onboarded through the same flow.

---

## Phase 1: Curated Seed Cohort

Privately invite a small number of writers into the founding pack.

This is not a neutral market-discovered whitelist. It is a curated seed group. That is acceptable as long as it is stated clearly.

### Goal

Start with enough credible writers that readers have real people to support.

### Target

* 5 to 10 credible founding writers for a quiet test.
* 10 to 25 founding writers for a public launch.

### Writer Criteria

A founding writer should have:

* a Nostr pubkey;
* existing longform work;
* a working payment endpoint;
* willingness to be listed publicly;
* at least five eligible articles from the last three months, or an operator-approved equivalent body of work;
* at least one article old enough to show they were not created only for the campaign.

The article rule can be strict for open applications and more flexible for invited writers, but the distinction should be clear.

## Phase 2: Public Interest Test

After the founding pack exists, publish the Essayist landing page.

The page should invite three actions:

* writers can apply;
* readers can choose writers they would support;
* readers can make a small founding contribution if the payment flow is ready.

### Goal

Measure actual interest, not applause.

### Strong Signals

* reader signs in with Nostr;
* reader chooses a writer to support;
* reader makes a support payment;
* writer applies with real work;
* writer verifies payout endpoint;
* curator proposes a useful pack.

### Weak Signals

* likes;
* reposts;
* comments saying “interesting”;
* anonymous visits;
* vague waitlist signup.

## Phase 3: Launch Decision

Run the public interest test for 14 to 21 days.

At the end, choose one of four outcomes:

### Launch

Enough writers and reader support exist. Open Essayist access.

### Small Pilot

Some interest exists, but not enough for a full public launch. Run a private pilot with the founding writers and first supporters.

### Reposition

Writers are interested but readers are not paying. Reframe as a follow-pack, magazine, or discovery project first.

### Pause

Neither side shows enough interest. Publish what was learned and do not overbuild.

---

# 5. Suggested Validation Thresholds

For a small but meaningful launch:

* 10 approved writers.
* 25 reader sign-ins.
* 10 readers choosing at least one writer to support.
* 5 actual support payments.
* 1 public founding writer pack.

For a stronger launch:

* 25 approved or pending writers.
* 50 reader sign-ins.
* 20 readers choosing writers.
* 10 actual support payments.
* 3 viable follow packs.

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

To apply, submit:

* your Nostr pubkey;
* at least five eligible longform articles;
* at least one article older than thirty-nine days or published before the campaign announcement;
* your main topics or language;
* a short bio;
* a payment endpoint for support.

## Note on Review

The founding pack is manually reviewed. The goal is not to judge taste from above. The goal is to start with real writers, real work, and a recipient pool readers can trust.

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

During the first launch period, the relay operator fee is waived. Readers get access by supporting at least one approved writer from the founding pack.

Writers with an existing body of longform work can apply to join the founding pack. Approved writers will be listed publicly and may be included in follow packs by topic, language, or editorial focus.

This is not a normal waitlist. I want to see whether readers will actually support writers before I build a heavier subscription system.

## Announcement Option B

Essayist is a small experiment in writer-first publishing on Nostr.

Instead of launching with an empty relay and asking people to pay the platform, I’m starting with writers.

The operator fee is waived during launch. To get access, readers support an approved writer directly.

First step: assemble the founding writer pack.

Writers can apply with existing longform work. Readers can browse the pack and choose who they would support.

If enough support appears, the relay opens.

## Announcement Option C

Launching soon: Essayist.

A Nostr relay for essays where access begins by supporting writers.

The first launch period waives the operator fee. Readers support approved writers from the founding pack. Writers apply with existing work. Follow packs make the recipient pool visible.

The test is simple: will readers fund writers before there is a platform economy around them?

---

# 13. Private Writer Invitation

## Short Version

I’m putting together a founding writer pack for Essayist, a writer-first Nostr relay for longform work.

The launch model is simple: during the first period, the relay operator fee is waived. Readers get access by supporting approved writers directly. The founding pack is the first group of writers readers can choose from.

I’m inviting a small number of writers with existing work before opening public applications. Would you be interested in being listed as one of the founding writers?

## Longer Version

I’m preparing a small launch experiment called Essayist.

It is a Nostr relay for longform writing, but the launch model is different from a normal paid platform. During the first launch period, the relay operator fee is waived. Readers get access by supporting approved writers directly.

That means I need a credible founding writer pack before testing reader demand. I’m inviting a small group of writers with existing work to be listed as launch-eligible recipients.

Being listed would mean readers can discover your work and support you as part of the access flow. It does not require exclusivity, and the goal is not to lock content into a platform. The goal is to test whether a writer-first relay can start with direct support instead of empty signups.

Would you want to be included in the founding pack?

---

# 14. Social Posts

## Post 1: Teaser

I’m working on Essayist: a writer-first Nostr relay for longform work.

The launch premise is simple:

The first money should go to writers.

During launch, the operator fee is waived. Readers get access by supporting approved writers from the founding pack.

More soon.

## Post 2: Writer Call

Looking for founding writers for Essayist.

Essayist is a writer-first Nostr relay where launch access begins by supporting approved writers.

If you have existing longform work, a Nostr pubkey, and a payment endpoint, you can apply for the founding pack.

The goal is to start with real writers, not empty accounts.

## Post 3: Reader Call

Would you support a writer to access a writer-first relay?

That is the core Essayist test.

During launch, the operator fee is waived. Readers choose an approved writer from the founding pack, support them directly, and get access.

If that does not interest people, I should know before overbuilding it.

## Post 4: Follow Pack

The Essayist whitelist will not be hidden admin state.

Approved writers will be published into follow packs so readers can inspect, follow, and support them.

The first pack is the founding writer pack. Later packs can be organized by topic, language, region, or publication.

## Post 5: Launch Logic

Essayist starts with a constraint:

No empty platform economy.

First, assemble writers with existing work.
Then, ask readers to support them.
Only then decide whether the relay should grow.

The point is not to collect signups. The point is to test support.

---

# 15. Email / Newsletter Copy

## Subject Options

* Introducing Essayist: a writer-first relay for longform work
* The first money should go to writers
* Help test Essayist, a Nostr relay for essays
* Writers wanted for the Essayist founding pack
* Would you support writers to access a relay?

## Email Body

I’m preparing a small launch experiment called Essayist.

Essayist is a writer-first Nostr relay for longform publishing. The launch premise is simple: the first money should go to writers.

During the first launch period, the relay operator fee will be waived. Readers will get access by supporting at least one approved writer from the founding pack.

That means the first step is not a broad platform launch. The first step is assembling a credible founding writer pack.

Writers can apply with existing longform work, a Nostr pubkey, and a payment endpoint. Approved writers will be listed publicly and may be included in follow packs by topic, language, region, or editorial focus.

Readers can browse the founding pack, choose who they would support, and help decide whether this should become a larger publishing relay.

The test is deliberately simple:

Will readers support writers before there is a full platform economy around them?

If the answer is yes, Essayist opens more broadly. If the answer is no, that is useful to know before building too much.

---

# 16. Launch Timeline

## Week 0: Internal Setup

Prepare the minimum system.

Tasks:

* create Essayist landing page;
* define founding writer criteria;
* create writer application form;
* create admin review view;
* create founding writer pack page;
* create reader interest/support flow;
* write FAQ;
* prepare private writer invitation;
* prepare launch posts.

## Week 1: Private Writer Seeding

Invite the first writers directly.

Goals:

* get 5 to 10 credible writers to agree;
* verify payment endpoints;
* collect bios and article links;
* create the first founding pack.

Do not publicly launch reader support until there is at least a small credible pack.

## Week 2: Public Writer Applications

Open applications publicly.

Goals:

* test writer-side demand;
* expand the founding pack;
* collect topic and language metadata;
* prepare follow packs.

Marketing focus:

* “Writers wanted for Essayist founding pack.”

## Week 3: Reader Interest Page

Open reader signups and pack browsing.

Goals:

* see who signs in;
* see which writers readers choose;
* collect pledge or support intent;
* optionally accept small founding contributions.

Marketing focus:

* “Would you support a writer to access a writer-first relay?”

## Week 4: Decision Point

Review the data.

Questions:

* Are there enough credible writers?
* Did readers choose anyone to support?
* Did any sats move?
* Which copy confused people?
* Which packs got attention?
* Is there enough signal for a pilot or launch?

Possible decisions:

* open a small pilot;
* continue recruiting writers;
* run another reader test;
* reposition as follow-pack discovery first;
* pause.

## Weeks 5–8: Pilot or Public Launch

If the signal is strong enough, launch the first access period.

Launch rule:

* operator fee waived;
* support one approved writer;
* receive access;
* publish follow pack;
* report aggregate support.

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
2. Write the private invite message.
3. Invite 5 to 10 writers manually.
4. Build the landing page with three actions: browse, apply, support.
5. Create the founding writer pack page.
6. Publish the writer call.
7. Open reader interest only after the first pack exists.
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

`FollowPackService::getArticlesForPubkeys()` already queries articles by pubkey with ordering and deduplication. The "at least five eligible articles from the last three months" rule can be enforced automatically against the local database during writer application review, without new queries.

### ZapButton component

`ZapButton.php` (Twig Live Component, `Molecules/`) generates NIP-57 zap request events and BOLT11 invoices via `LNURLResolver` and `NostrSigner`. It supports zap splits. It can be placed on a writer card on the Essayist landing page without modification.

**Limitation:** the component has no server-side receipt detection. It shows an invoice and lets the user click "Mark as paid" manually. There is no callback or webhook that fires when a payment lands. See the zap receipt gap below.

### Lightning address storage

`User::$lud16` already stores the lightning address for each logged-in user. This is the payment endpoint required by the writer application rules.

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

**Read access — pre-public-launch phase:**

During the pre-public-launch phase (Phase 0), reads are **not** served by exposing the relay URL to clients. Instead:

- Decent Newsroom reads from `strfry-essayist` **server-side** and renders content on a gated feed page (`/essayist`).
- The feed page requires `ROLE_ESSAYIST_SUPPORTER`. Anonymous users and logged-in users without the role are redirected to the landing/support page.
- The relay WebSocket URL (`wss://essayist.decentnewsroom.com`) is kept unlisted — not linked in the UI, not published in NIP-65 relay lists, not included in NIP-11 discovery. It is technically accessible to anyone who guesses it, but it is not advertised.

strfry itself has no built-in read-gating mechanism; access control at this phase is entirely at the Decent Newsroom application layer.

**Phase 2 (public launch):** the relay URL is published, NIP-11 discovery is activated, and clients can subscribe directly. `ROLE_ESSAYIST_SUPPORTER` continues to be the gate for the curated Decent Newsroom feed page, but the relay is no longer hidden.

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
ESSAYIST_POLICY_TOKEN=<output of: php -r "echo bin2hex(random_bytes(32));"> 
```

**DNS:** add a CNAME record `essayist.decentnewsroom.com → decentnewsroom.com` (same host, Caddy handles routing by `Host:` header). TLS is handled automatically by Caddy/Let's Encrypt when `SERVER_NAME` includes the wildcard `*.decentnewsroom.com`, or by the upstream proxy.

**Local development:**

```
ws://essayist.localhost
```

Browsers resolve `*.localhost` without a hosts-file entry in modern operating systems.

### 3. No writer application entity

There is no database entity or form for founding writer applications yet. The closest existing pattern is `ActiveIndexingSubscription` (npub + status fields + review metadata).

**Required work:** create `EssayistApplication` entity (npub, status: pending/approved/rejected, bio, topics, submitted_at, reviewed_at, reviewer_notes), a migration, a Symfony form, and an admin list view.

---

## Suggested minimal technical scope

The following covers the "Required" items from Section 3 with the fewest new parts:

1. **`ROLE_ESSAYIST_AUTHOR` and `ROLE_ESSAYIST_SUPPORTER` in `RolesEnum`** — two enum cases:
   - `ROLE_ESSAYIST_AUTHOR`: granted to approved writers via `user:elevate`. Controls relay write access.
   - `ROLE_ESSAYIST_SUPPORTER`: granted to readers who support an approved writer. Gates the Decent Newsroom feed page during the pre-public-launch phase.

2. **Relay write-policy endpoint: `/api/internal/essayist/writer/{pubkey}`** — queries the `User` entity to check if the given pubkey has `ROLE_ESSAYIST_AUTHOR`. Returns 200 if authorized, 403 otherwise. Protected by bearer token (`ESSAYIST_POLICY_TOKEN`).

3. **Relay feed page: `/essayist`** — a Symfony controller that queries `strfry-essayist` (via `NostrClient` pointed at `ws://strfry-essayist:7779`) for kind 30023 events, renders them with existing article card components, and is access-controlled by `ROLE_ESSAYIST_SUPPORTER`. Unauthenticated or ungated users are redirected to the Essayist landing page.

4. **Landing page controller + template** — uses existing `UserFromNpub` and `ZapButton` Twig components for writer cards (once a writer is approved, they can be listed in the landing page template or fetched from the relay feed to display recent articles). Publicly accessible but shows full writer list only to supporters.

5. **Admin dashboard command:** existing `user:elevate` command is used to grant both roles:
   ```bash
   docker compose exec php bin/console user:elevate <npub> ROLE_ESSAYIST_AUTHOR
   docker compose exec php bin/console user:elevate <npub> ROLE_ESSAYIST_SUPPORTER
   ```

6. **No separate application tracking table needed.** Writers apply via email, form, or direct approach; admin reviews their pubkey and recent articles manually (or with a quick `FollowPackService::getArticlesForPubkeys()` check if desired), then grants the role.

**Explicitly defer until receipt detection works:** automated role grant on payment. Until then, admin manually verifies each payment and runs `user:elevate` to grant `ROLE_ESSAYIST_SUPPORTER`.

**Relay URL advertising:** do not add `wss://essayist.decentnewsroom.com` to any NIP-65 relay list or link it in the UI until Phase 1 public launch begins.

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

> **Phase 0 note:** during the pre-public-launch phase, the relay URL is intentionally unlisted. The NIP-11 document exists and is technically fetchable, but `wss://essayist.decentnewsroom.com` is not published in any NIP-65 relay list, not linked in the Decent Newsroom UI, and not included in any public relay discovery index. Read access during this phase is exclusively through the gated Decent Newsroom feed page (`/essayist`). The NIP-11 document is activated for public discovery at Phase 1 launch.

