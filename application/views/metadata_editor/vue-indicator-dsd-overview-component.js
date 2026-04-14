Vue.component('indicator-dsd-overview', {
    data() {
        return {
            dataset_id: project_sid,
            loading: false,
            error: null,
            report: null,
        };
    },

    mounted() {
        this.loadReport();
    },

    computed: {
        structure() {
            return this.report && this.report.structure ? this.report.structure : null;
        },
        summary() {
            return this.structure && this.structure.summary ? this.structure.summary : null;
        },
        byType() {
            return (this.summary && this.summary.by_type) ? this.summary.by_type : {};
        },
        totalColumns() {
            return this.summary ? (this.summary.total_columns || 0) : 0;
        },
        structureValid() {
            return this.structure ? this.structure.valid : null;
        },
        structureErrors() {
            return this.structure ? (this.structure.errors || []) : [];
        },
        dataValidation() {
            return this.report ? (this.report.data_validation || null) : null;
        },
        hasData() {
            return this.dataValidation && this.dataValidation.has_data;
        },
        rowCount() {
            return this.dataValidation ? this.dataValidation.row_count : null;
        },
        // Required column types — each must appear exactly once.
        requiredTypes() {
            return [
                { type: 'indicator_id',       label: 'Indicator ID',        icon: 'mdi-identifier' },
                { type: 'geography',          label: 'Geography',            icon: 'mdi-map-marker' },
                { type: 'time_period',        label: 'Time Period',          icon: 'mdi-calendar-range' },
                { type: 'observation_value',  label: 'Observation Value',    icon: 'mdi-chart-line' },
            ];
        },
        // Optional / repeating column types.
        additionalTypes() {
            const skip = new Set(['indicator_id', 'geography', 'time_period', 'observation_value']);
            const result = [];
            Object.keys(this.byType).forEach(t => {
                if (!skip.has(t)) {
                    result.push({ type: t, count: this.byType[t] });
                }
            });
            return result;
        },
    },

    methods: {
        async loadReport() {
            this.loading = true;
            this.error   = null;
            try {
                const res = await axios.get(CI.base_url + '/api/indicator_dsd/validate/' + this.dataset_id);
                this.report = res.data || null;
            } catch (e) {
                this.error = (e.response && e.response.data && e.response.data.message) || e.message || 'Could not load DSD summary';
            } finally {
                this.loading = false;
            }
        },

        goTo(path) {
            this.$router.push(path);
        },

        typeCount(type) {
            return this.byType[type] != null ? this.byType[type] : 0;
        },

        typePresent(type) {
            return this.typeCount(type) === 1;
        },

        formatNumber(n) {
            if (n == null) return '—';
            return Number(n).toLocaleString();
        },
    },

    template: `
<div class="pa-4 mt-5" style="max-width: 100%; background:#fff; margin:10px; border-radius:4px;">

    <!-- Loading -->
    <div v-if="loading" class="d-flex align-center" style="gap:10px; min-height:120px;">
        <v-progress-circular indeterminate color="primary" size="24" width="2"></v-progress-circular>
        <span class="body-2 grey--text">Loading structure summary…</span>
    </div>

    <!-- Error -->
    <v-alert v-else-if="error" type="error" dense outlined class="mb-4">{{ error }}</v-alert>

    <!-- No DSD yet -->
    <template v-else-if="report && totalColumns === 0">
        <div class="text-center py-8">
            <v-icon size="48" color="grey lighten-1">mdi-table-off</v-icon>
            <div class="subtitle-1 grey--text mt-3">No data structure defined yet</div>
            <div class="caption grey--text mt-1 mb-4">Import a CSV file to create the structure automatically, or define it manually.</div>
            <v-btn small color="primary" @click="goTo('/indicator-dsd-import')">
                <v-icon left small>mdi-upload</v-icon> Import Data
            </v-btn>
            <v-btn small outlined class="ml-2" @click="goTo('/indicator-dsd')">
                <v-icon left small>mdi-pencil</v-icon> Define manually
            </v-btn>
        </div>
    </template>

    <!-- Summary -->
    <template v-else-if="report">

        <!-- Header row: column count + validation badge -->
        <div class="d-flex align-center justify-space-between mb-4 flex-wrap" style="gap:8px;">
            <div>
                <span class="text-h6 font-weight-bold">{{ totalColumns }}</span>
                <span class="body-2 grey--text ml-1">{{ totalColumns === 1 ? 'column' : 'columns' }}</span>
            </div>
            <v-chip v-if="structureValid === true"  small color="success" text-color="white" label>
                <v-icon left x-small>mdi-check-circle</v-icon> Structure valid
            </v-chip>
            <v-chip v-else-if="structureValid === false" small color="error" text-color="white" label>
                <v-icon left x-small>mdi-alert-circle</v-icon>
                {{ structureErrors.length }} {{ structureErrors.length === 1 ? 'error' : 'errors' }}
            </v-chip>
        </div>

        <!-- Required columns -->
        <div class="subtitle-2 mb-2">Required columns</div>
        <v-row dense class="mb-3">
            <v-col v-for="rt in requiredTypes" :key="rt.type" cols="6" sm="3">
                <v-card outlined :class="typePresent(rt.type) ? '' : 'grey lighten-4'" style="height:100%;">
                    <v-card-text class="pa-3 text-center">
                        <v-icon :color="typePresent(rt.type) ? 'primary' : 'grey lighten-1'" size="22">{{ rt.icon }}</v-icon>
                        <div class="caption mt-1" style="font-size:11px; line-height:1.3;">{{ rt.label }}</div>
                        <v-icon v-if="typePresent(rt.type)" small color="success" class="mt-1">mdi-check-circle</v-icon>
                        <v-icon v-else small color="grey lighten-1" class="mt-1">mdi-minus-circle-outline</v-icon>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <!-- Additional column types -->
        <template v-if="additionalTypes.length > 0">
            <div class="subtitle-2 mb-2">Additional columns</div>
            <div class="d-flex flex-wrap mb-4" style="gap:8px;">
                <v-chip v-for="at in additionalTypes" :key="at.type" small outlined label>
                    {{ at.count }} {{ at.type }}
                </v-chip>
            </div>
        </template>

        <!-- Data row -->
        <v-divider class="mb-3"></v-divider>
        <div class="d-flex align-center mb-4" style="gap:12px; flex-wrap:wrap;">
            <template v-if="hasData">
                <v-icon color="success" small>mdi-database-check</v-icon>
                <span class="body-2">
                    <strong>{{ formatNumber(rowCount) }}</strong>
                    <span class="grey--text ml-1">published rows</span>
                </span>
            </template>
            <template v-else-if="dataValidation && dataValidation.skipped">
                <v-icon color="grey" small>mdi-database-off-outline</v-icon>
                <span class="body-2 grey--text">No published data</span>
            </template>
        </div>

        <!-- Structure errors list (collapsed) -->
        <v-expansion-panels v-if="structureErrors.length > 0" flat class="mb-4">
            <v-expansion-panel style="border:1px solid #ffccbc; border-radius:4px;">
                <v-expansion-panel-header class="body-2 error--text py-2 px-3" style="min-height:40px;">
                    <span><v-icon small color="error" class="mr-1">mdi-alert-circle</v-icon>{{ structureErrors.length }} structure {{ structureErrors.length === 1 ? 'issue' : 'issues' }}</span>
                </v-expansion-panel-header>
                <v-expansion-panel-content>
                    <ul class="caption pl-4 my-1">
                        <li v-for="(e, i) in structureErrors" :key="i" class="mb-1">{{ e }}</li>
                    </ul>
                </v-expansion-panel-content>
            </v-expansion-panel>
        </v-expansion-panels>

        <!-- Action buttons -->
        <div class="d-flex flex-wrap" style="gap:8px;">
            <v-btn small outlined @click="goTo('/indicator-dsd')">
                <v-icon left small>mdi-pencil</v-icon> Edit Structure
            </v-btn>
            <v-btn small outlined @click="goTo('/indicator-dsd-import')">
                <v-icon left small>mdi-upload</v-icon> Import Data
            </v-btn>
            <v-btn small outlined @click="goTo('/indicator-dsd-chart')" v-if="hasData">
                <v-icon left small>mdi-chart-line</v-icon> View Chart
            </v-btn>
        </div>

    </template>

</div>
    `,
});
