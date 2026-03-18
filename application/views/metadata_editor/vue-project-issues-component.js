/**
 * Project Issues Component
 * 
 * Main component for managing project issues
 * Integrates issue list, detail dialog, and create dialog
 * 
 * Props:
 *   - projectId: Number - Project ID (required)
 *   - canEdit: Boolean - Whether user has edit permission (default: false)
 */
Vue.component('project-issues', {
    props: {
        projectId: {
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
            issueListKey: 0, // For forcing list refresh
            assessmentSubmitting: false,
            assessmentPhase: 'idle', // 'idle' | 'submitting' | 'running'
            assessmentPollIntervalMs: 2500,
            assessmentPollMaxWaitMs: 600000, // 10 minutes
            showAssessConfirm: false
        };
    },
    mounted() {
        this.checkAssessmentStatus();
    },
    methods: {
        async checkAssessmentStatus() {
            if (!this.projectId) return;
            try {
                const url = (CI && CI.base_url ? CI.base_url : '') + '/api/issues/project/' + this.projectId + '/assessment_status';
                const response = await axios.get(url);
                if (response.data.status !== 'success' || !response.data.assessment_job) return;
                const job = response.data.assessment_job;
                if (job.status !== 'pending' && job.status !== 'processing') return;
                this.assessmentSubmitting = true;
                this.assessmentPhase = 'running';
                const result = await this.pollJobUntilDone(job.uuid);
                if (result.status === 'completed' && result.job && result.job.result) {
                    console.log('Metadata assessment result:', result.job.result);
                    if (result.job.result.issues && result.job.result.issues.length) {
                        console.log('Issues from assessment:', result.job.result.issues);
                    }
                    EventBus.$emit('onSuccess', 'Assessment complete. Result logged to console and log file.');
                    this.refreshIssueList();
                } else if (result.status === 'failed') {
                    const msg = (result.job && result.job.error_message) ? result.job.error_message : 'Assessment failed';
                    EventBus.$emit('onFail', msg);
                } else if (result.status === 'timeout') {
                    EventBus.$emit('onFail', 'Assessment is taking longer than expected. Check job status later.');
                }
            } catch (err) {
                console.error('Check assessment status:', err);
            } finally {
                this.assessmentSubmitting = false;
                this.assessmentPhase = 'idle';
            }
        },
        createIssue() {
            this.$router.push('/issues/create');
        },
        refreshIssueList() {
            // Force re-render of issue list by changing key
            this.issueListKey++;
            // Refresh Vuex open-issues summary so field-level badges/indicators update without page reload
            if (this.$store && this.projectId) {
                this.$store.dispatch('fetchOpenIssuesSummary', { projectId: this.projectId });
            }
            // Notify field-issues components to drop local cache so they use fresh store summary
            if (typeof EventBus !== 'undefined') {
                EventBus.$emit('project-issues-refreshed', this.projectId);
            }
        },
        openAssessConfirm() {
            if (this.assessmentSubmitting) return;
            this.showAssessConfirm = true;
        },
        async submitForReview() {
            this.showAssessConfirm = false;
            if (this.assessmentSubmitting) return;
            this.assessmentSubmitting = true;
            this.assessmentPhase = 'submitting';
            try {
                const url = (CI && CI.base_url ? CI.base_url : '') + '/api/jobs/metadata_assessment';
                const response = await axios.post(url, { project_id: this.projectId });
                if (response.data.status !== 'success' || !response.data.uuid) {
                    throw new Error(response.data.message || 'Failed to submit for assessment');
                }
                const uuid = response.data.uuid;
                this.assessmentPhase = 'running';
                const result = await this.pollJobUntilDone(uuid);
                if (result.status === 'completed' && result.job && result.job.result) {
                    console.log('Metadata assessment result:', result.job.result);
                    if (result.job.result.issues && result.job.result.issues.length) {
                        console.log('Issues from assessment:', result.job.result.issues);
                    }
                    EventBus.$emit('onSuccess', 'Assessment complete. Result logged to console and log file.');
                    this.refreshIssueList();
                } else if (result.status === 'failed') {
                    const msg = (result.job && result.job.error_message) ? result.job.error_message : 'Assessment failed';
                    EventBus.$emit('onFail', msg);
                } else if (result.status === 'timeout') {
                    EventBus.$emit('onFail', 'Assessment is taking longer than expected. Check job status later.');
                }
            } catch (error) {
                console.error('Assess metadata error:', error);
                EventBus.$emit(
                    'onFail',
                    error.response && error.response.data && error.response.data.message
                        ? error.response.data.message
                        : (error.message || 'Failed to assess metadata')
                );
            } finally {
                this.assessmentSubmitting = false;
                this.assessmentPhase = 'idle';
            }
        },
        pollJobUntilDone(uuid) {
            const start = Date.now();
            const poll = () => {
                if (Date.now() - start > this.assessmentPollMaxWaitMs) {
                    return Promise.resolve({ status: 'timeout' });
                }
                const url = (CI && CI.base_url ? CI.base_url : '') + '/api/jobs/' + uuid;
                return axios.get(url).then((response) => {
                    const job = response.data.job;
                    const status = job ? job.status : response.data.status;
                    if (status === 'completed' || status === 'failed') {
                        return { status: status, job: job };
                    }
                    return new Promise((resolve) => {
                        setTimeout(() => poll().then(resolve), this.assessmentPollIntervalMs);
                    });
                });
            };
            return poll();
        }
    },
    template: `
        <div class="project-issues">
            <div class="m-4">
                <v-row>
                    <v-col cols="12">
                        <div class="d-flex align-center mb-4">
                            <div>
                                <h2 class="text-h5">
                                    <v-icon left color="primary">mdi-alert-circle-outline</v-icon>
                                    Issues
                                </h2>                                
                            </div>
                            <v-spacer></v-spacer>
                            <v-btn
                                color="primary"
                                :disabled="assessmentSubmitting"
                                :loading="assessmentSubmitting && assessmentPhase === 'submitting'"
                                @click="openAssessConfirm"
                                class="mr-2"
                            >
                                <v-progress-circular
                                    v-if="assessmentSubmitting && assessmentPhase === 'running'"
                                    indeterminate
                                    size="20"
                                    width="2"
                                    class="mr-2"
                                ></v-progress-circular>
                                <v-icon v-else left>mdi-auto-fix</v-icon>
                                {{ assessmentSubmitting && assessmentPhase === 'running' ? 'Assessment running' : (assessmentSubmitting ? 'Submitting…' : 'Assess metadata') }}
                            </v-btn>
                            <v-btn
                                v-if="canEdit"
                                color="primary"
                                @click="createIssue"
                            >
                                <v-icon left>mdi-plus</v-icon>
                                New Issue
                            </v-btn>
                        </div>

                        <!-- Issue List -->
                        <issue-list
                            :key="issueListKey"
                            :project-id="projectId"
                            :can-edit="canEdit"
                        ></issue-list>
                    </v-col>
                </v-row>
            </div>

            <!-- Assess metadata confirm dialog -->
            <v-dialog v-model="showAssessConfirm" max-width="480" persistent>
                <v-card>
                    <v-card-title class="text-h6">
                        <v-icon left color="primary">mdi-auto-fix</v-icon>
                        Assess metadata
                    </v-card-title>
                    <v-card-text class="pt-2">
                        This will send the project metadata to the quality assessment service. Detected issues will be added to the Issues list and shown next to the relevant fields.
                        <p class="mt-3 mb-0">You do not have to wait for the assessment to finish. You can leave this page and come back later; the issues will appear when the assessment completes.</p>
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer></v-spacer>
                        <v-btn text @click="showAssessConfirm = false">Cancel</v-btn>
                        <v-btn color="primary" @click="submitForReview">
                            Assess metadata
                        </v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>
        </div>
    `
});
