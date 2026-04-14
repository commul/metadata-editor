<?php
/**
 * Indicator / timeseries DSD — feature-specific copy only.
 * Shared labels (Structure, Validation, errors, pagination, etc.) live in general_lang.php.
 */

$lang['populate_local_codelists_confirm'] = 'Refresh local codelists from data for all fields set to "Local" vocabulary?';
$lang['local_codelists_populated'] = 'Local codelists updated';
$lang['populate_local_codelists_failed'] = 'Failed to populate local codelists';
$lang['populate_local_codelists'] = 'Refresh Codelists';
$lang['sum_stats_refresh_confirm'] = 'Compute column statistics from data and save to each DSD field? This can take a moment on large datasets.';
$lang['sum_stats_refreshed'] = 'Column statistics updated';
$lang['sum_stats_refresh_failed'] = 'Failed to refresh column statistics';
$lang['sum_stats_some_missing_in_data'] = 'some fields not found in data';
$lang['sum_stats_refresh'] = 'Refresh Stats';
$lang['confirm_delete_column'] = 'Are you sure you want to delete this column?';
$lang['confirm_delete_columns'] = 'Are you sure you want to delete the selected columns?';
$lang['error_deleting_columns'] = 'Error deleting columns';
$lang['dsd_time_period_empty_hint'] = 'Add a column and set its type to Time period, or map time on import.';
$lang['dsd_time_freq_incomplete'] = 'Set time format & FREQ';
$lang['column_name_empty'] = 'Column name is empty';
$lang['resize_panels'] = 'Drag to resize panels';
$lang['validate'] = 'Run validation';
$lang['dsd_validation_tab_empty'] = 'Run validation to see structure and data checks.';
$lang['dsd_validation_structure_ok'] = 'Column roles and cardinality match the rules for this project.';
$lang['dsd_validation_section_data'] = 'Data validation';
$lang['dsd_validation_rows_with_value'] = 'Rows with value';
$lang['dsd_validation_unique_observations'] = 'Unique keys';
$lang['dsd_validation_rows_scanned'] = 'Counted';
$lang['dsd_validation_observation_key_truncated'] = 'Counts may be incomplete (scan truncated).';

$lang['dsd_name_reserved_underscore'] = 'Column names cannot start with underscore (_); reserved for system fields.';
$lang['sum_stats_unavailable'] = 'Statistics unavailable';
$lang['dsd_freq_column_intro'] = 'This field type marks the CSV column that contains SDMX FREQ codes (e.g. A, M, Q) per row. Pair it with a Time period column: you do not set a global time period format on the time row when this column exists.';
$lang['dsd_freq_code_reference'] = 'FREQ codes (reference from config)';
$lang['dsd_freq_codes_hint'] = 'Map CSV values to these SDMX FREQ codes.';
$lang['dsd_time_mode_freq_from_data'] = 'FREQ from data';
$lang['dsd_time_mode_freq_from_data_body'] = 'A FREQ column is defined in this DSD:';
$lang['dsd_time_mode_freq_from_data_tail'] = 'Frequency comes from that column; time period values are interpreted using platform rules per FREQ. You do not need a separate time period format here.';
$lang['time_period_format'] = 'Time period format';
$lang['dsd_time_format_required_help'] = 'Required when there is no FREQ column (e.g. YYYY, YYYY-MM).';
$lang['dsd_constant_series_freq'] = 'Series frequency (FREQ)';
$lang['dsd_constant_series_freq_help'] = 'Single SDMX FREQ code for the whole series (e.g. A, M, Q). Required when no FREQ column exists.';
$lang['value_label_column_not_in_dsd'] = 'not listed as attribute';
$lang['value_label_column_no_attributes'] = 'No Attribute columns in this DSD yet. Add one under Column type, or clear this to use none.';
$lang['dsd_vocabulary'] = 'Codelist type';
$lang['dsd_vocab_none'] = 'None';
$lang['dsd_vocab_local'] = 'Codelist';
$lang['dsd_vocab_global'] = 'Global standard codelist';
$lang['dsd_global_codelist_pick'] = 'Standard codelist';
$lang['global_codelist_preview_title'] = 'Registry codes';
$lang['global_codelist_preview_hint'] = 'Used for validation and charts. To add or change codes, use the site codelist registry.';
$lang['global_codelist_preview_truncated'] = 'Preview shows at most 500 rows. Use search to narrow, or open the codelist in the registry for the full list.';
$lang['global_codelist_preview_empty'] = 'No codes in this codelist.';
$lang['global_codelist_preview_load_failed'] = 'Could not load codes.';
$lang['global_codelist_preview_no_registry'] = 'Select a standard codelist above to preview codes.';
$lang['local_codelist'] = 'Codelist';
$lang['dsd_save_column_for_local_codelist'] = 'Save this column first to create and edit the local codelist.';
$lang['sum_stats_panel_title'] = 'Summary statistics';
$lang['sum_stats_no_freq'] = 'No frequency breakdown (no present values).';
$lang['sum_stats_none_hint'] = 'No profile data yet. Use "Refresh Stats" on the data structure list to compute statistics for this field.';

$lang['dimension'] = 'Dimension';
$lang['measure'] = 'Measure';
$lang['attribute'] = 'Attribute';
$lang['indicator_name'] = 'Indicator Name';
$lang['annotation'] = 'Annotation';
$lang['periodicity'] = 'Periodicity';

$lang['import_freq_column_same_as_time'] = 'Choose a different column for FREQ than for TIME_PERIOD.';
$lang['validation_indicator_column_required'] = 'Choose the indicator code column.';
$lang['validation_loading_indicator_values'] = 'Loading indicator values from staging.';
$lang['validation_series_to_import_required'] = 'Select a series (indicator code) to import.';
$lang['staging_replaces_note'] = 'Uploading a CSV replaces the current preview for this project.';
$lang['staging_resume_banner'] = 'Continuing a previous import. Map fields, pick the indicator value, then import. Upload a new CSV below to start a new preview.';
$lang['staging_no_disk_file'] = 'The uploaded file was not found on the server for MySQL sync. You can still promote to timeseries; to update the data-structure import, re-upload the same CSV (or any CSV to start a new preview).';
$lang['import_dsd_drift_hint'] = 'If you changed column names or types on the Data structure page, re-check mappings. Matching DSD rows are linked by ID when headers still align so re-import updates the same column.';
$lang['indicator_series_section_title'] = 'Indicator series in this file';
$lang['indicator_series_section_help'] = 'First map the indicator code column and pick one series, then map the time period column with format and FREQ, then map geography and observation value so we can load charts and published tables.';
$lang['indicator_step1_title'] = 'Which column has the indicator codes?';
$lang['indicator_step1_caption'] = 'Typical names: INDICATOR, INDICATOR_ID, REF_AREA is geography—use the column that distinguishes series, not the observation value.';
$lang['indicator_code_column_label'] = 'Indicator code column';
$lang['series_code_chip'] = 'Series code';
$lang['choose_column'] = 'Choose column…';
$lang['optional_label_column'] = 'Optional label column';
$lang['indicator_step2_title'] = 'Which series do you want to import?';
$lang['indicator_step2_caption'] = 'Each option shows how many preview rows use that code. Pick the series to import.';
$lang['select_series_code'] = 'Indicator code (series) in file';
$lang['no_distinct_values'] = 'No values found in this column';
$lang['indicator_series_sorted_hint'] = 'Sorted by row count (highest first), then code.';
$lang['distinct_list_truncated'] = 'List may be truncated. If your code is missing, narrow the CSV or raise the limit on the server.';
$lang['indicator_step2_prereq'] = 'Choose the indicator code column in step 1 first—we will list the values found in the preview.';
$lang['import_step_time_period_title'] = 'Time period';
$lang['import_step_time_period_caption_v2'] = 'Map the TIME_PERIOD column. If your file has a separate column with SDMX FREQ codes per row, map it below; otherwise set one time format and one constant FREQ for this series.';
$lang['time_period_column'] = 'Time period column';
$lang['import_freq_column_optional'] = 'FREQ column (optional, from data)';
$lang['import_freq_column_placeholder'] = 'None — use constant FREQ';
$lang['freq_code'] = 'Constant FREQ (SDMX)';
$lang['import_time_when_freq_column'] = 'FREQ comes from the mapped column; you do not set time period format or constant FREQ for this step.';
$lang['import_time_period_format_freq_required'] = 'Both format and constant FREQ are required when there is no FREQ column.';
$lang['other_required_dimensions'] = 'Geography and observation value';
$lang['other_required_dimensions_caption_v2'] = 'Geography and observation value are required for import. Optional label column applies to geography only.';
$lang['project_idno_metadata_title'] = 'Project IDNO (metadata)';
$lang['project_idno_metadata_help'] = 'Study or project identifier from metadata. The series you import is chosen in step 2 above; this field is only for labeling or defaults when needed.';
$lang['staging_no_sample_rows'] = 'No sample rows loaded yet, or the preview is empty.';
$lang['total_in_staging'] = 'Total rows';
$lang['staging_sample_banner'] = 'Sample rows';
$lang['rows_shown'] = 'rows shown';
$lang['rows_total'] = 'rows total';
$lang['series_code_column_hint'] = 'This column should contain one indicator code per row.';

$lang['select_at_least_one_dimension_filter'] = 'Select at least one value in geography or another dimension filter.';
$lang['viz_facets_core_sdmx'] = 'Core';
$lang['geography_codes_combobox'] = 'Enter geography codes (no codelist on DSD)';
$lang['select_geography'] = 'Select geography';
$lang['field_freq'] = 'Periodicity';
$lang['select_freq_codes'] = 'Select frequency codes';
$lang['viz_facets_dimensions_and_measures'] = 'Dimensions';
$lang['dimension_codes_combobox'] = 'Type or paste codes (no codelist on DSD)';
$lang['select_codes'] = 'Select codes';
$lang['viz_facets_attributes'] = 'Attributes';
$lang['viz_facets_annotations'] = 'Annotations';
$lang['viz_facet_hint'] = 'Select filters, then Apply.';
$lang['select_dimension_filters_to_view_chart'] = 'Select at least one geography or dimension filter to view the chart';
$lang['apply_filters_or_import_data'] = 'Apply filters or import data';

$lang['local_codelist_add_row'] = 'Add code';
$lang['collapse_add_row'] = 'Collapse add row';
$lang['expand_add_row'] = 'Expand add row';
$lang['sort_by_code'] = 'Sort by code';
$lang['sort_by_label'] = 'Sort by label';
$lang['local_codelist_empty'] = 'No items yet. Add codes above or populate from data on the DSD list.';
$lang['confirm_delete_codelist_item'] = 'Delete this code from the local codelist?';
$lang['code_required'] = 'Code is required';

$lang['dsd_role_ts_year'] = 'Computed · period year';
$lang['dsd_role_ts_freq'] = 'Computed · SDMX FREQ';
$lang['dsd_role_unknown'] = 'Not in DSD';
$lang['dsd_role_indicator'] = 'Dimension · indicator';
$lang['dsd_role_geography'] = 'Dimension · geography';
$lang['dsd_role_time'] = 'Dimension · time';
$lang['dsd_role_measure'] = 'Measure · observation';
$lang['dsd_role_attribute'] = 'Attribute';
$lang['dsd_editor_type_label'] = 'DSD type';
$lang['duckdb_timeseries_empty'] = 'No published timeseries table in DuckDB yet. Import and promote data first.';
$lang['no_data_file'] = 'No data file found.';
$lang['preview_column_options'] = 'Preview';
$lang['unassigned_columns_step_title'] = 'Unassigned columns';
$lang['unassigned_columns_none'] = 'All selected columns have a type assigned. Nothing to review here.';
$lang['remaining'] = 'remaining';
$lang['all_assigned'] = 'all assigned';

$lang['dsd_attachment_level'] = 'Applies at';
$lang['dsd_attachment_level_hint'] = 'Observation = once per data row; Series = once per series; DataSet = once for the whole file. Defaults to Observation if not set.';
$lang['dsd_attachment_observation'] = 'Observation';
$lang['dsd_attachment_series'] = 'Series';
$lang['dsd_attachment_dataset'] = 'DataSet';
$lang['dsd_assignment_status'] = 'Value presence';
$lang['dsd_assignment_status_hint'] = 'Mandatory = a value must always be present in exported data; Conditional = value can be absent. Defaults to Conditional if not set.';
$lang['dsd_assignment_mandatory'] = 'Mandatory';
$lang['dsd_assignment_conditional'] = 'Conditional';

$lang['delete_data'] = 'Delete data';
$lang['delete_data_title'] = 'Delete data?';
$lang['delete_data_confirm'] = 'All data will be removed.';

// Generic UI labels not covered by general_lang
$lang['continue'] = 'Continue';
$lang['reset'] = 'Reset';
$lang['of'] = 'of';
$lang['rows'] = 'rows';
$lang['columns'] = 'columns';
$lang['fields'] = 'fields';
$lang['role'] = 'Role';
$lang['mapping'] = 'Mapping';
$lang['field_label'] = 'Field label';
$lang['status'] = 'Status';
$lang['source'] = 'Source';
$lang['value'] = 'Value';
$lang['errors'] = 'Errors';
$lang['warnings'] = 'Warnings';
$lang['skipped'] = 'Skipped';
$lang['structure'] = 'Structure';
$lang['validation'] = 'Validation';
$lang['data_errors'] = 'Data errors';
$lang['data_warnings'] = 'Data warnings';
$lang['validation_passed'] = 'Passed';
$lang['validation_failed'] = 'Failed';
$lang['could_not_save'] = 'Could not save';
$lang['request_failed'] = 'Request failed.';
$lang['optional_lowercase'] = 'optional';
$lang['type_to_search'] = 'Type to search...';

// Import CSV page
$lang['import_csv_data_structure'] = 'Import CSV - Data Structure';
$lang['import_csv_description'] = 'Upload a CSV file to create data structure columns';
$lang['select_csv_file'] = 'Select CSV file';
$lang['confirm_unsaved_changes'] = 'You have unsaved changes. Are you sure you want to leave this page?';
$lang['upload_another'] = 'Upload another file';
$lang['geography'] = 'Geography';
$lang['observation_value'] = 'Observation value';
$lang['indicator_idno'] = 'Project IDNO';
$lang['some_columns_exist'] = 'Some columns already exist in the data structure';
$lang['overwrite_existing_columns'] = 'Overwrite existing columns';
$lang['skip_existing_columns'] = 'Skip existing columns';
$lang['other_columns_step_title'] = 'Other columns';
$lang['unassigned'] = 'unassigned';
$lang['other_columns_caption'] = 'Columns not covered by the steps above. Assign a type to each unassigned column before importing.';
$lang['other_columns_none'] = 'No additional columns to classify.';

// Import workflow selection (step 1 when a DSD already exists)
$lang['workflow_data_only_label'] = 'Import data';
$lang['workflow_data_only_hint'] = 'Use the existing structure. Extra columns in the CSV are added as attributes.';
$lang['workflow_replace_label'] = 'Replace data and structure';
$lang['workflow_replace_hint'] = 'Delete the existing structure and all data, then define everything from the new CSV.';

// Import workflow — step 2 breadcrumb
$lang['change'] = 'Change';

// Import workflow — Workflow 2 (data only) step 2
$lang['preflight_checking'] = 'Checking CSV columns against structure…';
$lang['preflight_required_missing'] = 'Required structure columns missing from CSV:';
$lang['preflight_new_attributes'] = 'New columns will be added to the structure as attributes:';
$lang['preflight_missing_dsd'] = 'Structure columns not in CSV (no data for these):';
$lang['preflight_ok'] = 'All required structure columns found in CSV.';
$lang['select_indicator_value'] = 'Select the indicator to import';
$lang['choose_indicator_value'] = 'Choose indicator value…';
$lang['loading_indicator_values'] = 'Loading indicator values from CSV…';
$lang['no_indicator_values'] = 'No indicator values found in CSV';
$lang['import_data'] = 'Import Data';
$lang['data_preview'] = 'Preview';

// SDMX import tab
$lang['select_sdmx_xml_file'] = 'Select SDMX XML file';
$lang['sdmx_registry_url'] = 'SDMX Registry URL';
