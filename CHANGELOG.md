# Changelog

All notable changes to Doli Curate are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[1.0.1]: https://github.com/zacharymelo/doli-curate/releases/tag/v1.0.1
[1.0.0]: https://github.com/zacharymelo/doli-curate/releases/tag/v1.0.0
