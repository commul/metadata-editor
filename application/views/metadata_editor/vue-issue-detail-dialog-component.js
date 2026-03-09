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
        canApply() {
            return this.hasSuggestedMetadata && !this.currentIssue.applied;
        }
    },
    watch: {
        issue(newVal) {
            if (newVal) {
                this.editedIssue = JSON.parse(JSON.stringify(newVal));
                this.editMode = false;
            }
        }
    },
    methods: {
        formatDate(timestamp) {
            if (!timestamp) return '-';
            return moment.unix(timestamp).format('YYYY-MM-DD HH:mm');
        },
        formatMetadata(metadata) {
            if (!metadata || typeof metadata !== 'object') return '';
            return JSON.stringify(metadata, null, 2);
        },
        toggleEditMode() {
            if (this.editMode) {
                // Cancel edit
                this.editedIssue = JSON.parse(JSON.stringify(this.issue));
                this.editMode = false;
            } else {
                this.editMode = true;
            }
        },
        async saveChanges() {
            const title = (this.editedIssue.title !== undefined && this.editedIssue.title !== null ? String(this.editedIssue.title) : '').trim();
            if (!title) {
                this.$root.$refs.toast.showAlert('Title is required', 'error');
                return;
            }
            this.loading = true;
            try {
                const url = CI.base_url + '/api/issues/' + this.editedIssue.id;
                const response = await axios.put(url, {
                    title: this.editedIssue.title,
                    description: this.editedIssue.description,
                    category: this.editedIssue.category,
                    severity: this.editedIssue.severity,
                    status: this.editedIssue.status,
                    notes: this.editedIssue.notes
                });

                if (response.data.status === 'success') {
                    this.$root.$refs.toast.showAlert('Issue updated successfully', 'success');
                    this.$emit('issue-updated', response.data.issue);
                    this.editMode = false;
                } else {
                    throw new Error(response.data.message || 'Failed to update issue');
                }
            } catch (error) {
                console.error('Error updating issue:', error);
                this.$root.$refs.toast.showAlert(
                    error.response?.data?.message || error.message || 'Failed to update issue',
                    'error'
                );
            } finally {
                this.loading = false;
            }
        },
        async applyChanges() {
            if (!confirm('Apply the suggested changes to the project metadata?')) {
                return;
            }

            this.loading = true;
            try {
                const url = CI.base_url + '/api/issues/' + this.currentIssue.id + '/apply';
                const response = await axios.post(url);

                if (response.data.status === 'success') {
                    this.$root.$refs.toast.showAlert('Changes applied successfully', 'success');
                    this.$emit('issue-applied', response.data.issue);
                    this.dialogVisible = false;
                } else {
                    throw new Error(response.data.message || 'Failed to apply changes');
                }
            } catch (error) {
                console.error('Error applying changes:', error);
                this.$root.$refs.toast.showAlert(
                    error.response?.data?.message || error.message || 'Failed to apply changes',
                    'error'
                );
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
                    this.$root.$refs.toast.showAlert('Status updated successfully', 'success');
                    this.$emit('issue-updated', response.data.issue);
                } else {
                    throw new Error(response.data.message || 'Failed to update status');
                }
            } catch (error) {
                console.error('Error updating status:', error);
                this.$root.$refs.toast.showAlert(
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
            max-width="1200px"
            scrollable
        >
            <v-card v-if="currentIssue">
                <!-- Header -->
                <v-card-title class="headline grey lighten-2">
                    <v-icon left>mdi-alert-circle-outline</v-icon>
                    Issue #{{ currentIssue.id }}
                    <v-spacer></v-spacer>
                    <v-btn icon @click="close">
                        <v-icon>mdi-close</v-icon>
                    </v-btn>
                </v-card-title>

                <v-card-text class="pt-4">
                    <v-container>
                        <!-- Status and Severity -->
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
                                    <issue-status-badge :status="currentIssue.status"></issue-status-badge>
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
                                    <v-chip 
                                        v-if="currentIssue.severity" 
                                        small 
                                        :color="currentIssue.severity === 'critical' ? 'error' : currentIssue.severity === 'high' ? 'orange' : currentIssue.severity === 'medium' ? 'warning' : 'grey'"
                                        outlined
                                        class="text-capitalize"
                                    >
                                        {{ currentIssue.severity }}
                                    </v-chip>
                                    <span v-else class="text--disabled">Not set</span>
                                </div>
                            </v-col>
                            <v-col cols="12" md="3">
                                <div class="text-caption text--secondary">Created</div>
                                <div>{{ formatDate(currentIssue.created) }}</div>
                            </v-col>
                            <v-col cols="12" md="3">
                                <div class="text-caption text--secondary">Applied</div>
                                <v-chip small :color="currentIssue.applied ? 'success' : 'grey'" outlined>
                                    {{ currentIssue.applied ? 'Yes' : 'No' }}
                                </v-chip>
                            </v-col>
                        </v-row>

                        <!-- Category -->
                        <v-row dense class="mb-3">
                            <v-col cols="12">
                                <v-combobox
                                    v-if="editMode"
                                    v-model="editedIssue.category"
                                    :items="categoryOptions"
                                    label="Category"
                                    outlined
                                    dense
                                ></v-combobox>
                                <div v-else>
                                    <div class="text-caption text--secondary">Category</div>
                                    <v-chip small outlined v-if="currentIssue.category">{{ currentIssue.category }}</v-chip>
                                    <span v-else class="text--disabled">Not set</span>
                                </div>
                            </v-col>
                        </v-row>

                        <!-- Title -->
                        <v-row dense class="mb-3">
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
                                    <div class="mt-1">{{ currentIssue.title || '-' }}</div>
                                </div>
                            </v-col>
                        </v-row>

                        <!-- Field Path -->
                        <v-row dense class="mb-3" v-if="currentIssue.field_path">
                            <v-col cols="12">
                                <div class="text-caption text--secondary">Field Path</div>
                                <code class="pa-2 grey lighten-3 d-inline-block">{{ currentIssue.field_path }}</code>
                            </v-col>
                        </v-row>

                        <!-- Description -->
                        <v-row dense class="mb-3">
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
                                    <div class="mt-1">{{ currentIssue.description }}</div>
                                </div>
                            </v-col>
                        </v-row>

                        <!-- Metadata Comparison -->
                        <v-row v-if="hasCurrentMetadata || hasSuggestedMetadata">
                            <v-col cols="12">
                                <v-divider class="my-3"></v-divider>
                                <h3 class="mb-3">Metadata Changes</h3>
                            </v-col>
                            <v-col cols="12" md="6" v-if="hasCurrentMetadata">
                                <v-card outlined>
                                    <v-card-title class="text-subtitle-1 py-2 grey lighten-4">
                                        Current Metadata
                                    </v-card-title>
                                    <v-card-text>
                                        <pre class="text-caption" style="white-space: pre-wrap; word-wrap: break-word;">{{ formatMetadata(currentIssue.current_metadata) }}</pre>
                                    </v-card-text>
                                </v-card>
                            </v-col>
                            <v-col cols="12" md="6" v-if="hasSuggestedMetadata">
                                <v-card outlined color="green lighten-5">
                                    <v-card-title class="text-subtitle-1 py-2 green lighten-4">
                                        Suggested Metadata
                                        <v-icon right color="success" small>mdi-arrow-right</v-icon>
                                    </v-card-title>
                                    <v-card-text>
                                        <pre class="text-caption" style="white-space: pre-wrap; word-wrap: break-word;">{{ formatMetadata(currentIssue.suggested_metadata) }}</pre>
                                    </v-card-text>
                                </v-card>
                            </v-col>
                        </v-row>

                        <!-- Notes -->
                        <v-row dense class="mt-3">
                            <v-col cols="12">
                                <v-textarea
                                    v-if="editMode"
                                    v-model="editedIssue.notes"
                                    label="Notes"
                                    outlined
                                    rows="2"
                                ></v-textarea>
                                <div v-else-if="currentIssue.notes">
                                    <div class="text-caption text--secondary">Notes</div>
                                    <div class="mt-1">{{ currentIssue.notes }}</div>
                                </div>
                            </v-col>
                        </v-row>

                        <v-row dense class="mt-3" v-if="currentIssue.source">
                            <v-col cols="12">
                                <div class="text-caption text--secondary">
                                    Source: <span class="text-capitalize">{{ currentIssue.source }}</span>
                                </div>
                            </v-col>
                        </v-row>
                    </v-container>
                </v-card-text>

                <v-divider></v-divider>

                <!-- Actions -->
                <v-card-actions class="pa-4">
                    <v-btn
                        v-if="!editMode && canApply"
                        color="primary"
                        @click="applyChanges"
                        :loading="loading"
                    >
                        <v-icon left>mdi-check</v-icon>
                        Apply Changes
                    </v-btn>

                    <v-menu offset-y v-if="!editMode">
                        <template v-slot:activator="{ on, attrs }">
                            <v-btn
                                outlined
                                v-bind="attrs"
                                v-on="on"
                            >
                                Quick Actions
                                <v-icon right>mdi-menu-down</v-icon>
                            </v-btn>
                        </template>
                        <v-list dense>
                            <v-list-item @click="updateStatus('false_positive')">
                                <v-list-item-title>Mark as False Positive</v-list-item-title>
                            </v-list-item>
                            <v-list-item @click="updateStatus('accepted')">
                                <v-list-item-title>Accept</v-list-item-title>
                            </v-list-item>
                            <v-list-item @click="updateStatus('rejected')">
                                <v-list-item-title>Reject</v-list-item-title>
                            </v-list-item>
                            <v-list-item @click="updateStatus('dismissed')">
                                <v-list-item-title>Dismiss</v-list-item-title>
                            </v-list-item>
                        </v-list>
                    </v-menu>

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
    `
});
