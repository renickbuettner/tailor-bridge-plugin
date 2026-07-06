# Tailor Companion — October CMS Plugin

A token-authenticated REST API for [October CMS](https://octobercms.com) that
lets a native app (or any client) **browse, sync and edit Tailor content
offline-first**. It exposes all Tailor blueprints and entries over a small
versioned API, records every change in a journal so clients can sync
incrementally, and logs all mutations to an audit trail.

> This is the backend half of the Tailor Companion project. Pair it with the
> native app and your content stays between your device and your own server.

## Features

- **Token auth** — SHA-256-hashed access tokens, issued per device from the
  backend (QR pairing) or via credentials. No sessions, no third parties.
- **Schema API** — all blueprints (sections + globals) aggregated into one
  normalized structure with per-blueprint fingerprints for change detection.
- **Entry API** — cursor-paginated reads of canonical (published) entries,
  every core Tailor field type mapped to a clean JSON shape.
- **Incremental sync** — a change journal with a monotonic cursor captures
  create/update/delete from any source (API, backend, console), including
  hard deletes.
- **Batched writes** — create/update/delete with optimistic-concurrency
  conflict handling; blueprint validation always runs.
- **File uploads** — scoped, validated attachment upload & download.
- **Audit log** — every API mutation recorded with a field-level diff,
  viewable in the backend.
- **Backend UI** — Settings → Tailor Companion: App Connect (tokens + QR),
  Audit Log, and settings (API switch, token expiry, journal retention).

## Requirements

- October CMS 4.x
- PHP 8.2+
- The [Tailor](https://docs.octobercms.com/4.x/cms/tailor/introduction.html)
  module (ships with October) with at least one entry blueprint

## Installation

```bash
composer require renick/tailorcompanion-plugin
php artisan october:migrate
```

The plugin registers itself as `Renick.TailorCompanion` and installs to
`plugins/renick/tailorcompanion`.

## Getting started

1. In the backend, open **Settings → Tailor Companion → App Connect**.
2. Create a connection token — a QR code (and manual URL/login/token) is shown.
3. Point your client at `https://your-site/api/tailor-companion/v1` and send
   the token as `Authorization: Bearer <token>`.
4. Call `GET /ping` to verify, then `GET /schema` and `GET /entries/{uuid}`.

## API

Base path: `/api/tailor-companion/v1`

| Method & path | Purpose |
|---|---|
| `POST /auth/token` | Issue a token from `login` + `password` (throttled) |
| `GET /ping` | Auth check + server/schema version |
| `GET /schema` | All blueprints + normalized fields (ETag) |
| `GET /entries/{uuid}` | Cursor-paginated entries of a blueprint |
| `GET /entries/{uuid}/{id}` | A single entry |
| `GET /sync/changes` | Incremental change journal since a cursor |
| `POST /sync/batch` | Apply create/update/delete operations |
| `POST /files` | Upload an attachment |
| `GET /files/{id}` | Download an attachment |

The full contract — request/response schemas, error codes, field mapping — is
in [`docs/openapi.yaml`](docs/openapi.yaml) (OpenAPI 3.1). Rendered docs are
published from that spec.

## Testing

The plugin ships PHPUnit tests. Inside an October install:

```bash
php artisan plugin:test Renick.TailorCompanion
```

## License

[MIT](LICENSE) © Renick Büttner
