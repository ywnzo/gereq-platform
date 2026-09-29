# Customers (core)

Contacts / customers, storefront auth and related entities. Formerly `modules/Customers`; now part of the platform core under `src/Customers/`.

| | |
| --- | --- |
| Namespace | `Gereq\Customers` |
| Registrar | `Gereq\Customers\CustomersRegistrar` |
| Bootstrap | `Gereq\Customers\CustomersBootstrap` |
| Capabilities | `contacts` → `CustomersProvider`, `store_auth` → `StoreAuthService` |
| Admin | `/admin/customers` |
| Store API | `/api/v1/store/auth/*` |
| Assets | `resources/js/customers/*`, `resources/css/customers.css` |
