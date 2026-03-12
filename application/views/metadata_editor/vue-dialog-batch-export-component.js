/**
 * Batch export dialog: export multiple data files to one or more formats
 */
Vue.component('dialog-batch-export', {
    props: {
        value: { type: Boolean, default: false },
        selectedFiles: { type: Array, default: () => [] }  // [{ file_id, file_name }]
    },
    data() {
        return {
            selected_formats: [],
            available_formats: [
                { value: 'csv', label: 'CSV' },
                { value: 'dta', label: 'Stata (DTA)' },
                { value: 'sav', label: 'SPSS (SAV)' },
                { value: 'json', label: 'JSON' },
                { value: 'xpt', label: 'SAS' }
            ],
            /** Stata .dta format version (8-15). Used when any selected format is dta. */
            selected_stata_version: 14,
            stata_version_options: [8, 9, 10, 11, 12, 13, 14, 15].map(v => ({ value: v, label: 'Stata ' + v })),
            state: 'config',  // 'config' | 'running' | 'done'
            progress: {
                current: 0,
                total: 0,
                message: ''
            },
            results: [],  // { file_id, file_name, format, job_id, status: 'pending'|'done'|'failed', download_url?, error? }
            pollIntervalMs: 5000,
            pollTimer: null,
            zip_download_url: null,
            zip_creating: false,
            zip_error: null,
            zip_option: true,   // user option: zip all exported files into a single ZIP
            remove_after_zip: true,   // when zip_option is on: remove individual tmp files after successful zip
            individual_files_removed: false   // set true after we delete tmp files (links would 404)
        };
    },
    computed: {
        dialog: {
            get() { return this.value; },
            set(val) { this.$emit('input', val); }
        },
        projectId() {
            return this.$store.state.project_id;
        },
        taskCount() {
            if (this.selectedFiles.length === 0 || this.selected_formats.length === 0) return 0;
            return this.selectedFiles.length * this.selected_formats.length;
        },
        doneCount() {
            return this.results.filter(r => r.status === 'done' || r.status === 'failed').length;
        },
        successCount() {
            return this.results.filter(r => r.status === 'done').length;
        },
        failedCount() {
            return this.results.filter(r => r.status === 'failed').length;
        }
    },
    watch: {
        value(val) {
            if (!val) {
                this.resetState();
                if (this.pollTimer) {
                    clearTimeout(this.pollTimer);
                    this.pollTimer = null;
                }
            }
        }
    },
    methods: {
        resetState() {
            this.state = 'config';
            this.selected_formats = [];
            this.selected_stata_version = 14;
            this.progress = { current: 0, total: 0, message: '' };
            this.results = [];
            this.zip_download_url = null;
            this.zip_creating = false;
            this.zip_error = null;
            this.zip_option = true;
            this.remove_after_zip = true;
            this.individual_files_removed = false;
        },
        /** Filename without extension (matches backend filename_part). */
        filenamePart(name) {
            if (!name) return '';
            const i = name.lastIndexOf('.');
            return i >= 0 ? name.substring(0, i) : name;
        },
        async createZip() {
            const successful = this.results.filter(r => r.status === 'done');
            if (successful.length === 0) return;
            const filenames = [];
            for (const r of successful) {
                if (r.output_filename) {
                    filenames.push(r.output_filename);
                } else {
                    const file = this.selectedFiles.find(f => f.file_id === r.file_id);
                    const physical = (file && file.file_physical_name) ? file.file_physical_name : '';
                    const base = this.filenamePart(physical);
                    if (base) filenames.push(base + '.' + r.format);
                }
            }
            if (filenames.length === 0) return;
            const payload = { filenames: filenames };
            if (this.selected_formats.indexOf('dta') !== -1 && this.selected_stata_version != null) {
                payload.stata_version = this.selected_stata_version;
            }
            this.zip_creating = true;
            this.zip_error = null;
            try {
                const resp = await this.$store.dispatch('createBatchExportZip', payload);
                const zip_path = resp.data && resp.data.zip_path ? resp.data.zip_path : null;
                if (zip_path) {
                    this.zip_download_url = CI.base_url + '/api/files/download/' + this.projectId + '?file=' + encodeURIComponent(zip_path);
                    if (this.remove_after_zip) {
                        await this.removeIndividualExports(filenames);
                        this.individual_files_removed = true;
                    }
                } else {
                    this.zip_error = this.$t('batch_export_zip_failed') || 'Could not create zip';
                }
            } catch (e) {
                this.zip_error = (e.response && e.response.data && e.response.data.message) ? e.response.data.message : (e.message || 'Could not create zip');
            }
            this.zip_creating = false;
        },
        /** Delete individual tmp files in data/tmp after successful zip. */
        async removeIndividualExports(filenames) {
            const url = CI.base_url + '/api/files/delete/' + this.projectId;
            for (const name of filenames) {
                const relativePath = 'data/tmp/' + name;
                try {
                    const formData = new FormData();
                    formData.append('file', relativePath);
                    await axios.post(url, formData);
                } catch (e) {
                    console.warn('Could not remove tmp file:', name, e);
                }
            }
        },
        closeDialog() {
            this.dialog = false;
        },
        buildDownloadUrl(file_id, format, outputFilename) {
            let url = CI.base_url + '/api/datafiles/download_tmp_file/' + this.projectId + '/' + file_id + '/' + format;
            if (outputFilename) {
                url += '?filename=' + encodeURIComponent(outputFilename);
            }
            return url;
        },
        async startExport() {
            if (this.selectedFiles.length === 0 || this.selected_formats.length === 0) return;

            const tasks = [];
            this.selectedFiles.forEach(f => {
                this.selected_formats.forEach(format => {
                    tasks.push({ file_id: f.file_id, file_name: f.file_name, format: format });
                });
            });

            this.results = tasks.map(t => ({
                file_id: t.file_id,
                file_name: t.file_name,
                format: t.format,
                job_id: null,
                output_filename: null,
                status: 'pending',
                download_url: null,
                error: null
            }));

            this.state = 'running';
            this.progress = { current: 0, total: tasks.length, message: this.$t('batch_export_queuing') || 'Queuing exports...' };

            try {
                for (let i = 0; i < tasks.length; i++) {
                    const t = tasks[i];
                    this.progress.current = i;
                    this.progress.message = (this.$t('batch_export_queuing') || 'Queuing...') + ' ' + (i + 1) + ' / ' + tasks.length;
                    const payload = { file_id: t.file_id, format: t.format };
                    if (t.format === 'dta' && this.selected_stata_version != null) {
                        payload.export_options = { version: this.selected_stata_version };
                    }
                    const resp = await this.$store.dispatch('exportDatafileQueue', payload);
                    const job_id = resp.data && resp.data.job_id ? resp.data.job_id : null;
                    if (job_id) {
                        this.results[i].job_id = job_id;
                        if (resp.data && resp.data.output_filename) {
                            this.results[i].output_filename = resp.data.output_filename;
                        }
                    } else {
                        this.results[i].status = 'failed';
                        this.results[i].error = resp.data && resp.data.message ? resp.data.message : 'No job_id returned';
                    }
                }
                this.progress.current = tasks.length;
                this.progress.message = (this.$t('batch_export_processing') || 'Processing...') + ' ' + this.$t('job_status') + '...';
                this.pollUntilDone();
            } catch (e) {
                const msg = e.response && e.response.data && e.response.data.message ? e.response.data.message : (e.message || 'Export failed');
                this.results.forEach(r => {
                    if (r.status === 'pending') {
                        r.status = 'failed';
                        r.error = msg;
                    }
                });
                this.state = 'done';
            }
        },
        pollUntilDone() {
            const pending = this.results.filter(r => r.status === 'pending' && r.job_id);
            if (pending.length === 0) {
                this.state = 'done';
                this.pollTimer = null;
                if (this.successCount > 0 && this.zip_option) {
                    this.createZip();
                }
                return;
            }

            this.pollTimer = setTimeout(async () => {
                this.progress.message = (this.$t('batch_export_processing') || 'Processing...') + ' ' + this.doneCount + ' / ' + this.results.length + ' ' + this.$t('job_status');
                for (const r of pending) {
                    try {
                        const resp = await this.$store.dispatch('getJobStatus', { job_id: r.job_id });
                        const status = resp.data && resp.data.job_status ? resp.data.job_status : null;
                        if (status === 'done') {
                            r.status = 'done';
                            r.download_url = this.buildDownloadUrl(r.file_id, r.format, r.output_filename);
                        } else if (status === 'failed' || status === 'error') {
                            r.status = 'failed';
                            r.error = (resp.data && resp.data.message) ? resp.data.message : (status || 'Job failed');
                        }
                    } catch (e) {
                        r.status = 'failed';
                        r.error = e.response && e.response.data && e.response.data.message ? e.response.data.message : (e.message || 'Request failed');
                    }
                }
                this.pollUntilDone();
            }, this.pollIntervalMs);
        },
        closeAndClear() {
            if (this.pollTimer) {
                clearTimeout(this.pollTimer);
                this.pollTimer = null;
            }
            this.resetState();
            this.closeDialog();
        }
    },
    template: `
        <div class="vue-dialog-batch-export-component">
            <v-dialog v-model="dialog" width="560" persistent @input="function(v){ if(!v) resetState(); }">
                <v-card>
                    <v-card-title class="text-h6 grey lighten-2">
                        {{ $t('batch_export') || 'Batch export' }}
                    </v-card-title>
                    <v-card-text class="pt-4">
                        <!-- Config: select formats -->
                        <template v-if="state === 'config'">
                            <div class="mb-3">
                                <label class="text-body-2 font-weight-medium d-block mb-2">{{ $t('batch_export_selected_files') || 'Selected files' }} ({{ selectedFiles.length }})</label>
                                <ul class="text-caption pl-3 mb-3" style="max-height: 120px; overflow-y: auto;">
                                    <li v-for="f in selectedFiles" :key="f.file_id">{{ f.file_name || f.file_id }}</li>
                                </ul>
                            </div>
                            <div>
                                <label class="text-body-2 font-weight-medium d-block mb-2">{{ $t('batch_export_select_formats') || 'Select export format(s)' }}</label>
                                <v-simple-table dense class="batch-export-formats-table">
                                    <thead>
                                        <tr>
                                            <th class="text-left text-body-2" style="width: 90px;">{{ $t('batch_export_export') || 'Export' }}</th>
                                            <th class="text-left text-body-2">{{ $t('batch_export_format') || 'Format' }}</th>
                                            <th class="text-left text-body-2">{{ $t('batch_export_options') || 'Options' }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="fmt in available_formats" :key="fmt.value">
                                            <td>
                                                <v-checkbox
                                                    v-model="selected_formats"
                                                    :value="fmt.value"
                                                    hide-details
                                                    dense
                                                    class="mt-0 shrink"
                                                ></v-checkbox>
                                            </td>
                                            <td class="text-body-2">{{ fmt.label }}</td>
                                            <td>
                                                <select
                                                    v-if="fmt.value === 'dta'"
                                                    v-model.number="selected_stata_version"
                                                    style="max-width: 110px; padding: 2px 6px; font-size: 0.875rem;"
                                                >
                                                    <option v-for="opt in stata_version_options" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                                                </select>
                                                <span v-else class="text-caption text--secondary">—</span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </v-simple-table>
                            </div>
                            <div class="mt-3">
                                <v-checkbox
                                    v-model="zip_option"
                                    :label="$t('batch_export_zip_option') || 'Zip all exported files into a single ZIP'"
                                    hide-details
                                    dense
                                    class="mt-0 font-weight-normal"
                                ></v-checkbox>
                                <v-checkbox
                                    v-if="zip_option"
                                    v-model="remove_after_zip"
                                    :label="$t('batch_export_remove_after_zip') || 'Remove individual exports after zipping'"
                                    hide-details
                                    dense
                                    class="mt-0 ml-6 font-weight-normal"
                                ></v-checkbox>
                            </div>
                            <p v-if="taskCount > 0" class="text-caption text--secondary mt-2">
                                {{ $t('batch_export_task_count') || 'Total exports' }}: {{ taskCount }}
                            </p>
                        </template>

                        <!-- Running: progress -->
                        <template v-if="state === 'running'">
                            <div class="mb-3">
                                <div class="text-body-2 mb-2">{{ progress.message }}</div>
                                <v-progress-linear
                                    :value="results.length > 0 ? (doneCount / results.length) * 100 : 0"
                                    color="primary"
                                    height="8"
                                    rounded
                                ></v-progress-linear>
                                <div class="text-caption text--secondary mt-1">{{ doneCount }} / {{ results.length }} {{ $t('job_status') }}</div>
                            </div>
                        </template>

                        <!-- Done: results with zip + download links -->
                        <template v-if="state === 'done'">
                            <div class="text-body-2 mb-3">
                                <span v-if="successCount > 0" class="success--text">{{ successCount }} {{ $t('file_generated_success') }}</span>
                                <span v-if="failedCount > 0" class="error--text ml-2">{{ failedCount }} {{ $t('failed') }}</span>
                            </div>
                            <div v-if="successCount > 0 && zip_option" class="mb-3">
                                <div v-if="zip_creating" class="text-caption text--secondary">
                                    <v-progress-circular indeterminate size="20" width="2" class="mr-2"></v-progress-circular>
                                    {{ $t('batch_export_creating_zip') || 'Creating ZIP...' }}
                                </div>
                                <v-btn v-else-if="zip_download_url" color="primary" :href="zip_download_url" target="_blank" download class="mb-2">
                                    <v-icon left>mdi-folder-zip</v-icon>{{ $t('batch_export_download_zip') || 'Download all (ZIP)' }}
                                </v-btn>
                                <div v-else-if="zip_error" class="text-caption error--text">{{ zip_error }}</div>
                            </div>
                            <div style="max-height: 240px; overflow-y: auto;">
                                <div v-for="(r, idx) in results" :key="idx" class="d-flex align-center py-1">
                                    <span class="text-body-2 flex-grow-1">{{ r.file_name }} [{{ r.format.toUpperCase() }}]</span>
                                    <v-btn v-if="r.status === 'done' && !individual_files_removed" small color="primary" :href="r.download_url" target="_blank" download>
                                        <v-icon small left>mdi-download</v-icon>{{ $t('download') }}
                                    </v-btn>
                                    <span v-else-if="r.status === 'done' && individual_files_removed" class="text-caption text--secondary">{{ $t('batch_export_removed') || 'Removed' }}</span>
                                    <span v-else class="text-caption error--text">{{ r.error || $t('failed') }}</span>
                                </div>
                            </div>
                        </template>
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer></v-spacer>
                        <template v-if="state === 'config'">
                            <v-btn color="grey" text small @click="closeDialog">{{ $t('cancel') }}</v-btn>
                            <v-btn color="primary" small :disabled="selected_formats.length === 0 || selectedFiles.length === 0" @click="startExport">
                                {{ $t('export') }}
                            </v-btn>
                        </template>
                        <template v-if="state === 'running'">
                            <v-btn color="grey" text small disabled>{{ $t('cancel') }}</v-btn>
                        </template>
                        <template v-if="state === 'done'">
                            <v-btn color="primary" text small @click="closeAndClear">{{ $t('close') }}</v-btn>
                        </template>
                    </v-card-actions>
                </v-card>
            </v-dialog>
        </div>
    `
});
