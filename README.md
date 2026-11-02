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
- `POST /atlas-api/news/{id}` (or `PUT`, same route) updates an entry this API created. Same fields, all optional; `published` sets or
  clears publication. `atlas_ref` is fixed at creation. POST is accepted because some servers' nginx refuses PUT with a 405 (Urban
  Square, 30 October 2026); Atlas sends POST.
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

### v0.3 (30 October 2026)

- `elements`, instead of `body_html` (sending both is a 400): a list of 1 to 50 blocks, each `{section_id, headline, html}`. Each becomes one `tl_content`
  text element, in order, with `section_id` as its CSS id (empty means none, otherwise a CSS id) and `headline` as an h2 element headline (empty means none).
  A re-send replaces the elements this API made and leaves any a person added in the back end alone. The answer carries `elements_count`, read back.
  The element ids are kept in `tl_atlas_news_ref.content_ids`, which `contao:migrate` adds; until it has run, a list is refused with a 500 naming it.
- Optional fields, each written only where the site's `tl_news` has the column, and reported as `fields_applied` / `fields_unsupported` in the answer
  (`category_ids` counts as applied whenever it is accepted):
  `featured` (bool, core `featured`), `link_text` (core `linkText`, Contao 5 only), `og_title` and `og_description` (`hofff_st_og_title` /
  `hofff_st_og_description` from hofff/contao-social-tags, which also switches the extension on for the entry and sets its type to `article` when it has none).
  Column names checked in the sources of Contao 4.13.56 and 5.7 and of hofff/contao-social-tags 2.0.4 on 30 October 2026.
- `POST /atlas-api/news/{id}/delete` with `{"atlas_ref": "<the entry's ref>"}` deletes an entry this API created, with all its content elements and category
  links. A missing or different `atlas_ref` is a 409 and nothing is deleted; an id this API did not create is a 404. POST, not DELETE, for the same servers that refuse PUT.
- `php tests/api_sqlite.php` drives the real controller and writer against SQLite in the shape of a Contao 5.7 site and a 4.13 site (29 checks). It needs
  doctrine/dbal 3, symfony/http-foundation and their small dependencies as plain checkouts under `tests/lib/` or the folder named by `BUNDLE_TEST_LIB`.
  It proves the PHP logic only, not a site: MySQL types and Contao's own rendering are proved by the Atlas Connect job's smoke test on each site.

### v0.4 (2 November 2026)

- `GET /atlas-api/pages?url=<page address or path>` returns `{id, alias, type, pageTitle, description}` for the page whose alias matches the path (an empty path
  means the home page, alias `index`). 404 when none, 409 naming the ids when several site roots share the alias.
- `POST` or `PUT /atlas-api/pages/{id}` sets `pageTitle` and/or `description` (strings, 255 characters at most) on `tl_page` through `PageModel::save()`, then
  invalidates the page's cache tag (`contao.db.tl_page.<id>`) through `contao.cache.tag_manager` when the site has it. The answer carries the new values, the
  `previous` ones and `cache_cleared` (false means no tag manager was found, so a cached copy may stay until it expires). POST because some servers refuse PUT.
- Same bearer token as the news calls. The two columns have the same names on Contao 4.13 and 5.x (checked in the sources of 4.13.56 and 5.7, 2 November 2026).
  Not run against a live site (no PHP project here); the Atlas smoke test on each site is the proof.

### v0.5.1 (2 November 2026) - fix: image list answered 500

`GET /atlas-api/images` failed with `array_push(): Argument #1 ($array) must be of type array, null given` for any image that is used only in a
gallery (a `multiSRC` list) and in no single-image column: its placement list had not been started, and `array_push` was handed null. The list is
now started when the first gallery placement is added. Empty, NULL, truncated and non-array `multiSRC` values and meta values (`''`, NULL, `false`,
a bare string) were checked and already read as "none"; `tests/images_sqlite.php` now covers each of them (the check count is higher than the 31 below). Still read-only.

### v0.5 (2 November 2026) - read-only image inventory

Nothing in v0.5 writes: the two routes are GET only, run SELECT statements and read files, and never ask Contao's image factory for a variant
(which would create one). Same bearer token as the other calls.

- `GET /atlas-api/images?page=N&per_page=M` (per_page 1 to 500, default 100) lists every image file in `tl_files` (jpg, jpeg, png, webp, gif, svg,
  avif, any case), oldest id first: `id`, `uuid` (lower-case hex), `path`, `hash`, `extension`, `width` and `height` (from the file header, or an SVG's
  width/height or viewBox; null when the file is not on this server's disk), `meta` per language (alt, title, caption, link, license and whatever else
  the serialised array holds), and `placements`. The answer also carries `total`, `pages` and `uuid_columns` (every column the scan covered).
- A placement is any row whose UUID column references the file. The columns come from `information_schema`, never a fixed list: every `binary(16)`
  column (one UUID, e.g. `singleSRC`, `hofff_st_og_image`, Doublespark's `dsImg_*Src` and `tl_ds_portfolio.main_image`) and every blob column (a
  serialised list, e.g. `multiSRC`) of every `tl_` table, except `tl_files` itself, Contao's bookkeeping tables (versions, undo, log, search, crawl,
  cron, message queue, sessions), user and member permissions, image sizes, and blob columns whose name starts with `order` (`orderSRC` repeats
  `multiSRC` in sort order). Each placement has `table`, `column`, `column_kind` (`binary`: one image, `blob`: a list such as a gallery), `row_id`, `element_type` (the row's `type`), `published` (`invisible` inverted
  where the table has it, else `published`, else null), `overwrite_meta` (null when the table has no such column), the placement's own `alt`,
  `title` (`imageTitle`) and `caption` only when `overwriteMeta` is set, and `parent`: `page_id`/`page_alias`, `article_id`, `news_id`/`news_alias`,
  or `parent_table`/`parent_id` for anything else, plus `url` where Contao can work one out. Content elements are followed through nested elements
  (Contao 5 element groups) to their article or news entry.
- `GET /atlas-api/images/resolve?url[]=...&url[]=...` (1 to 20 addresses; a single `url=` also works) maps rendered addresses to source files. Each
  answer has `uuid`, `path`, `candidates` and `method`: `file_path` (a `files/` address), `deferred_store` (a variant Contao has not generated yet:
  `assets/images/deferred/<variant>.json` names its source exactly), `filename` (a generated variant), `ambiguous` (several image files share the
  name; all are listed as candidates) or `unresolved`.
- Why the file name, not the image factory: contao/image names a variant `<source file name>-<hash>.<format>` in a folder named after the hash's first
  character (`Resizer::createCachePath`); the hash covers the resize settings and cannot be reversed, the factory only works forwards (source to
  variant, creating it), and the deferred record is deleted once the variant is generated. So the deferred store resolves only variants not yet
  generated, and the file name is the general route. This is read from the contao/image source, not proved on a live site; the Atlas inventory
  job's unresolved and ambiguous counts are the live measure.
- `php tests/images_sqlite.php` drives the real controller and service against SQLite with `information_schema` attached, in the shape of a Contao 5.7
  site with Doublespark's custom columns (31 checks, including one that nothing in the database or project folder changed). Same libraries as
  `tests/api_sqlite.php`. Not run against a live site or MySQL here.
- `ContentUrls` is now the one place the bundle works out a news entry's or a page's public address (news publishing uses it too).

## Not covered

Writing image meta, renaming files and redirects are not part of this version (Image Optimisation Phase 3). Categories are read by the `contao-site-info` command (Atlas side), not by an endpoint here.

## Not verified

v0.1 and v0.2 were written without PHP available; both have since passed the Atlas smoke test live on four sites (30 October 2026). The `tl_news` columns `pageTitle` and `description`
are written only if the site's table has them; entry URLs come from `contao.routing.content_url_generator` (Contao 5.3 and later) or
`News::generateNewsUrl` (earlier), and are `null` if Contao cannot work one out. Test on a non-live site first.

## Column types (flags and timestamps)

The writer reads the real column type of `tl_news` and writes by it. `published` is `char(1)` on Contao 4.13 (`'1'` / `''`) but an integer or boolean column on 5.x (`1` / `0`; MySQL strict mode refuses `''` there, SQLSTATE 22007). `start` / `stop` are written as a timestamp (a digit string on string columns, an int on integer columns) and "none" as `''` / `0` (or NULL on a nullable column). Every other column the writer sets (`pid`, `tstamp`, `date`, `time`, `author`, `news_id`, `content_id`, `sorting`) is an integer on both versions and is written as an int; the rest are strings. Not run against a live PHP or database here.
