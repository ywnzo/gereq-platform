# Site inquiries

[![GEREQ](https://img.shields.io/badge/GEREQ-module-1a3020)](https://git.gereq.cz/gereq) [![Version](https://img.shields.io/badge/version-v0.2.0-3dd6c6)](https://git.gereq.cz/gereq/module-site_inquiries/-/tags) [![Module](https://img.shields.io/badge/module-site_inquiries-6eb6ff)](https://git.gereq.cz/gereq/module-site_inquiries) [![Visibility](https://img.shields.io/badge/visibility-private-f08a6a)](https://git.gereq.cz/gereq/module-site_inquiries)

Lokální modul pro Doprovody Vohralík (DIL-2026-028).

- veřejný `GET /api/public/site` — kontaktní údaje pro Vue frontend
- veřejný `POST /api/public/site/inquiries` — poptávka (validace, honeypot, rate limit)
- admin seznam poptávek + editace kontaktních údajů
- vytváří `Customer` se statusem `lead` a odesílá notifikační e-mail
