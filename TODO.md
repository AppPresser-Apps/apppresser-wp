# TODO

## SEO Settings

### High value

- [x] Twitter/X handle — configurable handle for `twitter:site` (and optionally `twitter:creator`). Currently outputs `@Site Name`, which is not a real handle.
- [x] Default meta description — site-wide fallback description (currently falls back to the site tagline).
- [x] Title separator — configurable separator (currently hardcoded as ` - `).
- [x] Robots meta control — global `noindex` toggle (e.g. search/404/archives) or per-post-type noindex.
- [x] Facebook App ID — output `fb:app_id` / `fb:admins`.

### Structured data (Schema.org)

- [x] JSON-LD Organization — name, logo, URL, social profiles.
- [x] JSON-LD Article — headline, author, datePublished, dateModified, image (singular posts).
- [x] JSON-LD BreadcrumbList — breadcrumbs for posts/pages/archives.

### Per-content overrides

- [ ] Meta box on posts/pages — custom title, description, and OG image per post (overrides auto-generated values).

### Other

- [ ] XML sitemap — generate `sitemap.xml` (posts, pages, taxonomies).
- [ ] `og:image` dimensions — `og:image:width` / `og:image:height`.
- [ ] `robots.txt` editor — manage robots.txt from the settings page.
- [ ] Social profile URLs — for `sameAs` in the Organization schema.
