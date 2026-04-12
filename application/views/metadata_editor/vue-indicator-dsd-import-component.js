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
            step: 1, // 1: upload+staging, 2: map + promote + MySQL import (progress overlays this step)
            existingColumnsAction: 'overwrite', // 'overwrite' | 'skip' when some columns already exist
            previewRows: 20,
            isProcessing: false,
            importStatus: '',
            importProgress: 0,
            indicatorIdValidation: null, // { valid: bool, error: string }
            editableStudyIdno: '', // Editable study IDNO
            csvPreviewView: 'column', // prefer column view when no local row preview
            // CSV column name to use as value label for each required field (for value_labels generation)
            requiredFieldLabelColumns: { indicator_id: '', geography: '', time_period: '', observation_value: '' },
            hasUnsavedChanges: false,
            // DuckDB staging pipeline
            stagingReady: false,
            /** { value: string, count: number } from staging distinct API */
            distinctItems: [],
            distinctTruncated: false,
            distinctLoading: false,
            distinctError: '',
            /** Max distinct indicator codes requested from API (server caps the same). */
            distinctListLimit: 3000,
            selectedIndicatorValue: '',
            /** True after GET data_draft_status hydrated step 2 */
            stagingResumedFromServer: false,
            /** Saved indicator_staging_upload.csv is present (MySQL import can run without a new browser upload) */
            serverStagingHasFile: false,
            /** Vertical v-stepper: 1 indicator column, 2 series, 3 time period, 4 other required dimensions */
            indicatorSeriesStepper: 1,
            /** From GET /api/indicator_dsd/{sid} ?detailed=1 — time_period_formats, freq_codes */
            dsdDictionaries: { time_period_formats: [], freq_codes: [] },
            /** CSV column used for last duckdb distinct fetch; avoids refetch (and stepper flicker) when only other roles change */
            _distinctFetchIndicatorColumn: ''
        }
    },
    created: async function() {
        await this.loadExistingColumns();
        this.editableStudyIdno = this.StudyIDNO || '';
        await this.tryResumeStagingFromServer();
    },
    mounted() {
        if (!document.getElementById('indicator-series-stepper-always-expanded-css')) {
            const style = document.createElement('style');
            style.id = 'indicator-series-stepper-always-expanded-css';
            // Vuetify 2 vertical VStepperContent collapses inactive panels via height:0 on .v-stepper__wrapper
            style.textContent = [
                '.indicator-series-stepper--always-expanded.v-stepper--vertical .v-stepper__wrapper {',
                '  height: auto !important;',
                '  overflow: visible !important;',
                '}'
            ].join('');
            document.head.appendChild(style);
        }
        this._boundBeforeUnload = this.handleBeforeUnload.bind(this);
        window.addEventListener('beforeunload', this._boundBeforeUnload);

        // Track hash changes (Vue Router hash mode) to warn about losing selections
        this._lastHash = window.location.hash || '';
        this._ignoreHashChange = false;
        this._boundHashChange = this.handleHashChange.bind(this);
        window.addEventListener('hashchange', this._boundHashChange);

        // Register a router guard as a fallback for hash-only route updates
        if (this.$router && Array.isArray(this.$router.beforeHooks)) {
            this._routeGuard = (to, from, next) => {
                if (!this.shouldWarnBeforeUnload()) {
                    return next();
                }
                if (!this.showUnsavedMessage()) {
                    // Best-effort revert hash if it changed
                    if (from && typeof from.hash === 'string') {
                        this._ignoreHashChange = true;
                        window.location.hash = from.hash || '';
                    }
                    return next(false);
                }
                return next();
            };
            this.$router.beforeHooks.push(this._routeGuard);
        }
    },
    beforeDestroy() {
        window.removeEventListener('beforeunload', this._boundBeforeUnload);
        window.removeEventListener('hashchange', this._boundHashChange);
        if (this._routeGuard && this.$router && Array.isArray(this.$router.beforeHooks)) {
            const idx = this.$router.beforeHooks.indexOf(this._routeGuard);
            if (idx > -1) {
                this.$router.beforeHooks.splice(idx, 1);
            }
        }
    },
    beforeRouteLeave(to, from, next) {
        if (!this.showUnsavedMessage()) {
            return next(false);
        }
        return next();
    },
    beforeRouteUpdate(to, from, next) {
        // Triggered on hash changes or in-place route updates when component is reused
        if (!this.showUnsavedMessage()) {
            return next(false);
        }
        return next();
    },
    watch: {
        columnMappings: {
            deep: true,
            handler() {
                if (this.step === 2) {
                    this.$nextTick(() => {
                        this.validateIndicatorId();
                        if (this.stagingReady) {
                            const m = this.columnMappings.find(function(x) {
                                return x.selected && x.columnType === 'indicator_id';
                            });
                            const col = m && m.csvColumn ? String(m.csvColumn).trim() : '';
                            if (col !== this._distinctFetchIndicatorColumn) {
                                this._distinctFetchIndicatorColumn = col;
                                this.fetchStagingDistinct();
                            }
                        }
                    });
                    this.hasUnsavedChanges = true;
                }
            }
        },
        editableStudyIdno() {
            if (this.step === 2 && this.stagingReady) {
                const study = String((this.editableStudyIdno || '').trim());
                if (study && this.distinctItems.some(function(i) { return i.value === study; })) {
                    this.selectedIndicatorValue = study;
                }
                this.$nextTick(() => {
                    this.validateIndicatorId();
                });
            } else if (this.step === 2) {
                this.$nextTick(() => {
                    this.validateIndicatorId();
                });
            }
            this.hasUnsavedChanges = true;
        },
        selectedIndicatorValue() {
            if (this.step === 2) {
                this.$nextTick(() => {
                    this.validateIndicatorId();
                });
            }
        },
        indicatorStep1Complete: {
            immediate: true,
            handler: function(now, prev) {
                if (!now) {
                    this.indicatorSeriesStepper = 1;
                    return;
                }
                if (now && prev !== true) {
                    this.$nextTick(function() {
                        this.indicatorSeriesStepper = 2;
                    }.bind(this));
                }
            }
        },
        indicatorStep2Complete: {
            immediate: true,
            handler: function(now, prev) {
                if (!now) {
                    if (this.indicatorSeriesStepper === 3 || this.indicatorSeriesStepper === 4) {
                        const v = this.selectedIndicatorValue;
                        var hasSeries = v != null && String(v).trim() !== '';
                        if (!hasSeries || this.distinctError) {
                            this.indicatorSeriesStepper = 2;
                        }
                    }
                    return;
                }
                if (now && prev !== true) {
                    this.$nextTick(function() {
                        this.indicatorSeriesStepper = 3;
                    }.bind(this));
                }
            }
        },
        indicatorStep3TimeComplete: {
            immediate: true,
            handler: function(now, prev) {
                if (!now) {
                    if (this.indicatorSeriesStepper === 4 || this.indicatorSeriesStepper === 5) {
                        this.indicatorSeriesStepper = 3;
                    }
                    return;
                }
                if (now && prev !== true) {
                    this.$nextTick(function() {
                        this.indicatorSeriesStepper = 4;
                    }.bind(this));
                }
            }
        },
        indicatorStep4OtherComplete: {
            immediate: true,
            handler: function(now, prev) {
                if (!now) {
                    if (this.indicatorSeriesStepper === 5) {
                        this.indicatorSeriesStepper = 4;
                    }
                    return;
                }
                if (now && prev !== true) {
                    this.$nextTick(function() {
                        this.indicatorSeriesStepper = 5;
                    }.bind(this));
                }
            }
        },
        step(newStep) {
            // Validate when entering step 2 (preview)
            if (newStep === 2) {
                if (!this.editableStudyIdno) {
                    this.editableStudyIdno = this.StudyIDNO || '';
                }
                this.indicatorSeriesStepper = 1;
                this.$nextTick(() => {
                    this.validateIndicatorId();
                    if (this.stagingReady) {
                        this.fetchStagingDistinct();
                    }
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
                let response = await axios.get(url, { params: { detailed: 1 } });
                if (response.data && response.data.columns) {
                    vm.existingColumns = response.data.columns;
                }
                if (response.data && response.data.dictionaries) {
                    vm.dsdDictionaries = {
                        time_period_formats: response.data.dictionaries.time_period_formats || [],
                        freq_codes: response.data.dictionaries.freq_codes || []
                    };
                }
            } catch (error) {
                console.log("Error loading existing columns", error);
            } finally {
                this.loading = false;
            }
        },
        tryResumeStagingFromServer: async function() {
            try {
                const res = await axios.get(CI.base_url + '/api/indicator_dsd/data_draft_status/' + this.dataset_id);
                if (res.data.status !== 'success' || !res.data.data) {
                    return;
                }
                const d = res.data.data;
                if (!d.exists) {
                    return;
                }
                const cols = d.columns || [];
                const names = cols.map(function(c) {
                    if (typeof c === 'string') {
                        return c;
                    }
                    return c && c.name != null ? String(c.name) : '';
                }).filter(Boolean);
                if (!names.length) {
                    return;
                }
                this.csvColumns = names;
                this.csvData = {
                    headers: names,
                    rows: [],
                    totalRows: d.row_count != null ? Number(d.row_count) : 0
                };
                this._distinctFetchIndicatorColumn = '';
                this.initializeColumnMappings();
                this.stagingReady = true;
                this.stagingResumedFromServer = true;
                this.serverStagingHasFile = !!d.csv_on_disk;
                this.indicatorSeriesStepper = 1;
                this.step = 2;
                this.hasUnsavedChanges = true;
                this.$nextTick(function() {
                    this.runAfterStagingReady();
                }.bind(this));
            } catch (e) {
                console.log('data_draft_status', e);
            }
        },
        handleFileUpload: function(event) {
            this.errors = [];
            this.warnings = [];
            this.file = event;
            this.stagingReady = false;
            this.stagingResumedFromServer = false;
            this.serverStagingHasFile = false;
            this.indicatorSeriesStepper = 1;

            if (!this.file) {
                return;
            }

            const fileName = (this.file.name || '').toLowerCase();
            if (!fileName.endsWith('.csv')) {
                this.errors.push('Only CSV files are supported');
                this.file = null;
                return;
            }
            // Full load is server-side in DuckDB; use Continue after confirming replace.
        },
        pollDuckdbJob: async function(jobId) {
            const sid = this.dataset_id;
            const maxAttempts = 600;
            for (let i = 0; i < maxAttempts; i++) {
                const res = await axios.get(
                    CI.base_url + '/api/indicator_dsd/job/' + sid,
                    { params: { job_id: jobId } }
                );
                if (res.data.status !== 'success' || res.data.job == null) {
                    const msg = (res.data && res.data.message) ? res.data.message : 'Job status request failed';
                    throw new Error(msg);
                }
                const j = res.data.job;
                const st = j.status;
                if (st === 'done') {
                    return j;
                }
                if (st === 'error') {
                    throw new Error(j.error || j.message || 'Job failed');
                }
                await new Promise(function(r) { setTimeout(r, 2000); });
            }
            throw new Error('Timed out waiting for job');
        },
        /** Load sample rows + distinct indicator list after staging is ready (new upload or resume). */
        runAfterStagingReady: async function() {
            try {
                await Promise.all([
                    this.fetchStagingSampleRows(),
                    this.fetchStagingDistinct()
                ]);
            } finally {
                this.validateIndicatorId();
            }
        },
        fetchStagingSampleRows: async function() {
            if (!this.stagingReady || this.step !== 2 || !this.csvData) {
                return;
            }
            try {
                var res = await axios.get(
                    CI.base_url + '/api/indicator_dsd/data_draft_preview/' + this.dataset_id,
                    { params: { limit: this.previewRows } }
                );
                if (res.data.status !== 'success') {
                    return;
                }
                var d = res.data.data || {};
                var rows = d.rows;
                if (!Array.isArray(rows)) {
                    return;
                }
                this.$set(this.csvData, 'rows', rows);
                if (rows.length) {
                    this.csvPreviewView = 'data';
                }
            } catch (e) {
                console.log('data_draft_preview', e);
                if (this.csvData) {
                    this.$set(this.csvData, 'rows', []);
                }
            }
        },
        startStagingUpload: async function() {
            this.errors = [];
            if (!this.file) {
                return;
            }
            this.isProcessing = true;
            this.importStatus = 'Uploading and loading into staging…';
            this.importProgress = 10;
            try {
                const fd = new FormData();
                fd.append('file', this.file);
                fd.append('delimiter', ',');
                // Do not send dsd_columns here: staging must accept any CSV headers; the user maps
                // columns in step 2. Sending DSD names forces FastAPI to reject files whose headers
                // do not match the current structure (e.g. a new or reorganized extract).
                const post = await axios.post(
                    CI.base_url + '/api/indicator_dsd/data_draft/' + this.dataset_id,
                    fd,
                    { headers: { 'Content-Type': 'multipart/form-data' } }
                );
                if (post.data.status !== 'success' || !post.data.job_id) {
                    throw new Error(post.data.message || 'Staging request failed');
                }
                this.importProgress = 30;
                this.importStatus = 'Loading CSV into staging (DuckDB)…';
                const job = await this.pollDuckdbJob(post.data.job_id);
                const data = job.data || {};
                const cols = data.columns || [];
                const names = cols.map(function(c) {
                    if (typeof c === 'string') {
                        return c;
                    }
                    return c && c.name != null ? String(c.name) : '';
                }).filter(Boolean);
                if (!names.length) {
                    throw new Error('Staging completed but no columns were returned');
                }
                this.csvColumns = names;
                this.csvData = {
                    headers: names,
                    rows: [],
                    totalRows: data.row_count != null ? Number(data.row_count) : 0
                };
                this._distinctFetchIndicatorColumn = '';
                this.initializeColumnMappings();
                this.stagingReady = true;
                this.stagingResumedFromServer = false;
                this.serverStagingHasFile = true;
                this.distinctItems = [];
                this.selectedIndicatorValue = '';
                this.distinctError = '';
                this.distinctTruncated = false;
                this.indicatorSeriesStepper = 1;
                this.step = 2;
                this.hasUnsavedChanges = true;
                this.$nextTick(function() {
                    this.runAfterStagingReady();
                }.bind(this));
            } catch (e) {
                let msg = (e.response && e.response.data && e.response.data.message) || e.message || 'Staging failed';
                this.errors.push(msg);
            } finally {
                this.isProcessing = false;
                this.importProgress = 0;
                this.importStatus = '';
            }
        },
        fetchStagingDistinct: async function() {
            if (!this.stagingReady || this.step !== 2) {
                return;
            }
            var m = this.columnMappings.find(function(x) {
                return x.selected && x.columnType === 'indicator_id';
            });
            if (!m) {
                this.distinctItems = [];
                this.selectedIndicatorValue = '';
                this.distinctTruncated = false;
                this.distinctError = '';
                this.validateIndicatorId();
                return;
            }
            this.distinctLoading = true;
            this.distinctError = '';
            try {
                var url = CI.base_url + '/api/indicator_dsd/data_draft_values/' + this.dataset_id;
                var res = await axios.get(url, {
                    params: { column: m.csvColumn, limit: this.distinctListLimit }
                });
                if (res.data.status !== 'success') {
                    throw new Error(res.data.message || 'Distinct request failed');
                }
                var d = res.data.data || {};
                var items = [];
                if (Array.isArray(d.items) && d.items.length) {
                    d.items.forEach(function(it) {
                        var v = it.value != null ? String(it.value) : '';
                        if (!v) {
                            return;
                        }
                        var c = it.count;
                        if (typeof c !== 'number') {
                            c = parseInt(c, 10);
                        }
                        if (isNaN(c) || c < 0) {
                            c = 0;
                        }
                        items.push({ value: v, count: c });
                    });
                } else {
                    var values = d.values;
                    if (!Array.isArray(values)) {
                        values = [];
                    }
                    values.forEach(function(v) {
                        var s = v != null && typeof v !== 'object' ? String(v) : JSON.stringify(v);
                        if (s) {
                            items.push({ value: s, count: 0 });
                        }
                    });
                }
                this.distinctItems = items;
                this.distinctTruncated = !!d.truncated;
                var study = String((this.editableStudyIdno || this.StudyIDNO || '').trim());
                if (study && this.distinctItems.some(function(i) { return i.value === study; })) {
                    this.selectedIndicatorValue = study;
                }
            } catch (e) {
                this.distinctError = (e.response && e.response.data && e.response.data.message) || e.message || 'Could not load distinct values';
                this.distinctItems = [];
                this.selectedIndicatorValue = '';
            } finally {
                this.distinctLoading = false;
                this.validateIndicatorId();
            }
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

            // Mark that there are unsaved changes once a CSV has been parsed
            this.hasUnsavedChanges = true;

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
                if (name.length > 0 && name.charAt(0) === '_') {
                    errors.push(`Column name "${name}" cannot start with underscore (_); reserved for system fields.`);
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
                'time_period': 'time_period',
                'freq': 'periodicity'
            };

            this.columnMappings = this.csvColumns.map((csvCol) => {
                // Convert to uppercase for SDMX compatibility
                const upperCol = csvCol.toUpperCase();
                const lowerCol = csvCol.toLowerCase();
                
                // Check if column already exists (compare uppercase)
                const existing = this.existingColumns.find(
                    col => col.name.toUpperCase() === upperCol
                );

                // Auto-map column type based on CSV column name; fall back to existing DSD type;
                // leave as '' (unassigned) for brand-new columns so the user can classify them in step 5.
                let columnType = '';
                if (autoMappingRules[lowerCol]) {
                    columnType = autoMappingRules[lowerCol];
                } else if (existing && existing.column_type) {
                    columnType = existing.column_type;
                }

                var tpFmt = null;
                var tpFreq = null;
                if (existing && String(existing.column_type || '') === 'time_period') {
                    tpFmt = existing.time_period_format || null;
                    var em = existing.metadata;
                    if (em && (em.freq || em.import_freq_code)) {
                        tpFreq = em.freq != null && String(em.freq).trim() !== '' ? em.freq : em.import_freq_code;
                    }
                }

                return {
                    csvColumn: csvCol,
                    columnName: upperCol, // Uppercase name for SDMX
                    selected: true, // Default: all selected
                    existingColumn: existing ? existing : null,
                    dsdColumnId: existing && existing.id != null ? existing.id : null,
                    columnType: columnType, // Auto-mapped or default
                    dataType: 'string', // default
                    label: csvCol,
                    description: '',
                    timePeriodFormat: tpFmt,
                    timePeriodFreqCode: tpFreq,
                    labelColumn: '' // Optional CSV column whose values are human-readable labels
                };
            });
        },
        processImport: async function() {
            if (!this.csvData || !this.stagingReady) {
                EventBus.$emit('onFail', 'No preview data to import');
                return;
            }
            if (!this.file && !this.serverStagingHasFile) {
                    EventBus.$emit('onFail', 'Upload the CSV or ensure the file exists on the server to finish the data-structure import');
                return;
            }

            const selectedColumns = this.columnMappings.filter(function(m) { return m.selected; });
            if (selectedColumns.length === 0) {
                EventBus.$emit('onFail', 'Please select at least one column to import');
                return;
            }

            const validation = this.validateIndicatorId();
            if (!validation || !validation.valid) {
                EventBus.$emit('onFail', validation ? validation.error : 'Indicator validation failed');
                return;
            }

            const indicatorIdMapping = this.columnMappings.find(function(m) {
                return m.selected && m.columnType === 'indicator_id';
            });
            if (!indicatorIdMapping) {
                EventBus.$emit('onFail', 'Indicator ID column is required');
                return;
            }

            this.isProcessing = true;
            this.importStatus = 'Publishing indicator data to timeseries…';
            this.importProgress = 15;

            const vm = this;
            const formData = new FormData();
            if (this.file) {
                formData.append('file', this.file);
            }
            const selectedMappings = this.columnMappings.filter(function(m) { return m.selected; });
            const hasFreqFromData = selectedMappings.some(function(m) {
                return m.columnType === 'periodicity';
            });
            // Build a quick lookup: csvColumn → uppercase DSD columnName
            var csvToColumnName = {};
            this.columnMappings.forEach(function(m) {
                if (m.csvColumn && m.columnName) {
                    csvToColumnName[m.csvColumn] = m.columnName;
                }
            });

            const payloadMappings = selectedMappings.map(function(m) {
                var o = {};
                Object.keys(m).forEach(function(k) { o[k] = m[k]; });
                if (!o.columnType) {
                    o.columnType = 'attribute'; // unassigned columns default to attribute on import
                }
                if (m.columnType === 'time_period' && hasFreqFromData) {
                    o.timePeriodFormat = null;
                    o.timePeriodFreqCode = null;
                }
                // Resolve labelColumn (CSV name) → uppercase DSD column name so the stored
                // value_label_column matches the column names used everywhere else in the DSD.
                if (o.labelColumn) {
                    o.labelColumn = csvToColumnName[o.labelColumn] || o.labelColumn.toUpperCase();
                }
                return o;
            });
            formData.append('column_mappings', JSON.stringify(payloadMappings));
            formData.append('overwrite_existing', this.existingColumnsAction === 'overwrite' ? '1' : '0');
            formData.append('skip_existing', this.existingColumnsAction === 'skip' ? '1' : '0');
            const idnoForFilter = String(this.selectedIndicatorValue || '').trim()
                || String(this.editableStudyIdno || this.StudyIDNO || '').trim();
            formData.append('indicator_idno', idnoForFilter);
            // Translate required-field label column values from CSV names → uppercase DSD column names.
            // requiredFieldLabelColumns stores the original CSV column name chosen by the user;
            // DSD column names are always uppercased, so we must match that before sending.
            var labelColsPayload = {};
            var self = this;
            Object.keys(this.requiredFieldLabelColumns || {}).forEach(function(key) {
                if (key === 'observation_value') { return; }
                var csvCol = self.requiredFieldLabelColumns[key];
                if (!csvCol) { return; }
                labelColsPayload[key] = csvToColumnName[csvCol] || csvCol.toUpperCase();
            });
            formData.append('required_field_label_columns', JSON.stringify(labelColsPayload));

            try {
                const pr = await axios.post(
                    CI.base_url + '/api/indicator_dsd/data_import/' + vm.dataset_id,
                    {
                        indicator_column: indicatorIdMapping.csvColumn,
                        indicator_value: String(this.selectedIndicatorValue)
                    },
                    { headers: { 'Content-Type': 'application/json' } }
                );
                if (pr.data.status !== 'success' || !pr.data.job_id) {
                    throw new Error(pr.data.message || 'Promote request failed');
                }
                this.importProgress = 45;
                this.importStatus = 'Promoting staging to timeseries…';
                await this.pollDuckdbJob(pr.data.job_id);

                this.importProgress = 65;
                this.importStatus = 'Updating data structure (MySQL)…';

                let url = CI.base_url + '/api/indicator_dsd/dsd_import/' + vm.dataset_id;
                let response = await axios.post(url, formData, {
                    headers: { 'Content-Type': 'multipart/form-data' },
                    onUploadProgress: (progressEvent) => {
                        if (this.file && progressEvent.total) {
                            this.importProgress = 65 + Math.round((progressEvent.loaded * 25) / progressEvent.total);
                        }
                    }
                });
                if (!this.file) {
                    this.importProgress = 88;
                }

                this.importProgress = 92;
                this.importStatus = 'Finalizing…';

                if (response.data) {
                    if (response.data.errors && response.data.errors.length > 0) {
                        this.errors = response.data.errors;
                        this.importStatus = 'Import completed with errors';
                        EventBus.$emit('onFail', 'CSV import completed with errors. Please check the errors below.');
                        this.step = 2;
                    } else if (response.data.status === 'success') {
                        if (response.data.warnings && response.data.warnings.length) {
                            this.warnings = this.warnings.concat(response.data.warnings);
                        }
                        if (this.$store && this.$store.dispatch) {
                            this.$store.dispatch('loadDataFiles', { dataset_id: this.dataset_id });
                        }
                        this.importStatus = 'Import completed successfully!';
                        const rowsMsg = response.data.rows_imported != null ? ' ' + response.data.rows_imported + ' rows imported.' : '';
                        const message = 'CSV imported successfully: ' + (response.data.created || 0) + ' created, ' + (response.data.updated || 0) + ' updated.' + rowsMsg;
                        EventBus.$emit('onSuccess', message);
                        this.importProgress = 100;
                        this.hasUnsavedChanges = false;
                        setTimeout(function() {
                            vm.$router.push('/indicator-dsd');
                        }, 1500);
                    } else {
                        throw new Error(response.data.message || 'Import failed');
                    }
                } else {
                    throw new Error('Invalid response from server');
                }
            } catch (error) {
                this.errors = [];
                if (error.response && error.response.data) {
                    if (error.response.data.message) {
                        this.errors.push(error.response.data.message);
                    }
                    if (error.response.data.errors && Array.isArray(error.response.data.errors)) {
                        this.errors = this.errors.concat(error.response.data.errors);
                    }
                } else {
                    this.errors.push(error.message || 'Failed to import');
                }
                this.step = 2;
                EventBus.$emit('onFail', 'Import failed: ' + (this.errors[0] || 'Unknown error'));
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
            this.existingColumnsAction = 'overwrite';
            this.importStatus = '';
            this.importProgress = 0;
            this.indicatorIdValidation = null;
            this.editableStudyIdno = this.StudyIDNO || '';
            this.csvPreviewView = 'column';
            this.requiredFieldLabelColumns = { indicator_id: '', geography: '', time_period: '', observation_value: '' };
            this.hasUnsavedChanges = false;
            this.stagingReady = false;
            this.distinctItems = [];
            this.distinctTruncated = false;
            this.distinctLoading = false;
            this.distinctError = '';
            this.selectedIndicatorValue = '';
            this.stagingResumedFromServer = false;
            this.serverStagingHasFile = false;
            this.indicatorSeriesStepper = 1;
            this._distinctFetchIndicatorColumn = '';
        },
        cancel: function() {
            // Allow Cancel to navigate away without prompting for unsaved changes
            this.hasUnsavedChanges = false;
            this.$router.push('/indicator-dsd');
        },
        formatIndicatorRowCount: function(n) {
            if (typeof n !== 'number' || isNaN(n)) {
                return '0';
            }
            try {
                return n.toLocaleString(undefined, { maximumFractionDigits: 0 });
            } catch (e) {
                return String(n);
            }
        },
        toggleSelectAll: function() {
            const value = this.selectAll;
            this.columnMappings.forEach(m => {
                m.selected = value;
            });
            this.hasUnsavedChanges = true;
        },
        setRequiredFieldMapping: function(fieldKey, csvColumn) {
            var vm = this;
            // Clear current mapping for this field type
            this.columnMappings.forEach(function(m) {
                if (m.columnType === fieldKey) {
                    m.columnType = 'attribute';
                    if (fieldKey === 'time_period') {
                        vm.$set(m, 'timePeriodFormat', null);
                        vm.$set(m, 'timePeriodFreqCode', null);
                    }
                }
            });
            // Set new mapping if a column was selected
            if (csvColumn) {
                const m = this.columnMappings.find(x => x.csvColumn === csvColumn);
                if (m) {
                    m.columnType = fieldKey;
                    m.selected = true;
                    if (fieldKey === 'time_period') {
                        if (m.timePeriodFormat === undefined) this.$set(m, 'timePeriodFormat', null);
                        if (m.timePeriodFreqCode === undefined) this.$set(m, 'timePeriodFreqCode', null);
                    }
                }
            }
            if (fieldKey === 'observation_value') {
                this.$set(this.requiredFieldLabelColumns, 'observation_value', '');
            }
            this.$nextTick(function() {
                this.validateIndicatorId();
            }.bind(this));
            this.hasUnsavedChanges = true;
        },
        setRequiredFieldLabelColumn: function(fieldKey, csvColumn) {
            if (fieldKey === 'observation_value') {
                return;
            }
            this.$set(this.requiredFieldLabelColumns, fieldKey, csvColumn || '');
            // Flag the label column as attribute if it has no type yet
            if (csvColumn) {
                var lm = this.columnMappings.find(function(x) { return x.csvColumn === csvColumn; });
                if (lm && !lm.columnType) {
                    lm.columnType = 'attribute';
                }
            }
            this.hasUnsavedChanges = true;
        },
        setOtherColumnLabelColumn: function(csvColumn, labelCsvColumn) {
            var m = this.columnMappings.find(function(x) { return x.csvColumn === csvColumn; });
            if (m) {
                this.$set(m, 'labelColumn', labelCsvColumn || '');
            }
            // Flag the chosen label column as attribute if it has no type yet
            if (labelCsvColumn) {
                var lm = this.columnMappings.find(function(x) { return x.csvColumn === labelCsvColumn; });
                if (lm && !lm.columnType) {
                    lm.columnType = 'attribute';
                }
            }
            this.hasUnsavedChanges = true;
        },
        setTimePeriodMappingField: function(field, value) {
            var m = this.timePeriodMapping;
            if (!m) {
                return;
            }
            var v = value != null && value !== '' ? value : null;
            this.$set(m, field, v);
            this.hasUnsavedChanges = true;
        },
        /** Map a CSV column to periodicity (FREQ from file). Clears conflicting mapping; optional clear=null removes FREQ column. */
        setFreqDataColumnMapping: function(csvColumn) {
            this.columnMappings.forEach(function(m) {
                if (m.columnType === 'periodicity') {
                    m.columnType = 'attribute';
                }
            });
            if (csvColumn) {
                var tp = this.timePeriodMapping;
                if (tp && tp.csvColumn === csvColumn) {
                    if (typeof EventBus !== 'undefined' && EventBus.$emit) {
                        EventBus.$emit('onFail', this.$t('import_freq_column_same_as_time') || 'Choose a different column for FREQ than for TIME_PERIOD.');
                    }
                    return;
                }
                var m = this.columnMappings.find(function(x) { return x.csvColumn === csvColumn; });
                if (m) {
                    if (m.columnType === 'time_period') {
                        if (typeof EventBus !== 'undefined' && EventBus.$emit) {
                            EventBus.$emit('onFail', this.$t('import_freq_column_same_as_time') || 'Choose a different column for FREQ than for TIME_PERIOD.');
                        }
                        return;
                    }
                    m.columnType = 'periodicity';
                    m.selected = true;
                    var trow = this.timePeriodMapping;
                    if (trow) {
                        this.$set(trow, 'timePeriodFormat', null);
                        this.$set(trow, 'timePeriodFreqCode', null);
                    }
                }
            }
            this.hasUnsavedChanges = true;
        },
        isRequiredFieldMapped: function(mapping) {
            if (!mapping || !mapping.selected) return false;
            const required = ['indicator_id', 'observation_value', 'geography', 'time_period'];
            return required.indexOf(mapping.columnType) !== -1;
        },
        validateIndicatorId: function() {
            this.indicatorIdValidation = null;

            const indicatorIdMapping = this.columnMappings.find(
                function(m) { return m.selected && m.columnType === 'indicator_id'; }
            );

            if (!indicatorIdMapping) {
                this.indicatorIdValidation = {
                    valid: false,
                    error: this.$t('validation_indicator_column_required')
                        || 'Choose which CSV column holds the indicator codes (the values that distinguish each series in this file).'
                };
                return this.indicatorIdValidation;
            }

            if (this.step === 2 && this.stagingReady) {
                if (this.distinctLoading) {
                    this.indicatorIdValidation = {
                        valid: false,
                        error: this.$t('validation_loading_indicator_values')
                            || 'Loading distinct values from that column…'
                    };
                    return this.indicatorIdValidation;
                }
                if (this.distinctError) {
                    this.indicatorIdValidation = {
                        valid: false,
                        error: this.distinctError
                    };
                    return this.indicatorIdValidation;
                }
                if (!this.selectedIndicatorValue || String(this.selectedIndicatorValue).trim() === '') {
                    this.indicatorIdValidation = {
                        valid: false,
                        error: this.$t('validation_series_to_import_required')
                            || 'Select which indicator code (series) to import. Only rows with that value will be used for charts and published data.'
                    };
                    return this.indicatorIdValidation;
                }
            }

            this.indicatorIdValidation = { valid: true };
            return this.indicatorIdValidation;
        },
        shouldWarnBeforeUnload: function() {
            // Warn only when there are unsaved changes and we are not mid-import
            return this.hasUnsavedChanges && !this.isProcessing;
        },
        showUnsavedMessage: function() {
            if (!this.shouldWarnBeforeUnload()) {
                return true;
            }
            return confirm(this.getUnsavedChangesMessage());
        },
        getUnsavedChangesMessage: function() {
            return this.$t('confirm_unsaved_changes') || 'You have unsaved changes. Are you sure you want to leave this page?';
        },
        handleBeforeUnload: function(event) {
            if (!this.shouldWarnBeforeUnload()) {
                return;
            }
            const message = this.getUnsavedChangesMessage();
            event.preventDefault();
            event.returnValue = message;
            return message;
        },
        handleHashChange: function(event) {
            if (this._ignoreHashChange) {
                // Skip synthetic hash change we triggered to revert navigation
                this._ignoreHashChange = false;
                this._lastHash = window.location.hash || '';
                return;
            }

            if (!this.shouldWarnBeforeUnload()) {
                this._lastHash = window.location.hash || '';
                return;
            }

            const confirmLeave = this.showUnsavedMessage();
            if (!confirmLeave) {
                // Revert to the previous hash to keep the user on the current view
                this._ignoreHashChange = true;
                window.location.hash = this._lastHash || '';
                return;
            }

            // Accepted navigation; remember new hash
            this._lastHash = window.location.hash || '';
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
        indicatorStep1Complete() {
            var s = this.requiredFieldsStatus;
            return !!(s && s.indicator_id && s.indicator_id.selected);
        },
        indicatorStep2Complete() {
            var v = this.selectedIndicatorValue;
            return !!(
                v != null
                && String(v).trim() !== ''
                && !this.distinctLoading
                && !this.distinctError
            );
        },
        timePeriodMapping() {
            return this.columnMappings.find(function(m) {
                return m.selected && m.columnType === 'time_period';
            }) || null;
        },
        /** Selected CSV column mapped as SDMX FREQ (periodicity) — frequency from data. */
        freqColumnMapping() {
            return this.columnMappings.find(function(m) {
                return m.selected && m.columnType === 'periodicity';
            }) || null;
        },
        importHasFreqColumnMapping() {
            return this.freqColumnMapping != null;
        },
        indicatorStep3TimeComplete() {
            var m = this.timePeriodMapping;
            if (!m) {
                return false;
            }
            if (this.importHasFreqColumnMapping) {
                return true;
            }
            return !!(m.timePeriodFormat && m.timePeriodFreqCode);
        },
        indicatorStep4OtherComplete() {
            const s = this.requiredFieldsStatus;
            return !!(
                s
                && s.observation_value && s.observation_value.selected
                && s.geography && s.geography.selected
            );
        },
        /** Step 5 is complete when no selected column is left unassigned. */
        indicatorStep5DimensionsComplete() {
            return this.indicatorStep4OtherComplete && this.unmappedColumnsForStep5.length === 0;
        },
        /** Selected columns that have no column type assigned yet (drives step-5 completion check). */
        unmappedColumnsForStep5() {
            return this.columnMappings.filter(function(m) {
                return m.selected && !m.columnType;
            });
        },
        /**
         * All selected columns not already locked by steps 1–4
         * (indicator_id, time_period, periodicity, geography, observation_value).
         * Shown in step 5 so users can review and classify the rest.
         */
        otherColumnsForStep5() {
            var lockedTypes = ['indicator_id', 'time_period', 'periodicity', 'geography', 'observation_value'];
            return this.columnMappings.filter(function(m) {
                return m.selected && lockedTypes.indexOf(m.columnType) === -1;
            });
        },
        hasErrors() {
            return this.errors.length > 0;
        },
        hasWarnings() {
            return this.warnings.length > 0;
        },
        requiredFieldsStatus() {
            const requiredFields = [
                { key: 'indicator_id', label: 'Indicator code column' },
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
        /** Geography + observation (time period has its own stepper step). */
        otherRequiredFieldsList() {
            return [
                { key: 'geography', label: this.$t('geography') || 'Geography' },
                { key: 'observation_value', label: this.$t('observation_value') || 'Observation value' }
            ];
        },
        timePeriodFormatSelectItems() {
            var arr = (this.dsdDictionaries && this.dsdDictionaries.time_period_formats) || [];
            return arr.map(function(x) {
                return {
                    text: x.label + ' (' + x.code + ')',
                    value: x.code
                };
            });
        },
        freqCodeSelectItems() {
            var arr = (this.dsdDictionaries && this.dsdDictionaries.freq_codes) || [];
            return arr.map(function(x) {
                return {
                    text: x.label + ' (' + x.code + ')',
                    value: x.code
                };
            });
        },
        /** v-autocomplete items: label shows code + count only; value is raw code for promote/import */
        distinctSelectItems() {
            var vm = this;
            return this.distinctItems.map(function(it) {
                var n = typeof it.count === 'number' ? it.count : 0;
                var formatted = vm.formatIndicatorRowCount(n);
                return {
                    value: it.value,
                    text: it.value + ' — ' + formatted
                };
            });
        },
        allRequiredFieldsSelected() {
            const status = this.requiredFieldsStatus;
            return !!(status.indicator_id && status.indicator_id.selected)
                && this.indicatorStep3TimeComplete
                && this.indicatorStep4OtherComplete;
        },
        canImport() {
            if (this.step !== 2) return false;
            if (this.isProcessing) return false;
            if (!this.csvData || !this.stagingReady) return false;
            if (!this.file && !this.serverStagingHasFile) return false;
            const selected = this.columnMappings.filter(function(m) { return m.selected; });
            if (selected.length === 0) return false;

            const allColumnsValid = selected.every(function(m) {
                if (!m.columnName || m.columnName.trim() === '') return false;
                if (!/^[A-Z0-9_]+$/.test(m.columnName)) return false;
                if (m.columnName.length > 255) return false;
                if (m.columnName.charAt(0) === '_') return false;
                return true;
            });
            if (!allColumnsValid) return false;
            if (!this.allRequiredFieldsSelected) return false;

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
                    <!-- Step 1: Upload CSV → load into staging (staging is always replaced on upload) -->
                    <div v-if="step === 1 && !isProcessing">
                        <v-file-input
                            v-model="file"
                            :label="$t('select_csv_file') || 'Select CSV File'"
                            accept=".csv"
                            outlined
                            dense
                            prepend-inner-icon="mdi-file-document"
                            @change="handleFileUpload"
                        ></v-file-input>
                        <p class="caption grey--text mt-1 mb-0">
                            {{ $t('staging_replaces_note') || 'Uploading a CSV replaces the current preview for this project.' }}
                        </p>

                        <v-alert v-if="hasErrors" type="error" class="mt-3">
                            <div v-for="error in errors" :key="error">{{error}}</div>
                        </v-alert>

                        <div class="mt-4 d-flex flex-wrap align-center" style="gap: 8px;">
                            <v-btn
                                color="primary"
                                :disabled="!file"
                                @click="startStagingUpload"
                            >{{ $t('continue') || 'Continue' }}</v-btn>
                            <v-btn text @click="cancel">{{$t("cancel") || "Cancel"}}</v-btn>
                        </div>
                    </div>

                    <!-- Step 2: Map columns + indicator value + import -->
                    <div v-if="step === 2 && csvData && !isProcessing">
                        <v-alert v-if="stagingResumedFromServer" type="info" dense outlined class="mb-3">
                            {{ $t('staging_resume_banner') || 'Continuing a previous import. Map fields, pick the indicator value, then import. Upload a new CSV below to start a new preview.' }}
                        </v-alert>
                        <v-alert v-if="stagingReady && !serverStagingHasFile" type="warning" dense outlined class="mb-3">
                            {{ $t('staging_no_disk_file') || 'The uploaded file was not found on the server for MySQL sync. You can still promote to timeseries; to update the data-structure import, re-upload the same CSV (or any CSV to start a new preview).' }}
                        </v-alert>
                        <!-- Indicator column + series: vertical stepper (linear flow) -->
                        <v-card class="mb-3" outlined>
                            <v-card-title class="pa-3 pb-1" style="font-size: 16px; font-weight: bold;">
                                {{ $t('indicator_series_section_title') || 'Indicator series in this file' }}
                            </v-card-title>
                            <v-card-text class="pa-3 pt-0">
                                <v-stepper
                                    v-model="indicatorSeriesStepper"
                                    vertical
                                    flat
                                    class="elevation-0 mt-2 indicator-series-stepper indicator-series-stepper--always-expanded"
                                >
                                    <v-stepper-step
                                        :step="1"
                                        :complete="indicatorStep1Complete"
                                        editable
                                    >
                                        <span class="text-body-1 font-weight-medium">{{ $t('indicator_step1_title') || 'Which column has the indicator codes?' }}</span>
                                    </v-stepper-step>
                                    <v-stepper-content :step="1">
                                        <v-simple-table dense class="required-fields-table mb-3">
                                            <thead>
                                                <tr>
                                                    <th class="text-left" style="min-width: 140px; padding: 6px 8px;">{{ $t('role') || 'Role' }}</th>
                                                    <th class="text-left" style="min-width: 160px; padding: 6px 8px;">{{$t("mapping") || "CSV column"}}</th>
                                                    <th class="text-left" style="min-width: 160px; padding: 6px 8px;">{{$t("field_label") || "Field label"}}</th>
                                                    <th class="text-left" style="width: 48px; padding: 6px 8px;">{{$t("status") || "OK"}}</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr class="required-fields-table-row" style="background-color: #f5f9ff;">
                                                    <td style="padding: 10px 8px; vertical-align: middle;">
                                                        {{ $t('indicator_code_column_label') || 'Indicator code column' }}
                                                        <v-chip x-small color="primary" outlined class="mt-1 d-block" style="width: fit-content;">
                                                            {{ $t('series_code_chip') || 'Series code' }}
                                                        </v-chip>
                                                    </td>
                                                    <td style="padding: 10px 8px;">
                                                        <v-autocomplete
                                                            :value="requiredFieldsStatus.indicator_id && requiredFieldsStatus.indicator_id.columnName"
                                                            @input="setRequiredFieldMapping('indicator_id', $event || null)"
                                                            :items="csvColumns"
                                                            hide-details
                                                            dense
                                                            outlined
                                                            clearable
                                                            :placeholder="$t('choose_column') || 'Choose column…'"
                                                            style="max-width: 240px; font-size: 13px;"
                                                        ></v-autocomplete>
                                                    </td>
                                                    <td style="padding: 10px 8px;">
                                                        <v-autocomplete
                                                            :value="requiredFieldLabelColumns.indicator_id"
                                                            @input="setRequiredFieldLabelColumn('indicator_id', $event || '')"
                                                            :items="csvColumns"
                                                            hide-details
                                                            dense
                                                            outlined
                                                            clearable
                                                            :placeholder="$t('optional_label_column') || 'Optional label column'"
                                                            style="max-width: 240px; font-size: 13px;"
                                                        ></v-autocomplete>
                                                    </td>
                                                    <td style="vertical-align: middle; padding: 10px 8px;">
                                                        <v-icon v-if="requiredFieldsStatus.indicator_id && requiredFieldsStatus.indicator_id.selected" color="success">mdi-check-circle</v-icon>
                                                        <v-icon v-else color="error">mdi-alert-circle</v-icon>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </v-simple-table>
                                    </v-stepper-content>
                                    <v-stepper-step
                                        :step="2"
                                        :complete="indicatorStep2Complete"
                                        :editable="indicatorStep1Complete"
                                    >
                                        <span class="text-body-1 font-weight-medium">{{ $t('indicator_step2_title') || 'Which series do you want to import?' }}</span>
                                    </v-stepper-step>
                                    <v-stepper-content :step="2">
                                        <p class="caption grey--text mb-3">
                                            {{ $t('indicator_step2_caption') || 'Each option shows how many preview rows use that code. Pick the series to import.' }}
                                        </p>
                                        <template v-if="requiredFieldsStatus.indicator_id && requiredFieldsStatus.indicator_id.selected">
                                            <v-alert
                                                v-if="indicatorIdValidation && !indicatorIdValidation.valid && !distinctLoading"
                                                type="error"
                                                dense
                                                outlined
                                                class="mb-2"
                                            >{{ indicatorIdValidation.error }}</v-alert>
                                            <v-autocomplete
                                                v-model="selectedIndicatorValue"
                                                :items="distinctSelectItems"
                                                item-value="value"
                                                item-text="text"
                                                :loading="distinctLoading"
                                                :disabled="distinctLoading"
                                                outlined
                                                dense
                                                clearable
                                                hide-details
                                                class="mb-1"
                                                :label="$t('select_series_code') || 'Indicator code (series) in file'"
                                                :no-data-text="$t('no_distinct_values') || 'No values found in this column'"
                                            ></v-autocomplete>
                                            <p v-if="distinctItems.length && !distinctLoading" class="caption grey--text mt-1 mb-0">
                                                {{ $t('indicator_series_sorted_hint') || 'Sorted by row count (highest first), then code.' }}
                                            </p>
                                            <v-alert v-if="distinctTruncated" type="warning" dense text class="mt-2 mb-0">
                                                {{ $t('distinct_list_truncated') || 'List may be truncated. If your code is missing, narrow the CSV or raise the limit on the server.' }}
                                            </v-alert>
                                        </template>
                                        <v-alert v-else type="info" dense outlined class="mb-0">
                                            {{ $t('indicator_step2_prereq') || 'Choose the indicator code column in step 1 first—we will list the values found in the preview.' }}
                                        </v-alert>
                                    </v-stepper-content>
                                    <v-stepper-step
                                        :step="3"
                                        :complete="indicatorStep3TimeComplete"
                                        :editable="indicatorStep2Complete"
                                    >
                                        <span class="text-body-1 font-weight-medium">{{ $t('import_step_time_period_title') || 'Time period' }}</span>
                                    </v-stepper-step>
                                    <v-stepper-content :step="3">
                                        <p class="caption grey--text mb-3">
                                            {{ $t('import_step_time_period_caption_v2') || 'Map the TIME_PERIOD column. If your file has a separate column with SDMX FREQ codes per row, map it below; otherwise set one time format and one constant FREQ for this series.' }}
                                        </p>
                                        <v-row dense align="end" class="ma-0">
                                            <v-col
                                                cols="12"
                                                class="py-1 px-2"
                                                :sm="importHasFreqColumnMapping ? 6 : 3"
                                                :md="importHasFreqColumnMapping ? 6 : 3"
                                            >
                                                <v-autocomplete
                                                    :value="requiredFieldsStatus.time_period && requiredFieldsStatus.time_period.columnName"
                                                    @input="setRequiredFieldMapping('time_period', $event || null)"
                                                    :items="csvColumns"
                                                    hide-details
                                                    dense
                                                    outlined
                                                    clearable
                                                    :label="$t('time_period_column') || 'Time period column'"
                                                    :placeholder="$t('choose_column') || 'Choose column…'"
                                                ></v-autocomplete>
                                            </v-col>
                                            <v-col
                                                cols="12"
                                                class="py-1 px-2"
                                                :sm="importHasFreqColumnMapping ? 6 : 3"
                                                :md="importHasFreqColumnMapping ? 6 : 3"
                                            >
                                                <v-autocomplete
                                                    :value="freqColumnMapping && freqColumnMapping.csvColumn"
                                                    @input="setFreqDataColumnMapping($event || null)"
                                                    :items="csvColumns"
                                                    hide-details
                                                    dense
                                                    outlined
                                                    clearable
                                                    :label="$t('import_freq_column_optional') || 'FREQ column (optional, from data)'"
                                                    :placeholder="$t('import_freq_column_placeholder') || 'None — use constant FREQ'"
                                                ></v-autocomplete>
                                            </v-col>
                                            <v-col
                                                v-if="!importHasFreqColumnMapping"
                                                cols="12"
                                                sm="3"
                                                md="3"
                                                class="py-1 px-2"
                                            >
                                                <v-select
                                                    :value="timePeriodMapping && timePeriodMapping.timePeriodFormat"
                                                    @input="setTimePeriodMappingField('timePeriodFormat', $event)"
                                                    :items="timePeriodFormatSelectItems"
                                                    item-text="text"
                                                    item-value="value"
                                                    hide-details
                                                    dense
                                                    outlined
                                                    clearable
                                                    :disabled="!timePeriodMapping"
                                                    :label="$t('time_period_format') || 'Time period format'"
                                                ></v-select>
                                            </v-col>
                                            <v-col
                                                v-if="!importHasFreqColumnMapping"
                                                cols="12"
                                                sm="3"
                                                md="3"
                                                class="py-1 px-2"
                                            >
                                                <v-select
                                                    :value="timePeriodMapping && timePeriodMapping.timePeriodFreqCode"
                                                    @input="setTimePeriodMappingField('timePeriodFreqCode', $event)"
                                                    :items="freqCodeSelectItems"
                                                    item-text="text"
                                                    item-value="value"
                                                    hide-details
                                                    dense
                                                    outlined
                                                    clearable
                                                    :disabled="!timePeriodMapping"
                                                    :label="$t('freq_code') || 'Constant FREQ (SDMX)'"
                                                ></v-select>
                                            </v-col>
                                        </v-row>
                                        <v-alert v-if="importHasFreqColumnMapping" type="info" dense outlined class="mt-2 mb-0">
                                            {{ $t('import_time_when_freq_column') || 'FREQ comes from the mapped column; you do not set time period format or constant FREQ for this step.' }}
                                        </v-alert>
                                        <p v-if="!importHasFreqColumnMapping && timePeriodMapping && (!timePeriodMapping.timePeriodFormat || !timePeriodMapping.timePeriodFreqCode)" class="caption amber--text text--darken-2 mt-2 mb-0">
                                            {{ $t('import_time_period_format_freq_required') || 'Both format and constant FREQ are required when there is no FREQ column.' }}
                                        </p>
                                    </v-stepper-content>
                                    <v-stepper-step
                                        :step="4"
                                        :complete="indicatorStep4OtherComplete"
                                        :editable="indicatorStep3TimeComplete"
                                    >
                                        <span class="text-body-1 font-weight-medium">{{ $t('other_required_dimensions') || 'Geography and observation value' }}</span>
                                    </v-stepper-step>
                                    <v-stepper-content :step="4">
                                        <p class="caption grey--text mb-3">
                                            {{ $t('other_required_dimensions_caption_v2') || 'Geography and observation value are required for import. Optional label column applies to geography only.' }}
                                        </p>
                                        <v-simple-table dense class="required-fields-table mb-1">
                                            <thead>
                                                <tr>
                                                    <th class="text-left" style="min-width: 120px; padding: 6px 8px;">{{$t("field") || "Field"}}</th>
                                                    <th class="text-left" style="min-width: 160px; padding: 6px 8px;">{{$t("mapping") || "Mapping"}}</th>
                                                    <th class="text-left" style="min-width: 160px; padding: 6px 8px;">{{$t("field_label") || "Field label"}}</th>
                                                    <th class="text-left" style="width: 48px; padding: 6px 8px;">{{$t("status") || "Status"}}</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr v-for="field in otherRequiredFieldsList" :key="field.key" class="required-fields-table-row">
                                                    <td style="padding: 10px 8px;">{{field.label}}</td>
                                                    <td style="padding: 10px 8px;">
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
                                                    </td>
                                                    <td v-if="field.key !== 'observation_value'" style="padding: 10px 8px;">
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
                                                    </td>
                                                    <td v-else class="text--disabled caption" style="padding: 10px 8px; vertical-align: middle;">—</td>
                                                    <td style="vertical-align: middle; padding: 10px 8px;">
                                                        <v-icon v-if="requiredFieldsStatus[field.key] && requiredFieldsStatus[field.key].selected" color="success">mdi-check-circle</v-icon>
                                                        <v-icon v-else color="error">mdi-alert-circle</v-icon>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </v-simple-table>
                                    </v-stepper-content>
                                    <v-stepper-step
                                        :step="5"
                                        :complete="indicatorStep5DimensionsComplete"
                                        :editable="indicatorStep4OtherComplete"
                                    >
                                        <span class="text-body-1 font-weight-medium">{{ $t('other_columns_step_title') || 'Other columns' }}</span>
                                        <small class="grey--text ml-1">{{ unmappedColumnsForStep5.length > 0 ? unmappedColumnsForStep5.length + ' ' + ($t('unassigned') || 'unassigned') : ($t('all_assigned') || 'all assigned') }}</small>
                                    </v-stepper-step>
                                    <v-stepper-content :step="5">
                                        <p class="caption grey--text mb-3">
                                            {{ $t('other_columns_caption') || 'Columns not covered by the steps above. Assign a type to each unassigned column before importing.' }}
                                        </p>
                                        <template v-if="otherColumnsForStep5.length === 0">
                                            <p class="caption grey--text mb-2">
                                                {{ $t('other_columns_none') || 'No additional columns to classify.' }}
                                            </p>
                                        </template>
                                        <template v-else>
                                            <v-simple-table dense class="required-fields-table mb-2">
                                                <thead>
                                                    <tr>
                                                        <th class="text-left" style="min-width: 140px; padding: 6px 8px;">{{ $t('field') || 'Field' }}</th>
                                                        <th class="text-left" style="min-width: 180px; padding: 6px 8px;">{{ $t('mapping') || 'Mapping' }}</th>
                                                        <th class="text-left" style="min-width: 180px; padding: 6px 8px;">{{ $t('field_label') || 'Field label' }}</th>
                                                        <th class="text-left" style="width: 48px; padding: 6px 8px;">{{ $t('status') || 'Status' }}</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr v-for="mapping in otherColumnsForStep5" :key="mapping.csvColumn" class="required-fields-table-row" :style="!mapping.columnType ? { backgroundColor: '#fff8e1' } : {}">
                                                        <td style="padding: 10px 8px; vertical-align: middle; font-size: 13px;">
                                                            {{ mapping.csvColumn }}
                                                        </td>
                                                        <td style="padding: 10px 8px; vertical-align: middle;">
                                                            <select
                                                                v-model="mapping.columnType"
                                                                @change="validateIndicatorId"
                                                                style="font-size: 12px; padding: 6px 8px; width: 100%; max-width: 180px; border: 1px solid #ccc; border-radius: 4px; background: white;"
                                                            >
                                                                <option value="">— choose type —</option>
                                                                <option value="dimension">dimension</option>
                                                                <option value="measure">measure</option>
                                                                <option value="attribute">attribute</option>
                                                                <option value="indicator_name">indicator_name</option>
                                                                <option value="annotation">annotation</option>
                                                            </select>
                                                        </td>
                                                        <td style="padding: 10px 8px; vertical-align: middle;">
                                                            <v-autocomplete
                                                                :value="mapping.labelColumn"
                                                                @input="setOtherColumnLabelColumn(mapping.csvColumn, $event || '')"
                                                                :items="csvColumns"
                                                                hide-details
                                                                dense
                                                                outlined
                                                                clearable
                                                                :placeholder="$t('optional_label_column') || 'Optional label column'"
                                                                style="max-width: 220px; font-size: 13px;"
                                                            ></v-autocomplete>
                                                        </td>
                                                        <td style="vertical-align: middle; padding: 10px 8px;">
                                                            <v-icon v-if="mapping.columnType" color="success">mdi-check-circle</v-icon>
                                                            <v-icon v-else color="warning">mdi-alert-circle</v-icon>
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </v-simple-table>
                                        </template>
                                    </v-stepper-content>
                                </v-stepper>
                            </v-card-text>
                        </v-card>

                        <v-expansion-panels class="mb-3" flat>
                            <v-expansion-panel>
                                <v-expansion-panel-header class="px-0">
                                    <span class="text-body-2 font-weight-medium">
                                        {{ $t('project_idno_metadata_title') || 'Project IDNO (metadata)' }}
                                        <span class="caption grey--text font-weight-regular"> — {{ $t('optional_lowercase') || 'optional' }}</span>
                                    </span>
                                </v-expansion-panel-header>
                                <v-expansion-panel-content class="px-0 pt-0">
                                    <p class="caption grey--text mb-2">
                                        {{ $t('project_idno_metadata_help') || 'Study or project identifier from metadata. The series you import is chosen in step 2 above; this field is only for labeling or defaults when needed.' }}
                                    </p>
                                    <div class="d-flex align-center flex-wrap" style="gap: 8px;">
                                        <v-text-field
                                            v-model="editableStudyIdno"
                                            hide-details
                                            dense
                                            outlined
                                            style="max-width: 280px; font-size: 13px;"
                                            :label="$t('indicator_idno') || 'Project IDNO'"
                                        ></v-text-field>
                                        <v-btn x-small text @click="editableStudyIdno = StudyIDNO || ''">{{$t("reset") || "Reset"}}</v-btn>
                                        <v-icon v-if="displayStudyIdno" small color="success" class="ml-1">mdi-check-circle</v-icon>
                                    </div>
                                </v-expansion-panel-content>
                            </v-expansion-panel>
                        </v-expansion-panels>

                        <v-alert v-if="hasErrors" type="error" class="mb-3">
                            <div v-for="error in errors" :key="error">{{error}}</div>
                        </v-alert>

                        <!-- Preview & column options -->
                        <v-card class="mb-3" outlined>
                            <v-card-title class="pa-3" style="font-size: 16px; font-weight: bold;">
                                {{$t("preview_column_options") || "Preview"}}
                            </v-card-title>
                            <v-card-text class="pa-3 pt-0">
                                <p v-if="!csvData.rows.length" class="grey--text text--darken-1 mb-2">
                                    {{ $t('staging_no_sample_rows') || 'No sample rows loaded yet, or the preview is empty.' }}
                                    <template v-if="csvData.totalRows"> {{ $t('total_in_staging') || 'Total rows' }}: {{ csvData.totalRows }}.</template>
                                </p>
                                <p v-else class="text-muted mb-2">
                                    {{ $t('staging_sample_banner') || 'Sample rows' }}:
                                    {{ csvData.rows.length }} {{ $t('rows_shown') || 'rows shown' }}
                                    <template v-if="csvData.totalRows != null"> ({{ $t('of') || 'of' }} {{ csvData.totalRows }} {{ $t('rows_total') || 'rows total' }})</template>.
                                </p>

                                <!-- Data Preview Table -->
                                <div class="mb-4" style="border: 1px solid #e0e0e0; border-radius: 4px; overflow-x: scroll; overflow-y: auto; max-height: 600px;">
                                <v-simple-table dense style="min-width: max-content; width: 100%;">
                                    <thead>
                                        <!-- Row 1: column name -->
                                        <tr>
                                            <th v-for="(mapping, idx) in columnMappings" :key="'name-'+idx" class="text-left" :style="[ { minWidth: '160px', padding: '4px 8px', borderBottom: '0' }, isRequiredFieldMapped(mapping) ? { backgroundColor: '#e3f2fd' } : {} ]">
                                                <span style="font-size: 11px; font-weight: normal;">{{mapping.csvColumn}}</span>
                                            </th>
                                        </tr>
                                        <!-- Row 2: data type -->
                                        <tr>
                                            <th v-for="(mapping, idx) in columnMappings" :key="'dtype-'+idx" :style="[ { padding: '2px 8px', borderBottom: '0' }, isRequiredFieldMapped(mapping) ? { backgroundColor: '#e3f2fd' } : {} ]">
                                                <select
                                                    v-model="mapping.dataType"
                                                    style="font-size: 11px; font-weight: normal; padding: 2px 4px; width: 100%; border: 1px solid #ccc; border-radius: 2px; background: white;"
                                                >
                                                    <option value="string">string</option>
                                                    <option value="integer">integer</option>
                                                    <option value="float">float</option>
                                                    <option value="double">double</option>
                                                    <option value="date">date</option>
                                                    <option value="boolean">boolean</option>
                                                </select>
                                            </th>
                                        </tr>
                                        <!-- Row 3: column type -->
                                        <tr>
                                            <th v-for="(mapping, idx) in columnMappings" :key="'ctype-'+idx" :style="[ { padding: '2px 8px 4px' }, isRequiredFieldMapped(mapping) ? { backgroundColor: '#e3f2fd' } : {} ]">
                                                <select
                                                    v-model="mapping.columnType"
                                                    @change="validateIndicatorId"
                                                    style="font-size: 11px; font-weight: normal; padding: 2px 4px; width: 100%; border: 1px solid #ccc; border-radius: 2px; background: white;"
                                                >
                                                    <option value="">— unassigned —</option>
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
                            </v-card-text>
                        </v-card>

                        <!-- When some columns already exist: choose overwrite or skip (radio, not alert) -->
                        <div v-if="hasWarnings" class="mb-3 pa-3" style="border: 1px solid #e0e0e0; border-radius: 4px;">
                            <div class="mb-2">{{$t("some_columns_exist") || "Some columns already exist in the data structure"}}</div>
                            <v-radio-group v-model="existingColumnsAction" hide-details class="mt-0 pt-0">
                                <v-radio
                                    value="overwrite"
                                    :label="$t('overwrite_existing_columns') || 'Overwrite existing columns'"
                                ></v-radio>
                                <v-radio
                                    value="skip"
                                    :label="$t('skip_existing_columns') || 'Skip existing columns'"
                                ></v-radio>
                            </v-radio-group>
                        </div>


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
