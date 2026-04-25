# LC-meta Minimal Package

This package is a minimal proof of concept for importing the administrative metadata block of LC-meta into the metadata editor without using the UI.

## What Was Added

The proof of concept consists of three parts:

1. A structured LC-meta package in this directory:
   - `manifest.json` defines the administrative LC-meta fields and search/listing mappings
   - `sample-metadata.json` provides a valid fixture record
2. A local CLI import path:
   - `application/controllers/cli/Schema_import.php`
   - `application/libraries/Schema_package_importer.php`
   - `application/libraries/Structured_schema_manifest_builder.php`
3. Generator support for schema-driven templates:
   - `application/libraries/Schema_template_generator.php` now emits usable UI hints for required fields, dropdowns, dates, and repeatable primitive fields

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

## Validation Performed

- The new PHP files were linted inside the app container.
- The manifest was compiled into a JSON Schema inside the container.
- The generated schema contains 23 LC-meta administrative properties.
- `sample-metadata.json` validates successfully against the generated schema.

## Current Blocker

The importer is ready, but the local database used during validation does not yet contain the schema-registry table `metadata_schemas`. Until the database is migrated, the import command will stop with:

```text
Missing required database tables: metadata_schemas. Run `php index.php cli/migrate latest` before importing schema packages.
```

## Fixture

[sample-metadata.json](sample-metadata.json) is a sample LC-meta record matching the generated schema.
