/// Dialog to manage tags for a project (add/remove). Used on projects list page when clicking tags on a card.
Vue.component('vue-project-tags-manage-dialog', {
    props: ['value', 'project_id', 'tags'],
    data: function () {
        return {
            localTags: [],
            add_tag_input: '',
            loading: false,
            dialog_loading: false
        };
    },
    watch: {
        value: function (open) {
            if (open && this.tags) {
                this.localTags = this.tags.map(function (t) { return { id: t.id, tag: t.tag, is_core: t.is_core }; });
            } else {
                this.add_tag_input = '';
            }
        },
        tags: function (val) {
            if (this.value && val) {
                this.localTags = val.map(function (t) { return { id: t.id, tag: t.tag, is_core: t.is_core }; });
            }
        }
    },
    computed: {
        dialog: {
            get: function () { return this.value; },
            set: function (v) { this.$emit('input', v); }
        }
    },
    methods: {
        removeTag: function (tag) {
            var vm = this;
            var sid = this.project_id;
            if (!sid) return;
            vm.dialog_loading = true;
            var url = CI.site_url + '/api/tags/remove_project_tags/' + sid;
            axios.post(url, { tags: [tag.id] })
                .then(function (response) {
                    vm.dialog_loading = false;
                    if (response.data && response.data.tags) {
                        vm.localTags = response.data.tags;
                    } else {
                        var idx = vm.localTags.findIndex(function (t) { return t.id === tag.id; });
                        if (idx > -1) vm.localTags.splice(idx, 1);
                    }
                    vm.$emit('tags-updated');
                })
                .catch(function (err) {
                    vm.dialog_loading = false;
                    var msg = (vm.$extractErrorMessage && vm.$extractErrorMessage(err)) || (err.response && err.response.data && err.response.data.message) || 'Failed';
                    if (vm.$alert) vm.$alert(msg, { color: 'error' }); else alert(msg);
                });
        },
        addTags: function () {
            var vm = this;
            var sid = this.project_id;
            var raw = (this.add_tag_input && this.add_tag_input.trim()) ? this.add_tag_input.trim() : '';
            if (!sid || !raw) return;
            var tags = raw.split(/[\n,]+/).map(function (s) { return s.trim(); }).filter(Boolean);
            if (tags.length === 0) return;
            vm.dialog_loading = true;
            var url = CI.site_url + '/api/tags/project/' + sid;
            axios.post(url, { tags: tags })
                .then(function (response) {
                    vm.dialog_loading = false;
                    vm.add_tag_input = '';
                    if (response.data && response.data.tags) {
                        vm.localTags = response.data.tags;
                    }
                    vm.$emit('tags-updated');
                })
                .catch(function (err) {
                    vm.dialog_loading = false;
                    var msg = (vm.$extractErrorMessage && vm.$extractErrorMessage(err)) || (err.response && err.response.data && err.response.data.message) || 'Failed';
                    if (vm.$alert) vm.$alert(msg, { color: 'error' }); else alert(msg);
                });
        }
    },
    template: `
    <div class="vue-project-tags-manage-dialog">
        <v-dialog v-model="dialog" max-width="500" persistent>
            <v-card>
                <v-card-title class="grey lighten-2 d-flex justify-space-between align-center">
                    <span>{{ $t('Tags') }}</span>
                    <v-progress-circular v-if="dialog_loading" indeterminate size="24" width="2"></v-progress-circular>
                </v-card-title>
                <v-card-text class="pt-3">
                    <div v-if="localTags.length === 0 && !dialog_loading" class="text-muted mb-3">{{ $t('None') }}</div>
                    <div class="mb-3">
                        <v-chip v-for="t in localTags" :key="'tag-' + t.id" small class="mr-1 mb-1" close @click:close="removeTag(t)">
                            {{ t.tag }}
                        </v-chip>
                    </div>
                    <v-divider class="my-3"></v-divider>
                    <div class="text-caption text-muted mb-2">{{ $t('add_tags_placeholder') }}</div>
                    <v-textarea
                        v-model="add_tag_input"
                        rows="3"
                        outlined
                        dense
                        hide-details
                        :placeholder="$t('Add tag')"
                    ></v-textarea>
                </v-card-text>
                <v-card-actions>
                    <v-spacer></v-spacer>
                    <v-btn text @click="dialog = false">{{ $t('close') }}</v-btn>
                    <v-btn color="primary" :disabled="!add_tag_input || !add_tag_input.trim() || dialog_loading" :loading="dialog_loading" @click="addTags">
                        {{ $t('Add') }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
    `
});
