# Mercure Administration

## Overview

The Mercure admin page (`/admin/mercure`) provides a comprehensive dashboard for
monitoring and testing the Mercure SSE (Server-Sent Events) hub that powers
real-time updates throughout the application.

**Route:** `/admin/mercure`  
**Access:** `ROLE_ADMIN` required  

## Features

### Hub Connectivity
- Displays the internal and public Mercure hub URLs
- Shows whether the JWT secret is configured
- Tests hub reachability with HTTP status code and response latency
- Re-test button for on-demand connectivity checks via AJAX

### BoltDB Transport Status
- Shows the BoltDB file path, existence, size, and last-modified timestamp
- Displays the transport age (how recently data was written)
- Shows configured cleanup frequency, write timeout, and dispatch timeout

### Publish Test
- Text input for specifying a Mercure topic (defaults to `/test/admin-ping`)
- Publishes a test message and reports success/failure, message ID, and latency
- Includes a live SSE listener that subscribes to the same topic and displays
  received messages in real-time — confirming the full publish→subscribe round-trip

### Active Subscriptions
- Queries the Mercure subscriptions API (`/.well-known/mercure/subscriptions`)
- Lists all active SSE subscriptions grouped by topic with subscriber counts
- Refresh button for on-demand updates

### Known Topic Patterns
- Registry of all Mercure topic patterns used across the application codebase
- Shows the pattern, description, and publishing service for each topic

## Architecture

### Backend

| File | Purpose |
|------|---------|
| `src/Service/Admin/MercureAdminService.php` | Service: config, connectivity test, publish test, subscriptions API, BoltDB info, topic registry |
| `src/Controller/Administration/MercureAdminController.php` | Controller: page render + AJAX endpoints for test-publish, test-connectivity, subscriptions |
| `config/services.yaml` | Service wiring with `MERCURE_URL`, `MERCURE_PUBLIC_URL`, `MERCURE_JWT_SECRET` |

### Frontend

| File | Purpose |
|------|---------|
| `templates/admin/mercure/index.html.twig` | Admin page template |
| `assets/controllers/ui/mercure_admin_controller.js` | Stimulus controller: AJAX actions, SSE listener, dynamic DOM updates |

### Endpoints

| Method | Path | Name | Purpose |
|--------|------|------|---------|
| GET | `/admin/mercure` | `admin_mercure_index` | Dashboard page |
| POST | `/admin/mercure/test-publish` | `admin_mercure_test_publish` | Publish test message (AJAX) |
| POST | `/admin/mercure/test-connectivity` | `admin_mercure_test_connectivity` | Re-test hub connectivity (AJAX) |
| GET | `/admin/mercure/subscriptions` | `admin_mercure_subscriptions` | Fetch active subscriptions (AJAX) |

## Mercure Topics Used in the Application

| Pattern | Publisher | Description |
|---------|-----------|-------------|
| `/articles/{pubkey}` | FetchAuthorArticlesHandler | Author article updates |
| `/author/{pubkey}/{type}` | FetchAuthorContentHandler | Author content (articles, drafts, media, highlights, bookmarks, interests) |
| `/comments/{coordinate}` | FetchCommentsHandler | Live comment updates for articles |
| `/event-fetch/{lookupKey}` | FetchEventFromRelaysHandler | Async event fetch result notification |
| `/curation/{id}/media-sync` | FetchMissingCurationMediaHandler | Curation media sync completion |
| `/chat/{communityId}/group/{slug}` | ChatMessageService | Chat group real-time messages |
| `/test/*` | TestMercureCommand / MercureAdminService | Test/diagnostic topics |

