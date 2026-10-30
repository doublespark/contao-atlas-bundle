# doublespark/contao-atlas-bundle

A small Contao bundle that lets Doublespark Atlas publish news entries to a Contao site. Version 0.3.0. Targets Contao 4.13 and 5.x. A plain
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

## Element list (v0.3)

`POST /atlas-api/news` and `POST` or `PUT /atlas-api/news/{id}` take an optional `elements` list instead of `body_html`. Request (`Authorization: Bearer <token>`, JSON):

```json
{
  "archive_id": 2,
  "headline": "10 PPC Strategies We Brought Back from BrightonSEO 2026",
  "teaser": "Twenty to thirty-five words.",
  "alias": "10-ppc-strategies-we-brought-back-from-brightonseo-2026",
  "meta_title": "At most 60 characters",
  "meta_description": "At most 155 characters",
  "author_id": 2,
  "published": false,
  "atlas_ref": "atlas-piece-77",
  "category_ids": [3],
  "featured": true,
  "link_text": "Read the strategies",
  "og_title": "At most 70 characters",
  "og_description": "At most 200 characters",
  "elements": [
    { "key": "tldr", "section_id": "tldr", "headline": "", "html": "<p><strong>TL;DR:</strong> ...</p>" },
    { "key": "at_a_glance", "section_id": "at-a-glance", "headline": "At A Glance", "html": "<ul><li>...</li></ul>" },
    { "key": "body", "section_id": "", "headline": "", "html": "<h2>...</h2><p>...</p>" }
  ]
}
```

- Each entry becomes one `tl_content` text element (`ptable` `tl_news`, `pid` the news id), in list order (`sorting` ascending). `headline` is written as
  Contao's serialised headline (`h2` unit); empty means no headline. `html` is the element text. `section_id` is the id part of the element's `cssID`;
  empty means none. `key` is accepted and not stored. `html` is required and must not be blank. `section_id` must be a CSS id: a letter first, then
  letters, digits, hyphens or underscores, 100 characters at most. The list holds 1 to 100 entries.
- On update the entry's content elements are deleted and rewritten in ONE database transaction (needs a transactional table engine, InnoDB on a normal
  Contao site), so a failure leaves the old body in place. `published` is changed only if the request carries it.
- Send `elements` or `body_html`, never both: 422. A site that uses `body_html` alone is unchanged.
- `featured`, `link_text`, `og_title` and `og_description` are written only if `tl_news` has a column for them, found in the schema at run time
  (case-insensitive): `featured`; `linkText` or `link_text`; `og_title` or `ogTitle`; `og_description` or `ogDescription`. A field with no column is
  listed in `fields_unsupported`. `featured` must be true or false, the others strings (422 otherwise). `category_ids` works as in 0.2.
- Answer, 201 on create, 200 on update or on the duplicate guard's existing entry:

```json
{
  "id": 41,
  "url": "https://www.example.co.uk/blog/x",
  "elements_count": 3,
  "fields_applied": ["featured", "category_ids", "og_title"],
  "fields_unsupported": ["link_text", "og_description"],
  "category_ids": [3],
  "author_id": 2
}
```

  `elements_count` is the number of elements written (1 for `body_html`; on an update that sends no body, the number the entry holds). `author_id`
  is kept from 0.2 because Atlas's connection test reads it. Validation errors are `422` with `{"error": "..."}` and nothing is written.
- Tests: `composer install` then `vendor/bin/phpunit` (in-memory SQLite, no Contao kernel).

## Deploying 0.3.0 to a site

Rules (Jamie's, for doublespark.co.uk): GitHub is the only source, the site takes the bundle through Composer with the lock file committed in the
site's repository, deploy is `composer install` then `contao:migrate`, and nothing is copied by hand. Do these in order, one at a time.

1. Confirm 0.3.0 is on GitHub: the bundle's `main` branch carries the 0.3.0 commit (and the `v0.3.0` tag exists, if a tag was pushed).
2. In a local checkout of the site's repository, on the same PHP version as the server, pull the latest `main`.
3. Run `composer update doublespark/contao-atlas-bundle` (this package only; never a bare `composer update`).
4. Check `git diff composer.lock`: only the `doublespark/contao-atlas-bundle` entry should change, to the 0.3.0 commit.
5. Commit `composer.lock` (and `composer.json` if it changed) and push to the site's GitHub repository.
6. On the server: `git pull`.
7. On the server: `composer install --no-dev --optimize-autoloader` (never `require` or `update` on the server).
8. On the server: `php vendor/bin/contao-console contao:migrate --no-interaction`. 0.3.0 adds no table, so this should report nothing to do.
9. On the server: `php vendor/bin/contao-console cache:clear --env=prod` (use the site's usual cache command if it differs).
10. Check from Atlas: send a one-block post (still `body_html`), then a multi-block post, and confirm `elements_count` in the answer matches. Do not touch
    `config/atlas_api.yaml`; the token is unchanged.

If the site needs `link_text`, `og_title` or `og_description`, its `tl_news` needs a column for each first (added by the site's own code and migration).
Until then they come back in `fields_unsupported`.

## Not covered

Images and any read endpoint are not part of this version. Categories are read by the `contao-site-info` command (Atlas side), not by an endpoint here.

## Not verified

Written without PHP available and not run against a Contao site; the 0.3.0 tests were written but not run for the same reason. The `tl_news` columns `pageTitle` and `description`
are written only if the site's table has them; entry URLs come from `contao.routing.content_url_generator` (Contao 5.3 and later) or
`News::generateNewsUrl` (earlier), and are `null` if Contao cannot work one out. Test on a non-live site first.

## Column types (flags and timestamps)

The writer reads the real column type of `tl_news` and writes by it. `published` is `char(1)` on Contao 4.13 (`'1'` / `''`) but an integer or boolean column on 5.x (`1` / `0`; MySQL strict mode refuses `''` there, SQLSTATE 22007). `start` / `stop` are written as a timestamp (a digit string on string columns, an int on integer columns) and "none" as `''` / `0` (or NULL on a nullable column). Every other column the writer sets (`pid`, `tstamp`, `date`, `time`, `author`, `news_id`, `content_id`, `sorting`) is an integer on both versions and is written as an int; the rest are strings. Not run against a live PHP or database here.
