# Changelog

All notable changes to Doli Curate are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[1.2.0]: https://github.com/zacharymelo/doli-curate/releases/tag/v1.2.0
[1.1.0]: https://github.com/zacharymelo/doli-curate/releases/tag/v1.1.0
[1.0.1]: https://github.com/zacharymelo/doli-curate/releases/tag/v1.0.1
[1.0.0]: https://github.com/zacharymelo/doli-curate/releases/tag/v1.0.0
