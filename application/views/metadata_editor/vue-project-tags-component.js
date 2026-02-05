// Manage Project tags
Vue.component('vue-project-tags', {
    props: {
        projectId: { type: [Number, String], default: null },
        canEdit: { type: Boolean, default: null }
    },
    data: function () {
        return {
            project_tags: [],
            loading: false,
            dialog_add_tags: false,
            dialog_tag_input: '',
            dialog_loading: false
        };
    },
    computed: {
        effectiveProjectId: function () {
            if (this.projectId != null && this.projectId !== '') {
                return this.projectId;
            }
            return this.$store && this.$store.state ? this.$store.state.project_id : null;
        },
        effectiveCanEdit: function () {
            if (this.canEdit !== null && this.canEdit !== undefined) {
                return this.canEdit;
            }
            return this.$store && this.$store.getters ? this.$store.getters.getUserHasEditAccess : false;
        },
    },
    watch: {
        effectiveProjectId: function (val) {
            if (val) {
                this.loadProjectTags();
            } else {
                this.project_tags = [];
            }
        },
        dialog_add_tags: function (open) {
            if (!open) {
                this.dialog_tag_input = '';
            }
        }
    },
    created: function () {
        if (this.effectiveProjectId) {
            this.loadProjectTags();
        }
    },
    methods: {
        loadProjectTags: function () {
            var vm = this;
            var sid = this.effectiveProjectId;
            if (!sid) { return; }
            vm.loading = true;
            var url = CI.base_url + '/api/tags/project/' + sid;
            axios.get(url)
                .then(function (response) {
                    vm.loading = false;
                    if (response.data && response.data.tags) {
                        vm.project_tags = response.data.tags;
                    } else {
                        vm.project_tags = [];
                    }
                })
                .catch(function (err) {
                    vm.loading = false;
                    vm.project_tags = [];
                    if (vm.$extractErrorMessage) {
                        vm.$alert(vm.$extractErrorMessage(err), { color: 'error' });
                    }
                });
        },
        openAddTagsDialog: function () {
            if (!this.effectiveCanEdit || !this.effectiveProjectId) { return; }
            this.dialog_tag_input = '';
            this.dialog_add_tags = true;
        },
        addTagsFromDialog: function () {
            var vm = this;
            var sid = this.effectiveProjectId;
            if (!sid || !this.effectiveCanEdit) { return; }
            var raw = (this.dialog_tag_input && this.dialog_tag_input.trim()) ? this.dialog_tag_input.trim() : '';
            if (!raw) { return; }
            var tags = raw.split(/[\n,]+/).map(function (s) { return s.trim(); }).filter(Boolean);
            if (tags.length === 0) { return; }
            vm.dialog_loading = true;
            var url = CI.base_url + '/api/tags/project/' + sid;
            axios.post(url, { tags: tags })
                .then(function (response) {
                    vm.dialog_loading = false;
                    vm.dialog_add_tags = false;
                    vm.dialog_tag_input = '';
                    if (response.data && response.data.tags) {
                        vm.project_tags = response.data.tags;
                    } else {
                        vm.loadProjectTags();
                    }
                })
                .catch(function (err) {
                    vm.dialog_loading = false;
                    if (vm.$extractErrorMessage) {
                        vm.$alert(vm.$extractErrorMessage(err), { color: 'error' });
                    }
                });
        },
        removeTag: function (tag) {
            var vm = this;
            var sid = this.effectiveProjectId;
            if (!sid || !this.effectiveCanEdit) { return; }
            var payload = { tags: [typeof tag === 'object' && tag.id != null ? tag.id : (tag.tag || tag)] };
            vm.loading = true;
            var url = CI.base_url + '/api/tags/remove_project_tags/' + sid;
            axios.post(url, payload)
                .then(function (response) {
                    vm.loading = false;
                    if (response.data && response.data.tags) {
                        vm.project_tags = response.data.tags;
                    } else {
                        vm.loadProjectTags();
                    }
                })
                .catch(function (err) {
                    vm.loading = false;
                    if (vm.$extractErrorMessage) {
                        vm.$alert(vm.$extractErrorMessage(err), { color: 'error' });
                    }
                });
        }
    },
    template: `
    <div class="vue-project-tags-component">
        <div class="component-container">
            <v-card>
                <v-card-title class="d-flex justify-space-between align-center">
                    <h6>{{ $t('Tags') }}</h6>
                    <span class="d-flex align-center">
                        <v-progress-circular v-if="loading" indeterminate size="20" width="2" class="mr-1"></v-progress-circular>
                        <v-btn v-if="effectiveCanEdit && effectiveProjectId" icon @click="openAddTagsDialog">
                            <v-icon>mdi-tag-plus</v-icon>
                        </v-btn>
                    </span>
                </v-card-title>
                <v-card-text>
                    <template v-if="!effectiveProjectId">
                        <div class="text-muted">{{ $t('Select a project') }}</div>
                    </template>
                    <template v-else>
                        <div v-if="project_tags.length === 0 && !loading" class="text-muted text-secondary mb-2">
                            {{ $t('None') }}
                        </div>
                        <div class="mb-2">
                            <template v-for="t in project_tags">
                                <v-chip :key="'tag-' + t.id" small class="mr-1 mb-1" :close="effectiveCanEdit" @click:close="removeTag(t)">
                                    {{ t.tag }}
                                </v-chip>
                            </template>
                        </div>
                    </template>
                </v-card-text>
            </v-card>

            <v-dialog v-model="dialog_add_tags" max-width="500" persistent>
                <v-card>
                    <v-card-title class="grey lighten-2">
                        {{ $t('Add tag') }}
                    </v-card-title>
                    <v-card-text class="pt-3">
                        <v-textarea
                            v-model="dialog_tag_input"
                            :placeholder="$t('add_tags_placeholder')"
                            rows="4"
                            outlined
                            dense
                            hide-details
                            auto-grow
                        ></v-textarea>                        
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer></v-spacer>
                        <v-btn text @click="dialog_add_tags = false">{{ $t('cancel') }}</v-btn>
                        <v-btn color="primary" :disabled="!dialog_tag_input || !dialog_tag_input.trim() || dialog_loading" :loading="dialog_loading" @click="addTagsFromDialog">
                            {{ $t('Add') }}
                        </v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>
        </div>
    </div>
    `
});
