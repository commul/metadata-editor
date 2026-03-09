/**
 * Issue Detail View Component
 *
 * Full-page view of a single issue. Edit controls shown only when canEdit is true.
 * Fetches issue by ID from API.
 *
 * Props:
 *   - issueId: Number (required) - Issue ID to load and display
 *   - canEdit: Boolean - Whether the user can edit (status, description, apply, etc.)
 *
 * Used on: issues/edit/{id} page
 */
Vue.component('issue-detail-view', {
    props: {
        issueId: {
            type: Number,
            required: true
        },
        canEdit: {
            type: Boolean,
            default: false
        }
    },
    data() {
        return {
            issue: null,
            editedIssue: null,
            editMode: false,
            loading: true,
            saving: false,
            loadError: null,
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
                'Format Issue',
                'Completeness',
                'Other'
            ],
            diffRoot: null
        };
    },
    computed: {
        baseUrl() {
            return (typeof CI !== 'undefined' && CI.site_url ? CI.site_url : '').replace(/\/?$/, '');
        },
        apiBase() {
            return this.baseUrl + '/api/issues';
        },
        issuesListUrl() {
            return this.baseUrl + '/issues';
        },
        projectUrl() {
            if (!this.issue || !this.issue.project_id) return '#';
            return this.baseUrl + '/editor/edit/' + this.issue.project_id;
        },
        currentIssue() {
            return this.editMode ? this.editedIssue : this.issue;
        },
        hasCurrentMetadata() {
            return this.currentIssue && this.currentIssue.current_metadata && Object.keys(this.currentIssue.current_metadata).length > 0;
        },
        hasSuggestedMetadata() {
            return this.currentIssue && this.currentIssue.suggested_metadata && Object.keys(this.currentIssue.suggested_metadata).length > 0;
        },
        canApply() {
            return this.canEdit && this.hasSuggestedMetadata && this.currentIssue && !this.currentIssue.applied;
        },
        canShowMetadataDiff() {
            return typeof JsonDiffKit !== 'undefined';
        }
    },
    watch: {
        issueId: {
            immediate: true,
            handler(id) {
                if (id) this.fetchIssue();
            }
        },
        issue(val) {
            if (val) {
                this.editedIssue = JSON.parse(JSON.stringify(val));
                this.editMode = true;
            }
        }
    },
    updated() {
        this.$nextTick(() => this.renderMetadataDiff());
    },
    beforeDestroy() {
        if (this.diffRoot && this.diffRoot.unmount) {
            this.diffRoot.unmount();
            this.diffRoot = null;
        }
    },
    methods: {
        fetchIssue() {
            this.loading = true;
            this.loadError = null;
            this.issue = null;
            this.editedIssue = null;
            this.editMode = true;
            const url = this.apiBase + '/' + this.issueId;
            axios.get(url)
                .then(res => {
                    if (res.data && res.data.status === 'success' && res.data.issue) {
                        this.issue = res.data.issue;
                        this.editedIssue = JSON.parse(JSON.stringify(res.data.issue));
                    } else {
                        this.loadError = res.data && res.data.message ? res.data.message : 'Failed to load issue';
                    }
                })
                .catch(err => {
                    const msg = err.response && err.response.data && err.response.data.message
                        ? err.response.data.message
                        : (err.message || 'Failed to load issue');
                    this.loadError = msg;
                    if (err.response && err.response.status === 404) {
                        this.loadError = 'Issue not found';
                    }
                })
                .finally(() => { this.loading = false; });
        },
        showAlert(message, type) {
            if (typeof EventBus !== 'undefined' && EventBus.$emit) {
                EventBus.$emit('alert', { message: message });
            } else {
                alert(message);
            }
        },
        formatDate(timestamp) {
            if (!timestamp) return '-';
            return typeof moment !== 'undefined' ? moment.unix(timestamp).format('YYYY-MM-DD HH:mm') : new Date(timestamp * 1000).toLocaleString();
        },
        formatMetadata(metadata) {
            if (metadata == null || metadata === '') return '';
            if (typeof metadata === 'string') return metadata;
            if (typeof metadata === 'object') return JSON.stringify(metadata, null, 2);
            return String(metadata);
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
            if (!this.issue || !this.currentIssue) return;
            const container = this.$refs.metadataDiffContainer;
            if (!container) return;
            if (typeof JsonDiffKit === 'undefined') return;
            const current = this.parseMetadataForDiff(this.currentIssue.current_metadata);
            const suggested = this.parseMetadataForDiff(this.currentIssue.suggested_metadata);
            if (current === null && suggested === null) return;
            const before = current !== null ? current : {};
            const after = suggested !== null ? suggested : {};
            try {
                if (this.diffRoot) {
                    this.diffRoot.unmount();
                    this.diffRoot = null;
                }
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
                console.error('Metadata diff render error:', err);
            }
        },
        formatStatus(status) {
            return (status || '').replace(/_/g, ' ');
        },
        getSeverityColor(severity) {
            const colors = { low: 'blue', medium: 'orange', high: 'deep-orange', critical: 'red' };
            return colors[severity] || 'grey';
        },
        getStatusColor(status) {
            const colors = {
                open: 'primary', accepted: 'blue', fixed: 'success',
                rejected: 'error', dismissed: 'grey', false_positive: 'warning'
            };
            return colors[status] || 'grey';
        },
        saveChanges() {
            const title = (this.editedIssue.title || '').trim();
            if (!title) {
                this.showAlert('Title is required');
                return;
            }
            this.saving = true;
            const url = this.apiBase + '/' + this.editedIssue.id;
            axios.put(url, {
                title: this.editedIssue.title,
                description: this.editedIssue.description,
                category: this.editedIssue.category,
                severity: this.editedIssue.severity,
                status: this.editedIssue.status,
                notes: this.editedIssue.notes
            })
                .then(res => {
                    if (res.data && res.data.status === 'success' && res.data.issue) {
                        this.issue = res.data.issue;
                        this.editedIssue = JSON.parse(JSON.stringify(res.data.issue));
                        this.editMode = true;
                        this.showAlert('Issue updated successfully');
                    } else {
                        throw new Error(res.data && res.data.message ? res.data.message : 'Failed to update');
                    }
                })
                .catch(err => {
                    const msg = err.response && err.response.data && err.response.data.message
                        ? err.response.data.message
                        : (err.message || 'Failed to update issue');
                    this.showAlert(msg);
                })
                .finally(() => { this.saving = false; });
        },
        applyChanges() {
            if (!confirm('Apply the suggested changes to the project metadata?')) return;
            this.saving = true;
            const url = this.apiBase + '/' + this.currentIssue.id + '/apply';
            axios.post(url)
                .then(res => {
                    if (res.data && res.data.status === 'success' && res.data.issue) {
                        this.issue = res.data.issue;
                        this.editedIssue = JSON.parse(JSON.stringify(res.data.issue));
                        this.showAlert('Changes applied successfully');
                    } else {
                        throw new Error(res.data && res.data.message ? res.data.message : 'Failed to apply');
                    }
                })
                .catch(err => {
                    const msg = err.response && err.response.data && err.response.data.message
                        ? err.response.data.message
                        : (err.message || 'Failed to apply changes');
                    this.showAlert(msg);
                })
                .finally(() => { this.saving = false; });
        },
        updateStatus(status) {
            this.saving = true;
            const url = this.apiBase + '/' + this.currentIssue.id + '/status';
            axios.post(url, { status: status })
                .then(res => {
                    if (res.data && res.data.status === 'success' && res.data.issue) {
                        this.issue = res.data.issue;
                        this.editedIssue = JSON.parse(JSON.stringify(res.data.issue));
                        this.showAlert('Status updated successfully');
                    } else {
                        throw new Error(res.data && res.data.message ? res.data.message : 'Failed to update status');
                    }
                })
                .catch(err => {
                    const msg = err.response && err.response.data && err.response.data.message
                        ? err.response.data.message
                        : (err.message || 'Failed to update status');
                    this.showAlert(msg);
                })
                .finally(() => { this.saving = false; });
        }
    },
    template: `
        <div class="issue-detail-view container ml-0">
            <v-progress-linear v-if="loading" indeterminate color="primary" class="mb-4"></v-progress-linear>

            <template v-else-if="loadError">
                <v-alert type="error" dismissible class="mb-4">
                    {{ loadError }}
                </v-alert>
                <v-btn :href="issuesListUrl" outlined>
                    <v-icon left>mdi-arrow-left</v-icon>
                    Back to Issues
                </v-btn>
            </template>

            <v-card v-else-if="issue">
                <v-card-title class="grey lighten-2 d-flex align-center flex-wrap">
                    [#{{ issue.id }}]
                    {{ issue.title || 'No title' }}                                        
                </v-card-title>
                <v-card-subtitle class="pl-4 pt-2 pb-0">
                    
                </v-card-subtitle>

                <v-card-text class="pt-4">
                    <v-container>
                        <v-row>
                            <!-- Single Column Layout -->
                            <v-col cols="12">
                                <!-- Title and Description -->
                                <div class="text-caption text--secondary mb-1">Title</div>
                                <v-text-field
                                    v-if="canEdit"
                                    v-model="editedIssue.title"
                                    label=""
                                    outlined
                                    dense
                                    class="mb-3"
                                    hint="Required"
                                    persistent-hint
                                ></v-text-field>
                                <div v-else class="mb-3 text-body-1 font-weight-medium">{{ currentIssue.title || '-' }}</div>

                                <div class="text-caption text--secondary mb-1">Description</div>
                                <v-textarea
                                    v-if="canEdit"
                                    v-model="editedIssue.description"
                                    label=""
                                    outlined
                                    rows="8"
                                    hide-details
                                    class="flex-grow-1"
                                ></v-textarea>
                                <div v-else class="pa-3 grey lighten-4 rounded text-body-1" style="min-height: 120px;">{{ currentIssue.description || '-' }}</div>

                                <!-- Notes -->
                                <div class="text-caption text--secondary mb-1 mt-2">Notes</div>
                                <v-textarea
                                    v-if="canEdit"
                                    v-model="editedIssue.notes"
                                    label=""
                                    outlined
                                    rows="2"
                                    hide-details
                                    class="mb-3"
                                ></v-textarea>
                                <div v-else-if="currentIssue.notes" class="mb-3">{{ currentIssue.notes }}</div>
                                <div v-else class="text--disabled mb-3">-</div>

                                <!-- Field Path -->
                                <div class="text-caption text--secondary mb-1 mt-3">Field path</div>
                                <code class="pa-2 grey lighten-3 d-inline-block mb-3">{{ currentIssue.field_path || '-' }}</code>

                                <!-- Current vs Suggested Metadata -->
                                <div class="text-caption text--secondary mb-1 mt-2">Current vs suggested metadata</div>
                                <v-card outlined class="mb-3">
                                    <v-card-text class="py-2">
                                        <div ref="metadataDiffContainer" class="metadata-diff-container" style="min-height: 120px; max-height: 400px; overflow: auto;"></div>
                                        <div v-if="!canShowMetadataDiff" class="text-caption pa-2 grey lighten-4 rounded">
                                            <div class="mb-2"><strong>Current:</strong></div>
                                            <pre class="ma-0" style="white-space: pre-wrap; word-wrap: break-word;">{{ formatMetadata(currentIssue.current_metadata) || '-' }}</pre>
                                            <div class="mb-2 mt-3"><strong>Suggested:</strong></div>
                                            <pre class="ma-0" style="white-space: pre-wrap; word-wrap: break-word;">{{ formatMetadata(currentIssue.suggested_metadata) || '-' }}</pre>
                                        </div>
                                    </v-card-text>
                                </v-card>

                                <!-- Project and Status Information -->
                                <div class="text-caption text--secondary mb-1 mt-3">Project</div>
                                <a :href="projectUrl" target="_blank" class="text-body-1 d-block mb-3">{{ currentIssue.project_title || ('Project ' + currentIssue.project_id) }}</a>

                                <!-- Status, Severity, and Category in One Row -->
                                <v-row>
                                    <v-col cols="12" md="4">
                                        <div class="text-caption text--secondary mb-1">Status</div>
                                        <v-select
                                            v-if="canEdit"
                                            v-model="editedIssue.status"
                                            :items="statusOptions"
                                            label=""
                                            outlined
                                            dense
                                            hide-details
                                            class="mb-3"
                                        ></v-select>
                                        <template v-else>
                                            <v-chip small :color="getStatusColor(currentIssue.status)" :outlined="currentIssue.status === 'open'" class="text-capitalize mb-3">{{ formatStatus(currentIssue.status) }}</v-chip>
                                        </template>
                                    </v-col>

                                    <v-col cols="12" md="4">
                                        <div class="text-caption text--secondary mb-1">Severity</div>
                                        <v-select
                                            v-if="canEdit"
                                            v-model="editedIssue.severity"
                                            :items="severityOptions"
                                            label=""
                                            outlined
                                            dense
                                            hide-details
                                            clearable
                                            class="mb-3"
                                        ></v-select>
                                        <template v-else>
                                            <v-chip v-if="currentIssue.severity" small :color="getSeverityColor(currentIssue.severity)" outlined class="text-capitalize mb-3">{{ currentIssue.severity }}</v-chip>
                                            <span v-else class="text--disabled d-block mb-3">-</span>
                                        </template>
                                    </v-col>

                                    <v-col cols="12" md="4">
                                        <div class="text-caption text--secondary mb-1">Category</div>
                                        <v-combobox
                                            v-if="canEdit"
                                            v-model="editedIssue.category"
                                            :items="categoryOptions"
                                            label=""
                                            outlined
                                            dense
                                            hide-details
                                            class="mb-3"
                                        ></v-combobox>
                                        <template v-else>
                                            <v-chip v-if="currentIssue.category" small outlined class="mb-3">{{ currentIssue.category }}</v-chip>
                                            <span v-else class="text--disabled d-block mb-3">-</span>
                                        </template>
                                    </v-col>
                                </v-row>

                                <!-- Additional Info -->
                                <div class="text-caption text--secondary mb-1">Created</div>
                                <div class="mb-3">{{ formatDate(currentIssue.created) }}</div>

                                <div class="text-caption text--secondary mb-1">Applied</div>
                                <v-chip small :color="currentIssue.applied ? 'success' : 'grey'" outlined class="mb-3">{{ currentIssue.applied ? 'Yes' : 'No' }}</v-chip>

                                <div v-if="currentIssue.source" class="text-caption text--secondary mb-2">Source: <span class="text-capitalize">{{ currentIssue.source }}</span></div>
                            </v-col>
                        </v-row>
                    </v-container>
                </v-card-text>

                <!-- Actions (only when canEdit) -->
                <v-divider v-if="canEdit"></v-divider>
                <v-card-actions v-if="canEdit" class="pa-4">
                    <v-btn v-if="canApply" color="primary" @click="applyChanges" :loading="saving">
                        <v-icon left>mdi-check</v-icon>
                        Apply changes
                    </v-btn>
                    <v-menu offset-y>
                        <template v-slot:activator="{ on, attrs }">
                            <v-btn outlined v-bind="attrs" v-on="on">
                                Quick actions
                                <v-icon right>mdi-menu-down</v-icon>
                            </v-btn>
                        </template>
                        <v-list dense>
                            <v-list-item @click="updateStatus('false_positive')"><v-list-item-title>Mark as False Positive</v-list-item-title></v-list-item>
                            <v-list-item @click="updateStatus('accepted')"><v-list-item-title>Accept</v-list-item-title></v-list-item>
                            <v-list-item @click="updateStatus('rejected')"><v-list-item-title>Reject</v-list-item-title></v-list-item>
                            <v-list-item @click="updateStatus('dismissed')"><v-list-item-title>Dismiss</v-list-item-title></v-list-item>
                        </v-list>
                    </v-menu>
                    <v-spacer></v-spacer>
                    <v-btn :href="issuesListUrl" outlined>
                        <v-icon left>mdi-arrow-left</v-icon>
                        Back to Issues
                    </v-btn>
                    <v-btn color="primary" @click="saveChanges" :loading="saving">Save changes</v-btn>
                </v-card-actions>
            </v-card>
        </div>
    `
});
