// Codelist Edit/Create Component
Vue.component('codelist-edit', {
    props: ['id'],
    data() {
        return {
            codelist: {
                agency: '',
                codelist_id: '',
                version: '1.0',
                name: '',
                description: '',
                lifecycle_state: 'draft',
                source_type: 'sdmx',
                source_uri: '',
                access_pattern: 'inline',
                is_hierarchical: false
            },
            loading: false,
            saving: false,
            is_dirty: false,
            errors: {},
            is_new: true,
            codes: [],
            codes_loading: false,
            show_codes_section: false
        }
    },
    created: async function() {
        // Get id from route params or props
        let routeId = null;
        if (this.$route && this.$route.params && this.$route.params.id) {
            routeId = this.$route.params.id;
        }
        const codelistId = this.id || routeId;
        
        if (codelistId) {
            this.is_new = false;
            this.id = codelistId;
            await this.loadCodelist();
        } else {
            this.is_new = true;
            this.resetForm();
        }
    },
    mounted: function() {
        // Also check route in mounted in case router wasn't ready in created
        if (this.is_new && this.$route && this.$route.params && this.$route.params.id) {
            this.id = this.$route.params.id;
            this.is_new = false;
            this.loadCodelist();
        }
    },
    watch: {
        codelist: {
            handler: function() {
                if (!this.loading) {
                    this.is_dirty = true;
                }
            },
            deep: true
        },
        '$route': {
            handler: function(to, from) {
                const routeId = to.params ? to.params.id : null;
                if (routeId !== this.id) {
                    this.id = routeId;
                    if (this.id) {
                        this.is_new = false;
                        this.loadCodelist();
                    } else {
                        this.is_new = true;
                        this.resetForm();
                    }
                }
            },
            immediate: false
        }
    },
    methods: {
        loadCodelist: async function() {
            this.loading = true;
            try {
                let url = CI.site_url + '/api/codelists/' + this.id;
                let response = await axios.get(url);
                
                if (response.data && response.data.codelist) {
                    this.codelist = response.data.codelist;
                    this.is_dirty = false;
                    
                    // Load codes
                    await this.loadCodes();
                }
            } catch (error) {
                console.log("Error loading codelist", error);
                EventBus.$emit('onFail', 'Failed to load codelist');
                if (this.$router) {
                    this.$router.push('/');
                }
            } finally {
                this.loading = false;
            }
        },
        loadCodes: async function() {
            this.codes_loading = true;
            try {
                let url = CI.site_url + '/api/codelists/' + this.id + '/codes';
                let response = await axios.get(url);
                
                if (response.data && response.data.codes) {
                    this.codes = response.data.codes;
                }
            } catch (error) {
                console.log("Error loading codes", error);
            } finally {
                this.codes_loading = false;
            }
        },
        saveCodelist: async function() {
            // Validate required fields
            if (!this.codelist.agency) {
                EventBus.$emit('onFail', 'Agency is required');
                return;
            }
            if (!this.codelist.codelist_id) {
                EventBus.$emit('onFail', 'Codelist ID is required');
                return;
            }
            if (!this.codelist.version) {
                EventBus.$emit('onFail', 'Version is required');
                return;
            }
            if (!this.codelist.name) {
                EventBus.$emit('onFail', 'Name is required');
                return;
            }

            this.saving = true;
            this.errors = {};

            try {
                let url, method;
                
                if (this.is_new) {
                    url = CI.site_url + '/api/codelists';
                    method = 'post';
                } else {
                    url = CI.site_url + '/api/codelists/' + this.id;
                    method = 'post'; // Using POST for update
                }

                let response = await axios[method](url, this.codelist);
                
                if (response.data && response.data.id) {
                    const codelist_id = response.data.id;
                    
                    EventBus.$emit('onSuccess', this.is_new ? 'Codelist created successfully' : 'Codelist updated successfully');
                    this.is_dirty = false;
                    
                    // Navigate to edit page if it was a new codelist
                    if (this.is_new && this.$router) {
                        this.$router.push('/edit/' + codelist_id);
                    }
                }
            } catch (error) {
                console.log("Error saving codelist", error);
                if (error.response && error.response.data) {
                    if (error.response.data.errors) {
                        this.errors = error.response.data.errors;
                    }
                    EventBus.$emit('onFail', error.response.data.message || 'Failed to save codelist');
                } else {
                    EventBus.$emit('onFail', 'Failed to save codelist');
                }
            } finally {
                this.saving = false;
            }
        },
        cancelEdit: function() {
            if (this.is_dirty) {
                if (!confirm("You have unsaved changes. Are you sure you want to leave this page?")) {
                    return;
                }
            }
            this.$router.push('/codelists');
        },
        resetForm: function() {
            this.codelist = {
                agency: '',
                codelist_id: '',
                version: '1.0',
                name: '',
                description: '',
                lifecycle_state: 'draft',
                source_type: 'sdmx',
                source_uri: '',
                access_pattern: 'inline',
                is_hierarchical: false
            };
            this.codes = [];
            this.is_dirty = false;
            this.errors = {};
        },
        deleteCodelist: async function() {
            if (!confirm("Are you sure you want to delete this codelist? This action cannot be undone.")) {
                return;
            }

            try {
                let url = CI.site_url + '/api/codelists/' + this.id + '/delete';
                await axios.post(url);
                
                EventBus.$emit('onSuccess', 'Codelist deleted successfully');
                if (this.$router) {
                    this.$router.push('/');
                }
            } catch (error) {
                console.log("Error deleting codelist", error);
                EventBus.$emit('onFail', 'Failed to delete codelist');
            }
        },
        viewCodes: function() {
            this.show_codes_section = !this.show_codes_section;
            if (this.show_codes_section && this.codes.length === 0 && !this.is_new) {
                this.loadCodes();
            }
        }
    },
    template: `
        <div class="codelist-edit-component">
            <v-card class="mb-2">
                <v-card-title class="d-flex justify-space-between align-center">
                    <div>
                        <h4 class="mb-0">
                            {{is_new ? ($t("create_codelist") || "Create Codelist") : ($t("edit_codelist") || "Edit Codelist")}}
                        </h4>
                        <small class="text-muted" v-if="!is_new && codelist.agency">
                            {{codelist.agency}} / {{codelist.codelist_id}} / {{codelist.version}}
                        </small>
                    </div>
                    <div>
                        <v-btn 
                            v-if="!is_new"
                            color="error" 
                            outlined 
                            small 
                            class="mr-2" 
                            @click="deleteCodelist"
                        >
                            <v-icon left small>mdi-delete</v-icon>
                            {{$t("Delete") || "Delete"}}
                        </v-btn>
                        <v-btn 
                            color="primary" 
                            small 
                            class="mr-2" 
                            @click="saveCodelist"
                            :loading="saving"
                            :disabled="saving"
                        >
                            <v-icon left small>mdi-content-save</v-icon>
                            {{$t("save") || "Save"}} <span v-if="is_dirty">*</span>
                        </v-btn>
                        <v-btn 
                            outlined 
                            small 
                            @click="cancelEdit"
                            :disabled="saving"
                        >
                            {{$t("cancel") || "Cancel"}}
                        </v-btn>
                    </div>
                </v-card-title>

                <v-card-text>
                    <v-progress-linear v-if="loading" indeterminate color="primary"></v-progress-linear>

                    <div v-if="!loading">
                        <!-- Error Messages -->
                        <v-alert v-if="Object.keys(errors).length > 0" type="error" class="mb-4">
                            <div v-for="(error, key) in errors" :key="key">
                                <strong>{{key}}:</strong> {{error}}
                            </div>
                        </v-alert>

                        <v-form>
                            <v-row>
                                <v-col cols="12" md="4">
                                    <v-text-field
                                        v-model="codelist.agency"
                                        label="Agency *"
                                        :rules="[v => !!v || 'Agency is required']"
                                        outlined
                                        dense
                                        required
                                    ></v-text-field>
                                </v-col>
                                <v-col cols="12" md="4">
                                    <v-text-field
                                        v-model="codelist.codelist_id"
                                        label="Codelist ID *"
                                        :rules="[v => !!v || 'Codelist ID is required']"
                                        outlined
                                        dense
                                        required
                                    ></v-text-field>
                                </v-col>
                                <v-col cols="12" md="4">
                                    <v-text-field
                                        v-model="codelist.version"
                                        label="Version *"
                                        :rules="[v => !!v || 'Version is required']"
                                        outlined
                                        dense
                                        required
                                    ></v-text-field>
                                </v-col>
                            </v-row>

                            <v-row>
                                <v-col cols="12">
                                    <v-text-field
                                        v-model="codelist.name"
                                        label="Name *"
                                        :rules="[v => !!v || 'Name is required']"
                                        outlined
                                        dense
                                        required
                                    ></v-text-field>
                                </v-col>
                            </v-row>

                            <v-row>
                                <v-col cols="12">
                                    <v-textarea
                                        v-model="codelist.description"
                                        label="Description"
                                        outlined
                                        dense
                                        rows="3"
                                    ></v-textarea>
                                </v-col>
                            </v-row>

                            <v-row>
                                <v-col cols="12" md="4">
                                    <v-select
                                        v-model="codelist.lifecycle_state"
                                        :items="[
                                            {text: 'Draft', value: 'draft'},
                                            {text: 'Published', value: 'published'},
                                            {text: 'Deprecated', value: 'deprecated'},
                                            {text: 'Superseded', value: 'superseded'}
                                        ]"
                                        label="Lifecycle State"
                                        outlined
                                        dense
                                    ></v-select>
                                </v-col>
                                <v-col cols="12" md="4">
                                    <v-select
                                        v-model="codelist.source_type"
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
                                    ></v-select>
                                </v-col>
                                <v-col cols="12" md="4">
                                    <v-select
                                        v-model="codelist.access_pattern"
                                        :items="[
                                            {text: 'Inline', value: 'inline'},
                                            {text: 'Paged', value: 'paged'},
                                            {text: 'Search Only', value: 'search_only'},
                                            {text: 'External', value: 'external'}
                                        ]"
                                        label="Access Pattern"
                                        outlined
                                        dense
                                    ></v-select>
                                </v-col>
                            </v-row>

                            <v-row>
                                <v-col cols="12" md="6">
                                    <v-text-field
                                        v-model="codelist.source_uri"
                                        label="Source URI"
                                        outlined
                                        dense
                                    ></v-text-field>
                                </v-col>
                                <v-col cols="12" md="6">
                                    <v-checkbox
                                        v-model="codelist.is_hierarchical"
                                        label="Hierarchical Codelist"
                                        dense
                                    ></v-checkbox>
                                </v-col>
                            </v-row>

                            <!-- Codes Section (only show for existing codelists) -->
                            <v-row v-if="!is_new">
                                <v-col cols="12">
                                    <v-divider class="my-4"></v-divider>
                                    <div class="d-flex justify-space-between align-center mb-2">
                                        <h5>{{$t("codes") || "Codes"}} ({{codes.length}})</h5>
                                        <v-btn 
                                            small 
                                            outlined 
                                            @click="viewCodes"
                                        >
                                            <v-icon left small>{{show_codes_section ? 'mdi-chevron-up' : 'mdi-chevron-down'}}</v-icon>
                                            {{show_codes_section ? ($t("hide_codes") || "Hide Codes") : ($t("view_codes") || "View Codes")}}
                                        </v-btn>
                                    </div>
                                    
                                    <v-expand-transition>
                                        <div v-show="show_codes_section">
                                            <v-progress-linear v-if="codes_loading" indeterminate color="primary"></v-progress-linear>
                                            <div v-else-if="codes.length === 0" class="text-center text-muted pa-4">
                                                {{$t("no_codes") || "No codes defined"}}
                                            </div>
                                            <div v-else>
                                                <!-- Codes will be displayed here - can add a codes component later -->
                                                <p class="text-muted">{{$t("codes_management_coming_soon") || "Codes management coming soon"}}</p>
                                            </div>
                                        </div>
                                    </v-expand-transition>
                                </v-col>
                            </v-row>
                        </v-form>
                    </div>
                </v-card-text>
            </v-card>
        </div>
    `
});
