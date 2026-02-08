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
            showValidationPanel: [], // Array for expansion panel v-model
            selectedColumns: [], // Array of selected column IDs for deletion
            sortOrder: 'asc', // asc, desc
            isPopulatingCodeLists: false
        }
    },
    created: async function() {
        await this.loadColumns();
        // Run validation after columns are loaded
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
        loadColumns: async function() {
            this.loading = true;
            const vm = this;
            let url = CI.base_url + '/api/indicator_dsd/' + vm.dataset_id + '?detailed=1';

            try {
                let response = await axios.get(url);
                if (response.data && response.data.columns) {
                    vm.columns = response.data.columns;
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
            this.$nextTick().then(() => {
                this.page_action = "edit";
                this.has_clicked_edit = true;
                this.edit_item = index;
                this.column_copy = _.cloneDeep(this.columns[index]);

                // Force Vue to update the view
                this.$forceUpdate();

                // Reset the flags after a short delay to ensure the watch doesn't trigger
                setTimeout(() => {
                    this.has_clicked_edit = false;
                    this.is_navigating = false;
                }, 100);
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
                "sort_order": this.columns.length
            };

            axios.post(url, new_column)
                .then((response) => {
                    if (response.data && response.data.id) {
                        new_column.id = response.data.id;
                        this.columns.push(new_column);
                        let newIdx = this.columns.length - 1;
                        this.editColumn(newIdx);
                        EventBus.$emit('onSuccess', 'Column created!');
                    }
                })
                .catch((error) => {
                    console.log("error creating column", error);
                    EventBus.$emit('onFail', 'Failed to create column');
                });
        },
        importColumns: function() {
            this.$router.push('/indicator-dsd-import');
        },
        populateCodeLists: async function() {

            if (!confirm('Populate code lists for all columns from data?')) {
                return;
            }

            const vm = this;
            vm.isPopulatingCodeLists = true;
            const url = CI.base_url + '/api/indicator_dsd/populate_code_lists/' + vm.dataset_id;
            try {
                const response = await axios.post(url);
                const data = response.data || {};
                if (data.status === 'success' || data.status === 'partial') {
                    const msg = data.updated !== undefined
                        ? (vm.$t('code_lists_populated') || 'Code lists populated') + ': ' + data.updated + ' ' + (vm.$t('columns_updated') || 'columns updated')
                        : (vm.$t('code_lists_populated') || 'Code lists populated');
                    EventBus.$emit('onSuccess', msg);
                    if (data.skipped && data.skipped.length > 0) {
                        console.log('Populate code lists skipped:', data.skipped);
                    }
                    if (data.errors && data.errors.length > 0) {
                        console.warn('Populate code lists errors:', data.errors);
                    }
                    await vm.loadColumns();
                } else {
                    EventBus.$emit('onFail', data.message || (vm.$t('populate_code_lists_failed') || 'Failed to populate code lists'));
                }
            } catch (error) {
                const msg = (error.response && error.response.data && error.response.data.message) || error.message || (vm.$t('populate_code_lists_failed') || 'Failed to populate code lists');
                EventBus.$emit('onFail', msg);
            } finally {
                vm.isPopulatingCodeLists = false;
            }
        },
        saveColumnDebounce: _.debounce(function(data) {
            this.saveColumn(data);
        }, 500),
        validateDSDDebounce: _.debounce(function() {
            if (this.columns.length > 0) {
                this.validateDSD();
            }
        }, 1000),
        saveColumn: function(data) {
            const vm = this;
            let url = CI.base_url + '/api/indicator_dsd/update/' + vm.dataset_id + '/' + data.id;

            // Exclude sort_order from updates - it should be managed separately
            const updateData = Object.assign({}, data);
            if (updateData.hasOwnProperty('sort_order')) {
                delete updateData.sort_order;
            }

            axios.post(url, updateData)
                .then(function (response) {
                    EventBus.$emit('onSuccess', 'Column saved!');
                    // Update column_copy only after successful save
                    if (vm.edit_item !== null) {
                        vm.column_copy = _.cloneDeep(vm.columns[vm.edit_item]);
                    }
                })
                .catch(function (error) {
                    console.log(error);
                    EventBus.$emit('onFail', 'Failed to save column');
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
            if (this.edit_item === null) {
                return;
            }
            Vue.set(this.columns, this.edit_item, column);
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
                            vm.showValidationPanel = [0]; // Expand the panel
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
                'geography': 'blue',
                'time_period': 'green',
                'indicator_id': 'purple',
                'observation_value': 'orange',
                'dimension': 'teal',
                'attribute': 'cyan',
                'annotation': 'grey',
                'periodicity': 'amber',
                'indicator_name': 'indigo'
            };
            return colors[type] || 'grey';
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
            
            // Apply search filter
            if (this.column_search !== '') {
                filtered = filtered.filter((item) => {
                    return (item.name + (item.label || ''))
                        .toUpperCase()
                        .includes(this.column_search.toUpperCase());
                });
            }
            
            // Apply sorting by sort_order
            filtered = [...filtered].sort((a, b) => {
                const aVal = a.sort_order !== null && a.sort_order !== undefined ? a.sort_order : 999999;
                const bVal = b.sort_order !== null && b.sort_order !== undefined ? b.sort_order : 999999;
                return this.sortOrder === 'asc' ? aVal - bVal : bVal - aVal;
            });
            
            return filtered;
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
                return { color: 'success', icon: 'mdi-check-circle', status: 'success' };
            }
        }
    },
    template: `
        <div class="indicator-dsd-component" style="display: flex; flex-direction: column; height: calc(100vh - 120px);">
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
                            color="info" 
                            class="mr-2" 
                            @click="validateDSD(true)"
                            :loading="isValidating"
                            small
                        >
                            <v-icon left small>mdi-check-circle</v-icon>
                            {{$t("validate") || "Validate"}}
                        </v-btn>
                        <v-btn 
                            color="primary" 
                            class="mr-2" 
                            @click="addColumn"
                            small
                        >
                            <v-icon left small>mdi-plus</v-icon>
                            {{$t("create_new") || "Create New"}}
                        </v-btn>
                        <v-btn 
                            color="primary"                              
                            @click="importColumns"
                            small
                        >
                            <v-icon left small>mdi-upload</v-icon>
                            {{$t("import") || "Import"}}
                        </v-btn>
                        <v-btn 
                            color="primary" 
                            class="ml-2"
                            @click="populateCodeLists"
                            :loading="isPopulatingCodeLists"
                            :disabled="columns.length === 0"
                            small
                        >
                            <v-icon left small>mdi-auto-mode</v-icon>
                            Code lists
                        </v-btn>
                    </div>
                </v-card-title>
            </v-card>

            <!-- Validation Panel -->
            <div v-if="validationResult" class="m-2" style="margin-top: 16px;">
                <v-expansion-panels v-model="showValidationPanel" :value="[0]">
                    <v-expansion-panel>
                        <v-expansion-panel-header>
                            <div class="d-flex align-center">
                                <v-icon 
                                    :color="validationResult.valid ? 'success' : 'error'" 
                                    class="mr-2"
                                >
                                    {{validationResult.valid ? 'mdi-check-circle' : 'mdi-alert-circle'}}
                                </v-icon>
                                <div>
                                    <strong>{{$t("dsd_validation") || "Data Structure Validation"}}</strong>
                                    <div class="text-caption">
                                        <span v-if="validationResult.valid" class="text-success">
                                            {{$t("validation_passed") || "Validation Passed"}}
                                        </span>
                                        <span v-else class="text-error">
                                            {{$t("validation_failed") || "Validation Failed"}}
                                        </span>
                                        <span v-if="validationResult.errors && validationResult.errors.length > 0" class="ml-2">
                                            • {{validationResult.errors.length}} {{$t("errors") || "errors"}}
                                        </span>
                                        <span v-if="validationResult.warnings && validationResult.warnings.length > 0" class="ml-2">
                                            • {{validationResult.warnings.length}} {{$t("warnings") || "warnings"}}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </v-expansion-panel-header>
                        <v-expansion-panel-content>
                            <div v-if="validationResult">
                                <!-- Summary -->
                                <v-alert 
                                    :type="validationResult.valid ? 'success' : 'error'"
                                    class="mb-4"
                                    :icon="validationResult.valid ? 'mdi-check-circle' : 'mdi-alert-circle'"
                                >
                                    <div class="font-weight-bold mb-2">
                                        {{validationResult.valid 
                                            ? ($t("validation_passed") || "Validation Passed") 
                                            : ($t("validation_failed") || "Validation Failed")}}
                                    </div>
                                    <!--
                                    <div v-if="validationResult.summary">
                                        <div class="mb-2">
                                            <strong>{{$t("total_columns") || "Total Columns"}}:</strong> {{validationResult.summary.total_columns}}
                                        </div>
                                        <div v-if="validationResult.summary.by_type && Object.keys(validationResult.summary.by_type).length > 0">
                                            <div class="font-weight-bold mb-2">{{$t("columns_by_type") || "Columns by Type"}}:</div>
                                            <v-chip 
                                                v-for="(count, type) in validationResult.summary.by_type" 
                                                :key="type" 
                                                small 
                                                class="mr-2 mb-2"
                                                color="green"
                                            >
                                                {{type}}: {{count}}
                                            </v-chip>
                                        </div>
                                    </div>
                                    -->
                                </v-alert>

                                <!-- Errors -->
                                <v-expansion-panels v-if="validationResult.errors && validationResult.errors.length > 0" class="mb-4">
                                    <v-expansion-panel>
                                        <v-expansion-panel-header>
                                            <div class="d-flex align-center">
                                                <v-icon color="error" class="mr-2">mdi-alert</v-icon>
                                                <strong class="text-error">
                                                    {{$t("errors") || "Errors"}} ({{validationResult.errors.length}})
                                                </strong>
                                            </div>
                                        </v-expansion-panel-header>
                                        <v-expansion-panel-content>
                                            <v-list dense>
                                                <v-list-item v-for="(error, idx) in validationResult.errors" :key="idx" class="px-0">
                                                    <v-list-item-icon class="mr-2">
                                                        <v-icon color="error" small>mdi-close-circle</v-icon>
                                                    </v-list-item-icon>
                                                    <v-list-item-content>
                                                        <v-list-item-title class="text-error">{{error}}</v-list-item-title>
                                                    </v-list-item-content>
                                                </v-list-item>
                                            </v-list>
                                        </v-expansion-panel-content>
                                    </v-expansion-panel>
                                </v-expansion-panels>

                                <!-- Warnings -->
                                <v-expansion-panels v-if="validationResult.warnings && validationResult.warnings.length > 0">
                                    <v-expansion-panel>
                                        <v-expansion-panel-header>
                                            <div class="d-flex align-center">
                                                <v-icon color="warning" class="mr-2">mdi-alert</v-icon>
                                                <strong class="text-warning">
                                                    {{$t("warnings") || "Warnings"}} ({{validationResult.warnings.length}})
                                                </strong>
                                            </div>
                                        </v-expansion-panel-header>
                                        <v-expansion-panel-content>
                                            <v-list dense>
                                                <v-list-item v-for="(warning, idx) in validationResult.warnings" :key="idx" class="px-0">
                                                    <v-list-item-icon class="mr-2">
                                                        <v-icon color="warning" small>mdi-alert-circle</v-icon>
                                                    </v-list-item-icon>
                                                    <v-list-item-content>
                                                        <v-list-item-title class="text-warning">{{warning}}</v-list-item-title>
                                                    </v-list-item-content>
                                                </v-list-item>
                                            </v-list>
                                        </v-expansion-panel-content>
                                    </v-expansion-panel>
                                </v-expansion-panels>

                                <!-- Success message if no errors -->
                                <!--
                                <div v-if="validationResult.valid && (!validationResult.errors || validationResult.errors.length === 0)" class="text-center pa-4">
                                    <v-icon color="success" size="64" class="mb-2">mdi-check-circle</v-icon>
                                    <div class="text-h6 text-success">{{$t("dsd_is_valid") || "Data Structure Definition is valid"}}</div>
                                </div>
                                -->
                            </div>
                        </v-expansion-panel-content>
                    </v-expansion-panel>
                </v-expansion-panels>
            </div>

            <!-- Two Column Layout -->
            <div style="display: flex; flex: 1; gap: 16px; overflow: hidden; background: rgb(240 240 240);" class="m-2 elevation-2">
                <!-- Left Column: Columns List (40%) -->
                <div style="flex: 0 0 40%; display: flex; flex-direction: column; border: 1px solid #e0e0e0; border-radius: 4px; overflow: hidden;">
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
                            
                            <!-- Spacer to push sort toggle to the right -->
                            <v-spacer></v-spacer>
                            
                            <!-- Sort Direction Toggle -->
                            <v-btn
                                icon                                
                                @click="toggleSortOrder"
                                class="mt-0"
                            >
                                <v-icon small>{{sortOrder === 'asc' ? 'mdi-sort-ascending' : 'mdi-sort-descending'}}</v-icon>
                            </v-btn>
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
                        <div v-else-if="filteredColumns.length === 0" class="pa-4 text-center text-muted">
                            {{$t("no_columns_found") || "No columns found"}}
                        </div>
                        <v-list v-else dense>
                            <v-list-item
                                v-for="(column, index) in filteredColumns"
                                :key="column.id || index"
                                @click="editColumnByColumn(column)"
                                :class="columnActiveClass(index, column)"
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
                                    <v-list-item-title>{{column.name}}</v-list-item-title>
                                    <v-list-item-subtitle v-if="column.label || (column.metadata && column.metadata.value_label_column)">
                                        <span v-if="column.label">{{column.label}}</span>
                                        <span v-if="column.metadata && column.metadata.value_label_column" class="ml-1">
                                            <span v-if="column.label"> · </span>{{$t("value_label_column") || "Label col"}}: {{column.metadata.value_label_column}}
                                        </span>
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
                                    <v-chip x-small color="grey" text-color="white">
                                        {{column.column_type}}
                                    </v-chip>
                                </v-list-item-action>
                            </v-list-item>
                        </v-list>
                    </div>
                </div>

                <!-- Right Column: Edit Form (60%) -->
                <div style="flex: 1; display: flex; flex-direction: column; border: 1px solid #e0e0e0; border-radius: 4px; overflow: hidden; background: white;">
                    <!-- Edit Header -->
                    <div class="pa-2" style="border-bottom: 1px solid #e0e0e0; background: #f5f5f5;">
                        <div class="d-flex justify-space-between align-center">
                            <div>
                                <strong v-if="activeColumn">{{activeColumn.name}}</strong>
                                <span v-else class="text-muted">{{$t("select_column_to_edit") || "Select a column to edit"}}</span>
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
                                :column="activeColumn" 
                                @input="OnColumnUpdate" 
                                :index_key="edit_item"
                            ></indicator-dsd-edit>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `
})
