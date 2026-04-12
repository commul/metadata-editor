// Global codelists listing (used by codelists/index.php). Remove uses POST /api/codelists/delete/{id} (not HTTP DELETE).
Vue.component('codelists', {
    props: [],
    data: function () {
        return {
            loading: false,
            deletingId: null,
            codelists: [],
            total: 0,
            search: '',
            importDialog: false,
            importSource: 'file',
            importFile: null,
            importUrl: '',
            importDryRun: false,
            importReplace: false,
            importing: false,
            headers: [
                { text: 'Name', value: 'name' },
                { text: 'Agency', value: 'agency' },
                { text: 'Codelist ID', value: 'codelist_id' },
                { text: 'Version', value: 'version' },
                { text: '', value: 'actions', sortable: false, align: 'end' }
            ]
        };
    },
    mounted: function () {
        var vm = this;
        this.loadCodelists();
        if (typeof EventBus !== 'undefined') {
            EventBus.$on('codelist-sdmx-open', vm.openImportDialog);
        }
    },
    beforeDestroy: function () {
        if (typeof EventBus !== 'undefined') {
            EventBus.$off('codelist-sdmx-open', this.openImportDialog);
        }
    },
    computed: {
        importCanSubmit: function () {
            if (this.importSource === 'url') {
                return (this.importUrl || '').trim().length > 0;
            }
            return !!this.importFile;
        }
    },
    methods: {
        apiBase: function () {
            var base = (typeof CI !== 'undefined' && CI.site_url) ? CI.site_url.replace(/\/$/, '') : '';
            return base + '/api/codelists';
        },
        notifyFail: function (err) {
            var m = 'Request failed';
            if (err.response && err.response.data && err.response.data.message) {
                m = err.response.data.message;
            }
            if (typeof EventBus !== 'undefined') {
                EventBus.$emit('onFail', m);
            } else {
                alert(m);
            }
        },
        notifySuccess: function (msg) {
            if (typeof EventBus !== 'undefined') {
                EventBus.$emit('onSuccess', msg);
            }
        },
        loadCodelists: function () {
            var vm = this;
            vm.loading = true;
            var url = vm.apiBase();
            if (vm.search) {
                url += '?search=' + encodeURIComponent(vm.search);
            }
            axios.get(url)
                .then(function (res) {
                    vm.loading = false;
                    if (res.data && res.data.status === 'success') {
                        vm.codelists = res.data.codelists || [];
                        vm.total = res.data.total != null ? res.data.total : vm.codelists.length;
                    } else {
                        vm.codelists = [];
                        vm.total = 0;
                    }
                })
                .catch(function (err) {
                    vm.loading = false;
                    vm.notifyFail(err);
                });
        },
        goCreate: function () {
            this.$router.push('/edit');
        },
        goView: function (row) {
            this.$router.push('/view/' + row.id);
        },
        /** GET codes in compact form (id, code, label, description, sort_order, parent_id); format=json keeps JSON even in a browser tab. */
        codelistJsonApiUrl: function (row) {
            return this.apiBase() + '/codes/' + row.id + '?compact=1&format=json';
        },
        sdmxExportUrl: function (row, version) {
            return this.apiBase() + '/export_sdmx/' + row.id + '?version=' + version;
        },
        goEdit: function (row) {
            this.$router.push('/edit/' + row.id);
        },
        deleteCodelist: function (row) {
            var vm = this;
            var label = (row.name && String(row.name).trim()) || row.codelist_id || row.id;
            if (!confirm('Delete codelist "' + label + '" and all its items? This cannot be undone.')) {
                return;
            }
            vm.deletingId = row.id;
            axios.post(vm.apiBase() + '/delete/' + row.id)
                .then(function () {
                    vm.deletingId = null;
                    vm.notifySuccess('Codelist deleted');
                    vm.loadCodelists();
                })
                .catch(function (err) {
                    vm.deletingId = null;
                    vm.notifyFail(err);
                });
        },
        openImportDialog: function () {
            this.importSource = 'file';
            this.importFile = null;
            this.importUrl = '';
            this.importDryRun = false;
            this.importReplace = false;
            this.importDialog = true;
        },
        closeImportDialog: function () {
            if (this.importing) {
                return;
            }
            this.importDialog = false;
        },
        submitSdmxImport: function () {
            var vm = this;
            if (!vm.importCanSubmit) {
                vm.notifyFail({
                    response: {
                        data: {
                            message: vm.importSource === 'url'
                                ? 'Enter a URL to SDMX-ML (https://…)'
                                : 'Choose an SDMX-ML (.xml) file'
                        }
                    }
                });
                return;
            }
            var url = vm.apiBase() + '/import_sdmx';
            var q = [];
            if (vm.importDryRun) {
                q.push('dry_run=1');
            }
            if (vm.importReplace) {
                q.push('replace=1');
            }
            if (q.length) {
                url += '?' + q.join('&');
            }
            vm.importing = true;
            var req;
            if (vm.importSource === 'url') {
                req = axios.post(url, { url: (vm.importUrl || '').trim() }, {
                    headers: { 'Content-Type': 'application/json' }
                });
            } else {
                var fd = new FormData();
                fd.append('file', vm.importFile);
                req = axios.post(url, fd);
            }
            req
                .then(function (res) {
                    vm.importing = false;
                    var d = res.data || {};
                    var msg = vm._formatImportSummary(d);
                    if (d.warnings && d.warnings.length) {
                        msg += ' — ' + d.warnings.slice(0, 4).join(' · ');
                        if (d.warnings.length > 4) {
                            msg += '…';
                        }
                    }
                    if (d.status === 'failed') {
                        vm.notifyFail({ response: { data: { message: msg || 'Import failed' } } });
                        return;
                    }
                    vm.notifySuccess(msg || 'Import finished');
                    if (!vm.importDryRun) {
                        vm.loadCodelists();
                    }
                    vm.importDialog = false;
                    vm.importFile = null;
                    vm.importUrl = '';
                })
                .catch(function (err) {
                    vm.importing = false;
                    vm.notifyFail(err);
                });
        },
        _formatImportSummary: function (d) {
            if (!d || typeof d !== 'object') {
                return '';
            }
            if (d.dry_run) {
                var n = 0;
                (d.imported || []).forEach(function (x) {
                    n += x.codes_count != null ? x.codes_count : 0;
                });
                return 'Preview: ' + (d.imported || []).length + ' codelist(s), ~' + n + ' codes (nothing saved)';
            }
            var parts = [];
            if ((d.imported || []).length) {
                parts.push('Imported ' + d.imported.length + ' list(s)');
            }
            if ((d.skipped || []).length) {
                parts.push('Skipped ' + d.skipped.length + ' (already exist)');
            }
            if ((d.failed || []).length) {
                parts.push('Failed ' + d.failed.length);
            }
            if (d.sdmx_version) {
                parts.push('SDMX ' + d.sdmx_version);
            }
            var s = parts.join(' · ') || 'Done';
            if (d.status === 'partial') {
                s = 'Partial: ' + s;
            }
            return s;
        }
    },
    template: `
        <div>
            <v-card class="mb-4">
                <v-card-title class="d-flex flex-wrap align-center">
                    <span class="text-h6">Global codelists</span>
                    <v-spacer></v-spacer>
                    <v-btn color="primary" outlined class="mr-2" @click="openImportDialog">Import SDMX</v-btn>
                    <v-btn color="primary" outlined @click="goCreate">New codelist</v-btn>
                </v-card-title>
                <v-card-text class="pt-0 pb-2">
                    <v-text-field
                        v-model="search"
                        label="Search codelists"
                        single-line
                        hide-details
                        dense
                        outlined
                        clearable
                        style="max-width: 480px;"
                        @keyup.enter="loadCodelists"
                        @click:clear="search = ''; loadCodelists()"
                    >
                        <template v-slot:append>
                            <v-btn icon small :loading="loading" @click="loadCodelists" title="Search">
                                <v-icon small>mdi-magnify</v-icon>
                            </v-btn>
                        </template>
                    </v-text-field>
                </v-card-text>
                <v-data-table
                    :headers="headers"
                    :items="codelists"
                    :loading="loading"
                    loading-text="Loading..."
                    hide-default-footer
                    class="elevation-0"
                >
                    <template v-slot:item.name="{ item }">
                        <a href="#" class="text-decoration-none" @click.prevent="goEdit(item)">{{ item.name }}</a>
                    </template>
                    <template v-slot:item.actions="{ item }">
                        <v-menu offset-y left>
                            <template v-slot:activator="{ on, attrs }">
                                <v-btn icon small v-bind="attrs" v-on="on" :loading="deletingId === item.id">
                                    <v-icon small>mdi-dots-vertical</v-icon>
                                </v-btn>
                            </template>
                            <v-list dense>
                                <v-list-item @click="goView(item)">
                                    <v-list-item-icon class="mr-2"><v-icon small>mdi-eye-outline</v-icon></v-list-item-icon>
                                    <v-list-item-title>View</v-list-item-title>
                                </v-list-item>
                                <v-list-item @click="goEdit(item)">
                                    <v-list-item-icon class="mr-2"><v-icon small>mdi-pencil-outline</v-icon></v-list-item-icon>
                                    <v-list-item-title>Edit</v-list-item-title>
                                </v-list-item>
                                <v-divider></v-divider>
                                <v-list-item :href="codelistJsonApiUrl(item)" target="_blank" rel="noopener noreferrer">
                                    <v-list-item-icon class="mr-2"><v-icon small>mdi-code-json</v-icon></v-list-item-icon>
                                    <v-list-item-title>JSON</v-list-item-title>
                                </v-list-item>
                                <v-list-item :href="sdmxExportUrl(item, '2.1')">
                                    <v-list-item-icon class="mr-2"><v-icon small>mdi-download-outline</v-icon></v-list-item-icon>
                                    <v-list-item-title>SDMX 2.1</v-list-item-title>
                                </v-list-item>
                                <v-list-item :href="sdmxExportUrl(item, '3.0')">
                                    <v-list-item-icon class="mr-2"><v-icon small>mdi-download-outline</v-icon></v-list-item-icon>
                                    <v-list-item-title>SDMX 3.0</v-list-item-title>
                                </v-list-item>
                                <v-divider></v-divider>
                                <v-list-item @click="deleteCodelist(item)" :disabled="deletingId != null && deletingId !== item.id">
                                    <v-list-item-icon class="mr-2"><v-icon small color="error">mdi-delete-outline</v-icon></v-list-item-icon>
                                    <v-list-item-title class="error--text">Delete</v-list-item-title>
                                </v-list-item>
                            </v-list>
                        </v-menu>
                    </template>
                </v-data-table>
                <v-card-text v-if="!loading && total > codelists.length" class="text-caption grey--text">
                    Total: {{ total }} (showing {{ codelists.length }} — use API pagination for more)
                </v-card-text>
            </v-card>

            <v-dialog v-model="importDialog" max-width="520" @click:outside="closeImportDialog">
                <v-card>
                    <v-card-title>Import codelists (SDMX-ML)</v-card-title>
                    <v-card-text>
                        <p class="text-body-2 grey--text text--darken-1 mb-2">
                            SDMX structure message (2.1 or 3.0) with <code>Codelist</code> or <code>HierarchicalCodelist</code> elements.
                        </p>
                        <v-radio-group v-model="importSource" row hide-details dense class="mt-0 mb-3">
                            <v-radio label="Upload file" value="file" :disabled="importing"></v-radio>
                            <v-radio label="From URL" value="url" :disabled="importing"></v-radio>
                        </v-radio-group>
                        <v-file-input
                            v-show="importSource === 'file'"
                            v-model="importFile"
                            dense
                            outlined
                            accept=".xml,text/xml,application/xml"
                            label="SDMX-ML file"
                            prepend-icon="mdi-file-xml-box"
                            :disabled="importing"
                            show-size
                        ></v-file-input>
                        <v-text-field
                            v-show="importSource === 'url'"
                            v-model="importUrl"
                            dense
                            outlined
                            clearable
                            label="URL to SDMX-ML"
                            placeholder="https://example.org/structure.xml"
                            prepend-inner-icon="mdi-link"
                            :disabled="importing"
                            hint="Server fetches this URL (http/https only; private networks blocked)."
                            persistent-hint
                        ></v-text-field>
                        <v-checkbox v-model="importDryRun" hide-details dense class="mt-0" label="Preview only (dry run — do not save)" :disabled="importing"></v-checkbox>
                        <v-checkbox v-model="importReplace" hide-details dense class="mt-0"
                            label="Replace existing lists with the same agency, id, and version" :disabled="importing"></v-checkbox>
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer></v-spacer>
                        <v-btn text :disabled="importing" @click="closeImportDialog">Cancel</v-btn>
                        <v-btn color="primary" :loading="importing" :disabled="!importCanSubmit" @click="submitSdmxImport">Import</v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>
        </div>
    `
});
