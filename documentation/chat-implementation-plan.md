# DN Community Chat — Implementation Plan

Phased implementation plan for the private, invite-only, community-scoped chat module. Structured as a self-contained **ChatBundle** (mirroring `UnfoldBundle`). See `chat.md` for the full product specification, `NIP/28.md` for the chat message event model (kind 42) and channel setup/moderation (kinds 40-41, 43-44).

---

## Overview

| Aspect | Decision |
|--------|----------|
| **Structure** | `src/ChatBundle/` — self-contained Symfony bundle (like `UnfoldBundle`) |
| **Auth model** | Separate `chat` firewall with cookie/session auth (not NIP-98) |
| **Identity** | Custodial Nostr keypairs, server-side signing |
| **Relay** | Separate `strfry-chat` instance (port 7778), kinds 0, 40-44 only |
| **Message storage** | **Relay-only** — no DB persistence of messages; the chat relay is the sole store |
| **Real-time** | Mercure SSE to browser, Stimulus controllers |
| **History** | Backend queries chat relay via WebSocket REQ; returns JSON to frontend |
| **Encryption** | AES-256-GCM with random IVs via `APP_ENCRYPTION_KEY` |
| **User entity** | Separate `ChatUser` inside the bundle — does NOT extend/reference `App\Entity\User` |

---

## Bundle Architecture

The ChatBundle follows the same pattern as `UnfoldBundle`:

```
src/ChatBundle/
  ChatBundle.php                      # Bundle class (extends Bundle)
  DependencyInjection/
    ChatExtension.php                 # Loads bundle services.yaml, sets parameters
    Configuration.php                 # Bundle config schema (chat_relay_url, encryption)
  Resources/
    config/
      services.yaml                   # Auto-wires all ChatBundle classes
      routes.yaml                     # Chat subdomain routes (conditioned on _chat_community)
    views/                            # Twig templates (namespaced @Chat)
      layout.html.twig
      activate.html.twig
      invite_required.html.twig
      groups/
        index.html.twig
        show.html.twig
      contacts/
        index.html.twig
      profile/
        edit.html.twig
      manage/
        groups.html.twig
        members.html.twig
        invites.html.twig
      partials/
        message.html.twig
        group_item.html.twig
  Entity/                             # Doctrine entities (within bundle)
    ChatCommunity.php
    ChatUser.php
    ChatCommunityMembership.php
    ChatGroup.php
    ChatGroupMembership.php
    ChatInvite.php
    ChatSession.php
  Enum/                               # Bundle-scoped enums
    ChatRole.php
    ChatCommunityStatus.php
    ChatUserStatus.php
    ChatGroupStatus.php
    ChatInviteType.php
  Repository/                         # Doctrine repositories
    ChatCommunityRepository.php
    ChatUserRepository.php
    ChatGroupRepository.php
    ChatGroupMembershipRepository.php
    ChatCommunityMembershipRepository.php
    ChatInviteRepository.php
    ChatSessionRepository.php
  Dto/                                # Data transfer objects
    ChatMessageDto.php
    ChatGroupDto.php
  Service/                            # Business logic
    ChatEncryptionService.php
    ChatKeyManager.php
    ChatCommunityResolver.php
    ChatEventSigner.php
    ChatRelayClient.php              # All relay I/O (publish, fetch, filter)
    ChatUserService.php
    ChatGroupService.php
    ChatInviteService.php
    ChatSessionManager.php
    ChatMessageService.php           # Sign + publish + Mercure push (no DB)
    ChatProfileService.php
    ChatAuthorizationChecker.php
  Security/                           # Auth (chat firewall)
    ChatUserProvider.php
    ChatSessionAuthenticator.php
    ChatRequestMatcher.php
  EventListener/                      # Request listener
    ChatRequestListener.php
  Controller/                         # Chat subdomain controllers
    ChatActivateController.php
    ChatGroupController.php          # Fetches messages from relay via ChatRelayClient
    ChatProfileController.php
    ChatContactsController.php
    ChatManageController.php
    ChatSettingsController.php
  Command/                            # CLI commands
    ChatDebugCommand.php
```

### Integration Points (outside the bundle)

These files live in the main app because they extend main-app concerns:

| File | Location | Why outside bundle |
|------|----------|--------------------|
| Admin controllers | `src/Controller/Administration/Chat/` | Part of `ROLE_ADMIN` admin section, reuses admin layout |
| Admin templates | `templates/admin/chat/` | Extends main admin base template |
| NIP-28 kinds | `src/Enum/KindsEnum.php` | Shared app-wide enum (kinds 40–44) |
| `CHAT` relay purpose | `src/Enum/RelayPurpose.php` | Shared app-wide enum |
| Security firewall | `config/packages/security.yaml` | App-level firewall config |
| Route import | `config/routes/chat.yaml` | Loads `@ChatBundle/Resources/config/routes.yaml` |
| Bundle registration | `config/bundles.php` | Standard Symfony registration |
| Service overrides | `config/services.yaml` | Complex bindings (encryption key, relay URL) |
| Stimulus controllers | `assets/controllers/chat/` | AssetMapper requires `assets/` directory |
| CSS | `assets/styles/04-pages/chat.css` | Follows project CSS organization |
| Docker relay | `docker/strfry-chat/` | Infrastructure, not PHP code |
| Migration | `migrations/` | Doctrine migrations must live in `migrations/` |

---

## Message Flow (relay-only)

Messages live exclusively on the private chat relay. No DB table for messages.

### Send

```
Browser → POST /groups/{slug}/messages
  → ChatGroupController (validates session + membership)
    → ChatMessageService.send()
      → ChatEventSigner.signForUser() — kind 42 (NIP-28), custodial key
      → ChatRelayClient.publish() — WebSocket to ws://strfy-chat:7778
      → HubInterface.publish() — Mercure SSE update to connected browsers
  ← JSON { ok: true, eventId }
```

### Read (initial page load)

```
Browser → GET /groups/{slug}
  → ChatGroupController (validates session + membership)
    → ChatRelayClient.fetchMessages(channelEventId, limit: 50)
      → WebSocket REQ to strfry-chat: {"kinds":[42],"#e":["<channelEventId>"],"limit":50}
      → Also fetches kind 43 (hide) and 44 (mute) for filtering
    → Resolves sender display names from ChatUser table
  ← Rendered HTML with initial messages
```

### Read (load more / pagination)

```
Browser → GET /groups/{slug}/history?before={timestamp}
  → ChatGroupController (validates session + membership)
    → ChatRelayClient.fetchMessages(channelEventId, limit: 50, until: timestamp)
  ← JSON array of message DTOs
```

### Real-time (new messages)

```
ChatMessageService.send()
  → publishes to Mercure topic: /chat/{communityId}/group/{groupSlug}
  → payload: ChatMessageDto JSON

Browser (Stimulus chat--messages controller)
  → EventSource subscribed to Mercure topic
  → onmessage: appends message to DOM, auto-scrolls
```

### Why not have JS read from the relay directly?

The chat relay is on the internal Docker network (`ws://strfry-chat:7778`). Exposing it publicly would require:
- A reverse proxy rule and public hostname
- Some form of relay-level auth (the relay itself has no concept of community/group membership)
- Duplicating authorization logic in JS

Keeping relay access server-side means all authorization stays in PHP, the relay stays internal, and the browser only sees authorized messages via the API + Mercure.

---

## Phase 1 — Foundation: Bundle Skeleton, Entities, Encryption

**Goal:** Bundle structure, data model, key custody, and community resolution.

### Bundle Skeleton

#### `src/ChatBundle/ChatBundle.php`
```php
class ChatBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__) . '/ChatBundle';
    }
}
```

#### `src/ChatBundle/DependencyInjection/ChatExtension.php`
- Loads `Resources/config/services.yaml`
- Sets parameters: `chat.relay_url`, `chat.encryption_key`

#### `src/ChatBundle/DependencyInjection/Configuration.php`
```php
$treeBuilder = new TreeBuilder('chat');
$treeBuilder->getRootNode()
    ->children()
        ->scalarNode('relay_url')
            ->defaultValue('ws://strfry-chat:7778')
        ->end()
    ->end();
```

#### `src/ChatBundle/Resources/config/services.yaml`
```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true
    App\ChatBundle\:
        resource: '../../'
        exclude:
            - '../../DependencyInjection/'
            - '../../Entity/'
```

#### `config/routes/chat.yaml`
```yaml
chat_bundle:
    resource: '@ChatBundle/Resources/config/routes.yaml'
```

#### `config/bundles.php`
```php
App\ChatBundle\ChatBundle::class => ['all' => true],
```

#### `config/packages/chat.yaml`
```yaml
chat:
    relay_url: '%env(default:chat_relay_default:CHAT_RELAY_URL)%'
```

### Entities (`src/ChatBundle/Entity/`)

7 entities. No message table — messages live on the relay only.

#### `ChatCommunity`
| Column | Type | Notes |
|--------|------|-------|
| `id` | int (PK, auto) | |
| `subdomain` | string(255), unique | e.g. `oakclub` |
| `name` | string(255) | Display name |
| `status` | string(20) | `active` / `suspended` |
| `relay_url` | string(500), nullable | Override relay URL per community |
| `created_at` | datetime_immutable | |
| `updated_at` | datetime_immutable | |

#### `ChatUser`
| Column | Type | Notes |
|--------|------|-------|
| `id` | int (PK, auto) | |
| `community_id` | FK → ChatCommunity | |
| `display_name` | string(255) | |
| `about` | text, nullable | |
| `pubkey` | string(64), indexed | Hex pubkey |
| `encrypted_private_key` | text | AES-256-GCM encrypted |
| `status` | string(20) | `pending` / `active` / `suspended` |
| `created_at` | datetime_immutable | |
| `activated_at` | datetime_immutable, nullable | |

Unique constraint: `(community_id, pubkey)`.

#### `ChatCommunityMembership`
| Column | Type | Notes |
|--------|------|-------|
| `id` | int (PK, auto) | |
| `user_id` | FK → ChatUser | |
| `community_id` | FK → ChatCommunity | |
| `role` | string(20) | `admin` / `guardian` / `user` |
| `joined_at` | datetime_immutable | |

Unique: `(user_id, community_id)`.

#### `ChatGroup`
| Column | Type | Notes |
|--------|------|-------|
| `id` | int (PK, auto) | |
| `community_id` | FK → ChatCommunity | |
| `slug` | string(255) | Unique per community |
| `name` | string(255) | |
| `channel_event_id` | string(64) | NIP-28 kind 40 event ID |
| `status` | string(20) | `active` / `archived` |
| `created_at` | datetime_immutable | |

Unique: `(community_id, slug)`.

#### `ChatGroupMembership`
| Column | Type | Notes |
|--------|------|-------|
| `id` | int (PK, auto) | |
| `user_id` | FK → ChatUser | |
| `group_id` | FK → ChatGroup | |
| `role` | string(20) | `admin` / `member` |
| `joined_at` | datetime_immutable | |

Unique: `(user_id, group_id)`.

#### `ChatInvite`
| Column | Type | Notes |
|--------|------|-------|
| `id` | int (PK, auto) | |
| `community_id` | FK → ChatCommunity | |
| `group_id` | FK → ChatGroup, nullable | Scoped to group if set |
| `token_hash` | string(64), indexed | SHA-256 of token |
| `type` | string(20) | `activation` / `group_join` |
| `role_to_grant` | string(20) | |
| `created_by_id` | FK → ChatUser | |
| `max_uses` | int, nullable | null = unlimited |
| `used_count` | int, default 0 | |
| `expires_at` | datetime_immutable, nullable | |
| `revoked_at` | datetime_immutable, nullable | |
| `created_at` | datetime_immutable | |

#### `ChatSession`
| Column | Type | Notes |
|--------|------|-------|
| `id` | int (PK, auto) | |
| `user_id` | FK → ChatUser | |
| `community_id` | FK → ChatCommunity | |
| `session_token` | string(128), indexed | SHA-256 hashed token |
| `expires_at` | datetime_immutable | |
| `revoked_at` | datetime_immutable, nullable | |
| `created_at` | datetime_immutable | |
| `last_seen_at` | datetime_immutable | |

### Enums

#### Inside the bundle (`src/ChatBundle/Enum/`)

| Enum | Values |
|------|--------|
| `ChatRole` | `ADMIN`, `GUARDIAN`, `USER` |
| `ChatCommunityStatus` | `ACTIVE`, `SUSPENDED` |
| `ChatUserStatus` | `PENDING`, `ACTIVE`, `SUSPENDED` |
| `ChatGroupStatus` | `ACTIVE`, `ARCHIVED` |
| `ChatInviteType` | `ACTIVATION`, `GROUP_JOIN` |

#### In main app (shared enums)

Add to `src/Enum/KindsEnum.php`:
```php
case CHANNEL_CREATE = 40;
case CHANNEL_METADATA = 41;
case CHANNEL_MESSAGE = 42; // NIP-28, chat text messages
case HIDE_MESSAGE = 43;
case MUTE_USER = 44;
```

Add to `src/Enum/RelayPurpose.php`:
```php
case CHAT = 'chat';
```

### Services (`src/ChatBundle/Service/`)

#### `ChatEncryptionService`
- Constructor: `$encryptionKey` (from `APP_ENCRYPTION_KEY` parameter)
- `encrypt(string $plaintext): string` — AES-256-GCM, random 12-byte IV, returns `base64(iv . ciphertext . tag)`
- `decrypt(string $ciphertext): string` — reverse
- **Critical:** Must use random IVs (not static/derived). Identical plaintexts must produce different ciphertexts.

#### `ChatKeyManager`
- `generateKeypair(): array{pubkey: string, encryptedPrivateKey: string}` — Uses `swentel\nostr\Key\Key`, encrypts via `ChatEncryptionService`
- `decryptPrivateKey(ChatUser $user): string` — Returns hex private key, held in memory only

#### `ChatCommunityResolver`
- Reads `_chat_community` request attribute (set by `ChatRequestListener`)
- Returns `?ChatCommunity`

### Event Listener (`src/ChatBundle/EventListener/`)

#### `ChatRequestListener`
- `#[AsEventListener(event: KernelEvents::REQUEST, priority: 31)]`
- Priority 31: runs just below `UnfoldRequestListener` (32), but before routing
- Extracts subdomain from host using same algorithm as `UnfoldRequestListener`
- Looks up `ChatCommunityRepository::findBySubdomain()`
- Sets `_chat_community` request attribute
- Falls through silently if not a chat subdomain (lets Unfold or main routing handle it)

### Configuration Wiring

In `config/services.yaml` (for complex bindings that can't be auto-wired):
```yaml
App\ChatBundle\Service\ChatEncryptionService:
    arguments:
        $encryptionKey: '%encryption_key%'

App\ChatBundle\EventListener\ChatRequestListener:
    arguments:
        $baseDomain: '%base_domain%'
```

### Migration
- Single migration creating 7 tables with indexes and foreign keys

### Dependencies
- None

---

## Phase 2 — Docker & Private Relay

**Goal:** Second strfry instance for chat traffic, isolated from public relay. This is the **sole message store** — no DB fallback.

### Docker Changes

#### `compose.yaml` — new service `strfry-chat`
```yaml
strfry-chat:
  image: dockurr/strfry:latest
  restart: unless-stopped
  command:
    - /bin/sh
    - -c
    - ./strfry relay /etc/strfry.conf
  volumes:
    - ./docker/strfry-chat/strfry.conf:/etc/strfry.conf:ro
    - ./docker/strfry-chat/write-policy.sh:/app/write-policy.sh:ro
    - strfry_chat_data:/var/lib/strfry
  ports:
    - "7778:7778"
```

Add `strfry_chat_data` to volumes.

#### `docker/strfry-chat/strfry.conf`
- Port: 7778
- Write policy: `./write-policy.sh`

#### `docker/strfry-chat/write-policy.sh`
- Accept kinds: 0, 40, 41, 42, 43, 44
- Reject all others

### RelayRegistry Update (`src/Service/Nostr/RelayRegistry.php`)
- Add `chatRelays` constructor parameter
- Wire `CHAT_RELAY_URL` in `services.yaml`
- Add `getChatRelay(): ?string` convenience method

### Dependencies
- Phase 1 (env var, enum)

---

## Phase 3 — Admin Backend

**Goal:** Admin CRUD for communities, users, groups, and invites. Admin controllers live in the main app (under `src/Controller/Administration/`) because they are protected by `ROLE_ADMIN` and share the admin layout.

### Services (inside bundle: `src/ChatBundle/Service/`)

#### `ChatEventSigner`
- `signForUser(ChatUser, int $kind, array $tags, string $content = ''): string` — decrypts key, signs with `swentel\nostr\Sign\Sign`, returns JSON

#### `ChatRelayClient`
Thin wrapper around `NostrRelayPool` scoped to the chat relay. All relay I/O goes through this service.
- `publish(Event $event): array` — publishes a signed event to the chat relay
- `fetchMessages(string $channelEventId, int $limit = 50, ?int $until = null): array` — REQ `{"kinds":[42],"#e":["<channelEventId>"],"limit":<limit>,"until":<until>}`, returns raw event objects
- `fetchModeration(string $channelEventId): array` — REQ `{"kinds":[43,44],"#e":["<channelEventId>"]}`, returns hide/mute events
- `fetchChannelMetadata(string $channelEventId): ?object` — REQ `{"kinds":[41],"#e":["<channelEventId>"],"limit":1}`

#### `ChatUserService`
- `createUser(ChatCommunity, string $displayName): ChatUser` — generates keys, stores encrypted, creates community membership
- `suspendUser(ChatUser): void`
- `activateUser(ChatUser): void`

#### `ChatGroupService`
- `createGroup(ChatCommunity, string $name, string $slug): ChatGroup` — signs kind 40 channel create event, publishes to chat relay via `ChatRelayClient`, stores `channelEventId`
- `archiveGroup(ChatGroup): void`
- `addMember(ChatGroup, ChatUser, string $role = 'member'): void`
- `removeMember(ChatGroup, ChatUser): void`

#### `ChatInviteService`
- `generateInvite(ChatCommunity, ChatInviteType, ?ChatGroup, string $roleToGrant, ?int $maxUses, ?\DateTimeImmutable $expiresAt, ChatUser $createdBy): string` — returns full URL with plaintext token
- `validateToken(string $token): ?ChatInvite` — checks hash, expiry, revocation, usage
- `redeemToken(string $token): ChatUser` — activates user, creates memberships
- `revokeInvite(ChatInvite): void`

### Controllers (main app: `src/Controller/Administration/Chat/`)

| Controller | Routes | Purpose |
|------------|--------|---------|
| `ChatAdminDashboardController` | `GET /admin/chat` | Community list overview |
| `ChatCommunityAdminController` | `/admin/chat/communities/*` | CRUD communities |
| `ChatUserAdminController` | `/admin/chat/communities/{id}/users/*` | Create/list/suspend users |
| `ChatGroupAdminController` | `/admin/chat/communities/{id}/groups/*` | Create/archive groups, manage members |
| `ChatInviteAdminController` | `/admin/chat/communities/{id}/invites/*` | Generate/revoke/list invites |
| `ChatSessionAdminController` | `/admin/chat/communities/{id}/sessions/*` | List/revoke sessions |

All routes guarded by `#[IsGranted('ROLE_ADMIN')]`.

### Templates (main app: `templates/admin/chat/`)
- `dashboard.html.twig`
- `communities/index.html.twig`, `communities/form.html.twig`, `communities/show.html.twig`
- `users/index.html.twig`
- `groups/index.html.twig`, `groups/members.html.twig`
- `invites/index.html.twig`
- `sessions/index.html.twig`

### Dependencies
- Phase 1, Phase 2

---

## Phase 4 — Authentication: Invite Activation & Chat Firewall

**Goal:** Separate security firewall for chat subdomains with session/cookie auth.

### Security (inside bundle: `src/ChatBundle/Security/`)

#### `ChatUserProvider`
- Implements `UserProviderInterface`
- Loads `ChatUser` by session token from `ChatSession` table
- `ChatUser` implements `UserInterface`:
  - `getRoles()` returns `['ROLE_CHAT_USER']` plus `ROLE_CHAT_ADMIN` or `ROLE_CHAT_GUARDIAN` based on community membership role
  - `getUserIdentifier()` returns pubkey

#### `ChatSessionAuthenticator`
- Reads `chat_session` cookie
- Validates against `ChatSession` table (not expired, not revoked)
- Returns `SelfValidatingPassport` with `ChatUser`
- On failure: redirects to invite-required page

#### `ChatRequestMatcher`
- Implements `RequestMatcherInterface`
- Returns `true` if request has `_chat_community` attribute (set by `ChatRequestListener` in Phase 1)
- This is how the `chat` firewall knows to activate — only for chat subdomain requests

### Session Management (inside bundle: `src/ChatBundle/Service/`)

#### `ChatSessionManager`
- `createSession(ChatUser, ChatCommunity): string` — generates 64-byte hex token, stores SHA-256 hash, sets `chat_session` cookie (HttpOnly, Secure, SameSite=Lax, scoped to subdomain)
- `validateSession(string $token): ?ChatSession`
- `revokeSession(int $sessionId): void`
- `revokeAllForUser(int $userId): void`
- `touchLastSeen(ChatSession): void`

### Security Configuration (`config/packages/security.yaml`)

```yaml
providers:
    # ...existing user_dto_provider...
    chat_user_provider:
        id: App\ChatBundle\Security\ChatUserProvider

firewalls:
    # ...dev firewall...
    chat:
        request_matcher: App\ChatBundle\Security\ChatRequestMatcher
        lazy: false
        stateless: false
        provider: chat_user_provider
        custom_authenticators:
            - App\ChatBundle\Security\ChatSessionAuthenticator
        logout:
            path: /chat/logout
        entry_point: App\ChatBundle\Security\ChatSessionAuthenticator
    # ...main firewall (must come AFTER chat)...
```

### Controllers (inside bundle: `src/ChatBundle/Controller/`)

#### `ChatActivateController`
- `GET /activate/{token}` — validates invite, activates user, creates session, sets cookie, redirects to `/groups`
- If group-scoped invite: also adds group membership

### Templates (inside bundle: `src/ChatBundle/Resources/views/`)
- `activate.html.twig` — success/error
- `invite_required.html.twig` — shown when unauthenticated

### Dependencies
- Phase 1, Phase 3

---

## Phase 5 — Chat Core: NIP-28 Messages, Relay I/O, Message Service

**Goal:** Send and receive chat messages via the private relay. All message data is relay-only.

### Services (inside bundle: `src/ChatBundle/Service/`)

#### `ChatMessageService`
Send flow:
1. Validate session, community, group membership via `ChatAuthorizationChecker`
2. Build kind 42 event (NIP-28): content = message text, tags = `["e", channelEventId, relayUrl, "root"]`
3. For replies: add `["e", replyEventId, relayUrl, "reply"]` and `["p", replyPubkey, relayUrl]`
4. Sign via `ChatEventSigner`
5. Publish to chat relay via `ChatRelayClient.publish()`
6. Publish Mercure update to `/chat/{communityId}/group/{groupSlug}` with the message DTO as payload
7. Return the message DTO

No DB write. The relay is the only store.

#### `ChatProfileService`
- `updateProfile(ChatUser, string $displayName, ?string $about): void` — signs kind 0, publishes to chat relay

#### `ChatAuthorizationChecker`
Fail-closed checks:
- `canAccessCommunity(ChatUser, ChatCommunity): bool`
- `canAccessGroup(ChatUser, ChatGroup): bool`
- `canSendMessage(ChatUser, ChatGroup): bool`
- `isGroupAdmin(ChatUser, ChatGroup): bool`
- `isCommunityAdmin(ChatUser, ChatCommunity): bool`
- Every controller action calls these before proceeding

### Controllers (inside bundle: `src/ChatBundle/Controller/`)

| Controller | Route | Method | Purpose |
|------------|-------|--------|---------|
| `ChatGroupController` | `/groups` | GET | List user's groups |
| `ChatGroupController` | `/groups/{slug}` | GET | Group chat view (fetches last 50 messages from relay) |
| `ChatGroupController` | `/groups/{slug}/messages` | POST | Send message (AJAX, JSON) |
| `ChatGroupController` | `/groups/{slug}/history` | GET | Paginated older messages (AJAX, `?before=timestamp`) |
| `ChatProfileController` | `/profile` | GET/POST | Edit profile |
| `ChatContactsController` | `/contacts` | GET | Users in shared groups |

**Message loading in controllers:**
- `GET /groups/{slug}` — calls `ChatRelayClient.fetchMessages(channelEventId, limit: 50)`, also fetches kind 43/44 events for filtering, resolves sender names from `ChatUser` table, renders HTML
- `GET /groups/{slug}/history` — calls `ChatRelayClient.fetchMessages(channelEventId, limit: 50, until: beforeTimestamp)`, returns JSON array of `ChatMessageDto`

All routes conditioned on `request.attributes.has('_chat_community')` in `routes.yaml`.

### DTOs (inside bundle: `src/ChatBundle/Dto/`)
- `ChatMessageDto` — `eventId`, `senderPubkey`, `senderDisplayName`, `content`, `createdAt`, `isReply`, `replyToEventId`
- `ChatGroupDto` — `slug`, `name`, `status`

### Dependencies
- Phase 1–4

---

## Phase 6 — Real-time UI: Mercure, Stimulus, Templates

**Goal:** Live chat interface with SSE message streaming.

### Stimulus Controllers (`assets/controllers/chat/`)

#### `chat_messages_controller.js`
```
data-controller="chat--messages"
```
- **Values:** `groupSlug` (String), `communityId` (String), `hubUrl` (String), `sendUrl` (String), `historyUrl` (String)
- **Targets:** `messageList`, `input`, `sendButton`, `loadMore`
- **Connect:** Subscribe to Mercure topic `/chat/{communityId}/group/{groupSlug}`
- **onmessage:** Parse JSON, build message HTML from DTO, append to `messageList`, auto-scroll to bottom
- **send action:** POST to `sendUrl` with `{ content: inputValue }`, clear input, disable button during send
- **loadMore action:** GET `historyUrl?before={oldestTimestamp}`, prepend messages to list
- **Disconnect:** Close EventSource

#### `chat_groups_controller.js`
```
data-controller="chat--groups"
```
- Optional: subscribe to `/chat/{communityId}/unread` for live unread badges

### CSS (`assets/styles/04-pages/chat.css`)
- Chat layout: sidebar (group list, 280px) + main panel (messages + input)
- Message bubbles: own messages right-aligned, others left-aligned
- Timestamps, sender names, reply threading indicators
- Mobile: stacked layout, group list becomes top bar
- Input area: textarea + send button, fixed to bottom

### Templates (inside bundle: `src/ChatBundle/Resources/views/`)

All templates are namespaced as `@Chat/...` (Symfony resolves via `ChatBundle::getPath()`).

#### `layout.html.twig`
- Minimal chat-specific layout (not the main DN layout)
- Community name in header
- Mercure hub URL meta tag
- Includes chat.css

#### `groups/index.html.twig`
- Group list with links to `/groups/{slug}`

#### `groups/show.html.twig`
- Chat view wired to `chat--messages` controller
- Message history (server-rendered initial load from relay)
- Input form with CSRF token
- Load more button

#### `contacts/index.html.twig`
- Users in shared groups

#### `profile/edit.html.twig`
- Display name + about fields

#### `partials/message.html.twig`
- Single message: sender name, content, timestamp
- Reused for initial server-rendered load

#### `partials/group_item.html.twig`
- Group name, link

### Mercure Topics
| Topic | Payload | Purpose |
|-------|---------|---------|
| `/chat/{communityId}/group/{groupSlug}` | `ChatMessageDto` JSON | New message in group |

### Dependencies
- Phase 5

---

## Phase 7 — Moderation, Guardian Scope, Polish

**Goal:** Message hiding, user muting, scoped management, security hardening.

### Moderation (inside bundle, relay-native)

Moderation is fully Nostr-native: kind 43 (hide) and kind 44 (mute) events are published to the relay. The backend filters them when loading messages.

#### Extend `ChatMessageService`
- `hideMessage(ChatUser $actor, string $eventId, string $reason): void`
  - Validate actor is group admin or community admin
  - Sign kind 43 event: `tags: [["e", eventId]]`, `content: {"reason": "..."}`
  - Publish to chat relay via `ChatRelayClient`
- `muteUser(ChatUser $actor, string $targetPubkey, string $reason): void`
  - Sign kind 44 event: `tags: [["p", targetPubkey]]`, `content: {"reason": "..."}`
  - Publish to chat relay via `ChatRelayClient`

#### Message filtering
When `ChatRelayClient.fetchMessages()` loads kind 42 events, it also fetches kind 43/44 events for the same channel. The controller (or a helper method on `ChatRelayClient`) filters out:
- Messages whose event ID appears in a kind 43 event (hidden)
- Messages whose sender pubkey appears in a kind 44 event (muted)

Admin views skip this filtering to show all messages with hidden/muted indicators.

#### Admin Chat View (main app)
- `ChatAdminChatViewController` (`src/Controller/Administration/Chat/`) — `GET /admin/chat/communities/{id}/chats/{groupSlug}`
- Fetches all messages from relay (no filtering), shows hidden/muted indicators
- Hide/mute action buttons

### Guardian / Scoped Management (inside bundle)

#### `ChatManageController` (`src/ChatBundle/Controller/`)
| Route | Purpose |
|-------|---------|
| `GET /manage/groups/{slug}` | Group settings |
| `GET /manage/groups/{slug}/members` | Member management |
| `POST /manage/groups/{slug}/members` | Add/remove members |
| `GET /manage/invites` | Scoped invite management |

Authorization: `ChatAuthorizationChecker` — guardians can only manage groups where they have `admin` role.

### Security Hardening

- **Rate limiting:** Symfony RateLimiter on `POST /groups/{slug}/messages` — 30/min per user
- **CSRF:** All POST endpoints use CSRF tokens
- **Cookie scope:** `chat_session` cookie scoped to subdomain (e.g., `.oakclub.decentnewsroom.com`)
- **Audit logging:** `ChatAuthorizationChecker` logs denied access with user/community/resource context
- **Error responses:** Never leak group names, user lists, or message content in error responses
- **Key handling:** Private keys never appear in logs, responses, or debug output

### Documentation
- Update `documentation/chat.md` with implementation notes
- Add CHANGELOG entry

### Dependencies
- Phase 5–6

---

## Architecture Decisions

### Why a bundle?
The chat module is a bounded context with its own entities, auth model, UI, and relay. Packaging it as a bundle:
- **Encapsulates** all chat-specific code in one directory tree
- **Mirrors** the existing `UnfoldBundle` pattern (team already knows it)
- **Isolates** the Doctrine mapping, services, routes, and templates
- **Enables** disabling the feature entirely by removing one line from `bundles.php`
- **Clarifies** which services are bundle-internal vs integration points with the main app

### Why relay-only message storage (no DB)?
- **Volume is small** — community chat for bounded groups, not a public feed
- **Privacy is scoped to the relay** — the relay boundary IS the privacy boundary; duplicating data in the DB adds a second surface to secure
- **Simpler architecture** — no migration for messages, no sync between DB and relay, no stale data
- **Nostr-native moderation** — kind 43/44 events live alongside kind 42 messages on the relay; filtering is done at read time
- **strfry handles it** — strfry is designed for this; it stores, indexes, and queries events efficiently
- **Trade-off accepted** — slightly slower reads (WebSocket round-trip to strfry-chat per page load) vs. simpler system with one source of truth. For small-volume community chat, this latency is negligible.

### Why backend reads from relay (not JS directly)?
- The chat relay is on the internal Docker network (`ws://strfry-chat:7778`) — not publicly exposed
- Authorization (community membership, group membership) is enforced server-side before returning any messages
- No need to expose relay publicly or implement relay-level auth
- Keeps all access control in one place (PHP)

### Why a separate `ChatUser` entity (not `App\Entity\User`)?
The main DN app uses self-sovereign Nostr identities — users authenticate with NIP-07/NIP-46 browser extensions and control their own keys. Chat users have **custodial** identities — the server generates and stores their keys. These are fundamentally different auth models.

### Why AES-256-GCM instead of the existing encryption pattern?
For key material:
- **GCM provides authentication** — detects tampering (CBC does not)
- **Random IVs are mandatory** — identical private keys must produce different ciphertexts
- **No padding oracle** — GCM is not vulnerable to padding oracle attacks

### Why a separate firewall instead of extending `main`?
- Chat auth is cookie/session-based; main app auth is NIP-98 header-based
- Different user providers (`ChatUser` vs `User`)
- Different entry points (invite page vs Nostr login)
- Clean separation prevents chat subdomain requests from triggering Nostr auth logic

### Why admin controllers outside the bundle?
Admin routes (`/admin/chat/*`) are protected by the main `ROLE_ADMIN` firewall and extend the main admin base template. The bundle's routes are conditioned on `_chat_community` (subdomain), but admin routes run on the main domain.

---

## Complete File Map

```
src/ChatBundle/                              # ← Self-contained bundle
│
├── ChatBundle.php                           # Bundle class
│
├── DependencyInjection/
│   ├── ChatExtension.php                    # Loads services, sets parameters
│   └── Configuration.php                    # Config schema
│
├── Resources/
│   ├── config/
│   │   ├── services.yaml                    # Auto-wires ChatBundle classes
│   │   └── routes.yaml                      # Chat subdomain routes
│   └── views/                               # @Chat namespace templates
│       ├── layout.html.twig
│       ├── activate.html.twig
│       ├── invite_required.html.twig
│       ├── groups/
│       │   ├── index.html.twig
│       │   └── show.html.twig
│       ├── contacts/
│       │   └── index.html.twig
│       ├── profile/
│       │   └── edit.html.twig
│       ├── manage/
│       │   ├── groups.html.twig
│       │   ├── members.html.twig
│       │   └── invites.html.twig
│       └── partials/
│           ├── message.html.twig
│           └── group_item.html.twig
│
├── Entity/
│   ├── ChatCommunity.php
│   ├── ChatUser.php
│   ├── ChatCommunityMembership.php
│   ├── ChatGroup.php
│   ├── ChatGroupMembership.php
│   ├── ChatInvite.php
│   └── ChatSession.php
│
├── Enum/
│   ├── ChatRole.php
│   ├── ChatCommunityStatus.php
│   ├── ChatUserStatus.php
│   ├── ChatGroupStatus.php
│   └── ChatInviteType.php
│
├── Repository/
│   ├── ChatCommunityRepository.php
│   ├── ChatUserRepository.php
│   ├── ChatGroupRepository.php
│   ├── ChatGroupMembershipRepository.php
│   ├── ChatCommunityMembershipRepository.php
│   ├── ChatInviteRepository.php
│   └── ChatSessionRepository.php
│
├── Dto/
│   ├── ChatMessageDto.php
│   └── ChatGroupDto.php
│
├── Service/
│   ├── ChatEncryptionService.php
│   ├── ChatKeyManager.php
│   ├── ChatCommunityResolver.php
│   ├── ChatEventSigner.php
│   ├── ChatRelayClient.php              # All relay I/O (publish, fetch, filter)
│   ├── ChatUserService.php
│   ├── ChatGroupService.php
│   ├── ChatInviteService.php
│   ├── ChatSessionManager.php
│   ├── ChatMessageService.php           # Sign + publish + Mercure push (no DB)
│   ├── ChatProfileService.php
│   └── ChatAuthorizationChecker.php
│
├── Security/
│   ├── ChatUserProvider.php
│   ├── ChatSessionAuthenticator.php
│   └── ChatRequestMatcher.php
│
├── EventListener/
│   └── ChatRequestListener.php
│
├── Controller/
│   ├── ChatActivateController.php
│   ├── ChatGroupController.php          # Fetches messages from relay via ChatRelayClient
│   ├── ChatProfileController.php
│   ├── ChatContactsController.php
│   ├── ChatManageController.php
│   └── ChatSettingsController.php
│
└── Command/
    └── ChatDebugCommand.php

# ── Main app integration points ──

src/Controller/Administration/Chat/         # Admin controllers (ROLE_ADMIN)
├── ChatAdminDashboardController.php
├── ChatCommunityAdminController.php
├── ChatUserAdminController.php
├── ChatGroupAdminController.php
├── ChatInviteAdminController.php
├── ChatSessionAdminController.php
└── ChatAdminChatViewController.php         # Reads from relay, shows all (no filter)

src/Enum/
├── KindsEnum.php                           # +kind 42 (NIP-28 channel message), kinds 40–41, 43–44 (NIP-28)
└── RelayPurpose.php                        # +CHAT

templates/admin/chat/                       # Admin templates (main layout)
├── dashboard.html.twig
├── communities/
│   ├── index.html.twig
│   ├── form.html.twig
│   └── show.html.twig
├── users/index.html.twig
├── groups/
│   ├── index.html.twig
│   └── members.html.twig
├── invites/index.html.twig
├── sessions/index.html.twig
└── chats/show.html.twig

assets/controllers/chat/                    # Stimulus controllers
├── chat_messages_controller.js
└── chat_groups_controller.js

assets/styles/04-pages/
└── chat.css

config/
├── bundles.php                             # +ChatBundle registration
├── packages/chat.yaml                      # Bundle configuration
├── packages/security.yaml                  # +chat firewall
├── routes/chat.yaml                        # Loads @ChatBundle routes
└── services.yaml                           # Complex service bindings

docker/strfry-chat/                         # Private relay config (sole message store)
├── strfry.conf
└── write-policy.sh

migrations/
└── VersionYYYYMMDDHHMMSS.php              # 7 tables (no message table)
```

---

## Estimation

| Phase | Scope | Effort |
|-------|-------|--------|
| 1 — Foundation | Bundle skeleton, 7 entities, 7 repos, 5 enums, 3 services, 1 listener, 1 migration | Large |
| 2 — Docker & Relay | 1 Docker service, 2 config files, RelayRegistry update | Small |
| 3 — Admin Backend | 6 admin controllers (main app), 5 services (bundle, incl. ChatRelayClient), ~10 templates | Large |
| 4 — Authentication | 3 security classes (bundle), 1 controller, security.yaml changes | Medium |
| 5 — Chat Core | 2 services (bundle), 3 controllers (bundle), 2 DTOs | Medium |
| 6 — Real-time UI | 2 Stimulus controllers, 1 CSS file, ~12 templates (bundle views) | Medium |
| 7 — Moderation & Polish | Extend services, 1 controller (main), 1 controller (bundle), hardening | Medium |

### Parallelization
- Phases 1 + 2 can be done together
- Phase 6 (UI) can start alongside Phase 5 with mocked data
- Phase 7 is independently deployable after Phase 6

