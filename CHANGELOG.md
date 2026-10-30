# Changelog

## 0.3.0 - 30 October 2026

Multi-element news body (post format spec v1.1, Atlas "Run C1").

- `POST /atlas-api/news` and `POST`/`PUT /atlas-api/news/{id}` accept an optional `elements` list: one `tl_content` text element per entry, in
  order, with the entry's headline (Contao's serialised h2 headline), `section_id` as the CSS id, and `html` as the text. On update the entry's
  content elements are replaced in one database transaction, so a failure leaves the old body intact.
- `body_html` alone behaves exactly as in 0.2. `body_html` and `elements` in one request is a 422.
- New optional fields `featured`, `link_text`, `og_title`, `og_description`. Each is written only if `tl_news` has a matching column (read from the
  schema at run time) and is named in `fields_applied` or `fields_unsupported`, never dropped silently.
- Every create and update answers `{id, url, elements_count, fields_applied, fields_unsupported, category_ids}`. `author_id` is still included as before.
  The duplicate guard's 200 answers in the same shape (nothing written, so `fields_applied` is empty).
- New validation errors are 422 with `{"error": "..."}` and happen before anything is written. Existing 400s are unchanged.
- An update never changes `published` unless the request carries `published` (unchanged from 0.2).
- Adds PHPUnit tests (`composer install && vendor/bin/phpunit`), an in-memory SQLite database, no Contao kernel.
