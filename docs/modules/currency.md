# Currency (core)

Currencies, FX rates and money formatting. Formerly `modules/Currency`; now part of the platform core under `src/Currency/`.

| | |
| --- | --- |
| Namespace | `Gereq\Currency` |
| Registrar | `Gereq\Currency\CurrencyRegistrar` |
| Capability | `currency` → `CurrencyBuilder` (provider id `core`) |
| Entities | `Currency`, `ExchangeRate`, `UserCurrencyPreference` |
| Admin | Settings → Platform (`/admin/settings?tab=core`); CRUD also at `/admin/currencies` |
| CLI | `gereq currency:rates:sync` |
| Twig | `money`, `money_code`, `currency_code`, `currencies`, … |
