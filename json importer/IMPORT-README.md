# PatientsCure JSON Importer

Create or update **one** Disease, Remedy or Ingredient from a JSON file, including nested ACF groups, repeaters, relationships, taxonomies and Yoast SEO.

The importer never hardcodes the ACF structure. It reads the field definitions that `patientscure-setup.php` registers, at runtime, and validates your JSON against them. If you add or rename a field in the setup plugin, the importer follows automatically. `php pcure-import.php --schema` prints exactly what it sees.

## 1. Folder structure

```
C:\laragon\www\pcure\                 <- WordPress root (next to wp-load.php)
├── pcure-import.php                  <- the importer (new)
├── IMPORT-README.md                  <- this file (new)
└── import-data\                      <- (new)
    ├── amlapitta.json                <- your real content goes here
    └── examples\
        ├── disease-example.json
        ├── remedy-example.json
        └── ingredient-example.json
```

Nothing else is touched. `patientscure-setup.php`, the theme, the Next.js app and all existing content are left alone.

## 2. Commands

```
php pcure-import.php import-data/amlapitta.json --dry-run    # validate + report, writes NOTHING
php pcure-import.php import-data/amlapitta.json              # real import
php pcure-import.php --schema                                # print the live ACF structure
php pcure-import.php --schema disease                        # ...for one type
```

| Option | Meaning |
|---|---|
| `--dry-run` | Validate and report only. No post, ACF, term or SEO write. |
| `--strict` | Any unresolved relationship or taxonomy term becomes a fatal error (nothing written). |
| `--create-terms` | Allow creating taxonomy terms that don't exist. Same as `"options": {"create_missing_terms": true}` in the JSON. |
| `--user=<id\|login>` | Run as this WordPress user (sets the author of new posts). |
| `--ascii` | Use `[OK]` `[!]` `[X]` instead of ✓ ⚠ ✗ if your console shows garbled characters. |

Exit codes: `0` success (warnings allowed) · `1` validation error, nothing written · `2` a write or verification problem.

Always run `--dry-run` first.

## 3. JSON format

Top-level keys are one of: a **reserved key**, an **ACF field name**, or a **taxonomy slug**. Anything else is an error (with a "did you mean" hint). Keys starting with `_` or `$` are ignored, so you can leave `_comment` notes.

| Reserved key | Required | Meaning |
|---|---|---|
| `type` | yes | `disease`, `remedy` or `ingredient` |
| `title` | yes | Post title |
| `slug` | yes | Post slug. Lowercase letters, numbers, hyphens. This is how existing posts are matched. |
| `status` | no | `publish`, `draft`, `pending`, `private`. New posts default to `publish`. On update, unchanged unless given. |
| `content` | no | The WordPress editor body (`post_content`). |
| `seo` | no | `{ "title": "...", "description": "..." }` → Yoast title / meta description |
| `options` | no | `{ "create_missing_terms": true }` |

The setup file marks **no ACF field as required**, so only `type`, `title` and `slug` are required by the importer.

Use the real ACF **field names** and the real nesting. Repeaters are arrays of row objects, never plain strings:

```json
"diet_and_lifestyle": {
  "pathya":         [ { "item": "..." } ],
  "apathya":        [ { "item": "..." } ],
  "lifestyle_tips": [ { "tip": "..." } ],
  "yoga_pranayama": [ { "practice": "..." } ]
}
```

Taxonomies use the real taxonomy slugs: `dosha`, `disease_cat`, `ingredient_cat`. (Not `disease_categories`: that is not what the setup file registers.)

```json
"dosha": ["Pitta"],
"disease_cat": ["Digestive"]
```

### Field reference (generated from patientscure-setup.php)

#### disease

Taxonomies: `dosha`, `disease_cat`

| JSON key | ACF type | JSON value |
|---|---|---|
| `sanskrit_name` | text | string |
| `summary` | textarea | string |
| `reading_time` | text | string |
| `updated_at` | text | string |
| `overview` | wysiwyg | string (HTML) |
| `featured` | true_false | true / false |
| `reviewed_by` | relationship | slug string → author |
| `home_remedies` | relationship | array of remedy slugs |
| `key_ingredients` | relationship | array of ingredient slugs |
| `ayurvedic_perspective` | group | object |
| &nbsp;&nbsp;&nbsp;&nbsp;`samprapti` | textarea | string |
| &nbsp;&nbsp;&nbsp;&nbsp;`dosha_imbalance` | text | string |
| &nbsp;&nbsp;&nbsp;&nbsp;`nidana` | repeater | array of row objects |
| &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`cause` | text | string |
| &nbsp;&nbsp;&nbsp;&nbsp;`dhatus_affected` | repeater | array of row objects |
| &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`dhatu` | text | string |
| `symptoms` | group | object |
| &nbsp;&nbsp;&nbsp;&nbsp;`classical` | repeater | array of row objects |
| &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`symptom` | text | string |
| &nbsp;&nbsp;&nbsp;&nbsp;`modern` | repeater | array of row objects |
| &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`symptom` | text | string |
| &nbsp;&nbsp;&nbsp;&nbsp;`warning_signs` | repeater | array of row objects |
| &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`warning` | text | string |
| `causes` | repeater | array of row objects |
| &nbsp;&nbsp;&nbsp;&nbsp;`cause` | text | string |
| `diet_and_lifestyle` | group | object |
| &nbsp;&nbsp;&nbsp;&nbsp;`pathya` | repeater | array of row objects |
| &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`item` | text | string |
| &nbsp;&nbsp;&nbsp;&nbsp;`apathya` | repeater | array of row objects |
| &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`item` | text | string |
| &nbsp;&nbsp;&nbsp;&nbsp;`lifestyle_tips` | repeater | array of row objects |
| &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`tip` | text | string |
| &nbsp;&nbsp;&nbsp;&nbsp;`yoga_pranayama` | repeater | array of row objects |
| &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`practice` | text | string |
| `precautions` | repeater | array of row objects |
| &nbsp;&nbsp;&nbsp;&nbsp;`precaution` | text | string |
| `faqs` | repeater | array of row objects |
| &nbsp;&nbsp;&nbsp;&nbsp;`question` | text | string |
| &nbsp;&nbsp;&nbsp;&nbsp;`answer` | textarea | string |
| `references` | repeater | array of row objects |
| &nbsp;&nbsp;&nbsp;&nbsp;`title` | text | string |
| &nbsp;&nbsp;&nbsp;&nbsp;`source` | text | string |
| &nbsp;&nbsp;&nbsp;&nbsp;`year` | text | string |

#### remedy

Taxonomies: `dosha`

| JSON key | ACF type | JSON value |
|---|---|---|
| `hindi_name` | text | string |
| `purpose` | textarea | string |
| `target_condition` | text | string |
| `difficulty` | select | one of: Very Easy / Easy / Moderate |
| `prep_time` | text | string |
| `dosage` | text | string |
| `timing` | text | string |
| `frequency` | text | string |
| `anupana` | text | string |
| `duration` | text | string |
| `traditional_context` | textarea | string |
| `featured` | true_false | true / false |
| `ingredients_list` | repeater | array of row objects |
| &nbsp;&nbsp;&nbsp;&nbsp;`ingredient` | relationship | slug string → ingredient |
| &nbsp;&nbsp;&nbsp;&nbsp;`quantity` | text | string |
| &nbsp;&nbsp;&nbsp;&nbsp;`notes` | text | string |
| `preparation` | repeater | array of row objects |
| &nbsp;&nbsp;&nbsp;&nbsp;`step` | text | string |
| `precautions` | repeater | array of row objects |
| &nbsp;&nbsp;&nbsp;&nbsp;`precaution` | text | string |
| `who_should_avoid` | repeater | array of row objects |
| &nbsp;&nbsp;&nbsp;&nbsp;`warning` | text | string |
| `tags` | repeater | array of row objects |
| &nbsp;&nbsp;&nbsp;&nbsp;`tag` | text | string |
| `verified_by` | relationship | slug string → author |
| `related_disease` | relationship | slug string → disease |

#### ingredient

Taxonomies: `ingredient_cat`

| JSON key | ACF type | JSON value |
|---|---|---|
| `botanical_name` | text | string |
| `sanskrit_name` | text | string |
| `hindi_name` | text | string |
| `short_description` | textarea | string |
| `full_description` | wysiwyg | string (HTML) |
| `recommended_dosage` | group | object |
| &nbsp;&nbsp;&nbsp;&nbsp;`churna` | text | string |
| &nbsp;&nbsp;&nbsp;&nbsp;`decoction` | text | string |
| &nbsp;&nbsp;&nbsp;&nbsp;`extract` | text | string |
| `ayurvedic_properties` | group | object |
| &nbsp;&nbsp;&nbsp;&nbsp;`rasa` | repeater | array of row objects |
| &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`value` | text | string |
| &nbsp;&nbsp;&nbsp;&nbsp;`guna` | repeater | array of row objects |
| &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;`value` | text | string |
| &nbsp;&nbsp;&nbsp;&nbsp;`virya` | text | string |
| &nbsp;&nbsp;&nbsp;&nbsp;`vipaka` | text | string |
| &nbsp;&nbsp;&nbsp;&nbsp;`dosha_effect` | textarea | string |
| `key_benefits` | repeater | array of row objects |
| &nbsp;&nbsp;&nbsp;&nbsp;`benefit` | text | string |
| `therapeutic_uses` | repeater | array of row objects |
| &nbsp;&nbsp;&nbsp;&nbsp;`use` | text | string |
| `safety_and_contraindications` | repeater | array of row objects |
| &nbsp;&nbsp;&nbsp;&nbsp;`safety` | text | string |
| `featured_remedies` | relationship | array of remedy slugs |
| `associated_diseases` | relationship | array of disease slugs |

`reading_time` and `updated_at` exist on Disease but are maintained by the `save_post_disease` hook in the setup file, so the disease example leaves them out. You may still supply them as plain strings; on an **update** the hook runs first and the importer's values are written after it.

## 4. How updates work

- A post is matched by **post type + slug**. Found → **UPDATE**. Not found → **CREATE**. Never a duplicate.
- The same slug in a different post type is not a match. It is never touched (you get a warning, in case `type` was a typo).
- More than one match for the same type + slug → error; the importer will not guess.
- **Only fields present in the JSON are written.** Fields you leave out are left exactly as they are (the report lists them).
- A field that is present **replaces** that field's stored value. For a repeater, the rows in the JSON become the rows in WordPress.
- Inside a repeater, every sub-field of every row is written (missing ones as empty), so a re-import can't leave stale cells from an older row.
- Inside a **group**, only the sub-fields you give are written; the others are untouched.
- **Empty arrays / objects are skipped with a warning.** The importer never clears existing rows, relationships or terms.
- New posts that should be `publish` are created as drafts, filled in completely, then published last, so nothing half-built goes live.
- Nothing is ever deleted: no posts, terms, meta, or files.

### Recommended import order

Relationships point at posts that must already exist, and Disease ↔ Remedy ↔ Ingredient reference each other. So:

1. Ingredients
2. Remedies
3. Diseases
4. Re-run any file that reported unresolved relationships (safe: it's an update).

## 5. Relationships

Give **slugs**; the importer stores post **IDs** in the ACF relationship field.

- Fields declared with `max_posts: 1` (`reviewed_by`, `verified_by`, `related_disease`, `ingredients_list[].ingredient`) take **one slug string**.
- The others take an **array of slugs**.
- Targets are looked up only among the post types the field allows (e.g. `home_remedies` → `remedy`).
- Not found → a `WARNING`, the rest of the post still imports, and the unresolved list is printed at the end. Nothing is stored for the missing target. With `--strict` this is a fatal error instead.
- If **no** target in a top-level relationship resolves, the field is skipped, not cleared.
- Inside a repeater row (`ingredients_list`), an unresolved ingredient leaves the row with an empty ingredient, plus a warning.
- Targets that exist but aren't published produce a warning (the link is still stored).

## 6. Errors

Everything is validated **before** any write. If anything fails, you get every error at once and nothing is written:

```
ERROR:
Invalid structure: symptoms.classical must be an array of row objects, e.g. [ { "symptom": "..." } ] but the JSON provides string.

ERROR:
Repeater rows must be objects such as { "item": "..." }; plain strings are not converted.

ERROR:
Required field missing (must be a non-empty string):
slug
```

After a real import the importer reads the data back from WordPress and checks it: ACF values, repeater row counts and relationship IDs in raw `postmeta` (numeric IDs, not slugs), taxonomy assignment and Yoast meta. Any mismatch is reported under `VERIFICATION`.

If a write fails midway there is no rollback (that would mean deleting). The report shows the post ID; fix the cause and re-run, which is an update.

## 7. Testing checklist (Laragon)

```
php -l pcure-import.php
php pcure-import.php --schema
php pcure-import.php import-data/examples/disease-example.json --dry-run
php pcure-import.php import-data/examples/ingredient-example.json
php pcure-import.php import-data/examples/remedy-example.json
php pcure-import.php import-data/examples/disease-example.json
```

The examples are `draft` placeholders. Delete the sample posts from the WordPress admin afterwards (the importer never deletes).

## 8. Notes

- `pcure-import.php` refuses to run outside the command line. Don't deploy it to a public server without removing it from the web root.
- Loading WordPress can itself write transients / cron options. That is WordPress, not the importer; no content is written in `--dry-run`.
- The seed terms in the setup file are created on `admin_init`, so on a fresh install open wp-admin once, or use `--create-terms`.
