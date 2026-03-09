/**
 * Issue Edit Page Component
 * 
 * Full page for viewing and editing existing issues
 */
const VueIssueEdit = Vue.component('issue-edit', {
    props: {
        issueId: {
            type: [String, Number],
            required: true
        },
        projectId: {
            type: Number,
            default: null
        }
    },
    data() {
        return {
            loading: false,
            saving: false,
            applying: false,
            issue: null,
            editedIssue: {},
            editMode: false,
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
            ],
            statusOptions: [
                { text: 'Open', value: 'open' },
                { text: 'Accepted', value: 'accepted' },
                { text: 'Fixed', value: 'fixed' },
                { text: 'Rejected', value: 'rejected' },
                { text: 'Dismissed', value: 'dismissed' },
                { text: 'False Positive', value: 'false_positive' }
            ],
            diffRoot: null,
            advancedPanel: [0]
        };
    },
    computed: {
        ProjectID() {
            return this.projectId || this.$root.dataset_id;
        },
        hasCurrentMetadata() {
            return this.issue && this.issue.current_metadata != null && typeof this.issue.current_metadata === 'object' && Object.keys(this.issue.current_metadata).length > 0;
        },
        hasSuggestedMetadata() {
            return this.issue && this.issue.suggested_metadata != null && typeof this.issue.suggested_metadata === 'object' && Object.keys(this.issue.suggested_metadata).length > 0;
        },
        hasAnyMetadata() {
            return this.issue && (this.issue.current_metadata != null || this.issue.suggested_metadata != null);
        },
        canApply() {
            return this.hasSuggestedMetadata && !this.issue.is_applied;
        },
        UserHasEditAccess() {
            return this.$root.UserHasEditAccess;
        },
        fieldPathOptions() {
            const formData = this.$store.state.formData;
            if (!formData || typeof formData !== 'object') {
                return [];
            }
            return this.flattenFormData(formData);
        }
    },
    mounted() {
        this.loadIssue();
    },
    updated() {
        this.$nextTick(() => this.$nextTick(() => this.renderMetadataDiff()));
    },
    beforeDestroy() {
        if (this.diffRoot && this.diffRoot.unmount) {
            this.diffRoot.unmount();
            this.diffRoot = null;
        }
    },
    watch: {
        advancedPanel: {
            handler(val) {
                if (val && val.length > 0) {
                    this.$nextTick(() => this.$nextTick(() => this.renderMetadataDiff()));
                }
            },
            deep: true
        },
        editMode() {
            this.$nextTick(() => this.$nextTick(() => this.renderMetadataDiff()));
        },
        currentMetadataText(val) {
            if (this.editMode) {
                this.parseMetadataText('current', val);
            }
        },
        suggestedMetadataText(val) {
            if (this.editMode) {
                this.parseMetadataText('suggested', val);
            }
        },
        'editedIssue.field_path'(newPath) {
            // Auto-populate current metadata when field path is changed in edit mode
            if (this.editMode && newPath && this.$store.state.formData) {
                const currentValue = this.getValueByPath(this.$store.state.formData, newPath);
                if (currentValue !== undefined && currentValue !== null) {
                    // Store the value directly, not as {path: value}
                    if (typeof currentValue === 'object') {
                        this.currentMetadataText = JSON.stringify(currentValue, null, 2);
                    } else {
                        this.currentMetadataText = String(currentValue);
                    }
                }
            }
        }
    },
    methods: {
        async loadIssue() {
            this.loading = true;
            try {
                const url = CI.base_url + '/api/issues/' + this.issueId;
                const response = await axios.get(url);

                if (response.data.status === 'success') {
                    this.issue = response.data.issue;
                    this.editedIssue = { ...this.issue };
                    this.$nextTick(() => {
                        setTimeout(() => {
                            this.$nextTick(() => this.renderMetadataDiff());
                        }, 200);
                    });
                } else {
                    throw new Error(response.data.message || 'Failed to load issue');
                }
            } catch (error) {
                console.error('Error loading issue:', error);
                EventBus.$emit(
                    'onFail',
                    error.response?.data?.message || error.message || 'Failed to load issue'
                );
                this.$router.push('/issues');
            } finally {
                this.loading = false;
            }
        },
        toggleEditMode() {
            if (!this.editMode) {
                // Entering edit mode
                this.editedIssue = { ...this.issue };
                this.currentMetadataText = this.issue.current_metadata ? JSON.stringify(this.issue.current_metadata, null, 2) : '';
                this.suggestedMetadataText = this.issue.suggested_metadata ? JSON.stringify(this.issue.suggested_metadata, null, 2) : '';
            }
            this.editMode = !this.editMode;
        },
        parseMetadataText(type, text) {
            if (!text || text.trim() === '') {
                if (type === 'current') {
                    this.editedIssue.current_metadata = {};
                } else {
                    this.editedIssue.suggested_metadata = {};
                }
                return;
            }

            try {
                const parsed = JSON.parse(text);
                if (type === 'current') {
                    this.editedIssue.current_metadata = parsed;
                } else {
                    this.editedIssue.suggested_metadata = parsed;
                }
                this.errors[type + '_metadata'] = null;
            } catch (e) {
                this.errors[type + '_metadata'] = 'Invalid JSON format';
            }
        },
        async saveChanges() {
            const title = (this.editedIssue.title !== undefined && this.editedIssue.title !== null ? String(this.editedIssue.title) : '').trim();
            if (!title) {
                EventBus.$emit('onFail', 'Title is required');
                return;
            }
            this.saving = true;
            try {
                const url = CI.base_url + '/api/issues/' + this.issueId;
                const response = await axios.put(url, this.editedIssue);

                if (response.data.status === 'success') {
                    EventBus.$emit('onSuccess', 'Issue updated successfully');
                    this.issue = response.data.issue;
                    this.editedIssue = { ...this.issue };
                    this.editMode = false;
                } else {
                    throw new Error(response.data.message || 'Failed to update issue');
                }
            } catch (error) {
                console.error('Error updating issue:', error);
                EventBus.$emit(
                    'onFail',
                    error.response?.data?.message || error.message || 'Failed to update issue'
                );
            } finally {
                this.saving = false;
            }
        },
        async applyChanges() {
            if (!confirm('Apply the suggested metadata changes to the project?')) {
                return;
            }

            this.applying = true;
            try {
                const url = CI.base_url + '/api/issues/apply/' + this.issueId;
                const response = await axios.post(url);

                if (response.data.status === 'success') {
                    EventBus.$emit('onSuccess', 'Changes applied successfully');
                    this.loadIssue();
                } else {
                    throw new Error(response.data.message || 'Failed to apply changes');
                }
            } catch (error) {
                console.error('Error applying changes:', error);
                EventBus.$emit(
                    'onFail',
                    error.response?.data?.message || error.message || 'Failed to apply changes'
                );
            } finally {
                this.applying = false;
            }
        },
        async updateStatus(newStatus) {
            try {
                const url = CI.base_url + '/api/issues/status/' + this.issueId;
                const response = await axios.post(url, { status: newStatus });

                if (response.data.status === 'success') {
                    EventBus.$emit('onSuccess', 'Status updated');
                    this.issue.status = newStatus;
                    this.editedIssue.status = newStatus;
                } else {
                    throw new Error(response.data.message || 'Failed to update status');
                }
            } catch (error) {
                console.error('Error updating status:', error);
                EventBus.$emit(
                    'onFail',
                    error.response?.data?.message || error.message || 'Failed to update status'
                );
            }
        },
        async deleteIssue() {
            if (!confirm('Are you sure you want to delete this issue?')) {
                return;
            }

            try {
                const url = CI.base_url + '/api/issues/' + this.issueId;
                const response = await axios.delete(url);

                if (response.data.status === 'success') {
                    EventBus.$emit('onSuccess', 'Issue deleted successfully');
                    this.$router.push('/issues');
                } else {
                    throw new Error(response.data.message || 'Failed to delete issue');
                }
            } catch (error) {
                console.error('Error deleting issue:', error);
                EventBus.$emit(
                    'onFail',
                    error.response?.data?.message || error.message || 'Failed to delete issue'
                );
            }
        },
        formatDate(timestamp) {
            if (!timestamp) return 'N/A';
            return moment.unix(timestamp).format('MMM D, YYYY');
        },
        formatMetadata(metadata) {
            if (!metadata || Object.keys(metadata).length === 0) {
                return 'No data';
            }
            return JSON.stringify(metadata, null, 2);
        },
        cancel() {
            if (this.editMode) {
                this.editMode = false;
                this.editedIssue = { ...this.issue };
            } else {
                this.$router.push('/issues');
            }
        },
        flattenFormData(obj, parentPath = '') {
            let fields = [];
            
            if (!obj || typeof obj !== 'object') {
                return fields;
            }
            
            if (Array.isArray(obj)) {
                // Handle arrays - add each item with index
                obj.forEach((item, index) => {
                    const currentPath = parentPath ? `${parentPath}[${index}]` : `[${index}]`;
                    
                    if (item && typeof item === 'object') {
                        // Recurse into array item
                        const nestedFields = this.flattenFormData(item, currentPath);
                        fields = fields.concat(nestedFields);
                    } else if (item !== null && item !== undefined) {
                        // Add primitive array item
                        fields.push({
                            text: currentPath,
                            value: currentPath
                        });
                    }
                });
            } else {
                // Handle objects
                Object.keys(obj).forEach(key => {
                    const value = obj[key];
                    const currentPath = parentPath ? `${parentPath}.${key}` : key;
                    
                    // Skip null or undefined values
                    if (value === null || value === undefined) {
                        return;
                    }
                    
                    if (typeof value === 'object') {
                        // Add the path itself for objects/arrays
                        fields.push({
                            text: currentPath,
                            value: currentPath
                        });
                        // Recurse into nested structure
                        const nestedFields = this.flattenFormData(value, currentPath);
                        fields = fields.concat(nestedFields);
                    } else {
                        // Add primitive value
                        fields.push({
                            text: currentPath,
                            value: currentPath
                        });
                    }
                });
            }
            
            return fields;
        },
        getValueByPath(obj, path) {
            // Extract value from nested object using dot notation and array indices
            if (!path || !obj) return undefined;
            
            let current = obj;
            // Split by dots but preserve array bracket notation
            const parts = path.split('.');
            
            for (let part of parts) {
                // Check if this part contains array access like "items[0]"
                const arrayMatch = part.match(/^([^\[]+)\[(\d+)\]$/);
                if (arrayMatch) {
                    const key = arrayMatch[1];
                    const index = parseInt(arrayMatch[2]);
                    if (current && typeof current === 'object' && key in current && Array.isArray(current[key])) {
                        current = current[key][index];
                    } else {
                        return undefined;
                    }
                } else if (part.match(/^\[(\d+)\]$/)) {
                    // Handle pure array access like "[0]"
                    const index = parseInt(part.match(/^\[(\d+)\]$/)[1]);
                    if (Array.isArray(current) && index < current.length) {
                        current = current[index];
                    } else {
                        return undefined;
                    }
                } else {
                    // Regular object key access
                    if (current && typeof current === 'object' && part in current) {
                        current = current[part];
                    } else {
                        return undefined;
                    }
                }
            }
            
            return current;
        },
        parseMetadataForDiff(val) {
            if (val == null) return null;
            if (typeof val === 'object') return val;
            if (typeof val === 'string') {
                try { return JSON.parse(val); } catch (e) { return { value: val }; }
            }
            return { value: String(val) };
        },
        renderMetadataDiff() {
            if (!this.issue) return;
            const container = this.$refs.metadataDiffContainer;
            if (!container) return;
            if (typeof JsonDiffKit === 'undefined') return;
            if (!document.contains(container)) return;

            const current = this.parseMetadataForDiff(this.issue.current_metadata);
            const suggested = this.parseMetadataForDiff(this.issue.suggested_metadata);
            const before = current !== null ? current : {};
            const after = suggested !== null ? suggested : {};

            try {
                if (this.diffRoot) {
                    try { this.diffRoot.unmount(); } catch (e) { /* ignore */ }
                    this.diffRoot = null;
                }
                container.innerHTML = '';
                const differ = new JsonDiffKit.Differ({
                    detectCircular: true,
                    showModifications: true,
                    arrayDiffMethod: 'lcs'
                });
                const diff = differ.diff(before, after);
                const viewer = new JsonDiffKit.Viewer({
                    diff: diff,
                    indent: 2,
                    lineNumbers: true,
                    highlightInlineDiff: true,
                    inlineDiffOptions: { mode: 'word', wordSeparator: ' ' },
                    syntaxHighlight: true
                });
                this.diffRoot = JsonDiffKit.ReactDOM.createRoot(container);
                this.diffRoot.render(JsonDiffKit.React.createElement(viewer.render));
            } catch (err) {
                console.error('Error rendering metadata diff:', err);
            }
        }
    },
    template: `
        <div class="issue-edit-page">
            <v-container fluid v-if="loading" style="max-width: 100% !important;">
                <v-row>
                    <v-col cols="12" class="text-center py-12">
                        <v-progress-circular indeterminate color="primary"></v-progress-circular>
                        <div class="mt-4">Loading issue...</div>
                    </v-col>
                </v-row>
            </v-container>

            <v-container fluid v-else-if="issue" style="max-width: 100% !important;" class="mt-4">
                <v-row>
                    <v-col cols="12">
                        <!-- Page Header -->
                        <div class="d-flex align-center mb-4">
                            <v-btn icon @click="cancel" class="mr-3">
                                <v-icon>mdi-arrow-left</v-icon>
                            </v-btn>
                            <div>
                                <h2 class="text-h5">
                                    [#{{ issue.id }}] - {{ issue.title }}
                                </h2>
                            </div>
                            <v-spacer></v-spacer>
                            
                            <!-- Action Buttons -->
                            <v-btn
                                v-if="!editMode && UserHasEditAccess"
                                color="primary"
                                outlined
                                @click="toggleEditMode"
                                class="mr-2"
                            >
                                <v-icon left>mdi-pencil</v-icon>
                                Edit
                            </v-btn>
                            <v-btn
                                v-if="editMode"
                                color="primary"
                                @click="saveChanges"
                                :loading="saving"
                                class="mr-2"
                            >
                                <v-icon left>mdi-content-save</v-icon>
                                Save
                            </v-btn>
                            <v-btn
                                v-if="editMode"
                                text
                                @click="cancel"
                            >
                                Cancel
                            </v-btn>
                            <v-menu v-if="!editMode && UserHasEditAccess">
                                <template v-slot:activator="{ on, attrs }">
                                    <v-btn
                                        icon
                                        v-bind="attrs"
                                        v-on="on"
                                    >
                                        <v-icon>mdi-dots-vertical</v-icon>
                                    </v-btn>
                                </template>
                                <v-list>
                                    <v-list-item v-if="canApply" @click="applyChanges">
                                        <v-list-item-icon>
                                            <v-icon>mdi-check-circle</v-icon>
                                        </v-list-item-icon>
                                        <v-list-item-title>Apply Changes</v-list-item-title>
                                    </v-list-item>
                                    <v-list-item @click="deleteIssue">
                                        <v-list-item-icon>
                                            <v-icon color="error">mdi-delete</v-icon>
                                        </v-list-item-icon>
                                        <v-list-item-title>Delete</v-list-item-title>
                                    </v-list-item>
                                </v-list>
                            </v-menu>
                        </div>

                        <!-- Issue Details Card -->
                        <v-card class="mb-4">
                            <v-card-text class="pa-6">
                                <!-- Status and Severity Row -->
                                <v-row dense class="mb-3">
                                    <v-col cols="12" md="3">
                                        <v-select
                                            v-if="editMode"
                                            v-model="editedIssue.status"
                                            :items="statusOptions"
                                            label="Status"
                                            outlined
                                            dense
                                        ></v-select>
                                        <div v-else>
                                            <div class="text-caption text--secondary">Status</div>
                                            <issue-status-badge :status="issue.status"></issue-status-badge>
                                        </div>
                                    </v-col>
                                    <v-col cols="12" md="3">
                                        <v-select
                                            v-if="editMode"
                                            v-model="editedIssue.severity"
                                            :items="severityOptions"
                                            label="Severity"
                                            outlined
                                            dense
                                            clearable
                                        ></v-select>
                                        <div v-else>
                                            <div class="text-caption text--secondary">Severity</div>
                                            <v-chip v-if="issue.severity" small :color="issue.severity === 'critical' ? 'error' : issue.severity === 'high' ? 'warning' : 'default'">
                                                {{ issue.severity }}
                                            </v-chip>
                                            <span v-else class="text--secondary">-</span>
                                        </div>
                                    </v-col>
                                    <v-col cols="12" md="3">
                                        <div class="text-caption text--secondary">Category</div>
                                        <v-combobox
                                            v-if="editMode"
                                            v-model="editedIssue.category"
                                            :items="categoryOptions"
                                            outlined
                                            dense
                                            clearable
                                        ></v-combobox>
                                        <div v-else>{{ issue.category || '-' }}</div>
                                    </v-col>
                                    <v-col cols="12" md="3">
                                        <div class="text-caption text--secondary">Created</div>
                                        <div>{{ formatDate(issue.created) }}</div>
                                    </v-col>
                                </v-row>

                                <v-divider class="my-4"></v-divider>

                                <!-- Title -->
                                <v-row dense>
                                    <v-col cols="12">
                                        <v-text-field
                                            v-if="editMode"
                                            v-model="editedIssue.title"
                                            label="Title *"
                                            outlined
                                            dense
                                            hint="Required"
                                            persistent-hint
                                        ></v-text-field>
                                        <div v-else>
                                            <div class="text-caption text--secondary">Title</div>
                                            <div class="mt-1">{{ issue.title || '-' }}</div>
                                        </div>
                                    </v-col>
                                </v-row>

                                <!-- Description -->
                                <v-row dense>
                                    <v-col cols="12">
                                        <v-textarea
                                            v-if="editMode"
                                            v-model="editedIssue.description"
                                            label="Description"
                                            outlined
                                            rows="3"
                                        ></v-textarea>
                                        <div v-else>
                                            <div class="text-caption text--secondary">Description</div>
                                            <div class="mt-1">{{ issue.description }}</div>
                                        </div>
                                    </v-col>
                                </v-row>

                                <!-- Notes -->
                                <v-row dense v-if="issue.notes || editMode">
                                    <v-col cols="12">
                                        <v-textarea
                                            v-if="editMode"
                                            v-model="editedIssue.notes"
                                            label="Notes"
                                            outlined
                                            rows="2"
                                        ></v-textarea>
                                        <div v-else>
                                            <div class="text-caption text--secondary">Notes</div>
                                            <div class="mt-1">{{ issue.notes }}</div>
                                        </div>
                                    </v-col>
                                </v-row>

                                <!-- Advanced Fields -->
                                <v-row dense class="mt-2">
                                    <v-col cols="12">
                                        <v-expansion-panels v-model="advancedPanel" elevation-1>
                                            <v-expansion-panel>
                                                <v-expansion-panel-header>
                                                    <div>
                                                        <v-icon left small>mdi-cog</v-icon>
                                                        <span class="text-subtitle-2">Advanced Fields</span>
                                                        <span class="text-caption text--secondary ml-2">(Field Path & Metadata Values)</span>
                                                    </div>
                                                </v-expansion-panel-header>
                                                <v-expansion-panel-content>
                                                    <!-- Field Path -->
                                                    <v-row dense v-if="issue.field_path || editMode" class="mt-2">
                                                        <v-col cols="12">
                                                            <v-autocomplete
                                                                v-if="editMode"
                                                                v-model="editedIssue.field_path"
                                                                :items="fieldPathOptions"
                                                                item-text="text"
                                                                item-value="value"
                                                                label="Field Path"
                                                                outlined
                                                                dense
                                                                clearable
                                                                placeholder="Select a field or type to search"
                                                                hint="Select from project metadata or type custom path"
                                                                persistent-hint
                                                            >
                                                                <template v-slot:item="{ item }">
                                                                    <v-list-item-content>
                                                                        <v-list-item-title>
                                                                            <code style="font-size: 12px;">{{ item.value }}</code>
                                                                        </v-list-item-title>
                                                                    </v-list-item-content>
                                                                </template>
                                                            </v-autocomplete>
                                                            <div v-else>
                                                                <div class="text-caption text--secondary">Field Path</div>
                                                                <code class="mt-1">{{ issue.field_path }}</code>
                                                            </div>
                                                        </v-col>
                                                    </v-row>

                                                    <!-- Metadata Comparison (show in both view and edit when issue has any metadata) -->
                                                    <v-row v-if="hasAnyMetadata || editMode" class="mt-3">
                                                        <v-col cols="12" md="6">
                                                            <div class="text-subtitle-2 mb-2">
                                                                <v-icon left small>mdi-file-document-outline</v-icon>
                                                                Current Metadata
                                                            </div>
                                                            <v-textarea
                                                                v-if="editMode"
                                                                v-model="currentMetadataText"
                                                                outlined
                                                                rows="8"
                                                                :error-messages="errors.current_metadata"
                                                            ></v-textarea>
                                                            <pre v-else style="background-color: #f5f5f5; padding: 12px; border-radius: 4px; overflow-x: auto; font-size: 12px; line-height: 1.5;">{{ formatMetadata(issue.current_metadata) }}</pre>
                                                        </v-col>
                                                        <v-col cols="12" md="6">
                                                            <div class="text-subtitle-2 mb-2">
                                                                <v-icon left small color="primary">mdi-file-document-edit-outline</v-icon>
                                                                Suggested Metadata
                                                            </div>
                                                            <v-textarea
                                                                v-if="editMode"
                                                                v-model="suggestedMetadataText"
                                                                outlined
                                                                rows="8"
                                                                :error-messages="errors.suggested_metadata"
                                                            ></v-textarea>
                                                            <pre v-else style="background-color: #f5f5f5; padding: 12px; border-radius: 4px; overflow-x: auto; font-size: 12px; line-height: 1.5;">{{ formatMetadata(issue.suggested_metadata) }}</pre>
                                                        </v-col>
                                                    </v-row>
                                                    <!-- JSON Diff preview (below current/suggested; show in both view and edit) -->
                                                    <v-row v-if="hasAnyMetadata || editMode" class="mt-3">
                                                        <v-col cols="12">
                                                            <div class="text-subtitle-2 mb-2">
                                                                <v-icon left small>mdi-compare</v-icon>
                                                                Diff preview
                                                            </div>
                                                            <div ref="metadataDiffContainer" class="metadata-diff-container" style="min-height: 120px; max-height: 400px; overflow: auto; background-color: #fafafa; border-radius: 4px; padding: 8px;"></div>
                                                        </v-col>
                                                    </v-row>
                                                </v-expansion-panel-content>
                                            </v-expansion-panel>
                                        </v-expansion-panels>
                                    </v-col>
                                </v-row>
                            </v-card-text>
                        </v-card>

                        <!-- Quick Actions (when not editing) -->
                        <v-card v-if="!editMode && UserHasEditAccess" class="mt-4">
                            <v-card-title class="text-subtitle-1">Quick Actions</v-card-title>
                            <v-card-text>
                                <v-chip-group>
                                    <v-chip @click="updateStatus('accepted')" :outlined="issue.status !== 'accepted'">
                                        <v-icon left small>mdi-check</v-icon>
                                        Accept
                                    </v-chip>
                                    <v-chip @click="updateStatus('fixed')" :outlined="issue.status !== 'fixed'">
                                        <v-icon left small>mdi-check-circle</v-icon>
                                        Mark Fixed
                                    </v-chip>
                                    <v-chip @click="updateStatus('rejected')" :outlined="issue.status !== 'rejected'">
                                        <v-icon left small>mdi-close</v-icon>
                                        Reject
                                    </v-chip>
                                    <v-chip @click="updateStatus('false_positive')" :outlined="issue.status !== 'false_positive'">
                                        <v-icon left small>mdi-alert-remove</v-icon>
                                        False Positive
                                    </v-chip>
                                </v-chip-group>
                            </v-card-text>
                        </v-card>
                    </v-col>
                </v-row>
            </v-container>
        </div>
    `
});
