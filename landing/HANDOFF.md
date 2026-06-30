# FasterFy Landing — Handoff / Continuation Guide

> Purpose: let **any** new session (or another Kiro account) pick up exactly
> where we left off, with full context. Read this first.

---

## 1. What this is

A **pre-launch landing page** for FasterFy (a WordPress "AI Media Optimizer"
plugin being turned into a subscription SaaS). Goal of the page:
**waitlist capture** + **pricing conversion**.

- Self-contained, no build step. Plain **PHP + CSS + vanilla JS**.
- Lives in the `landing/` folder of this repo.
- **English is the base language**; Spanish is a secondary toggle (the product
  targets the USA + LATAM, English-first).

## 2. Current status — DONE

- [x] Full page structure & copy (English base + ES translation).
- [x] FasterFy branding: SVG logo mark, favicon, OG image (brand palette).
- [x] Hardened waitlist endpoint (CSRF, honeypot, time-trap, rate-limit,
      server-side validation, JSON output, append-only storage).
- [x] Modern fintech/SaaS **dark** redesign (wide layout, large type, pill nav,
      hero dashboard widgets, stat cards with trend arrows, rounded cards,
      lime-green CTA block).
- [x] Stylized typography: **Space Grotesk** (display) + **Inter** (body),
      lighter weights.
- [x] Tasteful **microinteractions** (all respect `prefers-reduced-motion`):
      nav underline + active-section scrollspy, header elevation on scroll,
      reveal-up + staggered card reveals, icon hover micro-animations, floating
      hero widgets, animated progress bar, CTA arrow nudge.
- [x] `preview.html` — single self-contained file mirroring the page for quick
      visual review (form is simulated there).

## 3. Design system (source of truth)

- **Palette:** ink `#1F1F1F` / brand-lime `#33EE33` / white `#FFFFFF`
  (+ deep green-black `#14241a` for featured panels). Defined as CSS custom
  properties in `assets/css/styles.css` `:root`.
- **Fonts:** `--font-display` = "Space Grotesk", `--font` = "Inter"
  (Google Fonts, `display=swap`). Headings weight ~600 (intentionally light).
- **Container width:** 1320px. Base font 18px. Radii: 14 / 20 / 30 / 40px + pill.
- **Mode:** dark only (per owner's request).

## 4. File map

```
landing/
├── index.php            # The page. Sets CSRF token + security headers, renders HTML.
├── api/waitlist.php     # Hardened POST endpoint (JSON). Stores signups.
├── includes/bootstrap.php # Session, CSRF, security headers, rate limiting, data dir.
├── assets/
│   ├── css/styles.css   # All styles (design system + components + animations).
│   ├── js/main.js       # i18n (EN/ES), toggles, AJAX submit, scrollspy, reveals.
│   └── img/             # fasterfy-mark.svg, favicon.svg, og-image.svg
├── data/                # AUTO-CREATED at runtime, git-ignored. Waitlist + rate state.
├── preview.html         # Standalone inlined preview (CSS+JS+SVG inline; form simulated).
├── .htaccess            # Compression, asset caching, denies includes/ + data/.
├── README.md            # Setup / deploy / security overview.
└── HANDOFF.md           # ← this file.
```

> When editing styles/markup/JS, update **both** the real files
> (`index.php` + `assets/`) **and** `preview.html` (which inlines copies),
> so the quick preview stays in sync.

## 5. i18n conventions

- HTML carries English text + `data-i18n="key"` attributes (and
  `data-i18n-attr` for attribute translations like placeholders).
- `assets/js/main.js` holds the EN + ES dictionaries and swaps text on toggle
  (persisted in `localStorage`, defaults from `navigator.language`).
- The server (`api/waitlist.php`) has its own EN/ES message table.
- **Adding a string:** add `data-i18n` in markup, then add the key to BOTH
  `en` and `es` dictionaries in `main.js` (and mirror in `preview.html`).

## 6. Security model (waitlist form)

CSRF token (session, constant-time) · honeypot field `company_website` ·
time-trap (min fill seconds) · per-IP file rate limit · strict
validation/sanitization · JSON-only responses · append-only `data/waitlist.jsonl`.
CSP forbids inline JS (so all JS is external/IIFE).

## 7. How to view / run

- **Quick visual:** open `preview.html` (htmlpreview.github.io or download raw
  and open locally). Form is simulated there.
- **Real page:** `cd landing && php -S 127.0.0.1:8000` → http://127.0.0.1:8000
- **Validate:** `php -l index.php` / `node --check assets/js/main.js`

## 8. Deploy notes

- Any PHP 8.0+ host. Ensure `data/` is writable (ideally move it outside web
  root via `DATA_DIR` in `includes/bootstrap.php`).
- Nginx: replicate `.htaccess` rules (deny `includes/` + `data/`, gzip, cache).
- **Before launch:** confirm prices (placeholders), swap in the official logo
  asset, connect an email/CRM service with double opt-in.

## 9. Backlog / next steps (not yet done)

- [ ] Testimonials section (the lime-green block from the reference).
- [ ] Blog / "Latest news" section.
- [ ] Email service / CRM integration (Mailchimp, Brevo, ConvertKit…) +
      double opt-in for the waitlist.
- [ ] Replace placeholder prices with final ones.
- [ ] Swap reconstructed SVG logo for the official brand asset.
- [ ] Optional: live "spots remaining / N joined" social-proof counter.
- [ ] Optional: analytics (privacy-friendly) + conversion tracking.

## 10. Continuing from a new session / account

1. Give Kiro this repository (`EslenderE3/FasterFy-Plugin`).
2. Work on the branch where the landing lives (or `main` once merged).
3. Read this file + `README.md` for full context.
4. Keep English as the base language and wrap new strings for i18n.

_Design reference the owner liked: a modern fintech/SaaS landing (pill nav,
large type, floating dashboard cards, stat grid with up-arrows, lime accent),
adapted to dark mode._
