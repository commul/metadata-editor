// Indicator DSD CSV Import Component
Vue.component('indicator-dsd-import', {
    data() {
        return {
            dataset_id: project_sid,
            dataset_idno: project_idno,
            dataset_type: project_type,
            file: null,
            csvData: null,
            csvColumns: [],
            columnMappings: [],
            existingColumns: [],
            loading: false,
            errors: [],
            warnings: [],
            step: 1, // 1: upload, 2: preview (simplified - no stepper)
            overwriteExisting: false,
            skipExisting: false,
            previewRows: 10,
            isProcessing: false,
            importStatus: '',
            importProgress: 0,
            indicatorIdValidation: null, // { valid: bool, error: string }
            editableStudyIdno: '', // Editable study IDNO
            csvPreviewView: 'data', // 'data' = table with rows, 'column' = vertical list of fields for mapping
            // CSV column name to use as value label for each required field (for value_labels generation)
            requiredFieldLabelColumns: { indicator_id: '', geography: '', time_period: '', observation_value: '' }
        }
    },
    created: async function() {
        await this.loadExistingColumns();
        // Initialize editable study IDNO
        this.editableStudyIdno = this.StudyIDNO || '';
    },
        watch: {
        columnMappings: {
            deep: true,
            handler() {
                // Auto-validate when column mappings change (only on step 2 - preview)
                if (this.step === 2) {
                    this.$nextTick(() => {
                        this.validateIndicatorId();
                    });
                }
            }
        },
        editableStudyIdno() {
            // Auto-validate when study IDNO changes
            if (this.step === 2) {
                this.$nextTick(() => {
                    this.validateIndicatorId();
                });
            }
        },
        step(newStep) {
            // Validate when entering step 2 (preview)
            if (newStep === 2) {
                // Initialize editable study IDNO if not set
                if (!this.editableStudyIdno) {
                    this.editableStudyIdno = this.StudyIDNO || '';
                }
                this.$nextTick(() => {
                    this.validateIndicatorId();
                });
            }
        }
    },
    methods: {
        loadExistingColumns: async function() {
            this.loading = true;
            const vm = this;
            let url = CI.base_url + '/api/indicator_dsd/' + vm.dataset_id;

            try {
                let response = await axios.get(url);
                if (response.data && response.data.columns) {
                    vm.existingColumns = response.data.columns;
                }
            } catch (error) {
                console.log("Error loading existing columns", error);
            } finally {
                this.loading = false;
            }
        },
        handleFileUpload: function(event) {
            this.errors = [];
            this.warnings = [];
            this.file = event;
            
            if (!this.file) {
                return;
            }

            // Validate file type
            const fileName = this.file.name.toLowerCase();
            if (!fileName.endsWith('.csv')) {
                this.errors.push('Only CSV files are supported');
                this.file = null;
                return;
            }

            // Read CSV file
            this.readCSVFile(this.file);
        },
        readCSVFile: function(file) {
            const vm = this;
            const reader = new FileReader();
            
            reader.onload = function(e) {
                try {
                    const text = e.target.result;
                    vm.parseCSV(text);
                } catch (error) {
                    vm.errors.push('Error reading CSV file: ' + error.message);
                }
            };
            
            reader.onerror = function() {
                vm.errors.push('Error reading file');
            };
            
            reader.readAsText(file);
        },
        parseCSV: function(text) {
            this.errors = [];
            this.warnings = [];
            
            // Simple CSV parser (handles quoted fields)
            const lines = text.split(/\r?\n/).filter(line => line.trim() !== '');
            if (lines.length === 0) {
                this.errors.push('CSV file is empty');
                return;
            }

            // Parse header
            const headerLine = lines[0];
            const rawHeaders = this.parseCSVLine(headerLine);
            // Normalize column names: replace . and spaces with underscores, then ensure uniqueness
            const headers = this.normalizeCSVColumnNames(rawHeaders);

            // Validate column names
            const validationResult = this.validateColumnNames(headers);
            if (!validationResult.valid) {
                this.errors = validationResult.errors;
                return;
            }

            this.warnings = validationResult.warnings;

            // Parse data rows
            const dataRows = [];
            for (let i = 1; i < Math.min(lines.length, this.previewRows + 1); i++) {
                const values = this.parseCSVLine(lines[i]);
                if (values.length === headers.length) {
                    const row = {};
                    headers.forEach((header, index) => {
                        row[header] = values[index] || '';
                    });
                    dataRows.push(row);
                }
            }

            this.csvColumns = headers;
            this.csvData = {
                headers: headers,
                rows: dataRows,
                totalRows: lines.length - 1
            };

            // Initialize column mappings
            this.initializeColumnMappings();

            this.step = 2; // Move to preview step
        },
        parseCSVLine: function(line) {
            const values = [];
            let current = '';
            let inQuotes = false;
            
            for (let i = 0; i < line.length; i++) {
                const char = line[i];
                
                if (char === '"') {
                    if (inQuotes && line[i + 1] === '"') {
                        // Escaped quote
                        current += '"';
                        i++;
                    } else {
                        // Toggle quote state
                        inQuotes = !inQuotes;
                    }
                } else if (char === ',' && !inQuotes) {
                    values.push(current.trim());
                    current = '';
                } else {
                    current += char;
                }
            }
            
            values.push(current.trim());
            return values;
        },
        /** Convert . and spaces in column names to underscores; ensure unique names (append _2, _3 for duplicates). */
        normalizeCSVColumnNames: function(rawNames) {
            const normalized = rawNames.map((name) => {
                const n = String(name || '').trim().replace(/[\s.]+/g, '_').replace(/^_+|_+$/g, '');
                return n || 'column';
            });
            const seen = {};
            return normalized.map((name) => {
                let key = name.toUpperCase();
                let out = name;
                if (seen[key]) {
                    let suffix = 2;
                    do {
                        out = name + '_' + suffix;
                        key = out.toUpperCase();
                        suffix++;
                    } while (seen[key]);
                }
                seen[key] = true;
                return out;
            });
        },
        validateColumnNames: function(columnNames) {
            const errors = [];
            const warnings = [];
            const seen = {};
            const namePattern = /^[a-zA-Z0-9_]+$/;

            // Check for duplicates (after converting to uppercase)
            columnNames.forEach((name, index) => {
                const upperName = name.toUpperCase();
                if (seen[upperName]) {
                    errors.push(`Duplicate column name: "${name}" (case-insensitive)`);
                } else {
                    seen[upperName] = true;
                }
            });

            // Validate format and length
            columnNames.forEach((name) => {
                if (!namePattern.test(name)) {
                    errors.push(`Invalid column name: "${name}". Only alphanumeric characters and underscores are allowed.`);
                }
                if (name.length > 255) {
                    errors.push(`Column name "${name}" exceeds maximum length of 255 characters.`);
                }
            });

            // Check for existing columns (compare with uppercase)
            const existingNames = this.existingColumns.map(col => col.name.toUpperCase());
            columnNames.forEach((name) => {
                if (existingNames.includes(name.toUpperCase())) {
                    warnings.push(`Column "${name}" already exists in the data structure`);
                }
            });

            return {
                valid: errors.length === 0,
                errors: errors,
                warnings: warnings
            };
        },
        initializeColumnMappings: function() {
            // Auto-mapping rules for required dimensions
            const autoMappingRules = {
                'indicator': 'indicator_id',
                'indicator_id': 'indicator_id',
                'ref_area': 'geography',
                'obs_value': 'observation_value',
                'observation_value': 'observation_value',
                'time_period': 'time_period'
            };

            this.columnMappings = this.csvColumns.map((csvCol) => {
                // Convert to uppercase for SDMX compatibility
                const upperCol = csvCol.toUpperCase();
                const lowerCol = csvCol.toLowerCase();
                
                // Check if column already exists (compare uppercase)
                const existing = this.existingColumns.find(
                    col => col.name.toUpperCase() === upperCol
                );

                // Auto-map column type based on CSV column name
                let columnType = 'attribute'; // default
                if (autoMappingRules[lowerCol]) {
                    columnType = autoMappingRules[lowerCol];
                }

                return {
                    csvColumn: csvCol,
                    columnName: upperCol, // Uppercase name for SDMX
                    selected: true, // Default: all selected
                    existingColumn: existing ? existing : null,
                    columnType: columnType, // Auto-mapped or default
                    dataType: 'string', // default
                    label: csvCol,
                    description: ''
                };
            });
        },
        processImport: async function() {
            console.log('processImport called');
            
            if (!this.file || !this.csvData) {
                EventBus.$emit('onFail', 'No CSV file to import');
                return;
            }

            // Validate at least one column is selected
            const selectedColumns = this.columnMappings.filter(m => m.selected);
            console.log('Selected columns:', selectedColumns.length);
            if (selectedColumns.length === 0) {
                EventBus.$emit('onFail', 'Please select at least one column to import');
                return;
            }

            // Validate indicator_id before import
            const validation = this.validateIndicatorId();
            console.log('Indicator ID validation:', validation);
            if (!validation || !validation.valid) {
                EventBus.$emit('onFail', validation ? validation.error : 'Indicator ID validation failed');
                return;
            }

            this.step = 4; // Move to import progress step
            this.isProcessing = true;
            this.importStatus = 'Uploading CSV file...';
            this.importProgress = 10;

            const vm = this;
            const formData = new FormData();
            formData.append('file', this.file);
            // Only send selected columns
            const selectedMappings = this.columnMappings.filter(m => m.selected);
            console.log('Sending column mappings:', selectedMappings);
            formData.append('column_mappings', JSON.stringify(selectedMappings));
            formData.append('overwrite_existing', this.overwriteExisting ? '1' : '0');
            formData.append('skip_existing', this.skipExisting ? '1' : '0');
            formData.append('indicator_idno', (this.editableStudyIdno || this.StudyIDNO || '').trim());
            formData.append('required_field_label_columns', JSON.stringify(this.requiredFieldLabelColumns || {}));

            try {
                this.importStatus = 'Processing import...';
                this.importProgress = 30;

                let url = CI.base_url + '/api/indicator_dsd/import/' + vm.dataset_id;
                console.log('Import URL:', url);
                console.log('Dataset ID:', vm.dataset_id);
                
                let response = await axios.post(url, formData, {
                    headers: {
                        'Content-Type': 'multipart/form-data'
                    },
                    onUploadProgress: (progressEvent) => {
                        if (progressEvent.total) {
                            this.importProgress = 30 + Math.round((progressEvent.loaded * 50) / progressEvent.total);
                        }
                    }
                });

                console.log('Import response:', response.data);
                this.importProgress = 90;
                this.importStatus = 'Finalizing...';

                if (response.data) {
                    // Check if there are errors in the response
                    if (response.data.errors && response.data.errors.length > 0) {
                        // Import completed but with errors
                        this.errors = response.data.errors;
                        this.importStatus = 'Import completed with errors';
                        EventBus.$emit('onFail', 'CSV import completed with errors. Please check the errors below.');
                        this.step = 2; // Go back to preview to show errors
                    } else if (response.data.status === 'success') {
                        // Refresh project data so left-tree data preview gets the new file (no page refresh needed)
                        if (this.$store && this.$store.dispatch) {
                            this.$store.dispatch('loadDataFiles', { dataset_id: this.dataset_id });
                        }
                        // Successful import - when any field label was set, populate code_lists from CSV
                        const hasLabelColumns = Object.values(this.requiredFieldLabelColumns || {}).some(v => v && String(v).trim() !== '');
                        if (hasLabelColumns) {
                            this.importStatus = 'Populating code lists from CSV...';
                            try {
                                const popUrl = CI.base_url + '/api/indicator_dsd/populate_code_lists/' + this.dataset_id;
                                const popRes = await axios.post(popUrl);
                                const pop = popRes.data || {};
                                if (pop.updated !== undefined && pop.updated > 0) {
                                    this.importStatus = 'Import completed. Code lists populated.';
                                    const rowsMsg = response.data.rows_imported != null ? ` ${response.data.rows_imported} rows imported.` : '';
                                    const message = `CSV imported: ${response.data.created || 0} created, ${response.data.updated || 0} updated.${rowsMsg} Code lists populated for ${pop.updated} columns.`;
                                    EventBus.$emit('onSuccess', message);
                                } else {
                                    this.importStatus = 'Import completed successfully!';
                                    const rowsMsg = response.data.rows_imported != null ? ` ${response.data.rows_imported} rows imported.` : '';
                                    const message = `CSV imported successfully: ${response.data.created || 0} created, ${response.data.updated || 0} updated.${rowsMsg}`;
                                    EventBus.$emit('onSuccess', message);
                                }
                            } catch (popErr) {
                                this.importStatus = 'Import completed (code list populate had issues).';
                                const message = `CSV imported: ${response.data.created || 0} created, ${response.data.updated || 0} updated. Code list populate failed: ${(popErr.response && popErr.response.data && popErr.response.data.message) || popErr.message}`;
                                EventBus.$emit('onSuccess', message);
                            }
                        } else {
                            this.importStatus = 'Import completed successfully!';
                            const rowsMsg = response.data.rows_imported != null ? ` ${response.data.rows_imported} rows imported.` : '';
                            const message = `CSV imported successfully: ${response.data.created || 0} created, ${response.data.updated || 0} updated.${rowsMsg}`;
                            EventBus.$emit('onSuccess', message);
                        }
                        this.importProgress = 100;
                        setTimeout(() => {
                            this.$router.push('/indicator-dsd');
                        }, hasLabelColumns ? 2000 : 1500);
                    } else {
                        // Failed status
                        throw new Error(response.data.message || 'Import failed');
                    }
                } else {
                    throw new Error('Invalid response from server');
                }
            } catch (error) {
                console.error('Import error:', error);
                this.errors = [];
                if (error.response && error.response.data) {
                    if (error.response.data.message) {
                        this.errors.push(error.response.data.message);
                    }
                    if (error.response.data.errors && Array.isArray(error.response.data.errors)) {
                        this.errors = this.errors.concat(error.response.data.errors);
                    }
                } else {
                    this.errors.push(error.message || 'Failed to import CSV');
                }
                this.step = 2; // Go back to preview to show errors
                EventBus.$emit('onFail', 'CSV import failed: ' + (this.errors[0] || 'Unknown error'));
            } finally {
                this.isProcessing = false;
            }
        },
        reset: function() {
            this.file = null;
            this.csvData = null;
            this.csvColumns = [];
            this.columnMappings = [];
            this.errors = [];
            this.warnings = [];
            this.step = 1;
            this.importStatus = '';
            this.importProgress = 0;
            this.indicatorIdValidation = null;
            this.editableStudyIdno = this.StudyIDNO || '';
            this.csvPreviewView = 'data';
            this.requiredFieldLabelColumns = { indicator_id: '', geography: '', time_period: '', observation_value: '' };
        },
        cancel: function() {
            this.$router.push('/indicator-dsd');
        },
        toggleSelectAll: function() {
            const value = this.selectAll;
            this.columnMappings.forEach(m => {
                m.selected = value;
            });
        },
        setRequiredFieldMapping: function(fieldKey, csvColumn) {
            // Clear current mapping for this field type
            this.columnMappings.forEach(m => {
                if (m.columnType === fieldKey) m.columnType = 'attribute';
            });
            // Set new mapping if a column was selected
            if (csvColumn) {
                const m = this.columnMappings.find(x => x.csvColumn === csvColumn);
                if (m) {
                    m.columnType = fieldKey;
                    m.selected = true;
                }
            }
            this.$nextTick(() => this.validateIndicatorId());
        },
        setRequiredFieldLabelColumn: function(fieldKey, csvColumn) {
            this.$set(this.requiredFieldLabelColumns, fieldKey, csvColumn || '');
        },
        isRequiredFieldMapped: function(mapping) {
            if (!mapping || !mapping.selected) return false;
            const required = ['indicator_id', 'observation_value', 'geography', 'time_period'];
            return required.indexOf(mapping.columnType) !== -1;
        },
        validateIndicatorId: function() {
            // Reset validation
            this.indicatorIdValidation = null;
            
            // 1. Check if indicator_id column is mapped
            const indicatorIdMapping = this.columnMappings.find(
                m => m.selected && m.columnType === 'indicator_id'
            );
            
            if (!indicatorIdMapping) {
                this.indicatorIdValidation = {
                    valid: false,
                    error: 'Indicator ID column mapping is required'
                };
                return this.indicatorIdValidation;
            }
            
            // 2. Get StudyIDNO (use editable version)
            const studyIdno = this.editableStudyIdno || this.StudyIDNO;
            if (!studyIdno || String(studyIdno).trim() === '') {
                this.indicatorIdValidation = {
                    valid: false,
                    error: 'Indicator IDNO is not available'
                };
                return this.indicatorIdValidation;
            }
            
            // 3. Validate sample rows (first 10 or all if fewer)
            if (!this.csvData || !this.csvData.rows || this.csvData.rows.length === 0) {
                this.indicatorIdValidation = {
                    valid: false,
                    error: 'No CSV data available for validation'
                };
                return this.indicatorIdValidation;
            }
            
            const sampleRows = this.csvData.rows.slice(0, Math.min(10, this.csvData.rows.length));
            const csvColumn = indicatorIdMapping.csvColumn;
            
            for (let i = 0; i < sampleRows.length; i++) {
                const row = sampleRows[i];
                const indicatorId = row[csvColumn];
                
                // Check empty
                if (!indicatorId || String(indicatorId).trim() === '') {
                    this.indicatorIdValidation = {
                        valid: false,
                        error: `Indicator ID is empty in row ${i + 2}` // +2 because row 1 is header
                    };
                    return this.indicatorIdValidation;
                }
                
                // Check match (case-insensitive)
                const indicatorIdUpper = String(indicatorId).trim().toUpperCase();
                const studyIdnoUpper = String(studyIdno).trim().toUpperCase();
                
                if (indicatorIdUpper !== studyIdnoUpper) {
                    this.indicatorIdValidation = {
                        valid: false,
                        error: `Indicator ID '${indicatorId}' in row ${i + 2} does not match indicator IDNO '${studyIdno}'`
                    };
                    return this.indicatorIdValidation;
                }
            }
            
            // All validations passed
            this.indicatorIdValidation = { valid: true };
            return this.indicatorIdValidation;
        }
    },
    computed: {
        ProjectID() {
            return this.$store.state.project_id;
        },
        StudyIDNO() {
            // Get from series_description.idno in project metadata
            const seriesDescription = _.get(this.$store.state.formData, 'series_description');
            if (seriesDescription && seriesDescription.idno) {
                return seriesDescription.idno;
            }
            // Fallback to project_info.idno if not found in metadata
            return (this.$store.state.project_info && this.$store.state.project_info.idno) || '';
        },
        displayStudyIdno() {
            // Use editable version if set, otherwise use computed StudyIDNO
            return this.editableStudyIdno || this.StudyIDNO || '';
        },
        hasErrors() {
            return this.errors.length > 0;
        },
        hasWarnings() {
            return this.warnings.length > 0;
        },
        requiredFieldsStatus() {
            const requiredFields = [
                { key: 'indicator_id', label: 'Indicator ID' },
                { key: 'observation_value', label: 'Observation Value' },
                { key: 'geography', label: 'Geography' },
                { key: 'time_period', label: 'Time Period' }
            ];
            
            const status = {};
            const selectedMappings = this.columnMappings.filter(m => m.selected);
            
            requiredFields.forEach(field => {
                const mapping = selectedMappings.find(m => m.columnType === field.key);
                status[field.key] = {
                    label: field.label,
                    selected: !!mapping,
                    columnName: mapping ? mapping.csvColumn : null
                };
            });
            
            return status;
        },
        requiredFieldsList() {
            return [
                { key: 'indicator_idno', label: this.$t('indicator_idno') || 'Indicator IDNO', isIdno: true },
                { key: 'indicator_id', label: this.$t('indicator_id') || 'Indicator ID' },
                { key: 'geography', label: this.$t('geography') || 'Geography' },
                { key: 'time_period', label: this.$t('time_period') || 'Time Period' },
                { key: 'observation_value', label: this.$t('observation_value') || 'Observation Value' }
            ];
        },
        allRequiredFieldsSelected() {
            const status = this.requiredFieldsStatus;
            return status.indicator_id?.selected && 
                   status.observation_value?.selected && 
                   status.geography?.selected && 
                   status.time_period?.selected;
        },
        canImport() {
            if (this.step !== 2) return false;
            if (this.isProcessing) return false;
            if (!this.csvData) return false;
            const selected = this.columnMappings.filter(m => m.selected);
            if (selected.length === 0) return false;
            
            // Validate all selected columns have valid names
            const allColumnsValid = selected.every(m => {
                if (!m.columnName || m.columnName.trim() === '') return false;
                if (!/^[A-Z0-9_]+$/.test(m.columnName)) return false;
                if (m.columnName.length > 255) return false;
                return true;
            });
            
            if (!allColumnsValid) return false;
            
            // Validate all required fields are selected
            if (!this.allRequiredFieldsSelected) {
                return false;
            }
            
            // Validate indicator_id
            this.validateIndicatorId();
            if (this.indicatorIdValidation && !this.indicatorIdValidation.valid) {
                return false;
            }
            
            return true;
        },
        selectAll: {
            get() {
                return this.columnMappings.length > 0 && this.columnMappings.every(m => m.selected);
            },
            set(value) {
                this.columnMappings.forEach(m => {
                    m.selected = value;
                });
            }
        },
        allSelected() {
            return this.columnMappings.length > 0 && this.columnMappings.every(m => m.selected);
        },
        someSelected() {
            return this.columnMappings.some(m => m.selected) && !this.allSelected;
        }
    },
    template: `
        <div class="indicator-dsd-import-component" style="padding: 20px;">
            <v-card>
                <v-card-title class="d-flex justify-space-between align-center">
                    <div>
                        <h4>{{$t("import_csv_data_structure") || "Import CSV - Data Structure"}}</h4>
                        <small class="text-muted">{{$t("import_csv_description") || "Upload a CSV file to create data structure columns"}}</small>
                    </div>
                    <v-btn icon @click="cancel">
                        <v-icon>mdi-close</v-icon>
                    </v-btn>
                </v-card-title>

                <v-card-text>
                    <!-- Step 1: Upload (only shown when no file uploaded) -->
                    <div v-if="step === 1">
                        <v-file-input
                            v-model="file"
                            :label="$t('select_csv_file') || 'Select CSV File'"
                            accept=".csv"
                            outlined
                            dense
                            prepend-inner-icon="mdi-file-document"
                            @change="handleFileUpload"
                        ></v-file-input>

                        <v-alert v-if="hasErrors" type="error" class="mt-3">
                            <div v-for="error in errors" :key="error">{{error}}</div>
                        </v-alert>

                        <div class="mt-4">
                            <v-btn text @click="cancel">{{$t("cancel") || "Cancel"}}</v-btn>
                        </div>
                    </div>

                    <!-- Step 2: Preview and Configure (simplified single page) -->
                    <div v-if="step === 2 && csvData">
                        <!-- Required Fields: table with Field, Mapping, Field label, Status -->
                        <v-card class="mb-3" outlined>
                            <v-card-title class="pa-3" style="font-size: 16px; font-weight: bold;">
                                {{$t("required_fields") || "Required Fields"}}
                            </v-card-title>
                            <v-card-text class="pa-3 pt-0">
                                <v-simple-table dense class="required-fields-table">
                                    <thead>
                                        <tr>
                                            <th class="text-left" style="min-width: 120px; padding: 6px 8px;">{{$t("field") || "Field"}}</th>
                                            <th class="text-left" style="min-width: 160px; padding: 6px 8px;">{{$t("mapping") || "Mapping"}}</th>
                                            <th class="text-left" style="min-width: 160px; padding: 6px 8px;">{{$t("field_label") || "Field label"}}</th>
                                            <th class="text-left" style="width: 48px; padding: 6px 8px;">{{$t("status") || "Status"}}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="field in requiredFieldsList" :key="field.key" class="required-fields-table-row">
                                            <td class="font-weight-medium" style="padding: 10px 8px;">{{field.label}}</td>
                                            <td style="padding: 10px 8px;">
                                                <template v-if="field.isIdno">
                                                    <div class="d-flex align-center" style="gap: 6px;">
                                                        <v-text-field
                                                            v-model="editableStudyIdno"
                                                            hide-details
                                                            dense
                                                            outlined
                                                            style="max-width: 200px; font-size: 13px;"
                                                            @input="validateIndicatorId"
                                                        ></v-text-field>
                                                        <v-btn x-small text @click="editableStudyIdno = StudyIDNO || ''">{{$t("reset") || "Reset"}}</v-btn>
                                                    </div>
                                                </template>
                                                <template v-else>
                                                    <v-autocomplete
                                                        :value="requiredFieldsStatus[field.key] && requiredFieldsStatus[field.key].columnName"
                                                        @input="setRequiredFieldMapping(field.key, $event || null)"
                                                        :items="csvColumns"
                                                        hide-details
                                                        dense
                                                        outlined
                                                        clearable
                                                        :placeholder="$t('type_to_search') || 'Type to search...'"
                                                        style="max-width: 220px; font-size: 13px;"
                                                    ></v-autocomplete>
                                                </template>
                                            </td>
                                            <td style="padding: 10px 8px;">
                                                <template v-if="field.isIdno">
                                                    <span class="grey--text">—</span>
                                                </template>
                                                <template v-else>
                                                    <v-autocomplete
                                                        :value="requiredFieldLabelColumns[field.key]"
                                                        @input="setRequiredFieldLabelColumn(field.key, $event || '')"
                                                        :items="csvColumns"
                                                        hide-details
                                                        dense
                                                        outlined
                                                        clearable
                                                        :placeholder="$t('type_to_search') || 'Type to search...'"
                                                        style="max-width: 220px; font-size: 13px;"
                                                    ></v-autocomplete>
                                                </template>
                                            </td>
                                            <td style="vertical-align: middle; padding: 10px 8px;">
                                                <template v-if="field.isIdno">
                                                    <v-icon v-if="indicatorIdValidation && indicatorIdValidation.valid" color="success">mdi-check-circle</v-icon>
                                                    <v-icon v-else-if="indicatorIdValidation && !indicatorIdValidation.valid" color="error">mdi-alert-circle</v-icon>
                                                    <v-icon v-else color="grey lighten-1">mdi-circle-outline</v-icon>
                                                </template>
                                                <template v-else>
                                                    <v-icon v-if="requiredFieldsStatus[field.key] && requiredFieldsStatus[field.key].selected" color="success">mdi-check-circle</v-icon>
                                                    <v-icon v-else color="error">mdi-alert-circle</v-icon>
                                                </template>
                                            </td>
                                        </tr>
                                    </tbody>
                                </v-simple-table>
                            </v-card-text>
                        </v-card>

                        <v-alert v-if="hasErrors" type="error" class="mb-3">
                            <div v-for="error in errors" :key="error">{{error}}</div>
                        </v-alert>

                        <div class="d-flex align-center justify-space-between flex-wrap mb-3" style="gap: 12px;">                            
                            <!-- View switch: Data view / Column view -->
                            <div class="d-flex align-center" style="gap: 4px;">
                                <span class="mr-1">Switch view:</span>
                                <v-btn
                                    small
                                    icon
                                    :color="csvPreviewView === 'data' ? 'primary' : 'grey'"
                                    :text="csvPreviewView !== 'data'"
                                    :outlined="false"
                                    title="Data view"
                                    @click="csvPreviewView = 'data'"
                                >
                                    <v-icon>mdi-table</v-icon>
                                </v-btn>
                                <v-btn
                                    small
                                    icon
                                    :color="csvPreviewView === 'column' ? 'primary' : 'grey'"
                                    :text="csvPreviewView !== 'column'"
                                    :outlined="false"
                                    title="Columns view"
                                    @click="csvPreviewView = 'column'"
                                >
                                    <v-icon>mdi-view-list</v-icon>
                                </v-btn>
                            </div>
                        </div>

                        
                        <div v-if="csvPreviewView === 'data'">
                            <h5 class="mb-1">{{$t("data_preview")}}</h5>
                            <p class="text-muted mb-0">
                                {{csvData.totalRows}} {{$t("rows_found") || "rows found"}}
                                <template >
                                    ({{$t("showing_first") || "showing first"}} {{previewRows}})
                                </template>
                            </p>
                        </div>

                        <!-- Select All Checkbox -->
                        <div class="mb-3">
                            <v-checkbox
                                v-model="selectAll"
                                :indeterminate="someSelected"
                                @change="toggleSelectAll"
                            >
                                <template v-slot:label>
                                    <div class="font-weight-bold">{{$t("select_all") || "Select All"}}</div>
                                </template>
                            </v-checkbox>
                        </div>

                        <!-- Column view: table of fields for mapping -->
                        <div v-if="csvPreviewView === 'column'" class="mb-4">
                            <div class="csv-column-view-table" style="border: 1px solid #e0e0e0; border-radius: 4px; overflow: hidden;">
                                <v-simple-table dense>
                                    <thead>
                                        <tr>
                                            <th class="text-left" style="width: 48px;"></th>
                                            <th class="text-left" style="min-width: 160px;">{{$t("csv_field") || "CSV Field"}}</th>
                                            <th class="text-left" style="min-width: 180px;">{{$t("column_type") || "Column type"}}</th>
                                            <th class="text-left" style="min-width: 120px;">{{$t("data_type") || "Data type"}}</th>
                                            <th class="text-left" style="width: 80px;">{{$t("status") || "Status"}}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="(mapping, idx) in columnMappings"
                                            :key="idx"
                                            :style="isRequiredFieldMapped(mapping) ? { backgroundColor: '#e3f2fd' } : {}"
                                        >
                                            <td>
                                                <v-checkbox
                                                    v-model="mapping.selected"
                                                    hide-details
                                                    dense
                                                    @change="validateIndicatorId"
                                                    class="mt-0"
                                                ></v-checkbox>
                                            </td>
                                            <td class="font-weight-medium">{{mapping.csvColumn}}</td>
                                            <td>
                                                <select
                                                    v-model="mapping.columnType"
                                                    :disabled="!mapping.selected"
                                                    @change="validateIndicatorId"
                                                    style="font-size: 12px; padding: 6px 8px; width: 100%; max-width: 180px; border: 1px solid #ccc; border-radius: 4px; background: white;"
                                                >                                                    
                                                    <option value="dimension">dimension</option>
                                                    <option value="time_period">time_period</option>
                                                    <option value="measure">measure</option>
                                                    <option value="attribute">attribute</option>
                                                    <option value="indicator_id">indicator_id</option>
                                                    <option value="indicator_name">indicator_name</option>
                                                    <option value="annotation">annotation</option>
                                                    <option value="geography">geography</option>
                                                    <option value="observation_value">observation_value</option>
                                                    <option value="periodicity">periodicity</option>
                                                </select>
                                            </td>
                                            <td>
                                                <select
                                                    v-model="mapping.dataType"
                                                    :disabled="!mapping.selected"
                                                    style="font-size: 12px; padding: 6px 8px; width: 100%; max-width: 120px; border: 1px solid #ccc; border-radius: 4px; background: white;"
                                                >
                                                    <option value="string">string</option>
                                                    <option value="integer">integer</option>
                                                    <option value="float">float</option>
                                                    <option value="double">double</option>
                                                    <option value="date">date</option>
                                                    <option value="boolean">boolean</option>
                                                </select>
                                            </td>
                                            <td>
                                                <v-chip
                                                    v-if="mapping.existingColumn"
                                                    x-small
                                                    color="orange"
                                                >
                                                    {{$t("exists") || "Exists"}}
                                                </v-chip>
                                            </td>
                                        </tr>
                                    </tbody>
                                </v-simple-table>
                            </div>
                        </div>

                        <!-- Data view: Data Preview Table with Column Configuration in Headers -->
                        <div v-if="csvPreviewView === 'data'" class="mb-4" style="border: 1px solid #e0e0e0; border-radius: 4px; overflow-x: scroll; overflow-y: auto; max-height: 600px; position: relative;">
                            <div class="csv-preview-table-container" style="overflow-x: scroll; overflow-y: auto;">
                                <v-simple-table dense style="min-width: 100%;">
                                    <thead>
                                        <tr>
                                            <th v-for="(mapping, idx) in columnMappings" :key="idx" class="text-left" :style="[ { minWidth: '200px', verticalAlign: 'top', fontSize: '10px', padding: '2px' }, isRequiredFieldMapped(mapping) ? { backgroundColor: '#e3f2fd' } : {} ]">
                                                <div class="d-flex flex-column" style="gap: 2px;">
                                                    <v-checkbox
                                                        v-model="mapping.selected"
                                                        hide-details
                                                        @change="validateIndicatorId"
                                                        dense
                                                        style="font-size: 9px; margin: 0; padding: 0;"
                                                    >
                                                        <template v-slot:label>
                                                            <span style="font-size: 9px;">{{mapping.csvColumn}}</span>
                                                        </template>
                                                    </v-checkbox>                                                    
                                                    <select
                                                        v-model="mapping.columnType"
                                                        :disabled="!mapping.selected"
                                                        @change="validateIndicatorId"
                                                        style="font-size: 11px; font-weight: normal; padding: 2px 4px; width: 100%; border: 1px solid #ccc; border-radius: 2px; background: white;"
                                                    >
                                                        <option value="dimension">dimension</option>
                                                        <option value="time_period">time_period</option>
                                                        <option value="measure">measure</option>
                                                        <option value="attribute">attribute</option>
                                                        <option value="indicator_id">indicator_id</option>
                                                        <option value="indicator_name">indicator_name</option>
                                                        <option value="annotation">annotation</option>
                                                        <option value="geography">geography</option>
                                                        <option value="observation_value">observation_value</option>
                                                        <option value="periodicity">periodicity</option>
                                                    </select>
                                                    <select
                                                        v-model="mapping.dataType"
                                                        :disabled="!mapping.selected"
                                                        style="font-size: 11px; font-weight: normal; padding: 2px 4px; width: 100%; border: 1px solid #ccc; border-radius: 2px; background: white;"
                                                    >
                                                        <option value="string">string</option>
                                                        <option value="integer">integer</option>
                                                        <option value="float">float</option>
                                                        <option value="double">double</option>
                                                        <option value="date">date</option>
                                                        <option value="boolean">boolean</option>
                                                    </select>
                                                    <v-chip 
                                                        v-if="mapping.existingColumn" 
                                                        x-small 
                                                        color="orange"
                                                        style="font-size: 8px; height: 16px;"
                                                    >
                                                        {{$t("exists") || "Exists"}}
                                                    </v-chip>
                                                </div>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="(row, idx) in csvData.rows" :key="idx">
                                            <td v-for="(mapping, mapIdx) in columnMappings" :key="mapIdx" :style="[ { maxWidth: '200px', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', padding: '8px', fontSize: '11px' }, isRequiredFieldMapped(mapping) ? { backgroundColor: '#e3f2fd' } : {} ]" :title="row[mapping.csvColumn] || ''">
                                                {{(row[mapping.csvColumn] || '').length > 50 ? (row[mapping.csvColumn] || '').substring(0, 50) + '...' : (row[mapping.csvColumn] || '')}}
                                            </td>
                                        </tr>
                                    </tbody>
                                </v-simple-table>
                            </div>
                        </div>


                        <v-alert v-if="hasWarnings" type="warning" class="mb-3">
                            <div>{{$t("some_columns_exist") || "Some columns already exist in the data structure"}}</div>
                            <div class="mt-2">
                                <v-checkbox v-model="overwriteExisting" class="mt-0">
                                    <template v-slot:label>
                                        <div class="font-weight-regular">{{$t("overwrite_existing_columns") || "Overwrite existing columns"}}</div>
                                    </template>
                                </v-checkbox>
                                <v-checkbox v-model="skipExisting" class="mt-0">
                                    <template v-slot:label>
                                        <div class="font-weight-regular">{{$t("skip_existing_columns") || "Skip existing columns"}}</div>
                                    </template>
                                </v-checkbox>
                            </div>
                        </v-alert>


                        <div class="mt-4 d-flex justify-space-between align-center">
                            <div>
                                <v-btn text @click="reset">{{$t("upload_another") || "Upload Another File"}}</v-btn>
                            </div>
                            <div>
                                <v-btn 
                                    color="primary" 
                                    @click="processImport"
                                    :loading="isProcessing"
                                    :disabled="!canImport"
                                    large
                                >
                                    <v-icon left>mdi-upload</v-icon>
                                    {{$t("import") || "Import"}}
                                </v-btn>
                                <v-btn text @click="cancel" class="ml-2">{{$t("cancel") || "Cancel"}}</v-btn>
                            </div>
                        </div>
                    </div>

                    <!-- Import Progress (shown during import) -->
                    <div v-if="isProcessing" class="text-center pa-8">
                        <v-progress-linear
                            :value="importProgress"
                            color="primary"
                            height="25"
                            class="mb-3"
                        >
                            <strong>{{importProgress}}%</strong>
                        </v-progress-linear>
                        <p class="text-center">{{importStatus}}</p>
                    </div>
                </v-card-text>
            </v-card>
        </div>
    `
})
