# Changelog

All notable changes to Doli Curate are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.4.2] - 2026-08-23

### Fixed

- **Deployed JavaScript never reached browsers.** Every script URL was versioned
  with `MAIN_MODULE_DOLICURATE_VERSION` — a constant Dolibarr does not write —
  so all of them were pinned to the fallback `?v=1.0.0` permanently. Once a
  browser cached a file at that URL it kept serving it, and no amount of
  deploying changed anything.

  This is why shift-click range selection appeared not to work: the browser was
  still running the 1.0.0 script, which does not contain it. The same applied to
  every JavaScript change since 1.0.0, including the history batch detail.

  Scripts and the stylesheet are now versioned by the file's modification time,
  which changes exactly when the file does.

- **Stylesheet had the same problem**, being registered through `module_parts`,
  which emits an unversioned `<link>`. It is now emitted with a version token.
  Enabling the module also removes the orphaned `MAIN_MODULE_DOLICURATE_CSS`
  constant, which `delete_module_parts()` would otherwise leave behind for good.

  > If a script URL still reads `?v=1.0.0` after deploying, the old files are
  > still in place. A correct install shows a long number.

## [1.4.1] - 2026-08-23

### Added

- **Shift-click range selection on the assign worklist.** Click one row, then
  shift-click another to apply that state across every row between them.

  The range takes the state of the checkbox that was shift-clicked, so
  shift-clicking a box *off* clears the range rather than selecting it — undoing
  a mis-selected range is the same gesture as making one. Ranges work in both
  directions.

  The header select-all stays truthful: rows are updated in place rather than
  re-rendered, so it is resynced explicitly once a range completes the page.
  Shift-clicking otherwise drags a text selection across the rows it spans, so
  that is cleared as part of the gesture.

  The anchor is a position on the current page, so it is dropped whenever the
  rows underneath it change — paging, filtering, or a page-wide select-all.
  Selections themselves still survive all of those, as before.

## [1.4.0] - 2026-08-23

### Fixed

- **Module id collision.** This module was numbered `500420`, which is also used
  by another module in the same estate. Dolibarr keys module identity and
  permission definitions off that number, so two modules sharing one is a real
  conflict rather than a cosmetic clash.

  The module is now `500500`, and its five permissions moved from
  `500421`–`500425` to `500501`–`500505`.

  **Existing permission grants are migrated automatically.** Grants live in
  `llx_user_rights` and `llx_usergroup_rights` keyed by permission id, so
  renumbering alone would have left every grant pointing at an id this module no
  longer owns — users would have silently lost access with nothing to explain
  why. Enabling the module remaps them.

  The migration only touches an old id when nothing else currently claims it in
  `rights_def`. If another module has taken one, its grants are left alone
  rather than absorbed. Re-running is safe and does not duplicate grants.

  > Verified with a non-admin user and a group holding rights under the old ids:
  > both keep exactly the permissions they had, renumbered, and a grant
  > belonging to another module is untouched.

## [1.3.0] - 2026-08-23

### Changed

- **Rename and Move are now a single Modify action.** The tree row offered
  Rename, Move, Merge and Delete. Rename and Move have been replaced by one
  **Modify** editor that opens inline and edits name, colour and location
  together.

  This fixes a real defect, not just the click count. Renaming validated the new
  name against the category's **old** parent, while moving validated its **old**
  name against the new parent — so neither checked the new name against the new
  location. Renaming and re-parenting in two steps could therefore produce two
  siblings sharing a name, which makes category paths ambiguous. `updateCategory()`
  now performs one check against the values the category will actually have.

  The location dropdown omits the category itself and everything beneath it, so
  a move that would orphan a branch cannot be selected at all. The server-side
  subtree and self-parent guards remain as a backstop.

  Colour is editable from the same place; previously it could only be set when
  creating a category.

### Removed

- `DoliCurateTree::renameCategory()` and `moveCategory()`, and the `rename` and
  `move` AJAX actions, all superseded by `updateCategory()` and `update`.

## [1.2.0] - 2026-08-22

### Changed

- **Moved into the Products | Services menu.** The module no longer claims its
  own entry in the top navigation bar. It now sits at the bottom of the
  Products | Services left menu as a "Curate" section with its five screens
  beneath it, which is where a catalogue tool belongs.

  > Upgrading requires disabling and re-enabling the module: menu definitions
  > are written to `llx_menu` at activation, so the old top-level entry persists
  > until then. Settings and audit history are unaffected.

- **History rows now say what changed.** The list previously showed only add and
  remove counts, which was not enough to judge a batch. Each row now carries a
  summary — "5 products → Docks, Licences, Accessories" — and names the rule set
  when one was responsible.

### Added

- **Expandable history rows.** Clicking a batch lists every individual change:
  whether it was an add or a remove, which product, and which category. Products
  and categories link to their Dolibarr cards.

  Records stay honest when the catalogue moves on: a product or category deleted
  since the batch ran is shown struck through as `#id (deleted)` rather than as
  a blank, so a category merge reads correctly long after the source category is
  gone.

  Detail is fetched on demand and cached per batch, so opening a row costs one
  request and re-opening costs none. Batches larger than the fetch limit say how
  many changes are not shown.

## [1.1.0] - 2026-08-22

### Changed

- **"Has supplier" rules now use a supplier picker instead of a raw id box.**
  The field listed a thirdparty id, which meant looking the number up elsewhere
  and offered no protection against typing one that did not exist. It is now a
  dropdown of thirdparties flagged as suppliers, each annotated with how many
  products it prices — so a supplier that would make the rule match nothing is
  visibly `(0)` rather than a silent dead end. Suppliers with no purchase prices
  are still listed: hiding them would make an expected name simply absent with
  no explanation.

- **"Product type is" now offers Product / Service** rather than asking for
  `0` or `1`.

- **Saved rules display resolved values.** A supplier rule shows the supplier's
  name and a type rule shows Product or Service, instead of the stored id. A
  supplier deleted after its rule was written renders as `#id (Unknown)` so the
  rule does not read as valid.

### Added

- Server-side validation for both fields: a supplier id must belong to an
  existing thirdparty flagged as a supplier, and a product type must be 0 or 1.
  The picker makes bad input unlikely; the validation makes it impossible,
  including for anything posting the endpoint directly.

## [1.0.1] - 2026-08-22

### Fixed

- **Category multi-select could not be scrolled.** Dolibarr's eldy theme applies
  a bare `select { overflow: hidden }` rule. That is harmless for ordinary
  dropdowns, which the browser draws natively outside the element box, but it
  clips a `<select multiple>`: options past the visible rows were unreachable by
  mouse wheel or scrollbar, with no visual indication that more existed.
  Clicking still selected, which is why the list looked merely truncated rather
  than broken. The assign screen's category picker now sets `overflow-y: auto`
  and shows more rows by default.

## [1.0.0] - 2026-08-22

First release.

### Added

- **Coverage dashboard** — how much of the catalogue sits in at least one
  category, split by products and services, with a per-category breakdown and
  a count of empty categories.

- **Bulk assign screen** — a filterable worklist (search, type, sale/purchase
  status, category branch, and a tagged/untagged filter) with multi-select and
  one-action add or remove across many products. Selection survives paging and
  filter changes, so products can be gathered from several views before applying.

- **Rule sets** — re-runnable tagging rules matching on reference prefix,
  suffix, exact value or regular expression, on label substring, on product
  type, or on supplier. Every rule set can be previewed: the preview shows
  exactly which products each rule would newly affect, excluding anything
  already in the target category, before a single row is written.

- **Category tree management** — create, rename, move, merge and delete
  product categories. Moves are refused when they would place a category inside
  its own subtree; merges are refused into a descendant; duplicate sibling names
  are rejected.

- **Audit trail with undo** — every membership change is recorded with a batch
  id, actor, and source (assign screen, rule set, category merge, or undo). Any
  batch can be reversed from the History screen. The reversal is itself recorded,
  so an undo can be undone.

### Security

- Writes require both a Doli Curate right and the native `produit`/`service`
  create right, so the module never widens what a user could already do.
- Separate rights for reading, assigning, rule management, tree management and
  undo.
- All mutating endpoints are POST-only and CSRF-checked.
- A configurable batch limit caps how many changes one operation may make,
  guarding against a mistyped rule rewriting the whole catalogue.
- Every query is entity-scoped with `getEntity()`.

[1.4.2]: https://github.com/zacharymelo/doli-curate/releases/tag/v1.4.2
[1.4.1]: https://github.com/zacharymelo/doli-curate/releases/tag/v1.4.1
[1.4.0]: https://github.com/zacharymelo/doli-curate/releases/tag/v1.4.0
[1.3.0]: https://github.com/zacharymelo/doli-curate/releases/tag/v1.3.0
[1.2.0]: https://github.com/zacharymelo/doli-curate/releases/tag/v1.2.0
[1.1.0]: https://github.com/zacharymelo/doli-curate/releases/tag/v1.1.0
[1.0.1]: https://github.com/zacharymelo/doli-curate/releases/tag/v1.0.1
[1.0.0]: https://github.com/zacharymelo/doli-curate/releases/tag/v1.0.0
