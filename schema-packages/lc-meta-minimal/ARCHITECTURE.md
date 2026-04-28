# Metadata Editor Architecture Notes

This note records the main conclusion from the LC-meta proof of concept.

## Why A Schema-First Expectation Is Reasonable

The expectation is sound. In many systems, a JSON Schema is the main source of truth for field structure, required values, and constraints, with the UI generated directly from it or kept very close to it.

The metadata-editor only works like that in part.

## The Three Layers In This App

The current app uses three separate layers:

1. `JSON Schema`
   - Defines metadata structure and part of backend validation.
   - Stored under `datafiles/editor/user-schemas/...`.
2. `metadata_options.core_fields`
   - Maps schema fields to search and listing fields such as title, identifier, and years.
   - Stored with the schema record in `metadata_schemas`.
3. `Editor template JSON`
   - Defines page grouping, field order, labels, and display hints used by the metadata editor UI.
   - Stored in `editor_templates`.

Because of this split, a schema is only one layer of the runtime behavior.

## What The App Actually Does

When a schema is added through the existing API or the CLI package importer, the app stores the schema and then generates or assigns a default template. The editor loads that template and renders template items, not the compiled schema directly.

Search and project listings also do not come from the schema alone. They depend on `metadata_options.core_fields`.

## Practical Implications

- A custom schema can be integrated without editing the Vue views by hand.
- But the app is not schema-first end to end.
- Some JSON Schema features do not automatically become UI behavior.
- UI visibility rules are not schema-driven in the current editor.
- Validation behavior depends on the bundled validator, not just on what is legal JSON Schema on paper.

For this repository, that last point matters: the bundled `justinrainbow/json-schema` validator supports older constructs such as `allOf`, `anyOf`, `not`, `dependencies`, and `const`, but not the draft-07 `if` and `then` keywords in practice.

## Working Rule For LC-meta

For LC-meta package work in this repository, the safest approach is:

- Treat the manifest package as the maintained source of truth.
- Encode backend constraints only with the validator-supported JSON Schema subset.
- Encode editor grouping and page layout in the template layer.
- Encode search/listing mappings in `metadata_options.core_fields`.

This keeps most schema work detached from the UI code, but not completely independent from the application codebase. Changes to the template renderer, template generator, or validator library could still require package-level adjustments.
