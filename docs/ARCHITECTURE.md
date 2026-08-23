# Architecture

How Doli Curate is put together, and the constraints that shaped it. Read this before changing how mutations happen or adding a screen.

---

## Shape

Unlike a hook-only module, this one owns screens, menus and tables. It has no Dolibarr *business object* though — it does not create records users manage. It manipulates a relationship that already exists: rows in `llx_categorie_product`.

```
module/
  core/modules/modDoliCurate.class.php   descriptor: menus, five rights, settings, tables
  class/
    dolicuratecatalog.class.php          read-only queries: tree, worklist, coverage
    dolicuratecurator.class.php          THE ONLY WRITER of memberships, plus audit + undo
    dolicuraterules.class.php            rule sets: storage, preview, apply
    dolicuratetree.class.php             structural category changes
  ajax/
    products.php  worklist feed
    assign.php    add / remove / undo
    rules.php     rule set CRUD, preview, apply
    tree.php      create / rename / move / merge / delete
    stats.php     coverage, tree, history
    debug.php     admin diagnostics
  index.php assign.php rules.php tree.php history.php
  js/ dolicurate-core.js + one file per screen
```

---

## The single-writer rule

**Nothing except `DoliCurateCurator::applyChanges()` writes to `llx_categorie_product`.**

Rule application and category merges do not write memberships themselves — they build a list of changes and hand it to the curator. That is not tidiness; it is what makes the audit trail *complete*, and an audit trail with holes makes undo actively dangerous rather than merely incomplete.

Two consequences to preserve:

1. **A failed audit write fails the change.** If the log row cannot be inserted, the membership change is treated as failed and the batch rolls back. An unlogged change would be invisible to undo.
2. **The whole batch is one transaction.** Any failure rolls everything back, so a batch is all-or-nothing.

### Additive, never replacing

Memberships change via `Categorie::add_type()` / `del_type()`.

`Product::setCategories()` is **never** called. It replaces a product's entire category set, so using it to add one category would silently delete every other category that product was in. The only mention of it in the codebase is the comment explaining this.

To verify that property still holds:

```bash
grep -rnE '\->setCategories\s*\(' module/     # must return nothing
```

### Undo

`undoBatch()` reads the batch's log rows, inverts each action, and applies the inverse **as a new batch** with source `SOURCE_UNDO`. The original rows are then marked `undone = 1` so a batch cannot be reversed twice.

History stays a forward-only log. An undo is itself undoable.

A batch counts as fully undone only when every one of its rows is marked, which is why `listBatches()` compares `SUM(undone)` against `COUNT(*)` rather than trusting any single row.

---

## Tree traversal

`llx_categorie` has **no constraint preventing a category from becoming its own ancestor**, and Dolibarr's own tree walks would recurse forever on such a cycle. Every walk here is therefore guarded:

- `getCategoryTree()` builds the hierarchy in PHP from one flat SELECT, tracks a `seen` set, and caps depth at `MAX_TREE_DEPTH`. Nodes never reached from a root — i.e. nodes trapped in a cycle — are walked afterwards as roots so they stay visible rather than silently vanishing.
- `getDescendantIds()` descends breadth-first, one query per level, depth-capped.
- `getAncestorIds()` climbs with a `seen` set.

Iterative rather than recursive CTEs, so the module behaves identically on MySQL, MariaDB and PostgreSQL.

`moveCategory()` refuses any target inside the moved category's own subtree, and `mergeCategories()` refuses a target that is a descendant of the source. Those two checks are the only thing standing between a user and an unreachable branch.

---

## Rules

A rule compiles to a SQL predicate, so previewing over a large catalogue costs one query per rule rather than a scan in PHP.

**`MATCH_REGEX` is the exception** — there is no portable SQL regex across the three supported databases, so `ruleSqlPredicate()` returns `null` for it and matching happens in PHP. Because the filter is applied *after* the query, the regex path over-fetches (`limit * 20`) so the configured cap still yields a meaningful number of rows.

Preview and apply share `previewRule()`. That is deliberate: if they used separate queries they could drift, and a preview that does not predict the apply is worse than no preview. Both exclude products already in the target category, so a preview shows only real changes and a second run of a rule set correctly reports nothing to do.

A product matched by several rules produces several `(product, category)` pairs. `previewRuleSet()` counts **distinct pairs**, because that is what actually gets written.

---

## Permissions

Five separate rights, only `read` on by default:

| Right | Guards |
|---|---|
| `read` | dashboard, worklist, history |
| `assign` | add/remove memberships |
| `rules` | rule set CRUD and application |
| `tree` | rename, move, merge, delete |
| `undo` | reversing a batch |

`undo` is separate from `assign` on purpose: reversing a colleague's bulk operation is a different level of trust from tagging a product.

Every mutating endpoint additionally requires the **native** `produit`/`service` create right (`dolicurateAjaxGuard()`), so the module can never let someone edit products they could not otherwise edit.

---

## Class dependencies

Each class must `dol_include_once()` everything it references. This bit once: `DoliCurateCurator` used `DoliCurateCatalog::CATEGORY_TYPE_PRODUCT` without including that class. CLI tests passed because the test harness loaded both classes, while `ajax/assign.php` loads only the curator — so it fatalled in the browser and nowhere else.

When adding a cross-class reference, audit it:

```bash
for f in module/class/*.php module/ajax/*.php; do
  echo "$f"
  grep -oE 'DoliCurate(Catalog|Curator|Rules|Tree)' "$f" | sort -u | sed 's/^/  refs /'
  grep -oE "dol_include_once\('/dolicurate/class/[a-z]+\.class\.php'\)" "$f" | sed 's/^/  inc  /'
done
```

**A test harness that loads more than production does will hide this class of bug.** Exercise the real HTTP endpoints, not just the classes.

---

## Front end

Plain DOM APIs, no framework, so the module does not fight whichever jQuery the host theme ships. `dolicurate-core.js` holds shared helpers and is emitted by `dolicurateConfigBlock()`, which every screen already calls — so a screen only ever adds its own file.

Config, including the CSRF token, travels in a `<script type="application/json">` block rather than inline JS, so nothing needs escaping into a script context.

`D.post()` always attaches the token. Any new endpoint gets CSRF for free by going through it.

---

## Things that will bite you

- **Disabling the module deletes its settings.** `_remove()` drops the constants declared in `$this->const`. The audit tables survive.
- **`$conf` is stale after `activateModule()` in the same process.** Call `$conf->setValues($db)` before reading new constants.
- **Category `visible` is vestigial.** `Categorie::create()` writes it from an uninitialised property, so UI-created categories are always `0`, and no core query filters on it. Never filter on it — see the Doli Catalog bug of the same shape.
- **`fetch_array()` returns numeric *and* named keys.** Filter to named keys before printing rows, or every column appears twice.
- **The module stylesheet is not cache-busted, by design.** Dolibarr appends a
  version/theme parameter to CSS registered in `module_parts` only when the path
  does *not* end in `.css` — core's comment explains that some web server setups
  return the wrong content type for `style.css?param`, which defeats caching
  entirely. Following that convention means a CSS change needs a hard refresh
  after an upgrade. Do not add a query string to the registered path to work
  around it; a stale stylesheet is a smaller failure than CSS that stops being
  served as CSS.
- **The theme sets `select { overflow: hidden }`.** Harmless for dropdowns,
  breaks `<select multiple>` — the list silently clips with no scrollbar. Any
  new multi-select needs `overflow-y: auto`.
- **A form input named `action` shadows `form.action`** in JavaScript.
