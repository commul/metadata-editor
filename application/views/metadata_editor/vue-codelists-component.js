// Global Codelists Listing Component
Vue.component('codelists', {
    props: [],
    data() {
        return {
            codelists: [],
            loading: false,
            search: '',
            filters: {
                agency: '',
                lifecycle_state: '',
                source_type: '',
                is_hierarchical: null
            },
            pagination: {
                page: 1,
                itemsPerPage: 25,
                total: 0,
                offset: 0
            },
            sortBy: 'created_at',
            sortDesc: true,
            selectedCodelists: [],
            showFilters: false,
            page_action: 'list',
            edit_item: null
        }
    },
    created: async function() {
        await this.loadCodelists();
    },
    methods: {
        loadCodelists: async function() {
            this.loading = true;
            const vm = this;
            
            try {
                // Build query parameters
                let params = {
                    offset: this.pagination.offset,
                    limit: this.pagination.itemsPerPage,
                    order_by: this.sortBy,
                    order_dir: this.sortDesc ? 'DESC' : 'ASC'
                };

                // Add filters
                if (this.filters.agency) {
                    params.agency = this.filters.agency;
                }
                if (this.filters.lifecycle_state) {
                    params.lifecycle_state = this.filters.lifecycle_state;
                }
                if (this.filters.source_type) {
                    params.source_type = this.filters.source_type;
                }
                if (this.filters.is_hierarchical !== null) {
                    params.is_hierarchical = this.filters.is_hierarchical ? '1' : '0';
                }
                if (this.search) {
                    params.search = this.search;
                }

                let url = CI.site_url + '/api/codelists?' + new URLSearchParams(params).toString();
                let response = await axios.get(url);
                
                if (response.data && response.data.codelists) {
                    vm.codelists = response.data.codelists;
                    vm.pagination.total = response.data.total || 0;
                }
            } catch (error) {
                console.log("Error loading codelists", error);
                EventBus.$emit('onFail', 'Failed to load codelists');
            } finally {
                this.loading = false;
            }
        },
        clearSearch: function() {
            this.search = '';
            this.loadCodelists();
        },
        clearFilters: function() {
            this.filters = {
                agency: '',
                lifecycle_state: '',
                source_type: '',
                is_hierarchical: null
            };
            this.loadCodelists();
        },
        applyFilters: function() {
            this.pagination.page = 1;
            this.pagination.offset = 0;
            this.loadCodelists();
        },
        onPageChange: function(page) {
            this.pagination.page = page;
            this.pagination.offset = (page - 1) * this.pagination.itemsPerPage;
            this.loadCodelists();
        },
        onSortChange: function(options) {
            if (options.length > 0) {
                this.sortBy = options[0].key;
                this.sortDesc = options[0].order === 'desc';
                this.loadCodelists();
            }
        },
        getLifecycleStateColor: function(state) {
            const colors = {
                'draft': 'grey',
                'published': 'success',
                'deprecated': 'warning',
                'superseded': 'info'
            };
            return colors[state] || 'grey';
        },
        getLifecycleStateIcon: function(state) {
            const icons = {
                'draft': 'mdi-file-document-edit-outline',
                'published': 'mdi-check-circle',
                'deprecated': 'mdi-alert',
                'superseded': 'mdi-information'
            };
            return icons[state] || 'mdi-file-document';
        },
        getSourceTypeColor: function(type) {
            const colors = {
                'sdmx': 'primary',
                'iso': 'info',
                'classification': 'purple',
                'geography': 'teal',
                'external_registry': 'orange',
                'internal': 'grey'
            };
            return colors[type] || 'grey';
        },
        formatDate: function(dateString) {
            if (!dateString) return '';
            const date = new Date(dateString);
            return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
        },
        editCodelist: function(codelist) {
            // Navigate to edit page
            if (this.$router) {
                this.$router.push('/edit/' + codelist.id);
            } else {
                EventBus.$emit('codelist-edit', codelist);
            }
        },
        viewCodelist: function(codelist) {
            // Navigate to view page
            if (this.$router) {
                this.$router.push('/edit/' + codelist.id);
            } else {
                EventBus.$emit('codelist-view', codelist);
            }
        },
        deleteCodelist: async function(codelist) {
            if (!confirm(`Are you sure you want to delete "${codelist.name}"? This action cannot be undone.`)) {
                return;
            }

            try {
                let url = CI.site_url + '/api/codelists/' + codelist.id + '/delete';
                await axios.post(url);
                
                EventBus.$emit('onSuccess', 'Codelist deleted successfully');
                this.loadCodelists();
            } catch (error) {
                console.log("Error deleting codelist", error);
                EventBus.$emit('onFail', 'Failed to delete codelist');
            }
        },
        viewCodes: function(codelist) {
            if (this.$router) {
                this.$router.push('/edit/' + codelist.id + '#codes');
            } else {
                EventBus.$emit('codelist-codes', codelist);
            }
        },
        createCodelist: function() {
            if (this.$router) {
                this.$router.push('/edit');
            } else {
                EventBus.$emit('codelist-create');
            }
        },
        importCodelist: function() {
            EventBus.$emit('codelist-import');
        },
        exportCodelist: function(codelist) {
            EventBus.$emit('codelist-export', codelist);
        },
        toggleSelection: function(codelist) {
            const index = this.selectedCodelists.findIndex(c => c.id === codelist.id);
            if (index > -1) {
                this.selectedCodelists.splice(index, 1);
            } else {
                this.selectedCodelists.push(codelist);
            }
        },
        toggleSelectAll: function() {
            if (this.selectedCodelists.length === this.codelists.length) {
                this.selectedCodelists = [];
            } else {
                this.selectedCodelists = [...this.codelists];
            }
        },
        batchDelete: async function() {
            if (this.selectedCodelists.length === 0) {
                return;
            }

            if (!confirm(`Are you sure you want to delete ${this.selectedCodelists.length} codelist(s)? This action cannot be undone.`)) {
                return;
            }

            try {
                const deletePromises = this.selectedCodelists.map(codelist => {
                    let url = CI.base_url + '/api/codelists/' + codelist.id + '/delete';
                    return axios.post(url);
                });

                await Promise.all(deletePromises);
                
                EventBus.$emit('onSuccess', `${this.selectedCodelists.length} codelist(s) deleted successfully`);
                this.selectedCodelists = [];
                this.loadCodelists();
            } catch (error) {
                console.log("Error deleting codelists", error);
                EventBus.$emit('onFail', 'Failed to delete some codelists');
            }
        }
    },
    computed: {
        filteredCodelists: function() {
            // Client-side filtering if needed (though we're doing server-side)
            return this.codelists;
        },
        totalPages: function() {
            return Math.ceil(this.pagination.total / this.pagination.itemsPerPage);
        },
        allSelected: function() {
            return this.codelists.length > 0 && 
                   this.codelists.every(c => this.selectedCodelists.findIndex(s => s.id === c.id) > -1);
        },
        someSelected: function() {
            return this.selectedCodelists.length > 0 && !this.allSelected;
        }
    },
    watch: {
        search: function() {
            // Debounce search
            clearTimeout(this.searchTimeout);
            this.searchTimeout = setTimeout(() => {
                this.pagination.page = 1;
                this.pagination.offset = 0;
                this.loadCodelists();
            }, 500);
        }
    },
    template: `
        <div class="codelists-component">
            <v-card class="mb-2 m-2" flat>
                <v-card-title class="d-flex justify-space-between align-center">
                    <div>
                        <h4 class="mb-0 d-inline">{{$t("codelists")}}</h4>
                        <small class="text-muted ml-2" v-if="pagination.total > 0">
                            {{pagination.total}} {{$t("codelists") || "codelists"}}
                        </small>
                    </div>
                    <div>
                        <v-btn 
                            color="secondary" 
                            outlined 
                            class="mr-2" 
                            @click="importCodelist"
                            small
                        >
                            <v-icon left small>mdi-upload</v-icon>
                            {{$t("import") || "Import"}}
                        </v-btn>
                        <v-btn 
                            color="primary" 
                            @click="createCodelist"
                            small
                        >
                            <v-icon left small>mdi-plus</v-icon>
                            {{$t("create_new") || "Create New"}}
                        </v-btn>
                    </div>
                </v-card-title>

                <v-card-text>
                    <!-- Search and Filters -->
                    <v-row class="mb-2">
                        <v-col cols="12" md="6">
                            <v-text-field
                                v-model="search"
                                :placeholder="$t('search_codelists') || 'Search codelists...'"
                                prepend-inner-icon="mdi-magnify"
                                clearable
                                @click:clear="clearSearch"
                                outlined
                                dense
                                hide-details
                            ></v-text-field>
                        </v-col>
                        <v-col cols="12" md="6" class="text-right">
                            <v-btn 
                                text 
                                small 
                                @click="showFilters = !showFilters"
                            >
                                <v-icon left small>mdi-filter</v-icon>
                                {{$t("filters") || "Filters"}}
                                <v-icon right small>{{showFilters ? 'mdi-chevron-up' : 'mdi-chevron-down'}}</v-icon>
                            </v-btn>
                            <v-btn 
                                text 
                                small 
                                @click="clearFilters"
                                v-if="filters.agency || filters.lifecycle_state || filters.source_type || filters.is_hierarchical !== null"
                            >
                                <v-icon left small>mdi-close</v-icon>
                                {{$t("clear") || "Clear"}}
                            </v-btn>
                        </v-col>
                    </v-row>

                    <!-- Filter Panel -->
                    <v-expand-transition>
                        <v-row v-show="showFilters" class="mb-2">
                            <v-col cols="12" md="3">
                                <v-text-field
                                    v-model="filters.agency"
                                    label="Agency"
                                    outlined
                                    dense
                                    hide-details
                                ></v-text-field>
                            </v-col>
                            <v-col cols="12" md="3">
                                <v-select
                                    v-model="filters.lifecycle_state"
                                    :items="[
                                        {text: 'Draft', value: 'draft'},
                                        {text: 'Published', value: 'published'},
                                        {text: 'Deprecated', value: 'deprecated'},
                                        {text: 'Superseded', value: 'superseded'}
                                    ]"
                                    label="Lifecycle State"
                                    outlined
                                    dense
                                    hide-details
                                    clearable
                                ></v-select>
                            </v-col>
                            <v-col cols="12" md="3">
                                <v-select
                                    v-model="filters.source_type"
                                    :items="[
                                        {text: 'SDMX', value: 'sdmx'},
                                        {text: 'ISO', value: 'iso'},
                                        {text: 'Classification', value: 'classification'},
                                        {text: 'Geography', value: 'geography'},
                                        {text: 'External Registry', value: 'external_registry'},
                                        {text: 'Internal', value: 'internal'}
                                    ]"
                                    label="Source Type"
                                    outlined
                                    dense
                                    hide-details
                                    clearable
                                ></v-select>
                            </v-col>
                            <v-col cols="12" md="3">
                                <v-select
                                    v-model="filters.is_hierarchical"
                                    :items="[
                                        {text: 'All', value: null},
                                        {text: 'Hierarchical', value: true},
                                        {text: 'Non-hierarchical', value: false}
                                    ]"
                                    label="Hierarchical"
                                    outlined
                                    dense
                                    hide-details
                                ></v-select>
                            </v-col>
                            <v-col cols="12" class="text-right">
                                <v-btn 
                                    color="primary" 
                                    @click="applyFilters"
                                    small
                                >
                                    {{$t("apply_filters") || "Apply Filters"}}
                                </v-btn>
                            </v-col>
                        </v-row>
                    </v-expand-transition>

                    <!-- Batch Actions -->
                    <v-row v-if="selectedCodelists.length > 0" class="mb-2">
                        <v-col cols="12">
                            <v-alert type="info" outlined dense>
                                <div class="d-flex justify-space-between align-center">
                                    <span>{{selectedCodelists.length}} {{$t("selected") || "selected"}}</span>
                                    <v-btn 
                                        color="error" 
                                        outlined 
                                        small 
                                        @click="batchDelete"
                                    >
                                        <v-icon left small>mdi-delete</v-icon>
                                        {{$t("delete_selected") || "Delete Selected"}}
                                    </v-btn>
                                </div>
                            </v-alert>
                        </v-col>
                    </v-row>

                    <!-- Codelists Table -->
                    <v-data-table
                        :headers="[
                            {text: '', value: 'checkbox', sortable: false, width: '50px'},
                            {text: $t('name') || 'Name', value: 'name', sortable: true},
                            {text: $t('agency') || 'Agency', value: 'agency', sortable: true},
                            {text: $t('codelist_id') || 'Codelist ID', value: 'codelist_id', sortable: true},
                            {text: $t('version') || 'Version', value: 'version', sortable: true},
                            {text: $t('lifecycle_state') || 'State', value: 'lifecycle_state', sortable: true},
                            {text: $t('source_type') || 'Source', value: 'source_type', sortable: true},
                            {text: $t('codes') || 'Codes', value: 'code_count', sortable: true},
                            {text: $t('created') || 'Created', value: 'created_at', sortable: true},
                            {text: $t('actions') || 'Actions', value: 'actions', sortable: false, width: '100px'}
                        ]"
                        :items="codelists"
                        :loading="loading"
                        :server-items-length="pagination.total"
                        :items-per-page="pagination.itemsPerPage"
                        :page="pagination.page"
                        @update:page="onPageChange"
                        @update:sort-by="onSortChange"
                        class="elevation-1"
                        dense
                        :hide-default-footer="false"
                    >
                        <!-- Checkbox column -->
                        <template v-slot:item.checkbox="{ item }">
                            <v-checkbox
                                :value="selectedCodelists.findIndex(c => c.id === item.id) > -1"
                                @change="toggleSelection(item)"
                                hide-details
                                dense
                            ></v-checkbox>
                        </template>

                        <!-- Name column -->
                        <template v-slot:item.name="{ item }">
                            <div>
                                <div class="font-weight-medium" style="cursor: pointer; color: #1976D2;" @click="viewCodelist(item)">
                                    {{item.name}}
                                </div>
                                <div class="text-caption text--secondary" v-if="item.description">
                                    {{item.description.substring(0, 60)}}{{item.description.length > 60 ? '...' : ''}}
                                </div>
                            </div>
                        </template>

                        <!-- Lifecycle State column -->
                        <template v-slot:item.lifecycle_state="{ item }">
                            <v-chip 
                                :color="getLifecycleStateColor(item.lifecycle_state)" 
                                small 
                                outlined
                            >
                                <v-icon left small>{{getLifecycleStateIcon(item.lifecycle_state)}}</v-icon>
                                {{item.lifecycle_state}}
                            </v-chip>
                        </template>

                        <!-- Source Type column -->
                        <template v-slot:item.source_type="{ item }">
                            <v-chip 
                                :color="getSourceTypeColor(item.source_type)" 
                                small 
                                outlined
                            >
                                {{item.source_type}}
                            </v-chip>
                        </template>

                        <!-- Hierarchical indicator -->
                        <template v-slot:item.code_count="{ item }">
                            <div>
                                <span>{{item.code_count || 0}}</span>
                                <v-icon 
                                    v-if="item.is_hierarchical" 
                                    small 
                                    color="primary" 
                                    class="ml-1"
                                    title="Hierarchical"
                                >
                                    mdi-file-tree
                                </v-icon>
                            </div>
                        </template>

                        <!-- Created date -->
                        <template v-slot:item.created_at="{ item }">
                            <span class="text-caption">{{formatDate(item.created_at)}}</span>
                        </template>

                        <!-- Actions column -->
                        <template v-slot:item.actions="{ item }">
                            <v-menu offset-y>
                                <template v-slot:activator="{ on, attrs }">
                                    <v-btn 
                                        small 
                                        icon 
                                        v-on="on" 
                                        v-bind="attrs"
                                        :title="$t('More options')"
                                    >
                                        <v-icon>mdi-dots-vertical</v-icon>
                                    </v-btn>
                                </template>
                                <v-list dense>
                                    <v-list-item @click="viewCodelist(item)">
                                        <v-list-item-icon>
                                            <v-icon>mdi-eye</v-icon>
                                        </v-list-item-icon>
                                        <v-list-item-title>{{$t("view") || "View"}}</v-list-item-title>
                                    </v-list-item>
                                    <v-list-item @click="viewCodes(item)">
                                        <v-list-item-icon>
                                            <v-icon>mdi-code-tags</v-icon>
                                        </v-list-item-icon>
                                        <v-list-item-title>{{$t("view_codes") || "View Codes"}}</v-list-item-title>
                                    </v-list-item>
                                    <v-divider></v-divider>
                                    <v-list-item @click="editCodelist(item)">
                                        <v-list-item-icon>
                                            <v-icon>mdi-pencil</v-icon>
                                        </v-list-item-icon>
                                        <v-list-item-title>{{$t("edit") || "Edit"}}</v-list-item-title>
                                    </v-list-item>
                                    <v-list-item @click="exportCodelist(item)">
                                        <v-list-item-icon>
                                            <v-icon>mdi-file-export</v-icon>
                                        </v-list-item-icon>
                                        <v-list-item-title>{{$t("export") || "Export"}}</v-list-item-title>
                                    </v-list-item>
                                    <v-divider></v-divider>
                                    <v-list-item @click="deleteCodelist(item)" class="red--text">
                                        <v-list-item-icon>
                                            <v-icon color="red">mdi-delete-outline</v-icon>
                                        </v-list-item-icon>
                                        <v-list-item-title class="red--text">{{$t("Delete") || "Delete"}}</v-list-item-title>
                                    </v-list-item>
                                </v-list>
                            </v-menu>
                        </template>

                        <!-- Empty state -->
                        <template v-slot:no-data>
                            <div class="text-center pa-4">
                                <v-icon large color="grey">mdi-file-document-outline</v-icon>
                                <div class="mt-2">{{$t("no_codelists") || "No codelists found"}}</div>
                                <v-btn 
                                    color="primary" 
                                    small 
                                    class="mt-2" 
                                    @click="createCodelist"
                                >
                                    <v-icon left small>mdi-plus</v-icon>
                                    {{$t("create_first_codelist") || "Create First Codelist"}}
                                </v-btn>
                            </div>
                        </template>
                    </v-data-table>
                </v-card-text>
            </v-card>
        </div>
    `
});
