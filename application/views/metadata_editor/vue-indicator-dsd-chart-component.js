// Indicator DSD Chart Visualization Component
Vue.component('indicator-dsd-chart', {
    props: [],
    data() {
        return {
            dataset_id: project_sid,
            dataset_idno: project_idno,
            dataset_type: project_type,
            loading: false,
            chart: null,
            rawData: null, // Raw records from API
            filterOptions: {
                geography: [],
                time_period: {
                    min: null,
                    max: null,
                    values: []
                }
            },
            filters: {
                geography: [],
                time_period_start: null,
                time_period_end: null
            },
            error: null,
            activeTab: 0 // 0 = chart, 1 = table
        }
    },
    created: async function() {
        await this.loadFilterOptions();
        // Do not load chart data until user selects at least one geography
    },
    mounted: function() {
        // Watch for tab changes and resize chart when the chart tab becomes visible
        this.$watch('activeTab', (newVal) => {
            if (newVal === 0 && this.chart) {
                this.$nextTick(() => {
                    if (this.chart) {
                        setTimeout(() => {
                            this.chart.resize();
                        }, 100);
                    }
                });
            }
        });

        // Resize chart on window resize while chart tab is active
        const resizeHandler = () => {
            if (this.chart && this.activeTab === 0) {
                this.chart.resize();
            }
        };
        window.addEventListener('resize', resizeHandler);
        this._resizeHandler = resizeHandler;

        // Inject compact tab spacing overrides for Vuetify 2 tabs
        const style = document.createElement('style');
        style.textContent = `
            .indicator-dsd-chart-component .chart-tabs-header {
                margin: 0 !important;
                padding: 0 !important;
            }
            .indicator-dsd-chart-component .chart-tabs-header .v-tabs-bar {
                margin: 0 !important;
                padding: 0 !important;
                min-height: 48px !important;
                height: 48px !important;
            }
            .indicator-dsd-chart-component .chart-tabs-header .v-tab {
                margin: 0 !important;
                padding: 0 12px !important;
                min-height: 48px !important;
                height: 48px !important;
            }
            .indicator-dsd-chart-component .chart-tabs-header .v-tabs-slider-wrapper {
                margin: 0 !important;
            }
            .indicator-dsd-chart-component .chart-tabs-items {
                margin: 0 !important;
                padding: 0 !important;
            }
            .indicator-dsd-chart-component .chart-tabs-items .v-window__container {
                height: 100% !important;
                padding: 0 !important;
            }
            .indicator-dsd-chart-component .chart-tabs-items .v-window-item {
                height: 100% !important;
            }
            .indicator-dsd-chart-component .chart-tab {
                height: 100% !important;
                padding: 0 !important;
            }
            .indicator-dsd-chart-component .tab-pane {
                height: 100% !important;
                padding: 16px;
                box-sizing: border-box;
            }
            .indicator-dsd-chart-component .tab-pane--center {
                display: grid;
                place-items: center;
                text-align: center;
            }
            .indicator-dsd-chart-component .tab-pane--scroll {
                overflow: auto;
            }
            .indicator-dsd-chart-component .chart-area {
                position: relative;
                width: 100%;
                height: 100%;
            }
            .indicator-dsd-chart-component .chart-area canvas {
                width: 100% !important;
                height: 100% !important;
                display: block;
            }
        `;
        document.head.appendChild(style);
        this._customStyle = style;
    },
    beforeDestroy: function() {
        // Clean up resize listener and injected style
        if (this._resizeHandler) {
            window.removeEventListener('resize', this._resizeHandler);
        }
        if (this.chart) {
            this.chart.destroy();
        }
        if (this._customStyle && this._customStyle.parentNode) {
            this._customStyle.parentNode.removeChild(this._customStyle);
        }
    },
    methods: {
        loadFilterOptions: async function() {
            try {
                // Load DSD columns to get geography code_list for filter options
                const response = await axios.get(
                    CI.base_url + '/api/indicator_dsd/' + this.dataset_id + '?detailed=1'
                );
                if (response.data && response.data.columns && Array.isArray(response.data.columns)) {
                    const geoCol = response.data.columns.find(c => c.column_type === 'geography');
                    if (geoCol && geoCol.code_list && Array.isArray(geoCol.code_list)) {
                        this.filterOptions.geography = geoCol.code_list.map(item => ({
                            code: item.code != null ? String(item.code) : '',
                            label: item.label != null ? String(item.label) : (item.code != null ? String(item.code) : '')
                        })).filter(item => item.code !== '');
                    } else {
                        this.filterOptions.geography = [];
                    }
                }
            } catch (error) {
                console.error('Error loading filter options:', error);
                this.filterOptions.geography = [];
            }
        },
        loadChartData: async function() {
            this.loading = true;
            this.error = null;
            const vm = this;
            
            try {
                let url = CI.base_url + '/api/indicator_dsd/chart_data/' + vm.dataset_id;
                
                // Add filter parameters
                const params = new URLSearchParams();
                if (vm.filters.geography && vm.filters.geography.length > 0) {
                    params.append('geography', vm.filters.geography.join(','));
                }
                if (vm.filters.time_period_start) {
                    params.append('time_period_start', vm.filters.time_period_start);
                }
                if (vm.filters.time_period_end) {
                    params.append('time_period_end', vm.filters.time_period_end);
                }
                
                if (params.toString()) {
                    url += '?' + params.toString();
                }
                
                const response = await axios.get(url);
                
                if (response.data && response.data.status === 'success' && response.data.data) {
                    vm.rawData = response.data.data;
                    // Ensure records is an array
                    if (vm.rawData && !Array.isArray(vm.rawData.records)) {
                        console.warn('Records is not an array:', vm.rawData.records);
                        vm.rawData.records = [];
                    }
                    // Update time_period filter options from response (keep geography from DSD code_list)
                    if (response.data.data.filter_options && response.data.data.filter_options.time_period) {
                        vm.filterOptions.time_period = response.data.data.filter_options.time_period;
                    }
                    vm.$nextTick(() => {
                        vm.updateChart();
                    });
                } else {
                    throw new Error(response.data?.message || 'Failed to load chart data');
                }
            } catch (error) {
                console.error('Error loading chart data:', error);
                vm.error = error.response?.data?.message || error.message || 'Failed to load chart data';
                EventBus.$emit('onFail', vm.error);
            } finally {
                this.loading = false;
            }
        },
        transformToChartData: function(records) {
            if (!records || !Array.isArray(records) || records.length === 0) {
                return { labels: [], datasets: [] };
            }

            // Group records by geography
            const seriesData = {};
            const timePeriods = new Set();

            records.forEach(record => {
                const geo = record.geography;
                const timePeriod = record.time_period;
                const value = record.observation_value;

                if (!seriesData[geo]) {
                    seriesData[geo] = {};
                }
                seriesData[geo][timePeriod] = value;
                timePeriods.add(timePeriod);
            });

            // Get sorted time periods for labels
            const labels = Array.from(timePeriods).sort();

            // Create Chart.js datasets
            const datasets = [];
            const colors = ['#1976D2', '#4CAF50', '#FF9800', '#9C27B0', '#F44336', '#00BCD4', '#FFC107', '#795548'];
            let colorIdx = 0;

            Object.keys(seriesData).sort().forEach(geo => {
                const data = labels.map(timePeriod => ({
                    x: timePeriod,
                    y: seriesData[geo][timePeriod] !== undefined ? seriesData[geo][timePeriod] : null
                }));

                datasets.push({
                    label: geo,
                    data: data,
                    borderColor: colors[colorIdx % colors.length],
                    backgroundColor: colors[colorIdx % colors.length].replace(')', ', 0.1)'),
                    tension: 0.4,
                    fill: false
                });
                colorIdx++;
            });

            return { labels, datasets };
        },
        transformToTableData: function(records) {
            if (!records || !Array.isArray(records) || records.length === 0) {
                return [];
            }

            // Get unique geographies and time periods
            const geographies = new Set();
            const timePeriods = new Set();
            const dataMap = {}; // time_period -> geography -> value

            records.forEach(record => {
                const geo = record.geography;
                const timePeriod = record.time_period;
                const value = record.observation_value;

                geographies.add(geo);
                timePeriods.add(timePeriod);

                if (!dataMap[timePeriod]) {
                    dataMap[timePeriod] = {};
                }
                dataMap[timePeriod][geo] = value;
            });

            // Sort time periods and geographies
            const sortedTimePeriods = Array.from(timePeriods).sort();
            const sortedGeographies = Array.from(geographies).sort();

            // Create table rows
            return sortedTimePeriods.map(timePeriod => {
                const row = {
                    time_period: timePeriod
                };
                sortedGeographies.forEach(geo => {
                    const value = dataMap[timePeriod] && dataMap[timePeriod][geo] !== undefined 
                        ? dataMap[timePeriod][geo] 
                        : null;
                    // Format numeric values for display
                    if (value !== null && typeof value === 'number') {
                        row[geo] = value.toLocaleString(undefined, {maximumFractionDigits: 2});
                    } else {
                        row[geo] = value !== null ? String(value) : '-';
                    }
                });
                return row;
            });
        },
        updateChart: function() {
            if (!this.rawData || !this.rawData.records || !Array.isArray(this.rawData.records) || this.rawData.records.length === 0) {
                return;
            }

            const canvas = this.$refs.chartCanvas;
            if (!canvas) {
                return;
            }

            // Destroy existing chart
            if (this.chart) {
                this.chart.destroy();
            }

            // Check if Chart.js is available
            if (typeof Chart === 'undefined') {
                this.error = 'Chart.js library is not loaded';
                return;
            }

            // Transform raw data to Chart.js format
            const chartData = this.transformToChartData(this.rawData.records);

            const ctx = canvas.getContext('2d');
            
            // Set explicit dimensions on canvas parent
            const canvasParent = canvas.parentElement;
            if (canvasParent) {
                canvasParent.style.position = 'relative';
                canvasParent.style.width = '100%';
                canvasParent.style.height = '100%';
            }
            
            this.chart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: chartData.labels || [],
                    datasets: chartData.datasets || []
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    },
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top'
                        },
                        tooltip: {
                            enabled: true,
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            padding: 12,
                            titleFont: { size: 14 },
                            bodyFont: { size: 13 }
                        }
                    },
                    scales: {
                        x: {
                            title: {
                                display: true,
                                text: 'Time Period'
                            },
                            grid: {
                                display: false
                            }
                        },
                        y: {
                            title: {
                                display: true,
                                text: 'Observation Value'
                            },
                            beginAtZero: false,
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            }
                        }
                    }
                }
            });
            
            // Resize chart after a short delay to ensure DOM is ready
            this.$nextTick(() => {
                if (this.chart) {
                    this.chart.resize();
                }
            });
        },
        applyFilters: function() {
            if (!this.filters.geography || this.filters.geography.length === 0) {
                EventBus.$emit('onFail', this.$t('select_at_least_one_geography') || 'Select at least one geography to view the chart.');
                return;
            }
            this.loadChartData();
        },
        resetFilters: function() {
            this.filters = {
                geography: [],
                time_period_start: null,
                time_period_end: null
            };
            this.rawData = null;
            this.error = null;
            if (this.chart) {
                this.chart.destroy();
                this.chart = null;
            }
        },
        exportChart: function() {
            if (!this.chart) {
                return;
            }
            const url = this.chart.toBase64Image();
            const link = document.createElement('a');
            link.download = 'indicator-chart-' + new Date().getTime() + '.png';
            link.href = url;
            link.click();
        }
    },
    computed: {
        ProjectID() {
            return this.$store.state.project_id;
        },
        hasData: function() {
            return this.rawData && this.rawData.records && Array.isArray(this.rawData.records) && this.rawData.records.length > 0;
        },
        hasFilters: function() {
            return (this.filters.geography && this.filters.geography.length > 0) ||
                   this.filters.time_period_start ||
                   this.filters.time_period_end;
        },
        tableData: function() {
            if (!this.rawData || !this.rawData.records) {
                return [];
            }
            return this.transformToTableData(this.rawData.records);
        },
        tableHeaders: function() {
            if (!this.rawData || !this.rawData.records || !Array.isArray(this.rawData.records) || this.rawData.records.length === 0) {
                return [];
            }

            // Get unique geographies from records
            const geographies = new Set();
            this.rawData.records.forEach(record => {
                if (record && record.geography) {
                    geographies.add(record.geography);
                }
            });

            const sortedGeographies = Array.from(geographies).sort();

            const headers = [
                { text: this.$t('time_period') || 'Time Period', value: 'time_period', sortable: true }
            ];

            sortedGeographies.forEach(geo => {
                headers.push({
                    text: geo,
                    value: geo,
                    sortable: true,
                    align: 'right'
                });
            });

            return headers;
        }
    },
    template: `
        <div class="indicator-dsd-chart-component" style="display: flex; flex-direction: column; height: calc(100vh - 120px);">
            <!-- Page Title -->
            <v-card class="mb-2 m-2 p-2" flat>
                <v-card-title class="d-flex justify-space-between align-center">
                    <div>
                        <h4 class="mb-0">{{$t("timeseries_visualization") || "Timeseries Visualization"}}</h4>
                    </div>
                    <div>
                        <v-btn 
                            v-if="hasData"
                            color="primary" 
                            outlined 
                            small
                            @click="exportChart"
                        >
                            <v-icon left small>mdi-download</v-icon>
                            {{$t("export_chart") || "Export Chart"}}
                        </v-btn>
                    </div>
                </v-card-title>
            </v-card>

            <!-- Error Message -->
            <v-alert v-if="error" type="error" class="m-2" dismissible @input="error = null">
                {{error}}
            </v-alert>

            <!-- Two Column Layout -->
            <div style="display: flex; flex: 1; gap: 16px; overflow: hidden; background: rgb(240 240 240);" class="m-2 elevation-2">
                <!-- Left Column: Filters (30%) -->
                <div style="flex: 0 0 30%; display: flex; flex-direction: column; border: 1px solid #e0e0e0; border-radius: 4px; overflow: hidden; background: white;">
                    <div class="pa-3" style="border-bottom: 1px solid #e0e0e0; background: #f5f5f5;">
                        <h5>{{$t("filters") || "Filters"}}</h5>
                    </div>
                    
                    <div style="flex: 1; overflow-y: auto; padding: 16px;">
                        <!-- Geography Filter (from DSD code_list, typeahead) -->
                        <div class="mb-4">
                            <label class="font-weight-bold mb-2">{{$t("geography") || "Geography"}}</label>
                            <v-autocomplete
                                v-model="filters.geography"
                                :items="filterOptions.geography || []"
                                item-value="code"
                                item-text="label"
                                multiple
                                chips
                                dense
                                outlined
                                hide-details
                                clearable
                                :placeholder="$t('select_geography') || 'Select geography'"
                                :no-data-text="$t('no_geography_options') || 'No geography options. Populate code lists from CSV first.'"
                            ></v-autocomplete>
                            <small class="text-muted">{{$t("select_one_or_more_geography") || "Select one or more to load chart"}}</small>
                        </div>

                        <!-- Time Period Filter -->
                        <div class="mb-4">
                            <label class="font-weight-bold mb-2">{{$t("time_period") || "Time Period"}}</label>
                            <div class="mb-2">
                                <label class="text-caption">{{$t("from") || "From"}}</label>
                                <v-text-field
                                    v-model="filters.time_period_start"
                                    dense
                                    outlined
                                    hide-details
                                    :placeholder="filterOptions.time_period?.min || ''"
                                ></v-text-field>
                            </div>
                            <div>
                                <label class="text-caption">{{$t("to") || "To"}}</label>
                                <v-text-field
                                    v-model="filters.time_period_end"
                                    dense
                                    outlined
                                    hide-details
                                    :placeholder="filterOptions.time_period?.max || ''"
                                ></v-text-field>
                            </div>
                            <small class="text-muted">
                                {{$t("available_range") || "Available range"}}: 
                                <span v-if="filterOptions.time_period?.min && filterOptions.time_period?.max">
                                    {{filterOptions.time_period.min}} - {{filterOptions.time_period.max}}
                                </span>
                                <span v-else>{{$t("all_data") || "All data"}}</span>
                            </small>
                        </div>

                        <!-- Action Buttons -->
                        <div class="d-flex gap-2">
                            <v-btn 
                                color="primary" 
                                block
                                @click="applyFilters"
                                :loading="loading"
                            >
                                <v-icon left small>mdi-filter</v-icon>
                                {{$t("apply_filters") || "Apply Filters"}}
                            </v-btn>
                            <v-btn 
                                v-if="hasFilters"
                                color="secondary" 
                                outlined
                                block
                                @click="resetFilters"
                            >
                                <v-icon left small>mdi-refresh</v-icon>
                                {{$t("reset") || "Reset"}}
                            </v-btn>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Chart/Table (70%) -->
                <div style="flex: 1; display: grid; grid-template-rows: 48px 1fr; border: 1px solid #e0e0e0; border-radius: 4px; overflow: hidden; background: white; min-height: 0;">
                    <!-- Tabs Header -->
                    <v-tabs
                        v-model="activeTab"
                        class="chart-tabs-header"
                        height="48"
                        style="border-bottom: 1px solid #e0e0e0; background: #f5f5f5;"
                    >
                        <v-tab>
                            <v-icon left small>mdi-chart-line</v-icon>
                            {{$t("chart") || "Chart"}}
                        </v-tab>
                        <v-tab>
                            <v-icon left small>mdi-table</v-icon>
                            {{$t("data_table") || "Data Table"}}
                        </v-tab>
                    </v-tabs>
                    
                    <!-- Tab Content -->
                    <v-tabs-items
                        v-model="activeTab"
                        class="chart-tabs-items"
                        style="overflow: hidden;"
                    >
                        <!-- Chart Tab -->
                        <v-tab-item :value="0" class="chart-tab">
                            <div v-if="loading" class="tab-pane tab-pane--center pa-8">
                                <div>
                                    <v-progress-circular indeterminate color="primary"></v-progress-circular>
                                    <div class="mt-2">{{$t("loading") || "Loading"}}...</div>
                                </div>
                            </div>
                            <div v-else-if="!hasData" class="tab-pane tab-pane--center pa-8 text-muted">
                                <v-icon size="64" color="grey lighten-1">mdi-chart-line</v-icon>
                                <div v-if="!filters.geography || filters.geography.length === 0" class="mt-4">
                                    {{$t("select_geography_to_view_chart") || "Select at least one geography to view the chart"}}
                                </div>
                                <div v-else class="mt-4">{{$t("no_data_available") || "No data available"}}</div>
                                <div class="text-caption">{{$t("apply_filters_or_import_data") || "Apply filters or import data"}}</div>
                            </div>
                            <div v-else class="tab-pane">
                                <div class="chart-area">
                                    <canvas ref="chartCanvas"></canvas>
                                </div>
                            </div>
                        </v-tab-item>
                        
                        <!-- Data Table Tab -->
                        <v-tab-item :value="1" class="chart-tab">
                            <div class="tab-pane tab-pane--scroll">
                                <div v-if="loading" class="text-center pa-8">
                                    <v-progress-circular indeterminate color="primary"></v-progress-circular>
                                    <div class="mt-2">{{$t("loading") || "Loading"}}...</div>
                                </div>
                                <div v-else-if="!hasData" class="text-center pa-8 text-muted">
                                    <v-icon size="64" color="grey lighten-1">mdi-table</v-icon>
                                    <div v-if="!filters.geography || filters.geography.length === 0" class="mt-4">
                                        {{$t("select_geography_to_view_chart") || "Select at least one geography to view the chart"}}
                                    </div>
                                    <div v-else class="mt-4">{{$t("no_data_available") || "No data available"}}</div>
                                    <div class="text-caption">{{$t("apply_filters_or_import_data") || "Apply filters or import data"}}</div>
                                </div>
                                <div v-else>
                                    <v-data-table
                                        :headers="tableHeaders"
                                        :items="tableData"
                                        :items-per-page="50"
                                        class="elevation-1"
                                        dense
                                        :footer-props="{
                                            'items-per-page-options': [25, 50, 100, -1],
                                            'items-per-page-text': $t('rows_per_page') || 'Rows per page'
                                        }"
                                    >
                                        <template v-slot:item.time_period="{ item }">
                                            <strong>{{ item.time_period }}</strong>
                                        </template>
                                    </v-data-table>
                                </div>
                            </div>
                        </v-tab-item>
                    </v-tabs-items>
                </div>
            </div>
        </div>
    `
})
