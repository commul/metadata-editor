# LC-meta Minimal Package

This package is a minimal proof of concept for importing the administrative and learner metadata blocks of LC-meta into the metadata editor without using the UI.

## What Was Added

The proof of concept consists of three parts:

1. A structured LC-meta package in this directory:
   - `manifest.json` defines the administrative and learner LC-meta fields, page grouping, and search/listing mappings
   - `sample-metadata.json` provides a valid fixture record
2. A local CLI import path:
   - `application/controllers/cli/Schema_import.php`
   - `application/libraries/Schema_package_importer.php`
   - `application/libraries/Structured_schema_manifest_builder.php`
   - `application/libraries/Structured_template_manifest_builder.php`
3. A manifest-defined default template:
   - the stock `Schema_template_generator.php` remains unchanged
   - the package importer builds `lc-meta-minimal__core` directly from the manifest so section grouping is reproducible outside the UI
   - the manifest can now define either a single page (`fields`) or multiple pages (`pages`) inside one schema section

## Import

Run the importer inside the application container:

```bash
docker compose exec app php index.php cli/schema_import import lc-meta-minimal
```

The command will:

1. Read [manifest.json](manifest.json)
2. Build a JSON Schema from the structured manifest
3. Store the schema under `datafiles/editor/user-schemas/lc-meta-minimal/`
4. Register the schema in `metadata_schemas`
5. Generate and set the default template `lc-meta-minimal__core`

## UI Grouping

The administrative LC-meta block is rendered as a single editor page:

- `section_container`: `administrative_metadata`
- one child `section`: `Administrative metadata`
- 23 LC-meta fields inside that section

This avoids the stock generator behavior where flat fields appear as separate pages in the tree.

The learner block is rendered as a grouped section:

- `section_container`: `learner_metadata`
- seven child `section` pages: learner record, socio-demographic differences, target language, Ln differences, other languages, other individual differences, notes
- 52 learner fields across those pages

This keeps the `Learner` workbook tab readable without splitting it into unrelated top-level schema objects.

## Current Modeling Choice

The learner metadata is currently represented as a flat corpus-level block. The optional LC-meta `3.4 Other languages` bundle is stored as parallel repeatable fields rather than nested learner-language objects.

This is sufficient for a practicability test in the current metadata-editor, but it is not yet a full relational representation of per-learner and per-language records.

## Search And Listing Mappings

The package maps LC-meta fields into the editor's searchable core fields through `metadata_options.core_fields`:

- `idno` -> `corpus_pid`
- `title` -> first `corpus_name`
- `year_start` / `year_end` -> `corpus_date_of_publication`
- `attributes` -> availability, licence, version

## What This Tests

- LC-meta can be represented as a custom schema without editing PHP views by hand.
- New schema packages can be added by committing a structured JSON manifest.
- Search/listing mappings work programmatically from a structured file, not from manual UI template editing.
- UI grouping can also be defined programmatically from the same manifest, while leaving the existing schema upload path untouched.
- Multi-page sections make it possible to preserve one workbook tab as one schema section while still exposing smaller editor pages.

## Validation Performed

- The new PHP files were linted inside the app container.
- The manifest was compiled into a JSON Schema inside the container.
- The generated schema contains 23 administrative fields and 52 learner fields.
- The generated schema now encodes conditional-required rules with validator-supported `allOf` plus `anyOf` plus `not`, instead of draft-07 `if` and `then`.
- Dependent detail fields are constrained to non-empty values when they become required.
- `sample-metadata.json` validates successfully against the generated schema with the app's bundled `JsonSchema\Validator`.
- `php index.php cli/migrate latest` completed successfully in the app container.
- `php index.php cli/schema_import import lc-meta-minimal` completed successfully and updated template `lc-meta-minimal__core`.

## Fixture

[sample-metadata.json](sample-metadata.json) is a sample LC-meta record matching the generated schema.
