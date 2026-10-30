# doublespark/contao-atlas-bundle

A small Contao bundle that lets Doublespark Atlas publish news entries to a Contao site. Targets Contao 4.13 and 5.x. A plain
Symfony controller, no API Platform, no dependencies beyond Contao's own.

## Installing on a site

Installed like every other Doublespark bundle: through the site's own Git repository, never by changing code on the server.

1. In the site repo (locally, on the same PHP version as the site), add the VCS repository and require a tagged version:

   ```json
   "repositories": [
       { "type": "vcs", "url": "https://github.com/doublespark/contao-atlas-bundle" }
   ],
   "require": {
       "doublespark/contao-atlas-bundle": "dev-main"
   }
   ```

   then `composer update doublespark/contao-atlas-bundle` so only this package is added to `composer.lock`.
2. Add `/config/atlas_api.yaml` to the site's `.gitignore` (it holds the token hash and is written on the server, never committed).
3. Commit `composer.json`, `composer.lock` and `.gitignore`, push to GitHub.
4. On the server: `git pull`, `composer install` (never `require` or `update`), then `php vendor/bin/contao-console contao:migrate --no-interaction`
   to create `tl_atlas_news_ref`.
5. Atlas writes `config/atlas_api.yaml` (the token hash) and runs its smoke test.

Like the other in-house Doublespark bundles, sites require `dev-main` and `composer.lock` pins the exact commit, so `composer install`
on a server always installs exactly what was committed. To ship a change: commit here, then in each site repo run
`composer update doublespark/contao-atlas-bundle` locally, commit the lock file and deploy as above.

## What it adds

- `POST /atlas-api/news` creates a news entry in a named archive. Fields: `archive_id`, `headline`, `teaser`, `body_html`, `alias`,
  `meta_title`, `meta_description`, `author_id`, `publish_at` (Europe/London unless it carries its own offset), `published` (bool),
  `atlas_ref` (required string), `category_ids` (optional list of whole numbers, v0.2). Returns `{id, url, author_id}` with 201, plus
  `category_ids` (read back from the database) on a site that has the categories mechanism. If an entry with that `atlas_ref` already exists it returns the
  existing `{id, url}` with 200 and creates nothing.
- `PUT /atlas-api/news/{id}` updates an entry this API created. Same fields, all optional; `published` sets or clears publication.
  `atlas_ref` is fixed at creation.
- `category_ids` (v0.2) replaces the entry's categories on PUT (`[]` clears them; leaving it out changes nothing). It needs the news categories
  extension's data model on the site: a `tl_news_category` table and a relation table with `news_id` and `category_id` columns (normally
  `tl_news_categories`), found by looking at the schema, not assumed. If `tl_news` has a `categories` column that can hold text, the serialised id list is
  written there as well. **No mechanism, or a category id the site does not have, is a 422 naming the problem and nothing is saved; categories are never
  silently dropped.** A malformed `category_ids` is a 400. Not run against a live site (no PHP here): the extension's table names are matched by shape.
- Category column mode (30 October 2026), for a site with its own category dropdown instead of the extension (Doublespark: `tl_news.primary_category`, a whole-number
  id; the label comes from the site's own Options class). Set `category_mode: column` and `category_column: <name>` in `config/atlas_api.yaml` (Atlas writes them
  with `contao-set-token --category-mode column --category-column <name>`). Then `category_ids` must hold **exactly one** whole number (422 naming the rule otherwise, an
  empty list included); the column must exist in `tl_news` and not be one the bundle or Contao owns (422 otherwise); the value is written in the column's real type and
  the answer echoes `category_ids` as `[value]` read back from the column. The extension mechanism is not used in this mode. Without `category_mode` the behaviour is
  exactly as above. Not run against a live site (no PHP here).
- Author (v0.2): `author_id` must be a back-end user (`tl_user`), else 422. On create, a missing `author_id` falls back to
  `doublespark_contao_atlas.default_author_id` (set in the site's own `config/config.yaml`, NOT in `config/atlas_api.yaml`, which `contao-set-token` rewrites)
  if that user exists, else the column is left to its own default; an explicit 0 is never written. Core Contao's news archive has no author field of its
  own (not checked against a live site), so there is no archive level default. Atlas keeps a per-site default (Doublespark: tl_user id 2, Gary Cottam) and
  sends it as `author_id` on every post, with a per-post override. On update, `author_id` changes the author only when sent.
- Both need `Authorization: Bearer <token>`. No token or a wrong one is 401; an id this API did not create is 404.
- `config/atlas_api.yaml` holds `doublespark_contao_atlas: token_hash: '<sha256>'`. Atlas writes it on the server (outside Git, the file is gitignored) with a hash it
  computed itself, so the plain token never reaches the site.
- `php bin/console atlas:mint-token [--rotate]` generates a token, stores only its sha256 in `config/atlas_api.yaml` (mode 0600),
  and prints the token once to stdout (everything else goes to stderr). Clear the cache afterwards.
- A table, `tl_atlas_news_ref`, records the entries the API created. Run `contao:migrate` after installing to create it.

## Not covered

Images and any read endpoint are not part of this version. Categories are read by the `contao-site-info` command (Atlas side), not by an endpoint here.

## Not verified

Written without PHP available and not run against a Contao site. The `tl_news` columns `pageTitle` and `description`
are written only if the site's table has them; entry URLs come from `contao.routing.content_url_generator` (Contao 5.3 and later) or
`News::generateNewsUrl` (earlier), and are `null` if Contao cannot work one out. Test on a non-live site first.

## Column types (flags and timestamps)

The writer reads the real column type of `tl_news` and writes by it. `published` is `char(1)` on Contao 4.13 (`'1'` / `''`) but an integer or boolean column on 5.x (`1` / `0`; MySQL strict mode refuses `''` there, SQLSTATE 22007). `start` / `stop` are written as a timestamp (a digit string on string columns, an int on integer columns) and "none" as `''` / `0` (or NULL on a nullable column). Every other column the writer sets (`pid`, `tstamp`, `date`, `time`, `author`, `news_id`, `content_id`, `sorting`) is an integer on both versions and is written as an int; the rest are strings. Not run against a live PHP or database here.
