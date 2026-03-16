/**
 * Create Issue Dialog Component
 * 
 * Modal dialog for creating new issues manually
 * 
 * Props:
 *   - value: Boolean - v-model for dialog visibility
 *   - projectId: Number - Project ID (required)
 *   - initialFieldPath: String - Optional field path to pre-fill when opened from field context
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
        },
        initialFieldPath: {
            type: String,
            default: ''
        }
    },
    data() {
        return {
            loading: false,
            isMaximized: false,
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
                if (this.initialFieldPath) {
                    this.newIssue.field_path = this.initialFieldPath;
                    this.prepopulateCurrentMetadata();
                }
            } else {
                this.isMaximized = false;
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
        prepopulateCurrentMetadata() {
            if (!this.initialFieldPath || !this.$store || !this.$store.state.formData) return;
            var formData = this.$store.state.formData;
            var value = this.getNestedValue(formData, this.initialFieldPath);
            if (value !== undefined && value !== null) {
                this.newIssue.current_metadata = {};
                this.newIssue.current_metadata[this.initialFieldPath] = value;
                this.currentMetadataText = (typeof value === 'object' && value !== null)
                    ? JSON.stringify(value, null, 2)
                    : String(value);
            }
        },
        getNestedValue(obj, path) {
            if (!obj || !path) return undefined;
            var keys = path.split('.');
            var current = obj;
            for (var i = 0; i < keys.length; i++) {
                if (current == null || typeof current !== 'object') return undefined;
                current = current[keys[i]];
            }
            return current;
        },
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
                    this.newIssue.current_metadata = this.newIssue.field_path
                        ? { [this.newIssue.field_path]: parsed }
                        : parsed;
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
        showToast(message, type) {
            if (this.$root.$refs && this.$root.$refs.toast && typeof this.$root.$refs.toast.showAlert === 'function') {
                this.$root.$refs.toast.showAlert(message, type);
            }
        },
        async createIssue() {
            if (!this.isValid) {
                this.showToast('Please fill in the required fields', 'warning');
                return;
            }

            this.loading = true;
            try {
                const url = CI.base_url + '/api/issues';
                const response = await axios.post(url, this.newIssue);

                if (response.data.status === 'success') {
                    this.showToast('Issue created successfully', 'success');
                    this.$emit('issue-created', response.data.issue);
                    this.dialogVisible = false;
                    this.resetForm();
                } else {
                    throw new Error(response.data.message || 'Failed to create issue');
                }
            } catch (error) {
                console.error('Error creating issue:', error);
                this.showToast(
                    error.response?.data?.message || error.message || 'Failed to create issue',
                    'error'
                );
            } finally {
                this.loading = false;
            }
        },
        useSimpleFormat() {
            // Pretty-print current value if it is valid JSON (e.g. object/array); leave plain values unchanged.
            if (this.currentMetadataText && this.currentMetadataText.trim()) {
                try {
                    const parsed = JSON.parse(this.currentMetadataText);
                    this.currentMetadataText = JSON.stringify(parsed, null, 2);
                } catch (e) {
                    // Not JSON – leave as-is (plain value)
                }
            }
        },
        close() {
            this.dialogVisible = false;
        }
    },
    template: `
        <v-dialog
            v-model="dialogVisible"
            :max-width="isMaximized ? undefined : '900px'"
            :fullscreen="isMaximized"
            persistent
            transition="dialog-transition"
            content-class="create-issue-dialog"
        >
            <v-card class="d-flex flex-column" :class="{ 'fill-height': isMaximized }">
                <v-card-title class="headline grey lighten-2 flex-shrink-0">
                    <v-icon left>mdi-plus-circle</v-icon>
                    Create New Issue
                    <v-spacer></v-spacer>
                    <v-btn icon @click="isMaximized = !isMaximized" :title="isMaximized ? 'Restore' : 'Maximize'">
                        <v-icon>{{ isMaximized ? 'mdi-window-restore' : 'mdi-window-maximize' }}</v-icon>
                    </v-btn>
                    <v-btn icon @click="close">
                        <v-icon>mdi-close</v-icon>
                    </v-btn>
                </v-card-title>

                <v-card-text class="pt-4 flex-grow-1 overflow-auto dialog-content-resizable" style="min-height: 320px; resize: both;">
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


                    <!-- Current Metadata -->
                    <v-row dense>
                        <v-col cols="12">
                            <v-textarea
                                v-model="currentMetadataText"
                                label="Current Metadata"
                                outlined
                                rows="5"
                                placeholder="Current field value"
                                :error-messages="errors.current_metadata"
                                style="max-height: 250px; overflow-y: auto;"
                            ></v-textarea>
                        </v-col>
                    </v-row>

                    <!-- Suggested Metadata -->
                    <v-row dense class="mt-2">
                        <v-col cols="12">
                            <v-textarea
                                v-model="suggestedMetadataText"
                                label="Suggested Metadata"
                                outlined
                                rows="5"
                                placeholder="Suggested field value or JSON"
                                :error-messages="errors.suggested_metadata"
                                style="max-height: 250px; overflow-y: auto;"
                            ></v-textarea>
                        </v-col>
                    </v-row>

                    <!-- Category and Severity (at bottom) -->
                    <v-row dense class="mt-2">
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
                </v-card-text>

                <v-divider></v-divider>

                <v-card-actions class="pa-4">
                    <v-spacer></v-spacer>
                    <v-btn
                        small
                        text
                        @click="close"
                    >
                        Cancel
                    </v-btn>
                    <v-btn
                        small
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
