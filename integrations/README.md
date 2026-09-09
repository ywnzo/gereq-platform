# Integrations (third-party)

Packages in `integrations/` are **remote adapters** (Comgate, Stripe, Packeta…).  
They share the same lifecycle as modules (enable / migrate / settings), but:

- `kind: integration` in `module.php`
- PSR-4 namespace `Integrations\{Name}\`
- should only talk to a host capability (`payment_gateways`, `shipping_carriers`, …)
- must not own core domain tables (orders, intents, listings)
- appear in Admin → Moduly → **Plug-iny**, grouped via `groups` in `module.php`
  and definitions in `config/module_groups.php` (`surface: plugins`)

## Groups (Plug-iny)

| Group ID | Label | Packages |
|----------|-------|----------|
| `plugin.payments` | Platby | Comgate, Stripe, GoPay |
| `plugin.shipping` | Doprava | Packeta |
| `plugin.ai` | AI | Ollama, OpenAiCompat, Zai, DeepSeek, Gemini, Anthropic, OpenAi |

## Layout

```
integrations/
  Comgate/
  Stripe/
  GoPay/
  Packeta/
    module.php
    {Name}Module.php
    Application/
    Http/
    Resources/translations/
```

## Current packages

| Package | Host | Registers into |
|---------|------|----------------|
| Comgate | Payments | `PaymentGatewayRegistry` + notify |
| Stripe | Payments | `PaymentGatewayRegistry` + notify |
| GoPay | Payments | `PaymentGatewayRegistry` + notify |
| Packeta | Shop | `ShippingCarrierRegistry` + widget API |
| Ollama / OpenAiCompat / Zai / DeepSeek / Gemini / Anthropic / OpenAi | Ai | plug-in markers (`plugin.ai`); drivers live in `modules/Ai` |

Enable example:

```bash
php gereq module:enable payments
php gereq module:enable comgate
php gereq module:enable stripe
php gereq module:enable gopay
php gereq module:enable shop
php gereq module:enable packeta
```

Next candidates: PPL (scaffold still in Shop), iDoklad, MoneyS3.
