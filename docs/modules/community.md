# Community (`community`)

Community is the **social layer** of the Forum package: member profiles, follow graph, friendships/blocks, groups (open / closed / invite) and activity stats driven by [Forum](forum.md) events. On install it migrates legacy `forum_profiles` / `forum_follows` into community tables. [Messenger](messenger.md) consults Community for block checks; Forum uses friends visibility and profile/follow APIs when this module is enabled.

Related: [Forum](forum.md), [Achievements](achievements.md), [Messenger](messenger.md), [Customers](customers.md), [Media](media.md), [Calendar](calendar.md), [module system](../architecture/module-system.md).

## At a glance

| Item | Value |
| --- | --- |
| Module id | `community` |
| Version | `1.99.0` (`modules/Community/module.php`, `CommunityModule::version()`) |
| Directory | `modules/Community/` |
| Class | `Modules\Community\CommunityModule` |
| Requires | `core ^1.0` |
| Optional | `customers ^1.0`, `forum ^0.2`, `media ^1.0`, `calendar ^1.0` |
| Capabilities provided | `community` → `Application/CommunityProvider` |
| Groups / packages | `forum` (`module.php`); package **Forum** |
| Permissions | `community.view`, `community.manage`, `community.moderate` |
| API scopes | none (public API only; no `/api/v1` registration) |

## Domain model

| Entity | Table | Key fields |
| --- | --- | --- |
| `CommunityProfile` | `community_profiles` | PK `user_id`; headline, bio, location, website, `privacy` (`public`\|`followers`\|`private`) |
| `CommunityFollow` | `community_follows` | `follower_id`, `following_id` (unique pair) |
| `CommunityFriendship` | `community_friendships` | requester/addressee, `status` (`pending`\|`accepted`\|`blocked`) |
| `CommunityGroupCategory` | `community_group_categories` | name, slug, sort_order |
| `CommunityGroup` | `community_groups` | visibility `open`\|`closed`\|`invite`, category, owner |
| `CommunityGroupMember` | `community_group_members` | role `owner`\|`mod`\|`member` |
| `CommunityGroupInvite` | `community_group_invites` | inviter, invitee, status |
| `CommunityActivityStats` | `community_activity_stats` | PK `user_id`; visit/read counters, threads/posts/likes |

## Admin UI

`/admin/community` — dashboard; POST categories, create groups, moderate profiles. Menu **Obsah** (order 57). Command palette: community index.

## Public API (`/api/public/community/…`)

`features`, `meetups` (Calendar meetings when available), profile media attach, users + stats, follow/unfollow, profile patch, friends CRUD, block, groups join/leave/invite/accept. No `/api/v1`.

## Events

`community.user.followed|unfollowed`, `community.profile.updated`, `community.friendship.requested|accepted|declined|blocked`, `community.group.created|joined|left|invited`.

Hook `platform.event.dispatched` increments activity stats on `forum.*` create events.

## Other

- Migrations: 1 (`2026_09_25_000001_community.php`) — 8 tables + data copy from Forum.
- No settings, CLI or dashboard widgets.
- Identity is Customers storefront accounts (and admin Users where applicable).
