# ChatBundle — Private Community Chat

## Overview

Self-contained Symfony bundle for invite-only, community-scoped chat using NIP-28 kind 42 (channel messages) for chat text and NIP-28 (kinds 40-41, 43-44) for channel setup and moderation, over a private relay. Each community gets:

- Its own subdomain (`oakclub.decentnewsroom.com`)
- Custodial Nostr identities (users don't manage keys)
- Relay-only message storage (no database persistence for messages)
- Real-time via Mercure SSE

## Architecture

```
Browser → subdomain → ChatRequestListener → ChatSessionAuthenticator → Controller
             ↓                                       ↓
   _chat_community attribute              ChatUser (from session cookie)
             ↓
   Controller → ChatMessageService → ChatEventSigner → ChatRelayClient → strfry-chat
                                                              ↑
                                              Mercure Update ← (push to SSE)
```

### Message Flow

**Send:** JS POST → Controller → ChatMessageService → sign kind 42 → publish to relay → Mercure update  
**Read (initial):** Controller → ChatRelayClient.fetchFilteredMessages() → render Twig  
**Read (pagination):** JS fetch `/groups/{slug}/history?before=TIMESTAMP` → ChatRelayClient → JSON  
**Real-time:** Mercure SSE subscription on `/chat/{communityId}/group/{slug}`

### Why Backend Reads from Relay (Not JS Directly)

- The chat relay (`strfry-chat`) lives on the internal Docker network — not publicly exposed
- Authorization checks happen server-side (community membership, group membership, moderation)
- Custodial keys mean the server must sign all events anyway

## Entities

| Entity | Table | Purpose |
|--------|-------|---------|
| `ChatCommunity` | `chat_community` | Top-level scope, identified by subdomain |
| `ChatUser` | `chat_user` | Custodial Nostr identity with encrypted private key |
| `ChatCommunityMembership` | `chat_community_membership` | User ↔ Community role assignment |
| `ChatGroup` | `chat_group` | Chat channel, maps to NIP-28 kind 40 event |
| `ChatGroupMembership` | `chat_group_membership` | User ↔ Group membership |
| `ChatInvite` | `chat_invite` | Token-based invite (hashed, single-use or multi-use) |
| `ChatSession` | `chat_session` | Cookie-based login session |

## NIP-28 Event Flow

### Channel Create (kind 40)
Published when a group is created. Content = JSON `{"name": "...", "about": "...", "picture": "..."}`.
The event ID becomes `ChatGroup.channelEventId`.

### Chat Message (NIP-28 kind 42)
Published when a user sends a message. Content = plaintext message. Tags:
- `["e", "<channelEventId>", "<relayUrl>", "root"]` — links to channel
- `["e", "<replyToEventId>", "<relayUrl>", "reply"]` — optional reply

### Hide Message (kind 43)
Published by admins/guardians. Tags: `["e", "<eventIdToHide>"]`

### Mute User (kind 44)
Published by admins/guardians. Tags: `["p", "<pubkeyToMute>", "<relayUrl>"]`

## Roles

| Role | Capabilities |
|------|-------------|
| `admin` | Full community management, invite generation, moderation |
| `guardian` | Moderation (hide/mute), invite generation |
| `user` | Send messages, view groups |

## Security

### Separate Firewall
The chat uses its own Symfony security firewall (`chat`) with:
- `ChatRequestMatcher` — activates only for chat community subdomains
- `ChatSessionAuthenticator` — reads `chat_session` cookie
- `ChatUserProvider` — validates session, sets runtime roles

### Key Storage
Private keys are encrypted with AES-256-GCM using `APP_ENCRYPTION_KEY`.
Keys are decrypted transiently for signing and immediately discarded.

## Docker

### strfry-chat Service
Separate strfry instance (`docker/strfry-chat/`):
- Port: 7778 (internal)
- Write policy: accepts only kinds 0, 24, 40-41, 43-44
- Isolated database volume (`strfry_chat_data`)

## Admin Interface

Available at `/admin/chat`:
- Create/manage communities
- Create users with custodial keys
- Create groups (publishes kind 40)
- Generate invite links
- View/revoke sessions
- View chat logs (unfiltered, including hidden messages)

## Configuration

### Environment Variables
- `APP_ENCRYPTION_KEY` — AES-256-GCM key for private key encryption
- `CHAT_RELAY_URL` — Override default chat relay URL (default: `ws://strfry-chat:7778`)
- `BASE_DOMAIN` — Base domain for subdomain extraction

### Bundle Config (`config/packages/chat.yaml`)
```yaml
chat:
    relay_url: '%env(default:chat_relay_default:CHAT_RELAY_URL)%'
```

## Files

### Bundle (`src/ChatBundle/`)
```
ChatBundle.php              # Bundle class
Command/ChatDebugCommand.php
Controller/                 # 6 controllers (activate, group, profile, contacts, manage, settings)
DependencyInjection/        # Configuration + ChatExtension
Dto/                        # ChatMessageDto, ChatGroupDto
Entity/                     # 7 entities
Enum/                       # 5 enums (ChatRole, ChatCommunityStatus, etc.)
EventListener/              # ChatRequestListener (subdomain resolution)
Repository/                 # 7 repositories
Resources/config/           # services.yaml, routes.yaml
Resources/views/            # Twig templates for chat UI
Security/                   # ChatUserProvider, ChatSessionAuthenticator, ChatRequestMatcher
Service/                    # 12 services (encryption, signing, relay, messaging, etc.)
```

### Admin (`src/Controller/Administration/Chat/`)
7 admin controllers for CRUD operations on communities, users, groups, invites, sessions.

### Assets
- `assets/controllers/chat/chat_messages_controller.js` — Stimulus controller for sending/receiving
- `assets/controllers/chat/chat_groups_controller.js` — Groups list (future: unread badges)
- `assets/styles/04-pages/chat.css` — Chat-specific styles

### Docker
- `docker/strfry-chat/strfry.conf` — Chat relay configuration
- `docker/strfry-chat/write-policy.sh` — Kind filter (40-44 only)

