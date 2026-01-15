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

> 📚 **For detailed setup instructions, see [docs/SETUP.md](docs/SETUP.md)**

### Quick Start (Local Development)

```bash
# 1. Clone the repository
git clone https://github.com/decent-newsroom/newsroom.git
cd newsroom

# 2. Create environment file
cp .env.dist .env

# 3. Build and start containers
docker compose build
docker compose up -d

# 4. Access the application
# https://localhost:8443 (accept the self-signed certificate warning)
```

### Production Deployment

For production, you'll need to:
1. Configure secure passwords and secrets
2. Set your domain name for automatic TLS
3. Use the production compose file

```bash
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local up -d
```

See [docs/SETUP.md](docs/SETUP.md) for complete production setup instructions.

### Environment Variables

The `.env.dist` file contains all configuration options with inline documentation:
- ✅ Variables safe to use with defaults
- ⚠️ Variables to review for production  
- 🔒 Variables that **must** be changed for production (security-sensitive)

### Nostr Relay

The project includes a private read-only Nostr relay (powered by strfry) that acts as a local cache for long-form articles and related events. This improves performance and reduces dependency on public relays.

**Key Features:**
- Read-only cache
- Automatic periodic sync from upstream relays
- Caches long-form articles (NIP-23), reactions, zaps, highlights, and more
- WebSocket endpoint exposed via Caddy

## Documentation

- [Complete Setup Guide](docs/SETUP.md) - Local and production setup
- [Production Deployment](docs/production.md) - Server preparation and deployment
- [Relay Production Setup](docs/relay-production.md) - Nostr relay configuration
- [Troubleshooting](docs/troubleshooting.md) - Common issues and solutions

