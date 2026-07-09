# M14 — QR payment code

**Depends on:** M13 (embeds into E2/E7), M09 (My account slot)
**Spec:** F16 (§4.5)
**Goal:** Every payment instruction carries a scannable Czech QR platba code.

## Tasks

- **SpaydBuilder** (pure, unit-tested): builds
  `SPD*1.0*ACC:<IBAN>*AM:<amount>*CC:CZK*X-VS:<vs>*MSG:<course>` — correct
  formatting (amount with dot decimal, MSG sanitized/transliterated ASCII,
  length limits per SPAYD spec).
- IBAN plugin setting (with basic checksum validation); QR features degrade
  gracefully (hidden, instructions still textual) until IBAN is set.
- QR rendering via a small local PHP library (e.g. endroid/qr-code or a
  dependency-free alternative) — generated on our server, no external service.
- Embed: E2 + E7 emails (inline/attached image — test major clients' behavior),
  My account unpaid enrollments (rendered on the fly, auth required — the URL
  must not leak enrollment data to anonymous visitors).

## Acceptance criteria

- [ ] Unit tests: SPAYD string for normal case, diacritics in course name,
      price with halere, VS length
- [ ] Scanning the rendered QR with a real Czech banking app pre-fills account,
      amount and VS correctly (do this once, genuinely, with a test IBAN)
- [ ] E2 email in the mail catcher displays the QR; My account shows it for
      unpaid, hides it for paid
- [ ] No IBAN configured → no QR anywhere, no errors, text instructions intact
- [ ] QR image endpoint refuses anonymous/foreign-enrollment requests

## Verification

The banking-app scan is the verification that matters — SPAYD is exactly the kind
of format where tests pass and real apps still reject the string.

## Out of scope

Payment matching/import (owners still match manually in their bank).
