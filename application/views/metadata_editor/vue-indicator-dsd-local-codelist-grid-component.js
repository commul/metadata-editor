// Paginated local codelist editor for one indicator_dsd field (uses /api/local_codelists).
Vue.component('indicator-dsd-local-codelist-grid', {
    props: {
        projectSid: { type: [Number, String], required: true },
        /** indicator_dsd.id */
        dsdFieldId: { type: [Number, String], required: true },
        /** local_codelists.id; null until linked */
        localListId: { type: [Number, String], default: null },
        /** Used when creating a new local_codelists row */
        listDisplayName: { type: String, default: '' }
    },
    data: function() {
        return {
            localEnsureLoading: false,
            localItemsLoading: false,
            localItems: [],
            localItemsTotal: 0,
            localItemsOffset: 0,
            localItemsLimit: 50,
            newLocalCode: '',
            newLocalLabel: '',
            localEditing: null,
            localSaving: false,
            localItemsSortField: 'code',
            localItemsSortDir: 'asc',
            localItemsSearch: '',
            addRowPanelOpen: false,
            _itemsSearchTimer: null
        };
    },
    created: function() {
        var self = this;
        this.$nextTick(function() {
            self.bootstrap();
        });
    },
    beforeDestroy: function() {
        if (this._itemsSearchTimer) {
            clearTimeout(this._itemsSearchTimer);
            this._itemsSearchTimer = null;
        }
    },
    watch: {
        dsdFieldId: function() {
            this.localItemsOffset = 0;
            this.localItemsSortField = 'code';
            this.localItemsSortDir = 'asc';
            this.localItemsSearch = '';
            this.localEditing = null;
            this.bootstrap();
        },
        localListId: function(n) {
            if (n) {
                this.loadLocalItems();
            } else {
                this.localItems = [];
                this.localItemsTotal = 0;
            }
        }
    },
    computed: {
        resolvedListId: function() {
            var v = this.localListId;
            if (v === null || v === undefined || v === '') {
                return null;
            }
            var n = parseInt(v, 10);
            return isNaN(n) || n <= 0 ? null : n;
        },
        localItemsPageEnd: function() {
            if (this.localItemsTotal <= 0) {
                return 0;
            }
            return Math.min(this.localItemsOffset + this.localItems.length, this.localItemsTotal);
        },
        localHasPrevPage: function() {
            return this.localItemsOffset > 0;
        },
        localHasNextPage: function() {
            return this.localItemsOffset + this.localItems.length < this.localItemsTotal;
        }
    },
    methods: {
        emitListId: function(id) {
            this.$emit('update:local-list-id', id == null ? null : id);
        },
        bootstrap: function() {
            var vm = this;
            if (!vm.projectSid || !vm.dsdFieldId) {
                return;
            }
            vm.localEnsureLoading = true;
            vm.ensureLocalList()
                .then(function() {
                    return vm.loadLocalItems();
                })
                .catch(function(err) {
                    var msg = (err.response && err.response.data && err.response.data.message) || err.message || 'Local codelist setup failed';
                    if (typeof EventBus !== 'undefined' && EventBus.$emit) {
                        EventBus.$emit('onFail', msg);
                    }
                })
                .then(function() {
                    vm.localEnsureLoading = false;
                });
        },
        ensureLocalList: function() {
            var vm = this;
            var sid = vm.projectSid;
            var fid = vm.dsdFieldId;
            if (!sid || !fid) {
                return Promise.reject(new Error('Missing project or field id'));
            }
            if (vm.resolvedListId) {
                return Promise.resolve(vm.resolvedListId);
            }
            return axios.get(CI.base_url + '/api/local_codelists/lists/' + sid).then(function(res) {
                var lists = (res.data && res.data.lists) ? res.data.lists : [];
                var ex = lists.find(function(l) {
                    return String(l.field_id) === String(fid);
                });
                if (ex) {
                    vm.emitListId(ex.id);
                    return ex.id;
                }
                return axios.post(CI.base_url + '/api/local_codelists/list/' + sid, {
                    field_id: fid,
                    name: vm.listDisplayName || ('Field ' + fid)
                }).then(function(postRes) {
                    var newId = postRes.data && postRes.data.id;
                    if (newId) {
                        vm.emitListId(newId);
                    }
                    return newId;
                });
            });
        },
        loadLocalItems: function() {
            var vm = this;
            var lid = vm.resolvedListId;
            if (!lid || !vm.projectSid) {
                vm.localItems = [];
                vm.localItemsTotal = 0;
                return Promise.resolve();
            }
            vm.localItemsLoading = true;
            var params = {
                offset: vm.localItemsOffset,
                limit: vm.localItemsLimit,
                sort: vm.localItemsSortField,
                order: vm.localItemsSortDir
            };
            var q = String(vm.localItemsSearch || '').trim();
            if (q) {
                params.search = q;
            }
            return axios.get(CI.base_url + '/api/local_codelists/items/' + vm.projectSid + '/' + lid, {
                params: params
            }).then(function(res) {
                var d = res.data || {};
                vm.localItems = Array.isArray(d.items) ? d.items.slice() : [];
                vm.localItemsTotal = typeof d.total === 'number' ? d.total : 0;
            }).catch(function(err) {
                vm.localItems = [];
                vm.localItemsTotal = 0;
                var msg = (err.response && err.response.data && err.response.data.message) || err.message || 'Failed to load codelist items';
                if (typeof EventBus !== 'undefined' && EventBus.$emit) {
                    EventBus.$emit('onFail', msg);
                }
            }).then(function() {
                vm.localItemsLoading = false;
            });
        },
        localItemsPrevPage: function() {
            this.localItemsOffset = Math.max(0, this.localItemsOffset - this.localItemsLimit);
            this.loadLocalItems();
        },
        localItemsNextPage: function() {
            if (this.localItemsOffset + this.localItems.length < this.localItemsTotal) {
                this.localItemsOffset += this.localItemsLimit;
                this.loadLocalItems();
            }
        },
        localItemsChangeLimit: function(ev) {
            var n = parseInt(ev.target.value, 10);
            if (!isNaN(n) && n > 0) {
                this.localItemsLimit = n;
                this.localItemsOffset = 0;
                this.loadLocalItems();
            }
        },
        toggleLocalItemsSort: function(field) {
            if (field !== 'code' && field !== 'label') {
                return;
            }
            if (this.localItemsSortField === field) {
                this.localItemsSortDir = this.localItemsSortDir === 'asc' ? 'desc' : 'asc';
            } else {
                this.localItemsSortField = field;
                this.localItemsSortDir = 'asc';
            }
            this.localItemsOffset = 0;
            this.loadLocalItems();
        },
        localSortIcon: function(field) {
            if (this.localItemsSortField !== field) {
                return 'mdi-unfold-more-horizontal';
            }
            return this.localItemsSortDir === 'asc' ? 'mdi-arrow-up' : 'mdi-arrow-down';
        },
        onItemsSearchInput: function() {
            var vm = this;
            if (vm._itemsSearchTimer) {
                clearTimeout(vm._itemsSearchTimer);
            }
            vm._itemsSearchTimer = setTimeout(function() {
                vm._itemsSearchTimer = null;
                vm.localItemsOffset = 0;
                vm.loadLocalItems();
            }, 300);
        },
        clearItemsSearch: function() {
            this.localItemsSearch = '';
            this.localItemsOffset = 0;
            this.loadLocalItems();
        },
        toggleAddRowPanel: function() {
            this.addRowPanelOpen = !this.addRowPanelOpen;
        },
        startEditLocalItem: function(item) {
            this.localEditing = {
                id: item.id,
                code: item.code != null ? String(item.code) : '',
                label: item.label != null ? String(item.label) : ''
            };
        },
        cancelEditLocalItem: function() {
            this.localEditing = null;
        },
        saveEditLocalItem: function() {
            var vm = this;
            if (!vm.localEditing || !vm.projectSid) {
                return;
            }
            var e = vm.localEditing;
            vm.localSaving = true;
            axios.post(
                CI.base_url + '/api/local_codelists/item_update/' + vm.projectSid + '/' + e.id,
                { code: e.code, label: e.label }
            ).then(function() {
                vm.localEditing = null;
                if (typeof EventBus !== 'undefined' && EventBus.$emit) {
                    EventBus.$emit('onSuccess', vm.$t('saved') || 'Saved');
                }
                return vm.loadLocalItems();
            }).catch(function(err) {
                var msg = (err.response && err.response.data && err.response.data.message) || err.message || 'Update failed';
                if (typeof EventBus !== 'undefined' && EventBus.$emit) {
                    EventBus.$emit('onFail', msg);
                }
            }).then(function() {
                vm.localSaving = false;
            });
        },
        addLocalItem: function() {
            var vm = this;
            var code = String(vm.newLocalCode || '').trim();
            if (!code) {
                if (typeof EventBus !== 'undefined' && EventBus.$emit) {
                    EventBus.$emit('onFail', vm.$t('code_required') || 'Code is required');
                }
                return;
            }
            var lid = vm.resolvedListId;
            if (!lid || !vm.projectSid) {
                return;
            }
            vm.localSaving = true;
            axios.post(CI.base_url + '/api/local_codelists/items/' + vm.projectSid + '/' + lid, {
                code: code,
                label: String(vm.newLocalLabel || '').trim()
            }).then(function() {
                vm.newLocalCode = '';
                vm.newLocalLabel = '';
                if (typeof EventBus !== 'undefined' && EventBus.$emit) {
                    EventBus.$emit('onSuccess', vm.$t('added') || 'Added');
                }
                vm.localItemsOffset = 0;
                return vm.loadLocalItems();
            }).catch(function(err) {
                var msg = (err.response && err.response.data && err.response.data.message) || err.message || 'Add failed';
                if (typeof EventBus !== 'undefined' && EventBus.$emit) {
                    EventBus.$emit('onFail', msg);
                }
            }).then(function() {
                vm.localSaving = false;
            });
        },
        deleteLocalItem: function(item) {
            var vm = this;
            if (!item || !item.id || !vm.projectSid) {
                return;
            }
            if (!confirm(vm.$t('confirm_delete_codelist_item') || 'Delete this code from the local codelist?')) {
                return;
            }
            vm.localSaving = true;
            axios.post(CI.base_url + '/api/local_codelists/item_delete/' + vm.projectSid + '/' + item.id)
                .then(function() {
                    if (typeof EventBus !== 'undefined' && EventBus.$emit) {
                        EventBus.$emit('onSuccess', vm.$t('deleted') || 'Deleted');
                    }
                    if (vm.localItems.length <= 1 && vm.localItemsOffset > 0) {
                        vm.localItemsOffset = Math.max(0, vm.localItemsOffset - vm.localItemsLimit);
                    }
                    return vm.loadLocalItems();
                })
                .catch(function(err) {
                    var msg = (err.response && err.response.data && err.response.data.message) || err.message || 'Delete failed';
                    if (typeof EventBus !== 'undefined' && EventBus.$emit) {
                        EventBus.$emit('onFail', msg);
                    }
                })
                .then(function() {
                    vm.localSaving = false;
                });
        }
    },
    template: `
        <div class="indicator-dsd-local-codelist-grid border rounded p-2 bg-white">
            <div v-if="localEnsureLoading" class="small text-muted py-2">
                {{$t('loading') || 'Loading…'}}
            </div>
            <template v-else>
                <div class="d-flex flex-wrap align-items-center justify-content-between mb-2" style="gap: 8px;">
                    <div class="d-flex align-items-center flex-grow-1" style="gap: 4px; min-width: 0;">
                        <div class="position-relative d-inline-block flex-shrink-0" style="width: 11rem; max-width: 100%;">
                            <span
                                class="position-absolute d-flex align-items-center justify-content-center"
                                style="left: 0; top: 0; bottom: 0; width: 1.65rem; pointer-events: none; z-index: 1;"
                                aria-hidden="true"
                            >
                                <v-icon x-small dense color="grey">mdi-magnify</v-icon>
                            </span>
                            <input
                                type="search"
                                class="form-control form-control-sm"
                                style="height: 28px; padding: 2px 8px 2px 1.65rem; font-size: 0.75rem;"
                                v-model="localItemsSearch"
                                @input="onItemsSearchInput"
                                @keyup.esc="clearItemsSearch"
                                :placeholder="$t('search_codes_or_labels') || 'Search code or label…'"
                                :title="$t('search_codes_or_labels') || 'Search code or label'"
                            />
                        </div>
                        <v-btn
                            icon
                            x-small
                            outlined
                            class="flex-shrink-0"
                            @click="bootstrap"
                            :disabled="localSaving || localEnsureLoading || localItemsLoading"
                            :title="$t('refresh') || 'Refresh'"
                            :aria-label="$t('refresh') || 'Refresh'"
                        >
                            <v-icon dense small>mdi-refresh</v-icon>
                        </v-btn>
                    </div>
                    <div class="d-flex align-items-center flex-shrink-0" style="gap: 4px;">
                        <span class="small text-muted">{{$t('local_codelist_add_row') || 'Add code'}}</span>
                        <v-btn
                            icon
                            x-small
                            outlined
                            class="mr-0"
                            @click="toggleAddRowPanel"
                            :title="addRowPanelOpen ? ($t('collapse') || 'Collapse') : ($t('expand') || 'Expand')"
                            :aria-expanded="addRowPanelOpen ? 'true' : 'false'"
                            :aria-label="addRowPanelOpen ? ($t('collapse_add_row') || 'Collapse add row') : ($t('expand_add_row') || 'Expand add row')"
                        >
                            <v-icon dense small>{{ addRowPanelOpen ? 'mdi-chevron-up' : 'mdi-chevron-down' }}</v-icon>
                        </v-btn>
                    </div>
                </div>
                <v-expand-transition>
                    <div v-show="addRowPanelOpen" class="mb-2 pa-2 border rounded bg-light">
                        <div class="d-flex flex-wrap align-items-end" style="gap: 8px;">
                            <div class="flex-grow-1" style="min-width: 120px;">
                                <label class="small text-muted mb-0 d-block">{{$t('code') || 'Code'}}</label>
                                <input type="text" class="form-control form-control-sm" v-model="newLocalCode" maxlength="150"
                                    :disabled="localSaving" :placeholder="$t('new_code') || 'New code'" />
                            </div>
                            <div class="flex-grow-1" style="min-width: 160px;">
                                <label class="small text-muted mb-0 d-block">{{$t('label') || 'Label'}}</label>
                                <input type="text" class="form-control form-control-sm" v-model="newLocalLabel"
                                    :disabled="localSaving" :placeholder="$t('new_label') || 'Label'" />
                            </div>
                            <button type="button" class="btn btn-sm btn-primary" @click="addLocalItem" :disabled="localSaving || !resolvedListId">
                                {{$t('add') || 'Add'}}
                            </button>
                        </div>
                    </div>
                </v-expand-transition>
                <div class="table-responsive mt-1" style="max-height: 320px; overflow: auto;">
                    <table class="table table-sm table-striped mb-0" style="font-size: 0.8125rem;">
                        <thead class="thead-light">
                            <tr>
                                <th
                                    class="align-middle py-2 user-select-none"
                                    style="cursor: pointer;"
                                    @click="toggleLocalItemsSort('code')"
                                    :title="$t('sort_by_code') || 'Sort by code'"
                                >
                                    <span>{{$t('code') || 'Code'}}</span>
                                    <v-icon
                                        x-small
                                        dense
                                        class="ml-1 align-middle"
                                        :color="localItemsSortField === 'code' ? 'primary' : 'grey lighten-1'"
                                    >{{ localSortIcon('code') }}</v-icon>
                                </th>
                                <th
                                    class="align-middle py-2 user-select-none"
                                    style="cursor: pointer;"
                                    @click="toggleLocalItemsSort('label')"
                                    :title="$t('sort_by_label') || 'Sort by label'"
                                >
                                    <span>{{$t('label') || 'Label'}}</span>
                                    <v-icon
                                        x-small
                                        dense
                                        class="ml-1 align-middle"
                                        :color="localItemsSortField === 'label' ? 'primary' : 'grey lighten-1'"
                                    >{{ localSortIcon('label') }}</v-icon>
                                </th>
                                <th class="text-right align-middle py-2" style="width: 76px;">{{$t('actions') || 'Actions'}}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="localItemsLoading && localItems.length === 0">
                                <td colspan="3" class="text-muted text-center py-3">{{$t('loading') || 'Loading…'}}</td>
                            </tr>
                            <tr v-for="item in localItems" :key="'lci-' + item.id">
                                <template v-if="localEditing && localEditing.id === item.id">
                                    <td><input type="text" class="form-control form-control-sm" v-model="localEditing.code" maxlength="150" /></td>
                                    <td><input type="text" class="form-control form-control-sm" v-model="localEditing.label" /></td>
                                    <td class="text-right text-nowrap py-1">
                                        <v-btn icon x-small color="success" class="mr-0" @click="saveEditLocalItem" :disabled="localSaving" :title="$t('save') || 'Save'" :aria-label="$t('save') || 'Save'">
                                            <v-icon dense small>mdi-check</v-icon>
                                        </v-btn>
                                        <v-btn icon x-small @click="cancelEditLocalItem" :disabled="localSaving" :title="$t('cancel') || 'Cancel'" :aria-label="$t('cancel') || 'Cancel'">
                                            <v-icon dense small>mdi-close</v-icon>
                                        </v-btn>
                                    </td>
                                </template>
                                <template v-else>
                                    <td><code>{{ item.code }}</code></td>
                                    <td>{{ item.label }}</td>
                                    <td class="text-right text-nowrap py-1">
                                        <v-btn icon x-small color="primary" text @click="startEditLocalItem(item)" :disabled="localSaving || localEditing" :title="$t('edit') || 'Edit'" :aria-label="$t('edit') || 'Edit'">
                                            <v-icon dense small>mdi-pencil-outline</v-icon>
                                        </v-btn>
                                        <v-btn icon x-small color="error" text @click="deleteLocalItem(item)" :disabled="localSaving || localEditing" :title="$t('delete') || 'Delete'" :aria-label="$t('delete') || 'Delete'">
                                            <v-icon dense small>mdi-delete-outline</v-icon>
                                        </v-btn>
                                    </td>
                                </template>
                            </tr>
                            <tr v-if="!localItemsLoading && localItems.length === 0">
                                <td colspan="3" class="text-muted text-center py-3">{{$t('local_codelist_empty') || 'No items yet. Add codes above or populate from data on the DSD list.'}}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="d-flex flex-wrap align-items-center justify-content-between mt-2 small text-muted" v-if="localItemsTotal > 0">
                    <span>
                        {{$t('showing') || 'Showing'}} {{ localItemsOffset + 1 }}–{{ localItemsPageEnd }} {{$t('of') || 'of'}} {{ localItemsTotal }}
                    </span>
                    <div class="d-flex align-items-center" style="gap: 2px;">
                        <span class="mr-1">{{$t('per_page') || 'Per page'}}</span>
                        <select
                            class="form-control form-control-sm"
                            style="width: 3.25rem; height: 26px; padding: 0 0.35rem; font-size: 0.7rem; line-height: 1.2;"
                            :value="localItemsLimit"
                            @change="localItemsChangeLimit"
                        >
                            <option :value="25">25</option>
                            <option :value="50">50</option>
                            <option :value="100">100</option>
                        </select>
                        <v-btn icon x-small outlined :disabled="!localHasPrevPage || localItemsLoading" @click="localItemsPrevPage" :title="$t('previous') || 'Previous'" :aria-label="$t('previous') || 'Previous'">
                            <v-icon dense small>mdi-chevron-left</v-icon>
                        </v-btn>
                        <v-btn icon x-small outlined :disabled="!localHasNextPage || localItemsLoading" @click="localItemsNextPage" :title="$t('next') || 'Next'" :aria-label="$t('next') || 'Next'">
                            <v-icon dense small>mdi-chevron-right</v-icon>
                        </v-btn>
                    </div>
                </div>
            </template>
        </div>
    `
});
