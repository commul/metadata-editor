// Indicator Data Structure Definition (DSD) component
Vue.component('indicator-dsd', {
    props: [],
    data() {
        return {
            dataset_id: project_sid,
            dataset_idno: project_idno,
            dataset_type: project_type,
            columns: [],
            loading: false,
            column_search: '',
            page_action: 'list',
            edit_item: null,
            column_copy: {}, // copy of the column before any editing
            has_clicked_edit: true, // to ignore watch from triggering on editColumn click
            is_navigating: false, // to ignore watch from triggering during navigation
            validationResult: null,
            isValidating: false,
            /** 0 = Structure (editor), 1 = Validation report */
            dsdMainTab: 0,
            selectedColumns: [], // Array of selected column IDs for deletion
            sortOrder: 'asc', // asc, desc
            isPopulatingCodeLists: false,
            isRefreshingSumStats: false,
            globalCodelistsList: [],
            globalCodelistsLoading: false,
            dsdDictionaries: {
                time_period_formats: [],
                freq_codes: []
            },
            /** Persistent save/create errors: { message, errors[] } or null */
            dsdSaveError: null,
            /** Left column list width (px); resizable via splitter */
            dsdSplitListWidth: 280
        }
    },
    created: async function() {
        try {
            var s = localStorage.getItem('indicator_dsd_list_width_px');
            if (s) {
                var n = parseInt(s, 10);
                if (!isNaN(n) && n >= 220 && n <= 1200) {
                    this.dsdSplitListWidth = n;
                }
            }
        } catch (e) { /* ignore */ }
        this.fetchGlobalCodelists();
        await this.loadColumns();
        if (this.columns.length > 0) {
            this.validateDSDDebounce();
        }
    },
    watch: {
        activeColumn: {
            deep: true,
            handler(val, oldVal) {
                if (this.page_action != "edit") {
                    return;
                }

                if (this.has_clicked_edit) {
                    this.has_clicked_edit = false;
                    return;
                }

                // don't save if navigating to a column
                if (this.is_navigating) {
                    return;
                }

                // don't save if val is null/undefined
                if (!val) {
                    return;
                }

                // For single column, compare with the original copy
                if (JSON.stringify(val) == JSON.stringify(this.column_copy)) {
                    // no change detected
                } else {
                    this.saveColumnDebounce(val);
                }
            }
        },
        /*columns: {
            deep: true,
            handler() {
                // Debounce validation when columns change
                this.validateDSDDebounce();
            }
        }*/
    },
    methods: {
        clearColumnSearch: function() {
            this.column_search = '';
        },
        clearDsdSaveError: function() {
            this.dsdSaveError = null;
        },
        formatObservationKeyColumns: function(cols) {
            if (!cols || !cols.length) {
                return '';
            }
            return cols.map(function(kc) {
                var nm = (kc && kc.dsd_name) ? String(kc.dsd_name) : '';
                var tp = (kc && kc.column_type) ? String(kc.column_type) : '';
                return tp ? (nm + ' (' + tp + ')') : nm;
            }).join(', ');
        },
        dsdSplitClampWidth: function(w) {
            var root = this.$refs.dsdSplitRoot;
            var rootW = (root && root.getBoundingClientRect().width) || 960;
            var gutter = 8;
            var minW = 220;
            var maxW = Math.max(minW + 100, Math.floor(rootW * 0.62) - gutter);
            return Math.min(maxW, Math.max(minW, w));
        },
        dsdSplitDragEnd: function(persist) {
            if (this._dsdSplitMove) {
                document.removeEventListener('mousemove', this._dsdSplitMove);
            }
            if (this._dsdSplitUp) {
                document.removeEventListener('mouseup', this._dsdSplitUp);
            }
            this._dsdSplitMove = null;
            this._dsdSplitUp = null;
            if (document.body) {
                document.body.style.cursor = '';
                document.body.style.userSelect = '';
            }
            if (persist) {
                try {
                    localStorage.setItem('indicator_dsd_list_width_px', String(this.dsdSplitListWidth));
                } catch (err) { /* ignore */ }
            }
        },
        onDsdSplitMouseDown: function(e) {
            if (e.button !== 0) {
                return;
            }
            e.preventDefault();
            this.dsdSplitDragEnd(false);
            var vm = this;
            var startX = e.clientX;
            var startW = vm.dsdSplitListWidth;
            var onMove = function(ev) {
                vm.dsdSplitListWidth = vm.dsdSplitClampWidth(startW + (ev.clientX - startX));
            };
            var onUp = function() {
                vm.dsdSplitDragEnd(true);
            };
            vm._dsdSplitMove = onMove;
            vm._dsdSplitUp = onUp;
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
            document.body.style.cursor = 'col-resize';
            document.body.style.userSelect = 'none';
        },
        nudgeDsdSplit: function(delta) {
            this.dsdSplitListWidth = this.dsdSplitClampWidth(this.dsdSplitListWidth + delta);
            try {
                localStorage.setItem('indicator_dsd_list_width_px', String(this.dsdSplitListWidth));
            } catch (err) { /* ignore */ }
        },
        /** Populate dsdSaveError from axios error (REST message + errors array). */
        applyDsdSaveErrorFromResponse: function(error) {
            var data = error.response && error.response.data;
            var msg = data && data.message != null ? String(data.message) : '';
            if (!msg && error.message) {
                msg = String(error.message);
            }
            var errs = [];
            if (data && Array.isArray(data.errors)) {
                errs = data.errors.filter(function(x) {
                    return x != null && String(x).trim() !== '';
                }).map(function(x) {
                    return String(x);
                });
            }
            if (!msg && errs.length === 0) {
                msg = this.$t('request_failed') || 'Request failed.';
            }
            this.dsdSaveError = { message: msg, errors: errs };
            this.dsdMainTab = 0;
        },
        fetchGlobalCodelists: function() {
            var vm = this;
            vm.globalCodelistsLoading = true;
            axios.get(CI.base_url + '/api/codelists', { params: { limit: 500, order_by: 'name', order_dir: 'ASC' } })
                .then(function(res) {
                    var data = res.data || {};
                    vm.globalCodelistsList = Array.isArray(data.codelists) ? data.codelists : [];
                })
                .catch(function() {
                    vm.globalCodelistsList = [];
                })
                .then(function() {
                    vm.globalCodelistsLoading = false;
                });
        },
        loadColumns: async function() {
            this.loading = true;
            const vm = this;
            let url = CI.base_url + '/api/indicator_dsd/' + vm.dataset_id + '?detailed=1';

            try {
                let response = await axios.get(url);
                if (response.data && response.data.columns) {
                    vm.columns = response.data.columns;
                }
                if (response.data && response.data.dictionaries) {
                    var d = response.data.dictionaries;
                    vm.dsdDictionaries = {
                        time_period_formats: Array.isArray(d.time_period_formats) ? d.time_period_formats : [],
                        freq_codes: Array.isArray(d.freq_codes) ? d.freq_codes : []
                    };
                }
            } catch (error) {
                console.log("Error loading columns", error);
                EventBus.$emit('onFail', 'Failed to load columns');
            } finally {
                this.loading = false;
            }
        },
        editColumn: function(index) {
            this.exitEditMode();
            this.is_navigating = true;
            var vm = this;
            this.$nextTick().then(() => {
                vm.page_action = "edit";
                vm.has_clicked_edit = true;
                vm.edit_item = index;
                vm.$forceUpdate();

                // Clone column_copy AFTER the edit component's created() has run
                // and added any missing defaults — prevents false-positive change detection.
                vm.$nextTick(function() {
                    vm.column_copy = _.cloneDeep(vm.columns[index]);
                    setTimeout(function() {
                        vm.has_clicked_edit = false;
                        vm.is_navigating = false;
                    }, 50);
                });
            });
        },
        editColumnByColumn: function(column) {
            // Find the index of the column in the original columns array
            const index = this.columns.findIndex(col => col.id === column.id);
            if (index !== -1) {
                this.editColumn(index);
            }
        },
        addColumn: function() {
            this.column_search = "";
            this.page_action = "edit";

            let url = CI.base_url + '/api/indicator_dsd/' + this.dataset_id;
            let new_column = {
                "name": this.$t("untitled") || "Untitled",
                "label": this.$t("untitled") || "Untitled",
                "description": "",
                "data_type": "string",
                "column_type": "dimension",
                "time_period_format": null,
                "code_list": null,
                "code_list_reference": null,
                "metadata": {},
                "codelist_type": "none",
                "global_codelist_id": null,
                "local_codelist_id": null,
                "sort_order": this.columns.length
            };

            axios.post(url, new_column)
                .then((response) => {
                    if (response.data && response.data.id) {
                        this.clearDsdSaveError();
                        new_column.id = response.data.id;
                        this.columns.push(new_column);
                        let newIdx = this.columns.length - 1;
                        this.editColumn(newIdx);
                        EventBus.$emit('onSuccess', 'Column created!');
                        this.validateDSDDebounce();
                    }
                })
                .catch((error) => {
                    console.log("error creating column", error);
                    this.applyDsdSaveErrorFromResponse(error);
                });
        },
        importColumns: function() {
            this.$router.push('/indicator-dsd-import');
        },
        populateLocalCodelistsFromData: async function() {
            if (!confirm(this.$t('populate_local_codelists_confirm') || 'Refresh local codelists from data for all fields set to "Local" vocabulary?')) {
                return;
            }
            const vm = this;
            vm.isPopulatingCodeLists = true;
            const url = CI.base_url + '/api/indicator_dsd/populate_local_codelists/' + vm.dataset_id;
            try {
                const response = await axios.post(url);
                const data = response.data || {};
                if (data.status === 'success' || data.status === 'partial') {
                    const msg = data.updated !== undefined
                        ? ((vm.$t('local_codelists_populated') || 'Local codelists updated') + ': ' + data.updated + ' ' + (vm.$t('fields') || 'fields'))
                        : (vm.$t('local_codelists_populated') || 'Local codelists updated');
                    EventBus.$emit('onSuccess', msg);
                    if (data.warnings && data.warnings.length > 0) {
                        console.warn('Populate local codelists warnings:', data.warnings);
                    }
                    if (data.skipped && data.skipped.length > 0) {
                        console.log('Populate local codelists skipped:', data.skipped);
                    }
                    if (data.errors && data.errors.length > 0) {
                        console.warn('Populate local codelists errors:', data.errors);
                    }
                    await vm.loadColumns();
                } else {
                    EventBus.$emit('onFail', data.message || (vm.$t('populate_local_codelists_failed') || 'Failed to populate local codelists'));
                }
            } catch (error) {
                const msg = (error.response && error.response.data && error.response.data.message) || error.message || (vm.$t('populate_local_codelists_failed') || 'Failed to populate local codelists');
                EventBus.$emit('onFail', msg);
            } finally {
                vm.isPopulatingCodeLists = false;
            }
        },
        refreshSumStatsFromData: async function() {
            if (!confirm(this.$t('sum_stats_refresh_confirm') || 'Compute column statistics from data and save to each DSD field? This can take a moment on large datasets.')) {
                return;
            }
            const vm = this;
            vm.isRefreshingSumStats = true;
            const url = CI.base_url + '/api/indicator_dsd/sum_stats_refresh/' + vm.dataset_id;
            try {
                const response = await axios.post(url, {}, {
                    headers: { 'Content-Type': 'application/json' }
                });
                const data = response.data || {};
                if (data.status === 'success') {
                    let msg = vm.$t('sum_stats_refreshed') || 'Column statistics updated';
                    if (data.updated_columns != null) {
                        msg += ' (' + data.updated_columns + ' ' + (vm.$t('columns') || 'columns') + ')';
                    }
                    if (data.not_found_in_timeseries && data.not_found_in_timeseries.length > 0) {
                        console.warn('sum_stats: not in timeseries:', data.not_found_in_timeseries);
                        msg += ' — ' + (vm.$t('sum_stats_some_missing_in_data') || 'some fields not found in data');
                    }
                    EventBus.$emit('onSuccess', msg);
                    await vm.loadColumns();
                } else {
                    EventBus.$emit('onFail', data.message || (vm.$t('sum_stats_refresh_failed') || 'Failed to refresh column statistics'));
                }
            } catch (error) {
                const msg = (error.response && error.response.data && error.response.data.message) || error.message || (vm.$t('sum_stats_refresh_failed') || 'Failed to refresh column statistics');
                EventBus.$emit('onFail', msg);
            } finally {
                vm.isRefreshingSumStats = false;
            }
        },
        /** After DSD time/FREQ changes, refresh DuckDB _ts_year / _ts_freq on timeseries (no-op if table missing). */
        queueRecomputeTsDerivedSilent: function() {
            var vm = this;
            var url = CI.base_url + '/api/indicator_dsd/data_recompute/' + vm.dataset_id;
            axios.post(url, {}, { headers: { 'Content-Type': 'application/json' } })
                .then(function() {
                    /* Success is silent; job runs in FastAPI. Use duckdb_job poll if UI needs completion. */
                })
                .catch(function(err) {
                    var st = err.response && err.response.status;
                    if (st === 404) {
                        return;
                    }
                    if (err.response && err.response.data && err.response.data.message) {
                        if (st === 400 && /nothing to recompute/i.test(String(err.response.data.message))) {
                            return;
                        }
                    }
                });
        },
        saveColumnDebounce: _.debounce(function() {
            // Save current column from state so we always send latest (e.g. metadata.value_label_column)
            if (this.edit_item !== null && this.columns[this.edit_item]) {
                this.saveColumn(this.columns[this.edit_item]);
            }
        }, 300),
        validateDSDDebounce: _.debounce(function() {
            if (this.columns.length > 0) {
                this.validateDSD();
            }
        }, 1000),
        saveColumn: function(data) {
            const vm = this;
            // Use current column from state so value_label_column and all edits are included
            var col = (vm.edit_item !== null && vm.columns[vm.edit_item]) ? vm.columns[vm.edit_item] : data;
            let url = CI.base_url + '/api/indicator_dsd/update/' + vm.dataset_id + '/' + col.id;

            const updateData = _.cloneDeep(col);
            if (updateData.hasOwnProperty('sort_order')) {
                delete updateData.sort_order;
            }
            // Build metadata from current column (full replace on server)
            var meta = col.metadata;
            var hideValueLabelColumn = col.column_type === 'time_period' || col.column_type === 'observation_value';
            updateData.metadata = {};
            if (meta && typeof meta === 'object' && !Array.isArray(meta)) {
                for (var k in meta) {
                    if (!Object.prototype.hasOwnProperty.call(meta, k)) {
                        continue;
                    }
                    if (hideValueLabelColumn && k === 'value_label_column') {
                        continue;
                    }
                    updateData.metadata[k] = meta[k];
                }
            }
            if (!hideValueLabelColumn) {
                var val = (meta && meta.hasOwnProperty('value_label_column')) ? meta.value_label_column : '';
                updateData.metadata.value_label_column = val != null ? String(val) : '';
            }

            axios.post(url, updateData, {
                headers: { 'Content-Type': 'application/json' }
            })
                .then(function (response) {
                    vm.clearDsdSaveError();
                    EventBus.$emit('onSuccess', 'Column saved!');
                    // Update column_copy only after successful save
                    if (vm.edit_item !== null) {
                        vm.column_copy = _.cloneDeep(vm.columns[vm.edit_item]);
                    }
                    var ct = col.column_type;
                    if (ct === 'time_period' || ct === 'periodicity') {
                        vm.queueRecomputeTsDerivedSilent();
                    }
                    vm.validateDSDDebounce();
                })
                .catch(function (error) {
                    console.log(error);
                    vm.applyDsdSaveErrorFromResponse(error);
                });
        },
        deleteColumn: function() {
            const ids = this.selectedColumns.length > 0 ? this.selectedColumns : 
                       (this.edit_item !== null ? [this.columns[this.edit_item].id] : []);
            
            if (ids.length === 0) {
                return;
            }

            const confirmMessage = ids.length === 1 
                ? (this.$t("confirm_delete_column") || "Are you sure you want to delete this column?")
                : (this.$t("confirm_delete_columns") || `Are you sure you want to delete ${ids.length} columns?`);
            
            if (!confirm(confirmMessage)) {
                return;
            }

            const vm = this;
            let url = CI.base_url + '/api/indicator_dsd/delete/' + vm.dataset_id;
            axios.post(url, { sid: vm.dataset_id, ids: ids })
                .then(function (response) {
                    // Remove deleted columns from array (in reverse order to maintain indices)
                    const deletedIds = new Set(ids);
                    for (let i = vm.columns.length - 1; i >= 0; i--) {
                        if (deletedIds.has(vm.columns[i].id)) {
                            vm.columns.splice(i, 1);
                        }
                    }
                    
                    // Clear selection and reset edit state
                    vm.selectedColumns = [];
                    if (deletedIds.has(vm.columns[vm.edit_item]?.id)) {
                        vm.edit_item = null;
                        vm.page_action = "list";
                    }
                    
                    EventBus.$emit('onSuccess', ids.length === 1 ? 'Column deleted!' : `${ids.length} columns deleted!`);
                })
                .catch(function (error) {
                    console.log("error deleting column", error);
                    if (error.response && error.response.data && error.response.data.message) {
                        alert((vm.$t("error_deleting_columns") || "Error deleting columns") + ": " + error.response.data.message);
                    } else {
                        alert(vm.$t("error_deleting_columns") || "Error deleting columns");
                    }
                });
        },
        toggleColumnSelection: function(columnId) {
            const index = this.selectedColumns.indexOf(columnId);
            if (index > -1) {
                this.selectedColumns.splice(index, 1);
            } else {
                this.selectedColumns.push(columnId);
            }
        },
        isColumnSelected: function(columnId) {
            return this.selectedColumns.indexOf(columnId) > -1;
        },
        toggleSelectAll: function() {
            if (this.allColumnsSelected) {
                this.selectedColumns = [];
            } else {
                this.selectedColumns = this.filteredColumns.map(col => col.id);
            }
        },
        toggleSortOrder: function() {
            this.sortOrder = this.sortOrder === 'asc' ? 'desc' : 'asc';
        },
        exitEditMode: function() {
            if (this.edit_item === null) {
                return;
            }

            this.page_action = "list";
            this.edit_item = null;
        },
        hasDataChanged: function() {
            if (this.edit_item === null) return false;
            return JSON.stringify(this.columns[this.edit_item]) !== JSON.stringify(this.column_copy);
        },
        colNavigate: function(direction) {
            const total_cols = this.columns.length - 1;
            this.is_navigating = true;

            switch (direction) {
                case 'first':
                    this.edit_item = 0;
                    break;
                case 'prev':
                    if (this.edit_item > 0) {
                        this.edit_item = this.edit_item - 1;
                    }
                    break;
                case 'next':
                    if (this.edit_item < total_cols) {
                        this.edit_item = this.edit_item + 1;
                    }
                    break;
                case 'last':
                    this.edit_item = total_cols;
                    break;
            }

            this.editColumn(this.edit_item);

            // Reset the navigation flag after a short delay
            setTimeout(() => {
                this.is_navigating = false;
            }, 100);
        },
        columnActiveClass: function(idx, column) {
            if (!column || !column.id) {
                return '';
            }

            let classes = [];
            
            // Check if this column is the active one by comparing IDs
            if (this.isColumnActive(column)) {
                classes.push('activeRow');
            }

            return classes.join(' ');
        },
        getActiveColumnId: function() {
            if (this.edit_item !== null && this.columns[this.edit_item]) {
                return this.columns[this.edit_item].id;
            }
            return null;
        },
        isColumnActive: function(column) {
            if (!column || !column.id) {
                return false;
            }
            const activeId = this.getActiveColumnId();
            return activeId !== null && activeId === column.id;
        },
        hasEmptyName: function(column) {
            return !column || !column.name || String(column.name).trim() === '';
        },
        getRowStyle: function(column) {
            let style = 'cursor: pointer; border-bottom: 1px solid #e0e0e0;';
            
            // Check if this column is the active one by comparing IDs
            if (this.isColumnActive(column)) {
                style += ' background-color: #1f2d3d !important; color: white !important;';
            }
            
            return style;
        },
        OnColumnUpdate: function(column) {
            if (this.edit_item === null || this.is_navigating) {
                return;
            }
            Vue.set(this.columns, this.edit_item, column);
            if (column && column.id) {
                this.saveColumnDebounce();
            }
        },
        OnValueLabelColumnChange: function(newValue) {
            if (this.edit_item === null) return;
            var col = this.columns[this.edit_item];
            if (!col) return;
            if (!col.metadata) {
                Vue.set(col, 'metadata', {});
            }
            Vue.set(col.metadata, 'value_label_column', newValue == null ? '' : String(newValue));
            if (col.id && this.columnHasChanges(col)) {
                this.saveColumnDebounce();
            }
        },
        columnHasChanges: function(column) {
            if (!column || !this.column_copy) return false;
            var a = JSON.stringify(column);
            var b = JSON.stringify(this.column_copy);
            return a !== b;
        },
        validateDSD: async function(autoExpand = false) {
            this.isValidating = true;
            this.validationResult = null;
            const vm = this;
            let url = CI.base_url + '/api/indicator_dsd/validate/' + vm.dataset_id;

            try {
                let response = await axios.get(url);
                // Handle success response (200) - validation always returns 200
                if (response.data) {
                    vm.validationResult = response.data;
                    if (autoExpand) {
                        vm.$nextTick(() => {
                            vm.dsdMainTab = 1;
                        });
                    }
                }
            } catch (error) {
                // This is an actual error (network, server error, etc.)
                console.error("Validation error:", error);
                EventBus.$emit('onFail', 'Failed to validate data structure: ' + (error.response?.data?.message || error.message));
            } finally {
                this.isValidating = false;
            }
        },
        getColumnTypeColor: function(type) {
            const colors = {
                'geography': 'blue darken-1',
                'time_period': 'green darken-1',
                'indicator_id': 'deep-purple',
                'observation_value': 'purple darken-1',
                'dimension': 'teal darken-1',
                'attribute': 'cyan darken-1',
                'annotation': 'blue-grey',
                'periodicity': 'amber darken-2',
                'indicator_name': 'indigo',
                'measure': 'brown'
            };
            return colors[type] || 'blue-grey darken-1';
        },
        /** Time row needs format + constant FREQ when no DSD column is typed as periodicity (FREQ from data). */
        timePeriodRowNeedsConstantFreqSetup: function(column) {
            if (!column || column.column_type !== 'time_period') {
                return false;
            }
            var hasFreqCol = (this.columns || []).some(function(c) {
                return c && c.column_type === 'periodicity' && c.name && String(c.name).trim() !== '';
            });
            if (hasFreqCol) {
                return false;
            }
            var fmt = column.time_period_format;
            var meta = column.metadata || {};
            var ifc = meta.freq;
            if (ifc == null || String(ifc).trim() === '') {
                ifc = meta.import_freq_code;
            }
            var missingFmt = fmt == null || String(fmt).trim() === '';
            var missingFreq = ifc == null || String(ifc).trim() === '';
            return missingFmt || missingFreq;
        }
    },
    computed: {
        ProjectID() {
            return this.$store.state.project_id;
        },
        activeColumn: function() {
            if (this.edit_item !== null && this.columns[this.edit_item]) {
                return this.columns[this.edit_item];
            }
            return null;
        },
        filteredColumns: function() {
            let filtered = this.columns;
            if (this.column_search !== '') {
                filtered = filtered.filter((item) => {
                    return (item.name + (item.label || ''))
                        .toUpperCase()
                        .includes(this.column_search.toUpperCase());
                });
            }
            return filtered;
        },
        /** SDMX-oriented list groups (see docs/dsd-codelists-model.md §11) */
        groupedColumns: function() {
            var list = this.filteredColumns;
            var orderIndicator = ['indicator_id', 'indicator_name'];
            var orderTime = ['time_period', 'periodicity'];
            var orderCore = ['observation_value', 'geography'];
            var indicator = [], time = [], core = [], dimensions = [], attributes = [], annotations = [], others = [];
            list.forEach(function(col) {
                var t = col.column_type;
                if (orderIndicator.indexOf(t) >= 0) indicator.push(col);
                else if (orderTime.indexOf(t) >= 0) time.push(col);
                else if (orderCore.indexOf(t) >= 0) core.push(col);
                else if (t === 'dimension' || t === 'measure') dimensions.push(col);
                else if (t === 'attribute') attributes.push(col);
                else if (t === 'annotation') annotations.push(col);
                else others.push(col);
            });
            function sortByOrder(arr, order) {
                arr.sort(function(a, b) {
                    return order.indexOf(a.column_type) - order.indexOf(b.column_type);
                });
            }
            sortByOrder(indicator, orderIndicator);
            sortByOrder(time, orderTime);
            sortByOrder(core, orderCore);
            var groups = [];
            var t = this.$t.bind(this);
            if (indicator.length) {
                groups.push({ groupKey: 'indicator', groupLabel: t('dsd_group_indicator') || 'Indicator', columns: indicator });
            }
            groups.push({
                groupKey: 'time',
                groupLabel: t('dsd_group_time_period') || 'Time period',
                columns: time,
                showEmptyHint: time.length === 0
            });
            if (core.length) {
                groups.push({ groupKey: 'core', groupLabel: t('dsd_group_core') || 'Core fields', columns: core });
            }
            if (dimensions.length) {
                groups.push({ groupKey: 'dimensions', groupLabel: t('dsd_group_dimensions') || 'Dimensions', columns: dimensions });
            }
            if (attributes.length) {
                groups.push({ groupKey: 'attributes', groupLabel: t('dsd_group_attributes') || 'Attributes', columns: attributes });
            }
            if (annotations.length) {
                groups.push({ groupKey: 'annotations', groupLabel: t('dsd_group_annotations') || 'Annotations', columns: annotations });
            }
            if (others.length) {
                groups.push({ groupKey: 'others', groupLabel: t('dsd_group_others') || 'Others', columns: others });
            }
            return groups;
        },
        hasGroupedColumns: function() {
            return this.filteredColumns.length > 0;
        },
        allColumnsSelected: function() {
            return this.filteredColumns.length > 0 && 
                   this.filteredColumns.every(col => this.selectedColumns.indexOf(col.id) > -1);
        },
        someColumnsSelected: function() {
            return this.selectedColumns.length > 0 && !this.allColumnsSelected;
        },
        selectedColumnsCount: function() {
            return this.selectedColumns.length;
        },
        validationStatus: function() {
            if (!this.validationResult) {
                return null; // No validation run yet
            }
            
            const hasErrors = this.validationResult.errors && this.validationResult.errors.length > 0;
            const hasWarnings = this.validationResult.warnings && this.validationResult.warnings.length > 0;
            
            if (hasErrors) {
                return { color: 'error', icon: 'mdi-alert-circle', status: 'error' };
            } else if (hasWarnings) {
                return { color: 'warning', icon: 'mdi-alert', status: 'warning' };
            } else {
                return { color: 'success', icon: 'mdi-check', status: 'success' };
            }
        },
        validationTabErrorCount: function() {
            if (!this.validationResult || !Array.isArray(this.validationResult.errors)) {
                return 0;
            }
            return this.validationResult.errors.length;
        },
        validationTabWarningCount: function() {
            if (!this.validationResult || !Array.isArray(this.validationResult.warnings)) {
                return 0;
            }
            return this.validationResult.warnings.length;
        },
        /** One-line column summary after validation (e.g. "Columns: 11 · geography: 1, time_period: 1") */
        validationSummaryLine: function() {
            var vr = this.validationResult;
            if (!vr || !vr.summary) {
                return '';
            }
            var n = vr.summary.total_columns;
            var by = vr.summary.by_type;
            var t = this.$t.bind(this);
            var base = (t('columns') || 'Columns') + ': ' + (n != null ? n : '—');
            if (!by || typeof by !== 'object') {
                return base;
            }
            var parts = [];
            Object.keys(by).sort().forEach(function(k) {
                parts.push(k + ': ' + by[k]);
            });
            return parts.length ? base + ' · ' + parts.join(', ') : base;
        },
        validationStructureSection: function() {
            var vr = this.validationResult;
            if (!vr || !vr.structure) {
                return null;
            }
            return vr.structure;
        },
        validationDataSection: function() {
            var vr = this.validationResult;
            if (!vr || !vr.data_validation) {
                return null;
            }
            return vr.data_validation;
        }
    },
    beforeDestroy: function() {
        this.dsdSplitDragEnd(false);
    },
    template: `
        <div class="indicator-dsd-component" style="display: flex; flex-direction: column; height: calc(100vh - 120px); min-height: 0;overflow:hidden;padding:10px;background:white;">
            <!-- Page Title and Actions -->
            <v-card class="mb-2 m-2 p-2" flat>
                <v-card-title class="d-flex justify-space-between align-center">
                    <div class="d-flex align-center">
                        <div>
                            <h4 class="mb-0 d-inline">{{$t("data_structure_definition") || "Data Structure Definition"}}</h4>
                            <small class="text-muted ml-2" v-if="columns.length > 0">{{columns.length}} {{$t("columns") || "columns"}}</small>
                        </div>
                        <!-- Validation status indicator from API -->
                        <v-icon 
                            v-if="validationStatus"
                            :color="validationStatus.color"
                            class="ml-3"
                            small
                        >
                            {{validationStatus.icon}}
                        </v-icon>
                    </div>
                    <div>
                        <v-btn
                            color="primary"
                            class="mr-2"
                            @click="refreshSumStatsFromData"
                            :loading="isRefreshingSumStats"
                            :disabled="columns.length === 0"
                            small
                        >
                            <v-icon left small>mdi-chart-box-outline</v-icon>
                            {{ $t('sum_stats_refresh') || 'Refresh Stats' }}
                        </v-btn>
                        <v-btn 
                            v-if="typeof dsd_temporary_features_enabled !== 'undefined' && dsd_temporary_features_enabled"
                            color="primary" 
                            class="ml-2"
                            @click="populateLocalCodelistsFromData"
                            :loading="isPopulatingCodeLists"
                            :disabled="columns.length === 0"
                            small
                        >
                            <v-icon left small>mdi-database-import</v-icon>
                            {{ $t('populate_local_codelists') || 'Refresh Codelists' }}
                        </v-btn>
                        <v-btn 
                            v-if="typeof dsd_temporary_features_enabled !== 'undefined' && dsd_temporary_features_enabled"
                            color="primary"
                            class="ml-2"
                            @click="importColumns"
                            small
                        >
                            <v-icon left small>mdi-upload</v-icon>
                            {{$t("import") || "Import"}}
                        </v-btn>
                    </div>
                </v-card-title>
            </v-card>

            <v-tabs v-model="dsdMainTab" class="flex-shrink-0 mx-2 mt-1" background-color="transparent" show-arrows>
                <v-tab>{{ $t('structure') || 'Structure' }}</v-tab>
                <v-tab>
                    <span class="d-flex align-center">
                        {{ $t('validation') || 'Validation' }}
                        <v-chip
                            v-if="validationTabErrorCount > 0"
                            small
                            label
                            class="ml-2"
                            color="error"
                            text-color="white"
                        >{{ validationTabErrorCount }}</v-chip>
                        <v-chip
                            v-else-if="validationTabWarningCount > 0"
                            small
                            label
                            class="ml-2"
                            color="warning"
                            text-color="white"
                        >{{ validationTabWarningCount }}</v-chip>
                        <v-icon v-else-if="validationResult && validationResult.valid" small class="ml-1" color="success">mdi-check</v-icon>
                    </span>
                </v-tab>
            </v-tabs>

            <v-tabs-items v-model="dsdMainTab" class="flex-grow-1 dsd-main-tabs-items fill-height" style="min-height: 0; overflow: hidden; display: flex; flex-direction: column;">
                <v-tab-item class="dsd-tab-structure fill-height" eager>
                    <div class="d-flex flex-column flex-grow-1 fill-height" style="min-height: 0;">
                        <v-alert
                            v-if="dsdSaveError"
                            type="error"
                            outlined
                            dense
                            class="flex-shrink-0 ma-4"
                            style="border-width: 2px;"
                        >
                            <div class="d-flex align-start" style="gap: 8px;">
                                <div class="flex-grow-1" style="min-width: 0;">
                                    <div class="text-subtitle-2 font-weight-medium">
                                        {{ $t('could_not_save') || 'Could not save' }}
                                    </div>
                                    <ul
                                        v-if="dsdSaveError.errors && dsdSaveError.errors.length"
                                        class="mt-2 mb-0 pl-4"
                                        style="list-style-type: disc;"
                                    >
                                        <li v-for="(err, idx) in dsdSaveError.errors" :key="'dsd-save-err-' + idx" class="text-body-2">
                                            {{ err }}
                                        </li>
                                    </ul>
                                    <div v-else-if="dsdSaveError.message" class="mt-2 mb-0 text-body-2">
                                        {{ dsdSaveError.message }}
                                    </div>
                                </div>
                                <v-btn
                                    icon
                                    small
                                    class="flex-shrink-0 mt-n1"
                                    :aria-label="$t('close') || 'Close'"
                                    @click="clearDsdSaveError"
                                >
                                    <v-icon>mdi-close</v-icon>
                                </v-btn>
                            </div>
                        </v-alert>
            <!-- Two Column Layout (resizable split) -->
            <div ref="dsdSplitRoot" style="display: flex; flex: 1; min-height: 0; gap: 0; overflow: hidden; background: rgb(240 240 240);" class="m-2 elevation-2 indicator-dsd-split-root">
                <!-- Left Column: column list -->
                <div :style="{ flex: '0 0 ' + dsdSplitListWidth + 'px', width: dsdSplitListWidth + 'px', minWidth: dsdSplitListWidth + 'px', maxWidth: dsdSplitListWidth + 'px', display: 'flex', flexDirection: 'column', border: '1px solid #e0e0e0', borderRadius: '4px', overflow: 'hidden' }">
                    <!-- Search Header -->
                    <div class="pa-1" style="border-bottom: 1px solid #e0e0e0; background: #fff;">
                        <div class="d-flex align-center" style="gap: 8px;">
                            <!-- Search Box -->
                            <v-text-field
                                v-model="column_search"
                                :placeholder="$t('search') || 'Search...'"
                                prepend-inner-icon="mdi-magnify"
                                clearable
                                dense
                                single-line
                                hide-details
                                flat
                                solo
                                style="flex: 1; background-color: transparent !important;"
                                class="mt-0"
                            ></v-text-field>
                            
                            
                            
                        </div>
                    </div>
                    
                    <!-- Actions Header Row -->
                    <div class="pa-1" style="border-bottom: 1px solid #e0e0e0; background: #fff;">
                        <div class="d-flex align-center" style="gap: 8px;">
                            <!-- Batch Selection Checkbox -->
                            <v-checkbox
                                :input-value="allColumnsSelected"
                                :indeterminate="someColumnsSelected"
                                @change="toggleSelectAll"
                                hide-details
                                class="mt-0 ml-3"
                                dense
                            ></v-checkbox>
                            
                            <!-- Delete Icon -->
                            <v-btn
                                icon
                                small
                                @click="deleteColumn"
                                :disabled="selectedColumnsCount === 0"
                                color="error"
                                class="mt-0"
                            >
                                <v-icon small>mdi-delete</v-icon>
                            </v-btn>
                            
                            <!-- Spacer to push refresh icon to the right -->
                            <v-spacer></v-spacer>
                            
                            <!-- Refresh Icon -->
                            <v-btn
                                icon
                                small
                                @click="loadColumns"
                                :loading="loading"
                                class="mt-0"
                            >
                                <v-icon small>mdi-refresh</v-icon>
                            </v-btn>
                        </div>
                    </div>

                    <!-- Columns List -->
                    <div style="flex: 1; overflow-y: auto; background: white;padding-bottom:50px;">
                        <div v-if="loading" class="pa-4 text-center">
                            <v-progress-circular indeterminate color="primary"></v-progress-circular>
                            <div class="mt-2">{{$t("loading") || "Loading"}}...</div>
                        </div>
                        <div v-else-if="!hasGroupedColumns" class="pa-4 text-center text-muted">
                            {{$t("no_columns_found") || "No columns found"}}
                        </div>
                        <v-list v-else dense>
                            <template v-for="group in groupedColumns">
                                <v-subheader :key="'h-' + group.groupKey" class="font-weight-bold text-uppercase" style="height: 36px;">
                                    {{group.groupLabel}}
                                </v-subheader>
                                <div
                                    v-if="group.groupKey === 'time' && group.showEmptyHint"
                                    :key="'hint-' + group.groupKey + '-empty'"
                                    class="px-4 pb-2 text-caption text--secondary"
                                >
                                    {{ $t('dsd_time_period_empty_hint') || 'Add a column and set its type to Time period, or map time on import.' }}
                                </div>
                                <v-list-item
                                    v-for="column in group.columns"
                                    :key="column.id"
                                    @click="editColumnByColumn(column)"
                                    :class="columnActiveClass(0, column)"
                                    :style="getRowStyle(column)"
                                >
                                <v-list-item-action class="mr-2" @click.stop>
                                    <v-checkbox
                                        :input-value="isColumnSelected(column.id)"
                                        @change="toggleColumnSelection(column.id)"
                                        @click.stop
                                        hide-details
                                        color="primary"
                                    ></v-checkbox>
                                </v-list-item-action>
                                <v-list-item-avatar size="32" class="mr-2">
                                    <v-icon 
                                        v-if="column.data_type=='string'"
                                        color="primary"
                                    >mdi-alpha-a-box-outline</v-icon>
                                    <v-icon 
                                        v-else-if="column.data_type=='integer' || column.data_type=='float' || column.data_type=='double'"
                                        color="primary"
                                    >mdi-numeric-1-box-outline</v-icon>
                                    <v-icon 
                                        v-else-if="column.data_type=='date'"
                                        color="primary"
                                    >mdi-calendar</v-icon>
                                    <v-icon 
                                        v-else-if="column.data_type=='boolean'"
                                        color="primary"
                                    >mdi-checkbox-marked</v-icon>
                                    <v-icon 
                                        v-else
                                        color="grey"
                                    >mdi-help-circle</v-icon>
                                </v-list-item-avatar>
                                <v-list-item-content>
                                    <v-list-item-title class="d-flex flex-wrap align-center" style="gap: 6px;">
                                        <span class="font-weight-medium">{{ column.name }}</span>
                                        <v-chip
                                            v-if="timePeriodRowNeedsConstantFreqSetup(column)"
                                            x-small
                                            label
                                            class="ma-0"
                                            color="amber darken-2"
                                            text-color="black"
                                        >
                                            {{ $t('dsd_time_freq_incomplete') || 'Set time format & FREQ' }}
                                        </v-chip>
                                    </v-list-item-title>
                                    <v-list-item-subtitle v-if="column.label">
                                        {{ column.label }}
                                    </v-list-item-subtitle>
                                </v-list-item-content>
                                <v-list-item-action v-if="hasEmptyName(column)">
                                    <v-icon 
                                        color="warning" 
                                        small
                                        :title="$t('column_name_empty') || 'Column name is empty'"
                                    >
                                        mdi-alert
                                    </v-icon>
                                </v-list-item-action>
                                <v-list-item-action>
                                    <v-chip
                                        x-small
                                        label
                                        class="ma-0 font-weight-medium white--text text-capitalize"
                                        :color="getColumnTypeColor(column.column_type)"
                                    >
                                        {{ column.column_type }}
                                    </v-chip>
                                </v-list-item-action>
                                </v-list-item>
                            </template>
                        </v-list>
                    </div>
                    
                    <!-- Footer: Add column -->
                    <div class="pa-1" style="border-top: 1px solid #e0e0e0; background: #fff;">
                        <v-btn
                            small
                            color="primary"
                            @click="addColumn"
                            block
                            class="mt-0"
                        >
                            <v-icon left small>mdi-plus</v-icon>
                            {{$t("add_column") || "Add column"}}
                        </v-btn>
                    </div>
                </div>

                <div
                    class="indicator-dsd-split-gutter"
                    role="separator"
                    aria-orientation="vertical"
                    :aria-valuenow="dsdSplitListWidth"
                    tabindex="0"
                    :title="$t('resize_panels') || 'Drag to resize panels'"
                    @mousedown="onDsdSplitMouseDown"
                    @keydown.left.prevent="nudgeDsdSplit(-16)"
                    @keydown.right.prevent="nudgeDsdSplit(16)"
                    style="flex: 0 0 6px; width: 6px; cursor: col-resize; background: linear-gradient(to right, #dadada, #ececec); align-self: stretch; min-height: 0; outline: none;"
                ></div>

                <!-- Right Column: Edit Form -->
                <div style="flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; border: 1px solid #e0e0e0; border-radius: 4px; overflow: hidden; background: white;">
                    <!-- Edit Header -->
                    <div class="pa-2" style="border-bottom: 1px solid #e0e0e0; background: #f5f5f5;">
                        <div class="d-flex justify-space-between align-center">
                            <div>
                                <strong v-if="activeColumn">{{activeColumn.name}}</strong>                                
                            </div>
                            <div v-if="activeColumn">
                                <v-btn 
                                    icon 
                                    small 
                                    @click="colNavigate('first')"
                                    :disabled="edit_item === 0"
                                >
                                    <v-icon small>mdi-chevron-double-left</v-icon>
                                </v-btn>
                                <v-btn 
                                    icon 
                                    small 
                                    @click="colNavigate('prev')"
                                    :disabled="edit_item === 0"
                                >
                                    <v-icon small>mdi-chevron-left</v-icon>
                                </v-btn>
                                <v-btn 
                                    icon 
                                    small 
                                    @click="colNavigate('next')"
                                    :disabled="edit_item >= columns.length - 1"
                                >
                                    <v-icon small>mdi-chevron-right</v-icon>
                                </v-btn>
                                <v-btn 
                                    icon 
                                    small 
                                    @click="colNavigate('last')"
                                    :disabled="edit_item >= columns.length - 1"
                                >
                                    <v-icon small>mdi-chevron-double-right</v-icon>
                                </v-btn>
                                <v-btn 
                                    icon 
                                    small 
                                    color="error"
                                    @click="deleteColumn"
                                    class="ml-2"
                                >
                                    <v-icon small>mdi-delete</v-icon>
                                </v-btn>
                            </div>
                        </div>
                    </div>

                    <!-- Edit Form Content -->
                    <div style="flex: 1; overflow-y: auto; padding: 16px;padding-bottom:50px;">
                        <div v-if="!activeColumn" class="text-center pa-8 text-muted">
                            <v-icon size="64" color="grey lighten-1">mdi-table-column</v-icon>
                            <div class="mt-4">{{$t("no_column_selected") || "No column selected"}}</div>
                        </div>
                        <div v-else>
                            <indicator-dsd-edit 
                                :key="activeColumn && activeColumn.id ? ('dsd-edit-' + activeColumn.id) : 'dsd-edit-new'"
                                :column="activeColumn" 
                                :project-sid="dataset_id"
                                :dictionaries="dsdDictionaries"
                                :all-columns="columns"
                                :global-codelists-list="globalCodelistsList"
                                :global-codelists-loading="globalCodelistsLoading"
                                @input="OnColumnUpdate"
                                @value-label-column-change="OnValueLabelColumnChange"
                                :index_key="edit_item"
                            ></indicator-dsd-edit>
                        </div>
                    </div>
                </div>
            </div>
                    </div>
                </v-tab-item>
                <v-tab-item eager class="dsd-tab-validation">
                    <div
                        class="d-flex flex-column pa-3 ma-2"
                        style="max-height: calc(100vh - 300px); overflow-y: auto; overflow-x: hidden; background: #f5f5f5; border-radius: 8px; -webkit-overflow-scrolling: touch;"
                    >
                        <div class="d-flex align-center justify-space-between flex-wrap flex-shrink-0 mb-3" style="gap: 8px;">
                            <span class="text-h6 font-weight-medium">{{ $t('validation') || 'Validation' }}</span>
                            <v-btn
                                color="primary"
                                depressed
                                :loading="isValidating"
                                :disabled="columns.length === 0"
                                small
                                @click="validateDSD(false)"
                            >
                                <v-icon left small>mdi-refresh</v-icon>
                                {{ $t('validate') || 'Run validation' }}
                            </v-btn>
                        </div>

                        <div v-if="!validationResult && !isValidating" class="text-center pa-10">
                            <v-icon size="48" color="grey lighten-1">mdi-clipboard-text-outline</v-icon>
                            <p class="mt-3 mb-0 text-body-2 text--secondary">{{ $t('dsd_validation_tab_empty') || 'Run validation to see structure and data checks.' }}</p>
                        </div>
                        <div v-else-if="isValidating" class="text-center pa-10">
                            <v-progress-circular indeterminate color="primary" size="40"></v-progress-circular>
                            <div class="mt-3 text-body-2 text--secondary">{{ $t('loading') || 'Loading' }}…</div>
                        </div>

                        <div v-else-if="validationResult" class="d-flex flex-column" style="gap: 16px;">
                            <!-- Overall -->
                            <div
                                class="pa-3 rounded d-flex align-start"
                                style="gap: 12px; border: 1px solid rgba(0,0,0,.08);"
                                :style="validationResult.valid ? 'background: #e8f5e9; border-color: rgba(46,125,50,.25);' : 'background: #ffebee; border-color: rgba(211,47,47,.22);'"
                            >
                                <v-icon :color="validationResult.valid ? 'success' : 'error'" class="flex-shrink-0 mt-0">
                                    {{ validationResult.valid ? 'mdi-check-circle' : 'mdi-alert-circle' }}
                                </v-icon>
                                <div class="flex-grow-1" style="min-width: 0;">
                                    <div class="subtitle-1 font-weight-medium" :class="validationResult.valid ? 'success--text' : 'error--text'">
                                        {{ validationResult.valid ? ($t('validation_passed') || 'All checks passed') : ($t('validation_failed') || 'Some checks failed') }}
                                    </div>
                                    <div v-if="validationSummaryLine" class="text-caption text--secondary mt-1 text-wrap">
                                        {{ validationSummaryLine }}
                                    </div>
                                </div>
                            </div>

                            <!-- 1. Structure -->
                            <v-card v-if="validationStructureSection" outlined flat class="white" style="border-radius: 8px;">
                                <v-card-title class="py-3 subtitle-1 font-weight-medium d-flex align-center flex-wrap" style="gap: 8px; border-bottom: 1px solid rgba(0,0,0,.06);">
                                    <v-icon color="primary" class="mr-1">mdi-file-tree-outline</v-icon>
                                    {{ $t('structure') || 'Structure' }}
                                    <v-spacer></v-spacer>
                                    <v-chip
                                        small
                                        label
                                        :color="validationStructureSection.valid ? 'success' : 'error'"
                                        text-color="white"
                                        class="font-weight-medium"
                                    >
                                        <v-icon left color="white">{{ validationStructureSection.valid ? 'mdi-check-circle' : 'mdi-close-circle' }}</v-icon>
                                        {{ validationStructureSection.valid ? ($t('validation_passed') || 'Passed') : ($t('validation_failed') || 'Failed') }}
                                    </v-chip>
                                </v-card-title>
                                <v-card-text class="pt-3 pb-3">
                                    <template v-if="validationStructureSection.errors && validationStructureSection.errors.length">
                                        <div class="text-overline text--secondary mb-2">{{ $t('errors') || 'Errors' }}</div>
                                        <div
                                            v-for="(error, idx) in validationStructureSection.errors"
                                            :key="'vse-' + idx"
                                            class="d-flex align-start text-body-2 error--text mb-2"
                                            style="gap: 8px;"
                                        >
                                            <v-icon color="error" small class="flex-shrink-0 mt-1">mdi-close-circle</v-icon>
                                            <span class="text-wrap" style="min-width: 0;">{{ error }}</span>
                                        </div>
                                    </template>
                                    <template v-if="validationStructureSection.warnings && validationStructureSection.warnings.length">
                                        <div class="text-overline text--secondary mb-2 mt-3">{{ $t('warnings') || 'Warnings' }}</div>
                                        <div
                                            v-for="(warning, idx) in validationStructureSection.warnings"
                                            :key="'vsw-' + idx"
                                            class="d-flex align-start text-body-2 warning--text mb-2"
                                            style="gap: 8px;"
                                        >
                                            <v-icon color="warning" small class="flex-shrink-0 mt-1">mdi-alert</v-icon>
                                            <span class="text-wrap" style="min-width: 0;">{{ warning }}</span>
                                        </div>
                                    </template>
                                    <div
                                        v-if="validationStructureSection.valid && (!validationStructureSection.errors || !validationStructureSection.errors.length) && (!validationStructureSection.warnings || !validationStructureSection.warnings.length)"
                                        class="text-body-2 text--secondary"
                                    >
                                        {{ $t('dsd_validation_structure_ok') || 'Column roles and cardinality match the rules for this project.' }}
                                    </div>
                                </v-card-text>
                            </v-card>

                            <!-- 2. Data -->
                            <v-card v-if="validationDataSection" outlined flat class="white" style="border-radius: 8px;">
                                <v-card-title class="py-3 subtitle-1 font-weight-medium d-flex align-center flex-wrap" style="gap: 8px; border-bottom: 1px solid rgba(0,0,0,.06);">
                                    <v-icon color="primary" class="mr-1">mdi-database-check-outline</v-icon>
                                    {{ $t('dsd_validation_section_data') || 'Data validation' }}
                                    <v-spacer></v-spacer>
                                    <v-chip
                                        v-if="validationDataSection.skipped"
                                        small
                                        label
                                        color="grey"
                                        text-color="white"
                                        class="font-weight-medium"
                                    >
                                        <v-icon left color="white">mdi-minus-circle-outline</v-icon>
                                        {{ $t('skipped') || 'Skipped' }}
                                    </v-chip>
                                    <v-chip
                                        v-else
                                        small
                                        label
                                        :color="validationDataSection.valid ? 'success' : 'error'"
                                        text-color="white"
                                        class="font-weight-medium"
                                    >
                                        <v-icon left color="white">{{ validationDataSection.valid ? 'mdi-check-circle' : 'mdi-close-circle' }}</v-icon>
                                        {{ validationDataSection.valid ? ($t('validation_passed') || 'Passed') : ($t('validation_failed') || 'Failed') }}
                                    </v-chip>
                                </v-card-title>
                                <v-card-text class="pt-3 pb-3">
                                    <div v-if="validationDataSection.skipped" class="text-body-2 text--secondary">
                                        {{ validationDataSection.reason }}
                                    </div>
                                    <template v-else>
                                        <div v-if="validationDataSection.source || validationDataSection.row_count != null" class="text-caption text--secondary mb-3">
                                            <span v-if="validationDataSection.source">{{ $t('source') || 'Source' }}: <strong class="text--primary">{{ validationDataSection.source }}</strong></span>
                                            <span v-if="validationDataSection.row_count != null">
                                                <span v-if="validationDataSection.source"> · </span>{{ validationDataSection.row_count }} {{ $t('rows') || 'rows' }}
                                            </span>
                                        </div>

                                        <div
                                            v-if="validationDataSection.observation_key"
                                            class="pa-3 mb-3 rounded"
                                            style="background: rgba(0,0,0,.03); border: 1px solid rgba(0,0,0,.06);"
                                        >
                                            <div v-if="validationDataSection.observation_key.skipped" class="text-body-2 text--secondary">
                                                {{ validationDataSection.observation_key.reason }}
                                            </div>
                                            <template v-else>
                                                <div v-if="validationDataSection.observation_key.key_columns && validationDataSection.observation_key.key_columns.length" class="text-body-2 mb-2">
                                                    <span class="text--secondary">{{ $t('key') || 'Key' }}: </span>
                                                    <span class="text-wrap">{{ formatObservationKeyColumns(validationDataSection.observation_key.key_columns) }}</span>
                                                </div>
                                                <div v-if="validationDataSection.observation_key.value_column" class="text-body-2 mb-2">
                                                    <span class="text--secondary">{{ $t('value') || 'Value' }}: </span>
                                                    {{ validationDataSection.observation_key.value_column.dsd_name }}
                                                    <span class="text--secondary"> — {{ validationDataSection.observation_key.value_column.physical_name }}</span>
                                                </div>
                                                <div class="text-body-2">
                                                    <span v-if="validationDataSection.observation_key.rows_with_observation_value != null">
                                                        {{ $t('dsd_validation_rows_with_value') || 'Rows with value' }}: <strong>{{ validationDataSection.observation_key.rows_with_observation_value }}</strong>
                                                    </span>
                                                    <span v-if="validationDataSection.observation_key.unique_observation_count != null" class="ml-2">
                                                        · {{ $t('dsd_validation_unique_observations') || 'Unique keys' }}: <strong>{{ validationDataSection.observation_key.unique_observation_count }}</strong>
                                                    </span>
                                                    <span v-if="validationDataSection.observation_key.table_rows_read != null" class="ml-2 text--secondary">
                                                        · {{ $t('dsd_validation_rows_scanned') || 'Counted' }}: {{ validationDataSection.observation_key.table_rows_read }}
                                                    </span>
                                                </div>
                                                <div v-if="validationDataSection.observation_key.scan_truncated" class="text-caption warning--text mt-2">
                                                    {{ $t('dsd_validation_observation_key_truncated') || 'Counts may be incomplete (scan truncated).' }}
                                                </div>
                                            </template>
                                        </div>

                                        <template v-if="validationDataSection.errors && validationDataSection.errors.length">
                                            <div class="text-overline text--secondary mb-2">{{ $t('data_errors') || 'Data errors' }}</div>
                                            <div
                                                v-for="(error, idx) in validationDataSection.errors"
                                                :key="'vde-' + idx"
                                                class="d-flex align-start text-body-2 error--text mb-2"
                                                style="gap: 8px;"
                                            >
                                                <v-icon color="error" small class="flex-shrink-0 mt-1">mdi-close-circle</v-icon>
                                                <span class="text-wrap" style="min-width: 0;">{{ error }}</span>
                                            </div>
                                        </template>
                                        <template v-if="validationDataSection.warnings && validationDataSection.warnings.length">
                                            <div class="text-overline text--secondary mb-2 mt-3">{{ $t('data_warnings') || 'Data warnings' }}</div>
                                            <div
                                                v-for="(warning, idx) in validationDataSection.warnings"
                                                :key="'vdw-' + idx"
                                                class="d-flex align-start text-body-2 warning--text mb-2"
                                                style="gap: 8px;"
                                            >
                                                <v-icon color="warning" small class="flex-shrink-0 mt-1">mdi-alert</v-icon>
                                                <span class="text-wrap" style="min-width: 0;">{{ warning }}</span>
                                            </div>
                                        </template>
                                    </template>
                                </v-card-text>
                            </v-card>
                        </div>
                    </div>
                </v-tab-item>
            </v-tabs-items>
        </div>
    `
})
