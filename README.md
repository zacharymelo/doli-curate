# Doli Curate

Screens for organising a Dolibarr product catalogue into categories: a bulk
assign worklist, re-runnable tagging rules with a live preview, category tree
management, and a coverage dashboard.

Every membership change is recorded and can be undone.

## Why

A product that belongs to no category cannot be reached by browsing anywhere in
Dolibarr — only by search. On a catalogue that grew without a taxonomy, that can
be most of the stock. Fixing it product-by-product through the native interface
is impractical; this module makes it a bulk operation you can preview, apply and
reverse.

## Screens

### Dashboard

Coverage at a glance: how many products and services sit in at least one
category, how many do not, how many categories exist, and how many of those are
empty. A per-category breakdown shows where the stock actually is.

### Assign

A worklist you can filter by search text, product type, sale/purchase status,
category branch, and — the one that matters most — **tagged or untagged**.
Select rows, pick one or more categories, and add or remove in a single action.

Selection survives paging and filter changes, so you can gather products across
several different views before applying anything.

### Rules

Named, re-runnable rule sets. A rule matches on:

| Match | Against |
|---|---|
| Reference starts with / ends with / is exactly | `ref` |
| Reference matches regex | `ref` |
| Label contains | `label` |
| Product type is | product or service |
| Has supplier | a purchase price for that thirdparty |
| Every product | everything |

**Preview before you commit.** The preview lists exactly which products each
rule would newly affect, already excluding anything sitting in the target
category, so what you see is precisely what applying will change. A rule set can
be restricted to untagged products only, which makes it safe to re-run as new
stock arrives.

### Category tree

Create, rename, move, merge and delete categories. The destructive operations
are guarded:

- A category cannot be moved inside its own subtree.
- A category cannot be merged into one of its own descendants.
- Duplicate sibling names are rejected, so paths stay unambiguous.
- Deleting a category with subcategories is refused; deleting one that still
  holds products asks first, then detaches them through the audit trail.

Merging moves every product from the source to the target, re-parents the
source's subcategories, and deletes the source.

### History

Every batch this module applied: when, by whom, from which screen, and how many
memberships were added and removed. Any batch can be reversed. The reversal is
itself recorded, so an undo can be undone.

## Safety

- **Additive.** Memberships are changed with `Categorie::add_type()` and
  `del_type()`. `Product::setCategories()` is never called — it replaces a
  product's entire category set and would erase tagging done elsewhere.
- **One audited path.** Nothing outside `DoliCurateCurator` writes to
  `llx_categorie_product`. That is what makes the history complete and undo
  trustworthy.
- **Idempotent.** A change is skipped, not failed, when the catalogue is already
  in the requested state, so re-running anything is harmless.
- **Transactional.** If any change in a batch fails, the whole batch rolls back.
- **Capped.** A configurable batch limit bounds how much one operation can do.
- **Native permissions.** Writing requires a Doli Curate right *and* the native
  product create right.

> Merging or deleting a category removes it permanently. The product moves are
> reversible from History; the category row is not. The interface says so before
> you confirm.

## Requirements

- Dolibarr 19.0+
- PHP 7.3+
- Native **Products** (or Services) and **Categories** modules enabled
- MySQL / MariaDB / PostgreSQL

## Install

```bash
./build.sh
```

Then in Dolibarr: **Home → Setup → Modules → Deploy external module**, upload
`dolicurate-<version>.zip`, enable **Doli Curate**, and grant the rights you want
under **Users & Groups**. Only *read* is on by default.

## Local development

```bash
docker compose up -d
```

Dolibarr comes up on <http://localhost:8092> (admin / admin) with `./module`
mounted at `custom/dolicurate`.

## Permissions

| Right | Allows |
|---|---|
| `curate → read` | View the dashboard, worklist and history |
| `curate → assign` | Add and remove product categories |
| `curate → rules` | Create, edit and run rule sets |
| `curate → tree` | Rename, move, merge and delete categories |
| `curate → undo` | Reverse a previously applied batch |

Reversing someone else's batch is deliberately a separate right from tagging.

## Configuration

Home → Setup → Modules → Doli Curate → Setup.

| Setting | Default | Effect |
|---|---|---|
| Worklist page size | 50 | Rows per page on the assign screen |
| Preview limit | 200 | Rows shown per rule when previewing |
| Batch limit | 2000 | Maximum changes in one operation |
| Keep history for | 90 days | Older entries can no longer be undone |
| Show thumbnails | off | Product photos in the worklist |
| Debug mode | off | Exposes `ajax/debug.php` |

## Troubleshooting

Enable **Debug mode**, then as an admin open:

```
/custom/dolicurate/ajax/debug.php?mode=all
```

It reports module status, the current user's rights, table health, asset
presence, coverage figures, the category tree with counts, stored rule sets, and
recent batches. `?mode=sql&q=SELECT...` runs read-only queries.

## Related

[Doli Catalog](https://github.com/zacharymelo/doli-catalog) puts a visual
category browser into quote, order, invoice and BOM line entry. Doli Curate is
what you use to make that browser worth opening — the two are independent, but
a well-curated tree is what makes picking fast.
