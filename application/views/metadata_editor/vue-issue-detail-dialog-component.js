/**
 * Issue Detail Dialog Component
 * 
 * Modal dialog for viewing and editing issue details
 * Shows side-by-side comparison of current vs suggested metadata
 * 
 * Props:
 *   - value: Boolean - v-model for dialog visibility
 *   - issue: Object - Issue object to display
 *   - projectId: Number - Project ID
 * 
 * Events:
 *   - input: v-model update
 *   - issue-updated: Emitted after issue is updated
 *   - issue-applied: Emitted after changes are applied
 */
Vue.component('issue-detail-dialog', {
    props: {
        value: {
            type: Boolean,
            default: false
        },
        issue: {
            type: Object,
            default: null
        },
        projectId: {
            type: Number,
            required: true
        }
    },
    data() {
        return {
            loading: false,
            editMode: false,
            editedIssue: null,
            isMaximized: false,
            diffRoot: null,
            currentMetadataText: '',
            suggestedMetadataText: '',
            applyValueToApply: '',
            metadataPanels: [2], // Metadata and Suggested Metadata collapsed by default; Diff preview (index 2) open
            errors: {},
            statusOptions: [
                { text: 'Open', value: 'open' },
                { text: 'Accepted', value: 'accepted' },
                { text: 'Fixed', value: 'fixed' },
                { text: 'Rejected', value: 'rejected' },
                { text: 'Dismissed', value: 'dismissed' },
                { text: 'False Positive', value: 'false_positive' }
            ],
            severityOptions: [
                { text: 'Low', value: 'low' },
                { text: 'Medium', value: 'medium' },
                { text: 'High', value: 'high' },
                { text: 'Critical', value: 'critical' }
            ],
            categoryOptions: [
                'Typo / Wording',
                'Inconsistency',
                'Missing Data',
                'Format Issue'
            ],
            appliedOptions: [
                { text: 'No', value: 0 },
                { text: 'Yes', value: 1 }
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
        currentIssue() {
            return this.editMode ? this.editedIssue : this.issue;
        },
        hasCurrentMetadata() {
            return this.currentIssue && this.currentIssue.current_metadata && 
                   Object.keys(this.currentIssue.current_metadata).length > 0;
        },
        hasSuggestedMetadata() {
            return this.currentIssue && this.currentIssue.suggested_metadata && 
                   Object.keys(this.currentIssue.suggested_metadata).length > 0;
        },
        hasAnyMetadata() {
            return this.hasCurrentMetadata || this.hasSuggestedMetadata;
        },
        canApply() {
            let is_applied = this.issue && this.issue.applied;
            is_applied = Number(is_applied);
            return this.issue && this.hasSuggestedMetadata && !Boolean(is_applied);
        }
    },
    watch: {
        issue(newVal) {
            if (newVal) {
                this.editedIssue = JSON.parse(JSON.stringify(newVal));
                this.editMode = false;
                this.currentMetadataText = this.getMetadataFieldValue(newVal.current_metadata, newVal.field_path);
                this.suggestedMetadataText = this.getMetadataFieldValue(newVal.suggested_metadata, newVal.field_path);
                this.applyValueToApply = this.getMetadataFieldValue(newVal.suggested_metadata, newVal.field_path);
                this.$nextTick(() => this.renderMetadataDiff());
            }
        },
        value(newVal) {
            if (!newVal) {
                this.isMaximized = false;
                if (this.diffRoot && this.diffRoot.unmount) {
                    try { this.diffRoot.unmount(); } catch (e) { /* ignore */ }
                    this.diffRoot = null;
                }
                if (this.$refs && this.$refs.metadataDiffContainer) {
                    this.$refs.metadataDiffContainer.innerHTML = '';
                }
            } else {
                if (this.issue) {
                    this.applyValueToApply = this.getMetadataFieldValue(this.issue.suggested_metadata, this.issue.field_path);
                }
                this.$nextTick(() => this.renderMetadataDiff());
            }
        },
        currentMetadataText(val) {
            if (this.editMode) {
                this.parseMetadataText('current', val);
                this.$nextTick(() => this.renderMetadataDiff());
            }
        },
        suggestedMetadataText(val) {
            if (this.editMode) {
                this.parseMetadataText('suggested', val);
                this.$nextTick(() => this.renderMetadataDiff());
            }
        },
        metadataPanels() {
            this.$nextTick(() => this.renderMetadataDiff());
        }
    },
    methods: {
        formatDate(timestamp) {
            if (!timestamp) return '-';
            return moment.unix(timestamp).format('YYYY-MM-DD HH:mm');
        },
        formatMetadata(metadata) {
            if (metadata == null || (typeof metadata === 'object' && Object.keys(metadata).length === 0)) {
                return '';
            }
            const fieldPath = this.currentIssue && this.currentIssue.field_path;
            if (
                fieldPath &&
                typeof metadata === 'object' &&
                !Array.isArray(metadata) &&
                Object.prototype.hasOwnProperty.call(metadata, fieldPath)
            ) {
                const value = metadata[fieldPath];
                if (value === undefined || value === null) {
                    return '';
                }
                if (typeof value === 'object') {
                    return JSON.stringify(value, null, 2);
                }
                return String(value);
            }
            if (typeof metadata === 'object') {
                return JSON.stringify(metadata, null, 2);
            }
            return String(metadata);
        },
        getMetadataFieldValue(metadata, fieldPath) {
            if (metadata == null) return '';
            if (
                fieldPath &&
                typeof metadata === 'object' &&
                !Array.isArray(metadata) &&
                Object.prototype.hasOwnProperty.call(metadata, fieldPath)
            ) {
                const value = metadata[fieldPath];
                if (value === undefined || value === null) {
                    return '';
                }
                if (typeof value === 'object') {
                    return JSON.stringify(value, null, 2);
                }
                return String(value);
            }
            if (typeof metadata === 'object') {
                return JSON.stringify(metadata, null, 2);
            }
            return String(metadata);
        },
        parseMetadataText(type, text) {
            if (!this.editMode) return;
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
                const fieldPath = this.editedIssue.field_path || (this.issue && this.issue.field_path);
                if (type === 'current') {
                    this.editedIssue.current_metadata = fieldPath
                        ? { [fieldPath]: parsed }
                        : parsed;
                } else {
                    this.editedIssue.suggested_metadata = parsed;
                }
                this.errors[type + '_metadata'] = null;
            } catch (e) {
                const fieldPath = this.editedIssue.field_path || (this.issue && this.issue.field_path);
                if (fieldPath) {
                    const obj = {};
                    obj[fieldPath] = text;
                    if (type === 'current') {
                        this.editedIssue.current_metadata = obj;
                    } else {
                        this.editedIssue.suggested_metadata = obj;
                    }
                    this.errors[type + '_metadata'] = null;
                } else {
                    this.errors[type + '_metadata'] = 'Invalid JSON format or field path not set';
                }
            }
        },
        parseMetadataForDiff(val) {
            if (val == null) return null;

            const fieldPath = (this.currentIssue && this.currentIssue.field_path) || (this.issue && this.issue.field_path);
            let value = val;
            if (
                fieldPath &&
                typeof val === 'object' &&
                !Array.isArray(val) &&
                Object.prototype.hasOwnProperty.call(val, fieldPath)
            ) {
                value = val[fieldPath];
            }

            if (value == null) return null;
            if (typeof value === 'object') return value;
            if (typeof value === 'string') {
                try { return JSON.parse(value); } catch (e) { return { value: value }; }
            }
            return { value: String(value) };
        },
        renderMetadataDiff() {
            if (!this.hasAnyMetadata || !this.currentIssue) return;
            const container = this.$refs && this.$refs.metadataDiffContainer;
            if (!container) return;
            if (typeof JsonDiffKit === 'undefined') return;
            if (!document.contains(container)) return;

            const current = this.parseMetadataForDiff(this.currentIssue.current_metadata);
            const suggested = this.parseMetadataForDiff(this.currentIssue.suggested_metadata);
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
        },
        toggleEditMode() {
            if (this.editMode) {
                // Cancel edit
                this.editedIssue = JSON.parse(JSON.stringify(this.issue));
                this.editMode = false;
                this.currentMetadataText = this.getMetadataFieldValue(this.issue.current_metadata, this.issue.field_path);
                this.suggestedMetadataText = this.getMetadataFieldValue(this.issue.suggested_metadata, this.issue.field_path);
                this.$nextTick(() => this.renderMetadataDiff());
            } else {
                this.editMode = true;
                const fieldPath = (this.currentIssue && this.currentIssue.field_path) || (this.issue && this.issue.field_path);
                this.currentMetadataText = this.getMetadataFieldValue(this.currentIssue.current_metadata, fieldPath);
                this.suggestedMetadataText = this.getMetadataFieldValue(this.currentIssue.suggested_metadata, fieldPath);
                this.$nextTick(() => this.renderMetadataDiff());
            }
        },
        showToast(message, type) {
            if (this.$root.$refs && this.$root.$refs.toast && typeof this.$root.$refs.toast.showAlert === 'function') {
                this.$root.$refs.toast.showAlert(message, type);
            } else {
                console.warn('[issue-detail-dialog] Toast not available:', message, type);
            }
        },
        async saveChanges() {
            const title = (this.editedIssue.title !== undefined && this.editedIssue.title !== null ? String(this.editedIssue.title) : '').trim();
            if (!title) {
                this.showToast('Title is required', 'error');
                return;
            }
            this.loading = true;
            try {
                const url = CI.base_url + '/api/issues/' + this.editedIssue.id;
                const response = await axios.put(url, this.editedIssue);

                if (response.data.status === 'success') {
                    this.showToast('Issue updated successfully', 'success');
                    this.$emit('issue-updated', response.data.issue);
                    this.editMode = false;
                } else {
                    throw new Error(response.data.message || 'Failed to update issue');
                }
            } catch (error) {
                console.error('Error updating issue:', error);
                this.showToast(
                    error.response?.data?.message || error.message || 'Failed to update issue',
                    'error'
                );
            } finally {
                this.loading = false;
            }
        },
        getValueToApplyPayload() {
            const text = (this.applyValueToApply != null && String(this.applyValueToApply).trim()) ? String(this.applyValueToApply).trim() : '';
            if (!text) return null;
            try {
                return JSON.parse(text);
            } catch (e) {
                return text;
            }
        },
        /**
         * Set a nested value in obj by dot path (e.g. "table_description.title_statement.table_number").
         * Creates missing objects; uses Vue.set for reactivity when adding new keys.
         */
        setValueByPath(obj, path, value) {
            if (!path || !obj || typeof obj !== 'object') return;
            const parts = path.split('.');
            let current = obj;
            for (let i = 0; i < parts.length - 1; i++) {
                const key = parts[i];
                if (!(key in current) || current[key] == null) {
                    Vue.set(current, key, {});
                }
                current = current[key];
            }
            Vue.set(current, parts[parts.length - 1], value);
        },
        applyChanges() {
            const valueToApply = this.getValueToApplyPayload();
            if (valueToApply === null && (!this.applyValueToApply || !String(this.applyValueToApply).trim())) {
                this.showToast('Enter a value to apply', 'warning');
                return;
            }
            if (!confirm('Apply this value to the project metadata field?')) {
                return;
            }
            const fieldPath = this.issue && this.issue.field_path;
            if (!fieldPath) {
                this.showToast('No field path on this issue', 'error');
                return;
            }
            const formData = this.$store.state.formData;
            if (!formData || typeof formData !== 'object') {
                this.showToast('Project metadata not loaded', 'error');
                return;
            }
            this.loading = true;
            try {
                const value = valueToApply !== null ? valueToApply : String(this.applyValueToApply || '').trim();
                this.setValueByPath(formData, fieldPath, value);
                this.showToast('Changes applied to project metadata', 'success');
                this.$emit('issue-applied', this.issue);
            } catch (error) {
                console.error('Error applying changes:', error);
                this.showToast(error.message || 'Failed to apply changes', 'error');
            } finally {
                this.loading = false;
            }
        },
        async updateStatus(status) {
            this.loading = true;
            try {
                const url = CI.base_url + '/api/issues/' + this.currentIssue.id + '/status';
                const response = await axios.post(url, { status });

                if (response.data.status === 'success') {
                    this.showToast('Status updated successfully', 'success');
                    this.$emit('issue-updated', response.data.issue);
                } else {
                    throw new Error(response.data.message || 'Failed to update status');
                }
            } catch (error) {
                console.error('Error updating status:', error);
                this.showToast(
                    error.response?.data?.message || error.message || 'Failed to update status',
                    'error'
                );
            } finally {
                this.loading = false;
            }
        },
        close() {
            this.editMode = false;
            this.dialogVisible = false;
        }
    },
    template: `
        <v-dialog
            v-model="dialogVisible"
            :max-width="isMaximized ? undefined : '1200px'"
            :fullscreen="isMaximized"
            scrollable
            transition="dialog-transition"
            content-class="issue-detail-dialog"
        >
            <v-card v-if="currentIssue" class="d-flex flex-column" :class="{ 'fill-height': isMaximized }">
                <!-- Header -->
                <v-card-title class="headline grey lighten-2 flex-shrink-0">
                    <v-icon left>mdi-alert-circle-outline</v-icon>
                    <div class="d-flex flex-column flex-grow-1">
                        <div class="d-flex align-center">
                            <span class="mr-2">[#{{ currentIssue.id }}]</span>
                            <span class="font-weight-medium text-truncate" style="max-width: 100%;">
                                {{ currentIssue.title || 'Issue details' }}
                            </span>
                        </div>
                    </div>
                    <v-spacer></v-spacer>
                    <v-btn icon @click="isMaximized = !isMaximized" :title="isMaximized ? 'Restore' : 'Maximize'">
                        <v-icon>{{ isMaximized ? 'mdi-window-restore' : 'mdi-window-maximize' }}</v-icon>
                    </v-btn>
                    <v-btn icon @click="close">
                        <v-icon>mdi-close</v-icon>
                    </v-btn>
                </v-card-title>

                <v-card-text class="pt-4 flex-grow-1 overflow-auto dialog-content-resizable" style="min-height: 280px; resize: both;">
                    <v-container fluid>
                        <v-row>
                            <!-- Main column: core narrative and metadata -->
                            <v-col cols="12" md="9" class="border rounded-lg p-4">
                                <!-- Title (edit mode only; view-mode title is in header) -->
                                <v-row dense class="mb-3" v-if="editMode">
                                    <v-col cols="12">
                                        <v-text-field
                                            v-model="editedIssue.title"
                                            label="Title *"
                                            outlined
                                            dense
                                            hint="Required"
                                            persistent-hint
                                        ></v-text-field>
                                    </v-col>
                                </v-row>

                                <!-- Description -->
                                <v-row dense class="mb-3" no-gutters>
                                    <v-col cols="12" class="px-0">
                                        <v-textarea
                                            v-if="editMode"
                                            v-model="editedIssue.description"
                                            label="Description"
                                            outlined
                                            rows="3"
                                        ></v-textarea>
                                        <v-textarea
                                            v-else
                                            :value="currentIssue.description"
                                            label="Description"
                                            outlined
                                            rows="3"
                                            disabled
                                            hide-details
                                        ></v-textarea>
                                    </v-col>
                                </v-row>

                                <!-- Field Path -->
                                <v-row dense class="mb-3" no-gutters v-if="currentIssue.field_path">
                                    <v-col cols="12" class="px-0">
                                        <v-text-field
                                            :value="currentIssue.field_path"
                                            label="Field Path"
                                            outlined
                                            dense
                                            disabled
                                            hide-details
                                        ></v-text-field>
                                    </v-col>
                                </v-row>

                                <!-- Collapsible: Metadata -->
                                <v-expansion-panels v-model="metadataPanels" multiple class="mb-3">
                                    <v-expansion-panel v-if="hasCurrentMetadata || editMode">
                                        <v-expansion-panel-header class="justify-start">
                                            <span class="d-flex align-center">
                                                <v-icon left small class="mr-2">mdi-file-document-outline</v-icon>
                                                Metadata
                                            </span>
                                        </v-expansion-panel-header>
                                        <v-expansion-panel-content>
                                            <v-textarea
                                                v-if="editMode"
                                                v-model="currentMetadataText"
                                                outlined
                                                rows="5"
                                                :error-messages="errors.current_metadata"
                                                style="max-height: 250px; overflow-y: auto;"
                                            ></v-textarea>
                                            <pre
                                                v-else
                                                class="text-caption"
                                                style="white-space: pre-wrap; word-wrap: break-word; background-color: #fafafa; padding: 8px; border-radius: 4px; max-height: 250px; overflow: auto;"
                                            >{{ formatMetadata(currentIssue.current_metadata) }}</pre>
                                        </v-expansion-panel-content>
                                    </v-expansion-panel>

                                    <!-- Collapsible: Suggested Metadata + Apply -->
                                    <v-expansion-panel v-if="hasSuggestedMetadata || editMode">
                                        <v-expansion-panel-header class="justify-start">
                                            <span class="d-flex align-center">
                                                <v-icon left small class="mr-2">mdi-file-document-edit-outline</v-icon>
                                                Suggested Metadata
                                                <v-chip v-if="issue && issue.applied" x-small color="success" class="ml-2">Applied</v-chip>
                                            </span>
                                        </v-expansion-panel-header>
                                        <v-expansion-panel-content>
                                            <v-textarea
                                                v-if="editMode"
                                                v-model="suggestedMetadataText"
                                                outlined
                                                rows="5"
                                                :error-messages="errors.suggested_metadata"
                                                style="max-height: 250px; overflow-y: auto;"
                                            ></v-textarea>
                                            <template v-else>
                                                <v-textarea
                                                    class="mt-2"
                                                    v-model="applyValueToApply"
                                                    outlined
                                                    dense
                                                    rows="4"
                                                    placeholder="Value to apply"
                                                    style="max-height: 250px; overflow-y: auto;"
                                                ></v-textarea>
                                                <v-btn
                                                    color="primary"
                                                    small
                                                    class="mt-2"
                                                    @click="applyChanges"
                                                    :loading="loading"
                                                >
                                                    <v-icon small left>mdi-check</v-icon>
                                                    Apply to field
                                                </v-btn>
                                            </template>
                                        </v-expansion-panel-content>
                                    </v-expansion-panel>

                                    <!-- Collapsible: Diff preview -->
                                    <v-expansion-panel v-if="hasSuggestedMetadata">
                                        <v-expansion-panel-header class="justify-start">
                                            <span class="d-flex align-center">
                                                <v-icon left small class="mr-2">mdi-compare</v-icon>
                                                Diff preview
                                            </span>
                                        </v-expansion-panel-header>
                                        <v-expansion-panel-content>
                                            <div
                                                ref="metadataDiffContainer"
                                                class="metadata-diff-container"
                                                style="min-height: 120px; max-height: 400px; overflow: auto; background-color: #fafafa; border-radius: 4px; padding: 8px;"
                                            ></div>
                                        </v-expansion-panel-content>
                                    </v-expansion-panel>
                                </v-expansion-panels>
                            </v-col>

                            <!-- Sidebar: all other fields -->
                            <v-col cols="12" md="3" class="p-3">
                                <div class="sidebar-wrapper ml-4">   
                                <!-- Status and Severity -->
                                <v-row dense class="mb-3">
                                    <v-col cols="12">
                                        <div class="text-caption text--secondary mb-1">Status</div>
                                        <v-select
                                            v-if="editMode"
                                            v-model="editedIssue.status"
                                            :items="statusOptions"
                                            label="Status"
                                            outlined
                                            dense
                                        ></v-select>
                                        <v-select
                                            v-else
                                            :items="statusOptions"
                                            :value="currentIssue.status"
                                            label="Status"
                                            outlined
                                            dense
                                            disabled
                                        ></v-select>
                                    </v-col>
                                </v-row>
                                <v-row dense class="mb-3">
                                    <v-col cols="12">
                                        <div class="text-caption text--secondary mb-1">Severity</div>
                                        <v-select
                                            v-if="editMode"
                                            v-model="editedIssue.severity"
                                            :items="severityOptions"
                                            label="Severity"
                                            outlined
                                            dense
                                            clearable
                                        ></v-select>
                                        <v-select
                                            v-else
                                            :items="severityOptions"
                                            :value="currentIssue.severity"
                                            label="Severity"
                                            outlined
                                            dense
                                            disabled
                                        ></v-select>
                                    </v-col>
                                </v-row>

                                <!-- Category -->
                                <v-row dense class="mb-3">
                                    <v-col cols="12">
                                        <div class="text-caption text--secondary mb-1">Category</div>
                                        <v-combobox
                                            v-if="editMode"
                                            v-model="editedIssue.category"
                                            :items="categoryOptions"
                                            label="Category"
                                            outlined
                                            dense
                                        ></v-combobox>
                                        <v-combobox
                                            v-else
                                            :items="categoryOptions"
                                            :value="currentIssue.category"
                                            label="Category"
                                            outlined
                                            dense
                                            disabled
                                        ></v-combobox>
                                    </v-col>
                                </v-row>

                                <!-- Created / Applied -->
                                <v-row dense class="mb-3">
                                    <v-col cols="12">
                                        <div class="text-caption text--secondary">Created</div>
                                        <div>{{ formatDate(currentIssue.created) }}</div>
                                    </v-col>
                                </v-row>
                                <v-row dense class="mb-3" v-if="hasSuggestedMetadata">
                                    <v-col cols="12">
                                        <div class="text-caption text--secondary mb-1">Suggestions applied</div>
                                        <v-select
                                            v-if="editMode"
                                            v-model="editedIssue.applied"
                                            :items="appliedOptions"
                                            item-text="text"
                                            item-value="value"
                                            label="Suggestions applied"
                                            outlined
                                            dense
                                            hide-details
                                        ></v-select>
                                        <template v-else>
                                            <v-chip small :color="currentIssue.applied ? 'success' : 'grey'" outlined>
                                                {{ currentIssue.applied ? 'Yes' : 'No' }}
                                            </v-chip>
                                            <div v-if="currentIssue.applied && currentIssue.applied_on" class="text-caption mt-1 text--secondary">
                                                {{ formatDate(currentIssue.applied_on) }}
                                                <span v-if="currentIssue.applied_by"> by user #{{ currentIssue.applied_by }}</span>
                                            </div>
                                        </template>
                                    </v-col>
                                </v-row>

                                <!-- Notes -->
                                <v-row dense class="mb-3">
                                    <v-col cols="12">
                                        <div class="text-caption text--secondary mb-1">Notes</div>
                                        <v-textarea
                                            v-if="editMode"
                                            v-model="editedIssue.notes"
                                            label="Notes"
                                            outlined
                                            rows="2"
                                        ></v-textarea>
                                        <v-textarea
                                            v-else
                                            :value="currentIssue.notes"
                                            label="Notes"
                                            outlined
                                            rows="2"
                                            readonly
                                            hide-details
                                            :placeholder="currentIssue.notes ? '' : 'Not set'"
                                        ></v-textarea>
                                    </v-col>
                                </v-row>

                                <!-- Source -->
                                <v-row dense v-if="currentIssue.source">
                                    <v-col cols="12">
                                        <div class="text-caption text--secondary">
                                            Source: <span class="text-capitalize">{{ currentIssue.source }}</span>
                                        </div>
                                        </v-col>
                                    </v-row>
                                </div>
                            </v-col>
                        </v-row>
                    </v-container>
                </v-card-text>

                <v-divider></v-divider>

                <!-- Footer -->
                <v-card-actions class="pa-4">
                    <v-spacer></v-spacer>

                    <v-btn
                        v-if="editMode"
                        text
                        @click="toggleEditMode"
                    >
                        Cancel
                    </v-btn>
                    <v-btn
                        v-if="editMode"
                        color="primary"
                        @click="saveChanges"
                        :loading="loading"
                    >
                        Save Changes
                    </v-btn>
                    <v-btn
                        v-if="!editMode"
                        outlined
                        @click="toggleEditMode"
                    >
                        <v-icon left>mdi-pencil</v-icon>
                        Edit
                    </v-btn>
                    <v-btn
                        text
                        @click="close"
                    >
                        Close
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    `,
    // Ensure collapsible section headers show icon + title on the left
    created() {
        const style = document.createElement('style');
        style.textContent = `
            .issue-detail-dialog .v-expansion-panel-header.justify-start { justify-content: flex-start; }
            .issue-detail-dialog .v-expansion-panel-header.justify-start > *:first-child { flex: 0 1 auto; }
        `;
        if (document.head) document.head.appendChild(style);
        this._headerStyleEl = style;
    },
    beforeDestroy() {
        if (this._headerStyleEl && this._headerStyleEl.parentNode) {
            this._headerStyleEl.parentNode.removeChild(this._headerStyleEl);
        }
    }
});
