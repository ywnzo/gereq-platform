# Forum (`forum`)

Forum is the **Q&A and discussion engine** of the Forum package: categories (`qa` or `discussion`), threads (questions / discussions), posts, votes, accepted answers, moderation (flags, pending approval), tags, subscriptions, likes, bookmarks, polls and view/read tracking. It exposes a rich public CORS API for the storefront and authenticated `/api/v1/forum` for integrations. Optional [Community](community.md) owns the social graph (follows, friends visibility); optional [Achievements](achievements.md) supplies trust levels used by `forum.min_trust_level_to_post`.

Related: [Community](community.md), [Achievements](achievements.md), [Messenger](messenger.md), [Customers](customers.md), [module system](../architecture/module-system.md), [REST API](../architecture/api.md).

## At a glance

| Item | Value |
| --- | --- |
| Module id | `forum` |
| Version | `1.99.0` (`modules/Forum/module.php`, `ForumModule::version()`) |
| Directory | `modules/Forum/` |
| Class | `Modules\Forum\ForumModule` |
| Requires | `core ^1.0` |
| Optional | `customers ^1.0`, `community ^0.2`, `achievements ^0.2` |
| Capabilities provided | `forum` → `Application/ForumProvider` |
| Capabilities consumed | `community`, `achievements` (`trustLevel` / `trustLabel`) |
| Groups / packages | `forum` (`module.php`); package **Forum** required module |
| Permissions | `forum.view`, `forum.manage`, `forum.moderate` (group `perm.group.forum`) |
| API scopes | `forum.read`, `forum.write` |

## Domain model

| Entity | Table | Role |
| --- | --- | --- |
| `ForumCategory` | `forum_categories` | Nested categories; `kind` = `qa` \| `discussion` |
| `ForumTag` | `forum_tags` | Tags (publishable) |
| `ForumThread` | `forum_threads` | Question/discussion; status, votes, pin/lock/hide, visibility, mature |
| `ForumPost` | `forum_posts` | Answers (`parent_id` null) and comments |
| `ForumVote` | `forum_votes` | Up/down on thread or post |
| `ForumFlag` | `forum_flags` | Moderation reports |
| `ForumSubscription` | `forum_subscriptions` | Per-user thread watch |
| `ForumLike` / `ForumBookmark` | `forum_likes` / `forum_bookmarks` | Reactions / saves |
| `ForumRead` / `ForumThreadView` | `forum_reads` / `forum_thread_views` | Read position / anonymous views |
| `ForumPoll` (+ options/votes) | `forum_polls*` | Thread polls |
| `ForumProfile` / `ForumFollow` | `forum_profiles` / `forum_follows` | Legacy; prefer Community when enabled |

Publishables: `forum.categories`, `forum.tags`. Categories and tags take part in the staging → production publish flow.

## Admin UI

Menu group **Obsah**: hub, threads, flags, pending approvals, categories, tags, settings. Routes under `/admin/forum` (middleware `auth`, module `forum`). Command palette entries for index, flags, categories, tags.

## Public API (`/api/public/forum/…`)

Categories, tags, threads CRUD / reply / comment, votes, subscribe, accept answer, flags, profile/follow (delegates to Community when available), likes, bookmarks, thread read, poll vote, adjacent/summary/timeline/similar. CORS + OPTIONS on all routes.

## REST `/api/v1/forum`

Categories, tags, threads, reply, comment, votes, accept, subscribe, flag, user — scopes `forum.read` / `forum.write`.

## Events (webhook-enabled)

`forum.thread.created|updated|deleted|status_changed|locked|unlocked|hidden|unhidden|approved|merged`, `forum.post.created|accepted|approved`, `forum.flag.created|resolved`, `forum.like.created`.

## Settings

| Key | Default | Meaning |
| --- | --- | --- |
| `forum.allow_downvotes` | `true` | Allow downvote |
| `forum.require_first_post_moderation` | — | First post needs approval |
| `forum.min_trust_level_to_post` | `0`–`5` | Gate from Achievements trust |
| `forum.auto_lock_thread_after_days` | `180` | Auto-lock stale threads |
| `forum.accepted_answer_allows_author` | — | Author may accept own answer |

## Other

- Migrations: 7 files (base schema + lock/hidden, moderation, cover, follows/profiles, views/likes, bookmarks/polls/mature).
- Demo: `ForumDemoSeeder`.
- No CLI commands or dashboard widgets.
