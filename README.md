# Decent Newsroom

## Intro
Decentralised Newsroom is a platform for the creation, publishing, and discovery of mixed-media collaborative journals. 

Newsrooms used to be at the heart of journals and media houses, but they deteriorated when their business models started to fail. 
This project is a decentralised digital alternative. 

A lot of talented creators have found their opportunity in the handful of platforms available,
but there is synergy in collaboration that has been lost in the transition.

Let's bring back high-value professional journalism and collaborative publishing. 


## Constituent parts

This project has multiple facets that build on each other, making the whole more than the sum of its parts.

### Reader

A traditional newspaper lookalike made up of multiple individual journals. 
Logged-in users can pick and choose which journals they read and subscribe to, 
while passers-by can browse the default public ones.

### Article Editor

A content editor interface for writing essays, articles, and more. Featuring preview mode, saved drafts and personal notes. 

### Media Manager

In the current digital landscape, media content and written word have been driven apart, and it's time to bring them closer together again. 
The media manager is a place to create and share your own media library. 

### Marketplace

A marketplace for requesting custom-made media (photographs, graphics, data visualizations, animations, audio, video...), science review, contacts, etc. or 
for publishing art and stock images to make them available and discoverable to be included in the journals.

### Newsroom

A content management system for creating and updating journals and managing subscriptions. 

### Silk Search and Index

An integrated service that provides on-demand indexing and search.

## Setup

### Clone the repository

```bash
git clone https://github.com/decent-newsroom/newsroom.git
cd newsroom
```

### Create the .env file

Copy the example file `.env.dist` and replace placeholders with your actual configuration.

### Add a project encryption key and nsec
Symfony uses a vault mechanism for managing secrets securely. 
To save the nsec, run this command inside your Docker container:

```bash
docker-compose exec php bin/console secrets:set APP_NSEC
```

To save the encryption key:
```bash
docker-compose exec php bin/console secrets:set APP_ENCRYPTION_KEY
```

### Nostr Relay

The project includes a private read-only Nostr relay (powered by strfry) that acts as a local cache for long-form articles and related events. This improves performance and reduces dependency on public relays.

**Quick Start:**

```bash
# Start the relay
make relay-up

# Run initial backfill
make relay-prime

# Test it
make relay-test
```

For detailed documentation, see [documentation/relay.md](documentation/relay.md).

**Key Features:**
- Read-only cache (denies client writes)
- Automatic periodic sync from upstream relays
- Caches long-form articles (NIP-23), reactions, zaps, highlights, and more
- WebSocket endpoint exposed via Caddy
- Easy backup/restore with `make relay-export` / `make relay-import`


