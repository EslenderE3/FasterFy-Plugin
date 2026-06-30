# FasterFy — Pre-launch Landing Page

A self-contained, dependency-free landing page for the FasterFy pre-launch:
**waitlist capture** + **pricing conversion**. Built with plain PHP, CSS and
vanilla JS — no build step required. English is the base language with a
Spanish toggle (aligned with the product's i18n strategy: US + LATAM).

## Structure

```
landing/
├── index.php              # The page (semantic HTML, sets CSRF token + headers)
├── api/
│   └── waitlist.php       # Hardened waitlist endpoint (JSON)
├── includes/
│   └── bootstrap.php       # Session, CSRF, security headers, rate limiting
├── assets/
│   ├── css/styles.css      # Mobile-first, brand-themed styles
│   ├── js/main.js          # i18n, toggles, AJAX submit, animations
│   └── img/                # SVG logo mark, favicon, OG image
├── data/                   # (auto-created at runtime; git-ignored) signups + rate state
└── .htaccess               # Compression, caching, folder protection
```

## Sections

Hero + waitlist form · trust strip · features (benefits/ROI) · how it works ·
ROI band · pricing (Lite / Pro / Agency, placeholder prices) · FAQ · final CTA.

## Security

- **CSRF**: session-bound token, constant-time compare (`hash_equals`).
- **Honeypot** (`company_website`) + **time-trap** (min fill time) anti-bot.
- **Per-IP rate limiting** (file-based sliding window, IP stored hashed).
- **Server-side validation/sanitization** of every field; JSON-only output
  (no user input is ever reflected as HTML → no reflected XSS).
- **Security headers**: CSP (no inline JS), `X-Frame-Options`, `nosniff`,
  `Referrer-Policy`, `Permissions-Policy`.
- Signups are written append-only to `data/waitlist.jsonl` (git-ignored).

## Performance (Core Web Vitals)

- System font stack (zero font requests), inline SVG logo, preloaded CSS.
- Deferred, external JS (CSP-safe); IntersectionObserver-based reveal.
- `prefers-reduced-motion` respected; long-cache headers for fingerprinted assets.

## Run locally

```bash
cd landing
php -S 127.0.0.1:8000
# open http://127.0.0.1:8000
```

## Deploy

Upload the `landing/` contents to any PHP 8.0+ host. Ensure the web server can
create/write the `data/` directory (or point `DATA_DIR` in
`includes/bootstrap.php` to a writable path outside the web root). For Nginx,
replicate the `.htaccess` rules (deny `includes/` and `data/`, gzip, asset
caching).

> **Prices are placeholders** for pre-launch and should be confirmed before go-live.
> The logo mark in `assets/img/` is a vector reconstruction of the brand; swap in
> the official asset when available.
