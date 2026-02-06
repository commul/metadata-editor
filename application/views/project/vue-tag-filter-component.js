Vue.component('vue-tag-filter', {
    props: ['value'],
    data() {
        return {
            selected_tags: [],
            search: null,
            tags: [],
            is_loading: false
        }
    },
    watch: {
        search(val) {
            if (val === null || val === undefined) return
            if (this.is_loading) return
            this.is_loading = true
            this.searchTags();
        }
    },
    methods: {
        searchTags: _.debounce(function() {
            let vm = this;
            let keywords = (vm.search && vm.search.trim()) || '';
            if (!keywords) {
                vm.is_loading = false;
                return;
            }
            axios.get(CI.site_url + '/api/tags?search=' + encodeURIComponent(keywords) + '&limit=50&offset=0')
            .then(response => {
                vm.tags = response.data.tags || [];
            })
            .catch(function (error) {
                console.log(error);
            })
            .finally(() => (vm.is_loading = false));
        }, 300),
        removeSelectionItem: function(item) {
            let index = this.selected_tags.findIndex(t => t.id === item.id);
            if (index > -1) {
                this.selected_tags.splice(index, 1);
            }
        },
        applyFilter: function() {
            this.$emit('apply', this.selected_tags);
            this.selected_tags = [];
            this.search = null;
            this.dialog = false;
        },
        cancel: function() {
            this.selected_tags = [];
            this.search = null;
            this.dialog = false;
        }
    },
    computed: {
        dialog: {
            get: function() {
                return this.value;
            },
            set: function(newValue) {
                this.$emit('input', newValue);
            }
        }
    },
    template: `
        <v-dialog v-model="dialog" max-width="600px" scrollable>
            <v-card>
                <v-card-title class="text-h6 grey lighten-2">
                    {{$t('filter_by_tag')}}
                </v-card-title>
                <v-card-text class="mt-3">
                    <v-autocomplete
                        v-model="selected_tags"
                        :loading="is_loading"
                        :search-input.sync="search"
                        @change="search=''"
                        :items="tags"
                        solo
                        chips
                        color="primary"
                        :label="$t('search_tag')"
                        item-text="tag"
                        item-value="id"
                        multiple
                        return-object
                        :no-data-text="$t('type_to_search_tags')"
                    >
                        <template v-slot:selection="data">
                            <v-chip
                                v-bind="data.attrs"
                                :input-value="data.selected"
                                close
                                @click="data.select"
                                @click:close="removeSelectionItem(data.item)"
                                small
                            >
                                {{ data.item.tag }}
                            </v-chip>
                        </template>
                        <template v-slot:item="data">
                            <v-list-item-content>
                                <v-list-item-title>{{ data.item.tag }}</v-list-item-title>
                            </v-list-item-content>
                        </template>
                    </v-autocomplete>
                </v-card-text>
                <v-divider></v-divider>
                <v-card-actions>
                    <v-spacer></v-spacer>
                    <v-btn text @click="cancel">{{$t('cancel')}}</v-btn>
                    <v-btn color="primary" @click="applyFilter">{{$t('apply')}}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    `
});
