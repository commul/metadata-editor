/**
 * Create Issue Dialog Component
 * 
 * Modal dialog for creating new issues manually
 * 
 * Props:
 *   - value: Boolean - v-model for dialog visibility
 *   - projectId: Number - Project ID (required)
 * 
 * Events:
 *   - input: v-model update
 *   - issue-created: Emitted after issue is created
 */
Vue.component('create-issue-dialog', {
    props: {
        value: {
            type: Boolean,
            default: false
        },
        projectId: {
            type: Number,
            required: true
        }
    },
    data() {
        return {
            loading: false,
            newIssue: {
                project_id: null,
                title: '',
                description: '',
                category: '',
                field_path: '',
                severity: 'medium',
                current_metadata: {},
                suggested_metadata: {},
                source: 'manual',
                notes: ''
            },
            currentMetadataText: '',
            suggestedMetadataText: '',
            errors: {},
            categoryOptions: [
                'Typo / Wording',
                'Inconsistency',
                'Missing Data',
                'Format Issue',
                'Completeness',
                'Other'
            ],
            severityOptions: [
                { text: 'Low', value: 'low' },
                { text: 'Medium', value: 'medium' },
                { text: 'High', value: 'high' },
                { text: 'Critical', value: 'critical' }
            ]
        };
    },
    computed: {
        dialogVisible: {
            get() {
                return this.value;
            },
            set(val) {
                this.$emit('input', val);
            }
        },
        isValid() {
            return this.newIssue.title && this.newIssue.title.trim().length > 0 &&
                   this.newIssue.description && this.newIssue.description.trim().length > 0;
        }
    },
    watch: {
        value(newVal) {
            if (newVal) {
                this.resetForm();
                this.newIssue.project_id = this.projectId;
            }
        },
        currentMetadataText(val) {
            this.parseMetadataText('current', val);
        },
        suggestedMetadataText(val) {
            this.parseMetadataText('suggested', val);
        }
    },
    methods: {
        parseMetadataText(type, text) {
            if (!text || text.trim() === '') {
                if (type === 'current') {
                    this.newIssue.current_metadata = {};
                } else {
                    this.newIssue.suggested_metadata = {};
                }
                return;
            }

            try {
                const parsed = JSON.parse(text);
                if (type === 'current') {
                    this.newIssue.current_metadata = parsed;
                } else {
                    this.newIssue.suggested_metadata = parsed;
                }
                this.errors[type + '_metadata'] = null;
            } catch (e) {
                // If not JSON, treat as simple key-value
                if (this.newIssue.field_path) {
                    const obj = {};
                    obj[this.newIssue.field_path] = text;
                    if (type === 'current') {
                        this.newIssue.current_metadata = obj;
                    } else {
                        this.newIssue.suggested_metadata = obj;
                    }
                    this.errors[type + '_metadata'] = null;
                } else {
                    this.errors[type + '_metadata'] = 'Invalid JSON format or field path not set';
                }
            }
        },
        resetForm() {
            this.newIssue = {
                project_id: this.projectId,
                title: '',
                description: '',
                category: '',
                field_path: '',
                severity: 'medium',
                current_metadata: {},
                suggested_metadata: {},
                source: 'manual',
                notes: ''
            };
            this.currentMetadataText = '';
            this.suggestedMetadataText = '';
            this.errors = {};
        },
        async createIssue() {
            if (!this.isValid) {
                this.$root.$refs.toast.showAlert('Please fill in the required fields', 'warning');
                return;
            }

            this.loading = true;
            try {
                const url = CI.base_url + '/api/issues';
                const response = await axios.post(url, this.newIssue);

                if (response.data.status === 'success') {
                    this.$root.$refs.toast.showAlert('Issue created successfully', 'success');
                    this.$emit('issue-created', response.data.issue);
                    this.dialogVisible = false;
                    this.resetForm();
                } else {
                    throw new Error(response.data.message || 'Failed to create issue');
                }
            } catch (error) {
                console.error('Error creating issue:', error);
                this.$root.$refs.toast.showAlert(
                    error.response?.data?.message || error.message || 'Failed to create issue',
                    'error'
                );
            } finally {
                this.loading = false;
            }
        },
        useSimpleFormat() {
            // Helper to automatically format field_path as key
            if (this.newIssue.field_path && this.currentMetadataText && !this.currentMetadataText.startsWith('{')) {
                const obj = {};
                obj[this.newIssue.field_path] = this.currentMetadataText;
                this.currentMetadataText = JSON.stringify(obj, null, 2);
            }
            if (this.newIssue.field_path && this.suggestedMetadataText && !this.suggestedMetadataText.startsWith('{')) {
                const obj = {};
                obj[this.newIssue.field_path] = this.suggestedMetadataText;
                this.suggestedMetadataText = JSON.stringify(obj, null, 2);
            }
        },
        close() {
            this.dialogVisible = false;
        }
    },
    template: `
        <v-dialog
            v-model="dialogVisible"
            max-width="900px"
            persistent
        >
            <v-card>
                <v-card-title class="headline grey lighten-2">
                    <v-icon left>mdi-plus-circle</v-icon>
                    Create New Issue
                    <v-spacer></v-spacer>
                    <v-btn icon @click="close">
                        <v-icon>mdi-close</v-icon>
                    </v-btn>
                </v-card-title>

                <v-card-text class="pt-4">
                    <!-- Title -->
                    <v-row dense>
                        <v-col cols="12">
                            <v-text-field
                                v-model="newIssue.title"
                                label="Title *"
                                outlined
                                dense
                                placeholder="Short title for the issue"
                                hint="Required"
                                persistent-hint
                            ></v-text-field>
                        </v-col>
                    </v-row>

                    <!-- Description -->
                    <v-row dense>
                        <v-col cols="12">
                            <v-textarea
                                v-model="newIssue.description"
                                label="Description *"
                                outlined
                                rows="3"
                                placeholder="Describe the issue..."
                            ></v-textarea>
                        </v-col>
                    </v-row>

                    <!-- Category and Severity -->
                    <v-row dense>
                        <v-col cols="12" md="6">
                            <v-combobox
                                v-model="newIssue.category"
                                :items="categoryOptions"
                                label="Category"
                                outlined
                                dense
                                placeholder="Select or type category"
                            ></v-combobox>
                        </v-col>
                        <v-col cols="12" md="6">
                            <v-select
                                v-model="newIssue.severity"
                                :items="severityOptions"
                                label="Severity"
                                outlined
                                dense
                            ></v-select>
                        </v-col>
                    </v-row>

                    <!-- Field Path -->
                    <v-row dense>
                        <v-col cols="12">
                            <v-text-field
                                v-model="newIssue.field_path"
                                label="Field Path"
                                outlined
                                dense
                                placeholder="e.g., series_description.methodology"
                                hint="Dot-separated path to the metadata field"
                                persistent-hint
                            ></v-text-field>
                        </v-col>
                    </v-row>

                    <v-row dense class="mt-3">
                        <v-col cols="12">
                            <v-divider></v-divider>
                            <div class="text-subtitle-2 mt-3 mb-2">
                                Metadata Values
                                <v-btn
                                    x-small
                                    text
                                    color="primary"
                                    @click="useSimpleFormat"
                                    class="ml-2"
                                >
                                    <v-icon left x-small>mdi-auto-fix</v-icon>
                                    Auto-format
                                </v-btn>
                            </div>
                            <div class="text-caption text--secondary mb-3">
                                Enter values as JSON objects or simple text (will use field path as key)
                            </div>
                        </v-col>
                    </v-row>

                    <!-- Current Metadata -->
                    <v-row dense>
                        <v-col cols="12" md="6">
                            <v-textarea
                                v-model="currentMetadataText"
                                label="Current Metadata"
                                outlined
                                rows="5"
                                placeholder='{"field.path": "current value"}'
                                :error-messages="errors.current_metadata"
                            ></v-textarea>
                        </v-col>
                        <v-col cols="12" md="6">
                            <v-textarea
                                v-model="suggestedMetadataText"
                                label="Suggested Metadata"
                                outlined
                                rows="5"
                                placeholder='{"field.path": "suggested value"}'
                                :error-messages="errors.suggested_metadata"
                            ></v-textarea>
                        </v-col>
                    </v-row>

                    <!-- Notes -->
                    <v-row dense>
                        <v-col cols="12">
                            <v-textarea
                                v-model="newIssue.notes"
                                label="Notes (optional)"
                                outlined
                                rows="2"
                                placeholder="Additional notes or context..."
                            ></v-textarea>
                        </v-col>
                    </v-row>
                </v-card-text>

                <v-divider></v-divider>

                <v-card-actions class="pa-4">
                    <v-spacer></v-spacer>
                    <v-btn
                        text
                        @click="close"
                    >
                        Cancel
                    </v-btn>
                    <v-btn
                        color="primary"
                        @click="createIssue"
                        :loading="loading"
                        :disabled="!isValid"
                    >
                        <v-icon left>mdi-plus</v-icon>
                        Create Issue
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    `
});
