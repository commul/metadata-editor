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
            issueListKey: 0 // For forcing list refresh
        };
    },
    methods: {
        createIssue() {
            this.$router.push('/issues/create');
        },
        refreshIssueList() {
            // Force re-render of issue list by changing key
            this.issueListKey++;
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
        </div>
    `
});
