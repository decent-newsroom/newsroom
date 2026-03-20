# ChatBundle Agent Context

## Purpose
Private community chat via NIP-28 (Public Chat) over a dedicated strfry relay.
Users get custodial Nostr identities; messages are stored on the relay only (no DB persistence).

## Key Design Decisions
- **Relay-only messages**: Messages are read from the chat relay on each request. No `ChatMessageIndex` entity exists.
- **Custodial keys**: Private keys are AES-256-GCM encrypted in `chat_user.encrypted_private_key`. Decrypted transiently for signing.
- **Separate firewall**: Chat uses its own Symfony security firewall (`chat`) with cookie-based sessions, completely independent from the main app's Nostr auth.
- **Subdomain routing**: `ChatRequestListener` resolves subdomains to `ChatCommunity` entities at request priority 31 (just below Unfold at 32).
- **No npm**: Stimulus controllers in `assets/controllers/chat/` follow the project's AssetMapper pattern.

## File Layout
```
src/ChatBundle/
├── ChatBundle.php                    # Bundle class
├── Command/ChatDebugCommand.php      # chat:debug CLI
├── Controller/                       # 6 community-facing controllers
├── DependencyInjection/              # Configuration + ChatExtension
├── Dto/                              # ChatMessageDto, ChatGroupDto
├── Entity/                           # 7 entities (no message entity!)
├── Enum/                             # ChatRole, ChatCommunityStatus, etc.
├── EventListener/ChatRequestListener # Subdomain → ChatCommunity
├── Repository/                       # 7 Doctrine repositories
├── Resources/config/                 # services.yaml, routes.yaml
├── Resources/views/                  # Twig templates (layout, groups, profile, etc.)
├── Security/                         # ChatUserProvider, ChatSessionAuthenticator, ChatRequestMatcher
└── Service/                          # Core services (relay, signing, encryption, messaging)
```

## Services
| Service | Role |
|---------|------|
| `ChatEncryptionService` | AES-256-GCM encrypt/decrypt |
| `ChatKeyManager` | Generate keypairs, decrypt private keys |
| `ChatEventSigner` | Sign Nostr events for a ChatUser |
| `ChatRelayClient` | Publish/query the chat relay (WebSocket) |
| `ChatMessageService` | Orchestrate send: sign → relay → Mercure |
| `ChatAuthorizationChecker` | Fail-closed permission checks |
| `ChatSessionManager` | Create/validate/revoke session cookies |
| `ChatCommunityResolver` | Get current ChatCommunity from request |
| `ChatUserService` | Create/activate/suspend users |
| `ChatGroupService` | Create groups (publishes kind 40) |
| `ChatInviteService` | Generate/validate/revoke invite tokens |
| `ChatProfileService` | Update display name + publish kind 0 |

## Common Tasks
- **Add a new community**: Admin → `/admin/chat` → New Community
- **Generate invite**: Admin → community → Invites → Generate
- **Test relay**: `docker compose exec php bin/console chat:debug`
- **View messages (admin)**: Admin → community → Groups → View Chat (shows all including hidden)

