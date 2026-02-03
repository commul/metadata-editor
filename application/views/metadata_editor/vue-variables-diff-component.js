/**
 * Variables vs CSV columns mismatch page.
 * Route: #/variables-diff/:file_id
 */

Vue.component('variables-diff', {
    props: ['file_id'],
    data: function() {
        var fileId = this.file_id || (this.$route && this.$route.params && this.$route.params.file_id);
        return {
            fid: fileId,
            columns_diff: null,
            loading: false,
            error: '',
            actionLoading: false,
            actionError: '',
            confirmRemove: false
        };
    },
    computed: {
        projectId: function() {
            return this.$store.state.project_id;
        },
        fileLabel: function() {
            var files = this.$store.state.data_files || [];
            var f = files.find(function(x) { return x.file_id === this.fid; }.bind(this));
            return (f && f.file_name) ? f.file_name : this.fid;
        },
        columnsInDbNotInCsv: function() {
            return (this.columns_diff && this.columns_diff.columns_in_db_not_in_csv) ? this.columns_diff.columns_in_db_not_in_csv : [];
        },
        columnsInDbNotInCsvCount: function() {
            return this.columnsInDbNotInCsv.length;
        },
        hasMismatch: function() {
            return this.columns_diff && !this.columns_diff.in_sync;
        },
        pageTitle: function() {
            var label = this.fileLabel || this.fid || '';
            return (this.$t('metadata_columns_diff') ) + (label ? ' — ' + label : '');
        }
    },
    watch: {
        file_id: function(newVal) {
            if (newVal) {
                this.fid = newVal;
                this.columns_diff = null;
                this.error = '';
                this.fetchColumnsDiff();
            }
        },
        '$route.params.file_id': function(newVal) {
            if (newVal && newVal !== this.fid) {
                this.fid = newVal;
                this.columns_diff = null;
                this.error = '';
                this.fetchColumnsDiff();
            }
        }
    },
    mounted: function() {
        this.fid = this.file_id || (this.$route && this.$route.params && this.$route.params.file_id) || this.fid;
        this.fetchColumnsDiff();
    },
    methods: {
        goToVariables: function() {
            this.$router.push('/variables/' + this.fid);
        },
        fetchColumnsDiff: function() {
                var vm = this;
                if (!vm.projectId || !vm.fid) return;
                vm.loading = true;
                vm.error = '';
                var url = CI.base_url + '/api/datafiles/columns_diff/' + vm.projectId + '/' + encodeURIComponent(vm.fid);
                axios.get(url)
                    .then(function(response) {
                        vm.loading = false;
                        if (response.data && response.data.status === 'success' && response.data.columns_diff) {
                            vm.columns_diff = response.data.columns_diff;
                        } else {
                            vm.error = vm.$t('failed') || 'Failed to load column comparison';
                        }
                    })
                    .catch(function(err) {
                        vm.loading = false;
                        vm.error = (err.response && err.response.data && err.response.data.message) ? err.response.data.message : (vm.$t('failed') || 'Failed to load');
                    });
        },
        removeFromMetadata: function() {
            if (this.columnsInDbNotInCsvCount === 0) return;
            this.confirmRemove = true;
        },
        confirmRemoveSubmit: function() {
                var vm = this;
                vm.actionLoading = true;
                vm.actionError = '';
                vm.$store.dispatch('loadVariables', { dataset_id: vm.projectId, fid: vm.fid })
                    .then(function() {
                        var vars = vm.$store.getters.getVariablesByFid(vm.fid);
                        if (!Array.isArray(vars)) {
                            vm.actionError = vm.$t('variables_not_loaded') || 'Variables not loaded';
                            vm.actionLoading = false;
                            return;
                        }
                        var namesToRemove = vm.columnsInDbNotInCsv;
                        var uids = [];
                        vars.forEach(function(v) {
                            if (v.name && namesToRemove.indexOf(v.name) !== -1) {
                                uids.push(v.uid);
                            }
                        });
                        if (uids.length === 0) {
                            vm.actionError = vm.$t('no_variables_to_remove') || 'No matching variables found';
                            vm.actionLoading = false;
                            return;
                        }
                        var url = CI.base_url + '/api/variables/delete/' + vm.projectId;
                        return axios.post(url, { uid: uids });
                    })
                    .then(function(response) {
                        vm.actionLoading = false;
                        vm.confirmRemove = false;
                        vm.$store.dispatch('loadVariables', { dataset_id: vm.projectId, fid: vm.fid }).then(function() {
                            vm.fetchColumnsDiff();
                        });
                        if (typeof EventBus !== 'undefined') {
                            EventBus.$emit('onSuccess', (vm.$t('variables_removed') || 'Variables removed from metadata.'));
                        }
                    })
                    .catch(function(err) {
                        vm.actionLoading = false;
                        vm.actionError = (err.response && err.response.data && err.response.data.message) ? err.response.data.message : (vm.$t('failed') || 'Failed');
                        if (typeof EventBus !== 'undefined') {
                            EventBus.$emit('onFail', vm.actionError);
                        }
                    });
        },
        cancelRemove: function() {
            this.confirmRemove = false;
            this.actionError = '';
        }
    },
    template: `
        <div class="variables-diff-page container-fluid pt-4 pb-5">
            <v-card>
                <v-card-title class="mt-4">
                    <v-btn icon class="mr-2" @click="goToVariables" :title="$t('variables')">
                        <v-icon>mdi-arrow-left</v-icon>
                    </v-btn>
                    <span>{{ pageTitle }}</span>
                </v-card-title>
                <v-card-text class="pt-4">
                    <div v-if="loading" class="d-flex align-center justify-center py-5">
                        <v-progress-circular indeterminate color="primary" size="40"></v-progress-circular>
                        <span class="ml-3">{{ $t('loading') }}...</span>
                    </div>
                    <div v-else-if="error" class="alert alert-danger">{{ error }}</div>
                    <div v-else-if="!hasMismatch && columns_diff" class="alert alert-success">
                        {{ $t('variables_match_csv')}}                        
                    </div>
                    <div v-else-if="hasMismatch" class="pt-2">
                        <p class="text-body-2 mb-3">{{ $t('columns_in_db_not_in_csv_help') }}</p>
                        <div v-if="columnsInDbNotInCsvCount > 0" class="mb-3" style="width: fit-content;">
                            <table class="table table-bordered table-sm">
                                <thead>
                                    <tr>
                                        <th style="width: 60px;">#</th>
                                        <th>{{ $t('variable') || 'Variable' }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(name, index) in columnsInDbNotInCsv" :key="name">
                                        <td>{{ index + 1 }}</td>
                                        <td>{{ name }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-else class="text-body-2 text-secondary">{{ $t('none') || 'None' }}</p>
                        <v-btn v-if="columnsInDbNotInCsvCount > 0" color="warning" dark @click="removeFromMetadata">
                            {{ $t('remove') }} ({{ columnsInDbNotInCsvCount }})
                        </v-btn>
                    </div>
                </v-card-text>
            </v-card>

            <!-- Confirm remove -->
            <v-dialog v-model="confirmRemove" max-width="400" persistent>
                <v-card>
                    <v-card-title class="text-subtitle-1">{{ $t('remove') }}</v-card-title>
                    <v-card-text>
                        <p class="text-body-2">{{ $t('confirm_remove_variables', {count: columnsInDbNotInCsvCount}) }}</p>
                        <div v-if="actionError" class="alert alert-danger mt-2" color="red">{{ actionError }}</div>
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer></v-spacer>
                        <v-btn text @click="cancelRemove">{{ $t('cancel') || 'Cancel' }}</v-btn>
                        <v-btn color="error" dark depressed :loading="actionLoading" @click="confirmRemoveSubmit">{{ $t('Remove') || 'Remove' }}</v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>
        </div>
        `
});
