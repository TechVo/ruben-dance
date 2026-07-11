# Cookie audit (M15 / spec §6.2)

Spec §6.2 claims the site can run **without a cookie consent banner** because v1
only ever sets strictly-necessary cookies (WordPress's own login session, and the
language choice). This document *demonstrates* that claim rather than assuming it,
per the M15 acceptance criterion — every public page type was crawled, both as an
anonymous visitor and as a logged-in customer, and every `Set-Cookie` response
header was recorded.

## Method

Crawled the wp-env instance (`http://localhost:8888`) with `curl -D -` (dumps
response headers, including every `Set-Cookie`) against every distinct public page
type, in both languages where applicable, first as an anonymous visitor (fresh
cookie jar) and then as a logged-in seeded customer
(`emily.clark@example.com`). No browser devtools were used — response-header
inspection is equivalent and scriptable, so it was re-run against every page
rather than sampled.

Pages crawled:

- Home page (`/`, `/en/`)
- Course catalog (`[rd_catalog]`, `/kurzy/`, `/en/courses/`)
- Public calendar (`[rd_calendar]`, `/kalendar/`)
- Login (`[rd_login]`, `/prihlaseni/`)
- Register (`[rd_register]`, `/registrace/`)
- Lost password (`[rd_lost_password]`, `/zapomenute-heslo/`)
- My account (`[rd_account]`, `/muj-ucet/`, `/en/my-account/`) — anonymous (redirect)
  and logged-in
- Enrollment form (`[rd_enroll]`, `/prihlaska/?term_id=1`) — anonymous
  (login-required screen) and logged-in
- Privacy policy / Terms & Conditions placeholder pages
  (`/zasady-ochrany-osobnich-udaju/`, `/obchodni-podminky/`)

## Result

### Anonymous visitor

Only one cookie is ever set, on the very first page load:

| Cookie | Set by | Purpose | Attributes |
|---|---|---|---|
| `pll_language` | Polylang | Remembers the visitor's chosen language across visits | `Max-Age=31536000` (1 year), `SameSite=Lax`, not `HttpOnly` (Polylang's front-end language switcher script reads it) |

No other `Set-Cookie` header appeared on any subsequent anonymous page, in either
language.

### Logged-in customer

On login (`wp-login.php`), WordPress core sets its own three standard
authentication cookies:

| Cookie | Set by | Purpose |
|---|---|---|
| `wordpress_test_cookie` | WP core | One-time check that the browser accepts cookies at all (login form only) |
| `wordpress_{hash}` | WP core | Auth cookie, scoped to `/wp-admin` and `/wp-content/plugins` |
| `wordpress_logged_in_{hash}` | WP core | Session/login-state cookie, scoped site-wide (`path=/`) |

All three are `HttpOnly`. No plugin — this one included — adds any cookie of its
own for account/session handling; the public `[rd_account]`/`[rd_enroll]` flows
rely entirely on these core WordPress cookies plus the standard PHP session-free,
cookie-based auth WordPress already ships.

Once logged in, `pll_language` continues to be set/refreshed exactly as for an
anonymous visitor — same cookie, same purpose, unaffected by login state.

### Full inventory

| Cookie | Category (Act 127/2005 Sb. / ePrivacy) | Consent required? |
|---|---|---|
| `wordpress_test_cookie` | Strictly necessary (authentication) | No — exempt |
| `wordpress_{hash}` | Strictly necessary (authentication) | No — exempt |
| `wordpress_logged_in_{hash}` | Strictly necessary (session) | No — exempt |
| `pll_language` | Strictly necessary (the visitor's own explicit language choice, remembered for them — spec §6.2 explicitly names "the language choice" as one of the two exempt categories) | No — exempt |

**No analytics, advertising, or third-party tracking cookie was observed anywhere
on the site.** There is no Google Analytics, Meta Pixel, or any other marketing
tag installed (spec §6.2: "v1 uses only strictly necessary cookies... therefore no
Google Analytics/Meta pixel in v1"). Cloudflare Turnstile, if enabled later per
spec, is designed to avoid consent-requiring cookies — not evaluated here since it
is not part of this build.

## Conclusion

Every cookie the site sets, for an anonymous visitor and a logged-in customer
alike, falls into one of the two categories spec §6.2 says are exempt from
opt-in consent under Czech law (Act 127/2005 Sb.): the WordPress login session,
and the Polylang language-choice cookie. **The "no banner needed" claim is
confirmed, not merely assumed.**

If marketing tags, analytics, or any other non-essential cookie are ever added,
this audit must be re-run and a proper opt-in consent-mode banner added *before*
those cookies are allowed to be set (spec §6.2's own instruction) — at that point
this document should be updated to reflect the new baseline, not silently go
stale.

_Audit performed: 2026-07-11, against the M15 build (wp-env, WordPress 7.0.1,
Polylang 3.8.5, ruben-dance 0.1.0)._
