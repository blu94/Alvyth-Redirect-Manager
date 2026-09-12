# Changelog

All notable changes to Redirect Manager are recorded here, newest first. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the versions follow
[semantic versioning](https://semver.org/spec/v2.0.0.html) — comparable with `version_compare`,
which is what Alvyth's updater reads them with.

---

## [Unreleased]

### Requires

- **Alvyth >= 1.4.1.** Four fixes below have a half that lives in Alvyth itself: the query-string
  encoding behind the seeded-form defect, the locale carried on `PathNotResolved`, the per-page
  permission verb, and letting a client fault out of `GenericModuleController` at its own
  status. On an older core each one silently reverts to the defect it replaced — the package
  would install, the screens would work, and the open-redirect path would be back with nothing
  to say so. So the floor is raised rather than left understated, the same way it was raised to
  1.3.0 for the root dispatch, and the package refuses an older core at install.

### Security

- **A visitor can no longer pre-fill the admin's redirect form with their own destination.**
  The Broken Links row action opens the rule form with the dead path already in it, and the
  path is whatever a stranger asked for. An address containing `&` — `/promo&to_path=https://
  elsewhere.example` — was substituted into the link raw, so its `&` became a real parameter
  separator and seeded **To path** with an address nobody on this side chose. One Save minted an
  open redirect on the shop's own domain. Values are now encoded for the position they land in.
  *(The fix is in core's `useModuleWrapper`; it protects every module, not only this one.)*
- **Scoring a suggestion can no longer be made expensive by the visitor.** `similar_text` is
  O(n³) in the worst case and the path came straight from the request URL: 5,000 candidates
  against a 2,100-character path measured **2.0 seconds of CPU**, on the cheapest request there
  is to generate. The needle is now cut to the width a path can be stored at, and a candidate
  that could not reach the threshold on its length alone is never compared — an exact bound, so
  no suggestion is lost. The same measurement now costs **0.6 ms**. Scored answers are cached
  per address, so a crawler asking the same dead URL repeatedly pays once.
- **The CSV export no longer writes formulas.** A cell beginning `=`, `+`, `-`, `@`, tab or
  carriage return is executed on open by Excel, LibreOffice and Sheets, and this screen tells
  the operator to save the export as a `.csv`. Such cells are now escaped, and the import takes
  the escape back off — so a negative priority still round-trips as a number.
- **Prune history now needs `redirect_manager.delete`.** Every action on this screen arrives as
  a POST, which meant `create`: a bulk delete of recorded 404s was admitted to anyone who could
  create, and refused to a role granted delete and nothing else. Marking an entry dealt with is
  an `update`. The verbs are declared in `module.json` and checked again in the repository, so
  the package still fails closed on a core that predates the declaration.

### Fixed

- **The Overview's monthly chart no longer reports a lifetime total as a monthly figure.** It
  summed each rule's running `hits` into whichever month it was last used, labelled "Redirects
  served" — so a rule that had served 10,000 redirects put all 10,000 into one month and moved
  all 10,000 to the next the moment it was used again. The series now counts **rules last used**
  in each month, which is what `last_hit_at` can honestly answer.
- **A broken link fixed by a prefix or pattern rule now closes.** Only exact rules closed an
  entry, so an operator who moved a whole section with one prefix rule — the case prefix rules
  exist for — watched every path under it stay open, with the detail screen reporting that
  nothing fixed it. Both halves now ask the matcher, so the screen and the storefront cannot
  disagree about whether a path is fixed.
- **A 404 on the site root is recorded.** `/?p=999` — an old permalink for a post, and the
  address this package raised its core floor to serve — normalises to an empty path and was
  dropped, so the one case query matching exists for produced no evidence to write a rule from.
  The query is now a column and part of a recorded problem's identity, so `/?p=123` and
  `/?p=456` are two entries rather than one that names neither.
- **A redirect keeps the visitor's locale.** Core strips the locale segment before asking, and
  the destination was built from the stripped path — so `/ms/laman-lama` redirected to
  `/laman-baru` and moved a Malay visitor onto the default locale. Storefront suggestions had
  the same fault from the other end: the locale was left on the path being scored while every
  candidate was a bare slug, so a multilingual shop got worse suggestions than a monolingual one.
- **A dead path longer than 255 characters is recorded rather than dropped.** The write threw,
  the failure was swallowed, and no row appeared — so the long URLs a broken link builder
  generates were exactly the ones never seen.
- **Every rule in a chain is counted.** Only the first was, so the rules in the middle reported
  zero hits and appeared under "Rules never used" — an invitation to delete the rule that makes
  the chain resolve.
- **The listener stands aside when another plugin has already answered.** Core takes the first
  offer, but this one still recorded a miss — putting a path that redirects perfectly well onto
  Broken Links as a dead end.
- **An import no longer stores a rule that can never fire.** `status` was the one enumerated
  column not validated, so a file calling it `enabled` — which Redirection and several WordPress
  exporters do — imported cleanly, reported "1 created", and stored a rule the matcher never
  matches. All three enumerated columns are now checked from one list, and another tool's words
  for active and inactive are understood.
- **An unrecognised CSV header is named as the cause.** The first line was read as data and every
  row after it failed on its destination, so the operator was told once per line that a rule needs
  somewhere to send the visitor — the symptom furthest from the cause. More column names are
  recognised: `source`, `old_url`, `url`, `redirect_from`, `target`, `destination`, `new_url`.
- **Settings are read back after being saved.** `firstOrCreate(['id' => 1])` could not create the
  row it looked for — `id` is not fillable — so it inserted at whatever the auto-increment had
  reached and missed again next time. On a fresh install the counter is at 1 and it worked by
  coincidence; once anything moved it, the table grew once per request and nothing saved in
  Settings was ever read back.
- **Broken Links refuses a write it cannot honour.** `PUT` on an entry answered 200 having
  changed nothing, which a caller cannot tell from success. It now refuses and names the action
  that does work.
- **A refused rule reports as a refusal.** A duplicate, an uncompilable pattern or a rule with
  nothing to match on arrived as **HTTP 500** with the explanation in the body — so a screen
  refusing bad input a hundred times a day looked like a hundred outages. They are validation
  failures now, and the message lands on the field that caused it.

### Changed

- **A fresh install starts with an ignore list.** The Settings screen already explained why one
  is necessary and then shipped none, so every install spent its first weeks filling Broken Links
  with scanners probing for software the site does not serve. The seeded patterns are narrow —
  nothing that could match a page, product, post or collection URL — and are ordinary rows an
  operator can edit or remove. An install that already has a list keeps it.
- **Recording stops at 10,000 open entries and says so on the Overview.** Deduplication bounds
  this table at one row per *distinct* dead path, and generating distinct paths is exactly what a
  scanner does. Past the ceiling, addresses already listed keep counting and new ones are not
  added — a list that silently stopped growing reads as a site whose links stopped breaking.
- **Import and export are bounded at 5,000 rules, stated on the screen.** Both run inside a
  single request and the import inside a transaction, so an unbounded file was a request that
  timed out halfway with no way to tell which half applied.
- **Cached state is scoped to its database.** The rule set, the candidate paths and the recording
  ceiling were cached under flat keys, so two schemas sharing one cache store — which is what
  this project's own dev container does — could answer each other's questions.
- **Settings are memoised per request rather than per process**, so the plugin behaves the same
  under Octane, Swoole or a long-running queue worker as it does under php-fpm.

### Added

- Tests: **30 → 101**, covering every fix above. The CSV importer, the recorder, the suggester,
  the Overview's aggregates, the settings row and the permission checks had no coverage at all.
- This changelog.

---

## [1.1.0]

- A rule can match the site root, so `/?p=123` can finally redirect. Requires Alvyth 1.3.0, whose
  `ThemeController` dispatches `PathNotResolved` for the root when the request carries a query.
- `/` is kept as a destination instead of being lost on save.
- Re-creating a deleted rule revives it rather than colliding with a soft-deleted row the
  operator cannot see.
- The suggested fix shown against a broken link is narrowed to a rule that could actually fix it.
- The broken-link actions are reachable, and the package's own tests are runnable.

## [1.0.0]

- First release. Extracted from Alvyth core, where redirects were two built-in screens backed by
  a `redirects` table read directly by `ThemeController`.
