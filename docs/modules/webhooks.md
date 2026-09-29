# Webhooks (core)

Outbound event delivery and inbound provider webhooks. Formerly `modules/Webhooks`; now part of the platform core under `src/Webhooks/`.

| | |
| --- | --- |
| Namespace | `Gereq\Webhooks` |
| Registrar | `Gereq\Webhooks\WebhooksRegistrar` |
| Capability | `webhooks` → `InboundWebhookRegistry` (provider id `core`) |
| Entities | `Webhook`, `WebhookDelivery` |
| Admin | `/admin/developer/webhooks`, `/admin/developer/webhook-deliveries` |
| Inbound | `POST /api/v1/webhooks/inbound/{provider}` |

See also [Hooks, events & webhooks](../architecture/hooks-events-webhooks.md).
