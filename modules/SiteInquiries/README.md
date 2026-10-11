# Site inquiries

Lokální modul pro Doprovody Vohralík (DIL-2026-028).

- veřejný `GET /api/public/site` — kontaktní údaje pro Vue frontend
- veřejný `POST /api/public/site/inquiries` — poptávka (validace, honeypot, rate limit)
- admin seznam poptávek + editace kontaktních údajů
- vytváří `Customer` se statusem `lead` a odesílá notifikační e-mail
