# Remove Password from PDF

A fast, privacy-first tool to unlock password-protected PDFs you own.

## Deploying on Hostinger (Shared Hosting)

1. PHP Version: set PHP 8.1+ in your hosting control panel.
2. Upload files: deploy the repo to your domain root.
3. Permissions: ensure `_storage`, `_storage/tmp`, `_storage/out` are writable (755/775).
4. HTTPS: enable SSL and keep HSTS (already configured in `.htaccess`).
5. Env vars (optional):
   - `HOST_ALLOWLIST="example.com,www.example.com"`
   - `CANONICAL_HOST="example.com"`
6. Health check: visit `/index.php?action=health`.

Note: qpdf/Ghostscript are required to unlock PDFs. Shared hosting may not allow them; consider a VPS or a compatible API.

## Security
- CSP restricts resources; tailored for Tailwind CDN runtime.
- CSRF protection on form posts.
- File validation for type/size.
- Storage not web-accessible via `.htaccess`.

## Performance
- Gzip compression and cache headers via `.htaccess`.
- Resource hints for CDNs.
- Minimal CSS fallback to avoid FOUC.

## Next steps
- Switch to compiled CSS to remove CDN dependency and tighten CSP.
- Add sitemap.xml and branded error pages.
- Optionally protect health endpoint via token.
