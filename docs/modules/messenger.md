# Messenger (`messenger`)

Messenger provides **private conversations** between members (inbox / sent / trash / archive / spam folders). It is API-first: there is no admin menu or CRUD UI. Optional [Community](community.md) enforces block checks (`isBlockedEitherWay`); display names come from [Customers](customers.md) when available.

Related: [Forum](forum.md), [Community](community.md), [Customers](customers.md), [module system](../architecture/module-system.md).

## At a glance

| Item | Value |
| --- | --- |
| Module id | `messenger` |
| Version | `1.99.0` (`modules/Messenger/module.php`, `MessengerModule::version()`) |
| Directory | `modules/Messenger/` |
| Class | `Modules\Messenger\MessengerModule` |
| Requires | `core ^1.0` |
| Optional | `community ^0.2`, `customers ^1.0`, `media ^1.0` |
| Capabilities provided | `messenger` → `Application/MessengerProvider` |
| Groups / packages | `forum` (`module.php`); package **Forum** |
| Permissions | `messenger.view`, `messenger.manage` (registered; **no admin routes/menu**) |
| API scopes | none (public API only) |

## Domain model

| Entity | Table | Key fields |
| --- | --- | --- |
| `MessengerConversation` | `messenger_conversations` | `subject`, `created_by`, timestamps |
| `MessengerParticipant` | `messenger_participants` | `conversation_id`, `user_id`, `folder`, `last_read_at`, `joined_at` |
| `MessengerMessage` | `messenger_messages` | `conversation_id`, `author_id`, `body`, `created_at` |

Folders: `inbox`, `sent`, `trash`, `archive`, `spam`. Writes are rate-limited; messaging is refused when Community reports a block either way.

## Admin UI

None — `boot()` is empty. Permissions exist for future admin tooling.

## Public API (`/api/public/messenger/…`)

| Endpoint | Purpose |
| --- | --- |
| `GET features` | Capability flags |
| `GET` / `POST conversations` | List by folder / create with participants + first message |
| `GET` / `POST …/messages` | Read / send |

Auth via `MessengerPublicIdentity`. No `/api/v1`.

## Events

`messenger.message.sent` (webhook-enabled).

## Other

- Migrations: 1 — three tables.
- No settings, CLI or dashboard widgets.
