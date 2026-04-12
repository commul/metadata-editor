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
            /** SDMX core: FREQ column when periodicity exists and has a resolved codelist */
            coreFacetFreq: null,
            /** column_type === dimension or measure — facets like SDMX slice dimensions; items may be empty (combobox) */
            facetDimensions: [],
            /** column_type === attribute, only if codelist */
            facetAttributes: [],
            /** column_type === annotation, only if codelist */
            facetAnnotations: [],
            /** Slice filters except geography (keys: FREQ + dimensions/measures + attributes + annotations) */
            dimensionFilters: {},
            filterOptionsError: null,
            /** DSD column name for geography (facet count merge); null if no geography codelist */
            geographyColumnName: null,
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
        await this.loadFacetCounts();
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
            .indicator-dsd-chart-component .facet-group-title {
                font-size: 0.75rem;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.04em;
                color: rgba(0,0,0,0.55);
                margin-top: 12px;
                margin-bottom: 8px;
            }
            .indicator-dsd-chart-component .facet-group-title:first-of-type {
                margin-top: 0;
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
            const mapItems = function(c) {
                if (!c.code_list || !Array.isArray(c.code_list)) {
                    return [];
                }
                return c.code_list.map(item => ({
                    code: item.code != null ? String(item.code) : '',
                    label: item.label != null ? String(item.label) : (item.code != null ? String(item.code) : '')
                })).filter(item => item.code !== '');
            };
            const hasCodelist = function(c) {
                return c.code_list && Array.isArray(c.code_list) && c.code_list.length > 0;
            };
            const facetLabel = function(c) {
                return (c.label && String(c.label).trim()) ? c.label : c.name;
            };
            try {
                const response = await axios.get(
                    CI.base_url + '/api/indicator_dsd/' + this.dataset_id + '?detailed=1&resolve_codelists=1'
                );
                if (response.data && response.data.columns && Array.isArray(response.data.columns)) {
                    const cols = response.data.columns;
                    const geoCol = cols.find(c => c.column_type === 'geography');
                    if (geoCol && hasCodelist(geoCol)) {
                        this.filterOptions.geography = mapItems(geoCol);
                        this.geographyColumnName = geoCol.name;
                    } else {
                        this.filterOptions.geography = [];
                        this.geographyColumnName = null;
                    }

                    const dimFilters = {};
                    this.coreFacetFreq = null;
                    this.facetDimensions = [];
                    this.facetAttributes = [];
                    this.facetAnnotations = [];

                    const freqCol = cols.find(c => c.column_type === 'periodicity');
                    if (freqCol && hasCodelist(freqCol)) {
                        this.coreFacetFreq = {
                            name: freqCol.name,
                            label: facetLabel(freqCol),
                            column_type: 'periodicity',
                            items: mapItems(freqCol)
                        };
                        dimFilters[freqCol.name] = [];
                    }

                    cols.filter(c => c.column_type === 'dimension' || c.column_type === 'measure').forEach(c => {
                        const items = hasCodelist(c) ? mapItems(c) : [];
                        this.facetDimensions.push({
                            name: c.name,
                            label: facetLabel(c),
                            column_type: c.column_type,
                            items
                        });
                        dimFilters[c.name] = [];
                    });

                    cols.filter(c => c.column_type === 'attribute' && hasCodelist(c)).forEach(c => {
                        this.facetAttributes.push({
                            name: c.name,
                            label: facetLabel(c),
                            column_type: 'attribute',
                            items: mapItems(c)
                        });
                        dimFilters[c.name] = [];
                    });

                    cols.filter(c => c.column_type === 'annotation' && hasCodelist(c)).forEach(c => {
                        this.facetAnnotations.push({
                            name: c.name,
                            label: facetLabel(c),
                            column_type: 'annotation',
                            items: mapItems(c)
                        });
                        dimFilters[c.name] = [];
                    });

                    this.dimensionFilters = dimFilters;
                }
            } catch (error) {
                console.error('Error loading filter options:', error);
                this.filterOptionsError = (error.response && error.response.data && error.response.data.message)
                    || error.message
                    || 'Could not load filter options';
                this.filterOptions.geography = [];
                this.geographyColumnName = null;
                this.coreFacetFreq = null;
                this.facetDimensions = [];
                this.facetAttributes = [];
                this.facetAnnotations = [];
                this.dimensionFilters = {};
            }
        },
        /**
         * Append " (n)" to item labels from dataset-wide DuckDB counts (DSD column keys).
         */
        mergeItemsWithCounts: function(items, countRows) {
            const m = {};
            (countRows || []).forEach(function(r) {
                if (r && r.value != null && r.count != null) {
                    m[String(r.value)] = Number(r.count);
                }
            });
            return (items || []).map(function(it) {
                const code = String(it.code != null ? it.code : '');
                const baseLabel = it.label != null && String(it.label).trim() !== ''
                    ? String(it.label)
                    : code;
                const n = m[code];
                const label = n != null ? baseLabel + ' (' + n.toLocaleString() + ')' : baseLabel;
                return Object.assign({}, it, { label: label });
            });
        },
        applyFacetCountsToFilterItems: function(column_counts) {
            if (!column_counts || typeof column_counts !== 'object') {
                return;
            }
            if (this.geographyColumnName && this.filterOptions.geography && this.filterOptions.geography.length) {
                const gr = column_counts[this.geographyColumnName];
                if (gr && gr.length) {
                    this.filterOptions.geography = this.mergeItemsWithCounts(this.filterOptions.geography, gr);
                }
            }
            if (this.coreFacetFreq && this.coreFacetFreq.items && this.coreFacetFreq.items.length) {
                const fr = column_counts[this.coreFacetFreq.name];
                if (fr && fr.length) {
                    this.coreFacetFreq = Object.assign({}, this.coreFacetFreq, {
                        items: this.mergeItemsWithCounts(this.coreFacetFreq.items, fr)
                    });
                }
            }
            this.facetDimensions = this.facetDimensions.map(function(col) {
                const rows = column_counts[col.name];
                if (!col.items || !col.items.length || !rows || !rows.length) {
                    return col;
                }
                return Object.assign({}, col, { items: this.mergeItemsWithCounts(col.items, rows) });
            }.bind(this));
            this.facetAttributes = this.facetAttributes.map(function(col) {
                const rows = column_counts[col.name];
                if (!col.items || !col.items.length || !rows || !rows.length) {
                    return col;
                }
                return Object.assign({}, col, { items: this.mergeItemsWithCounts(col.items, rows) });
            }.bind(this));
            this.facetAnnotations = this.facetAnnotations.map(function(col) {
                const rows = column_counts[col.name];
                if (!col.items || !col.items.length || !rows || !rows.length) {
                    return col;
                }
                return Object.assign({}, col, { items: this.mergeItemsWithCounts(col.items, rows) });
            }.bind(this));
        },
        loadFacetCounts: async function() {
            try {
                const response = await axios.get(
                    CI.base_url + '/api/indicator_dsd/chart_facet_counts/' + this.dataset_id
                );
                if (response.data && response.data.status === 'success' && response.data.data
                    && response.data.data.column_counts) {
                    this.applyFacetCountsToFilterItems(response.data.data.column_counts);
                }
            } catch (e) {
                console.warn('chart facet counts:', e);
            }
        },
        loadChartData: async function() {
            this.loading = true;
            this.error = null;
            const vm = this;

            try {
                const url = CI.base_url + '/api/indicator_dsd/chart_data/' + vm.dataset_id;
                const dimensions = {};
                Object.keys(vm.dimensionFilters || {}).forEach(k => {
                    const arr = vm.dimensionFilters[k];
                    if (Array.isArray(arr) && arr.length > 0) {
                        dimensions[k] = arr.slice();
                    }
                });
                const body = {
                    geography: vm.filters.geography && vm.filters.geography.length > 0 ? vm.filters.geography.slice() : [],
                    dimensions,
                    time_period_start: vm.filters.time_period_start || null,
                    time_period_end: vm.filters.time_period_end || null
                };

                const response = await axios.post(url, body, {
                    headers: { 'Content-Type': 'application/json' }
                });

                if (response.data && response.data.status === 'success' && response.data.data) {
                    vm.rawData = response.data.data;
                    if (vm.rawData && !Array.isArray(vm.rawData.records)) {
                        console.warn('Records is not an array:', vm.rawData.records);
                        vm.rawData.records = [];
                    }
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
        /** Stable series identity (codes); matches API series_key. */
        chartSeriesId: function(record) {
            if (!record) {
                return '';
            }
            if (record.series_key != null && record.series_key !== '') {
                return String(record.series_key);
            }
            return record.geography != null && record.geography !== '' ? String(record.geography) : '';
        },
        /** Legend / table header text (labels when API provides series_key_label). */
        chartSeriesDisplay: function(record) {
            if (!record) {
                return '';
            }
            if (record.series_key_label != null && String(record.series_key_label).trim() !== '') {
                return String(record.series_key_label);
            }
            return this.chartSeriesId(record);
        },
        transformToChartData: function(records) {
            if (!records || !Array.isArray(records) || records.length === 0) {
                return { labels: [], datasets: [] };
            }

            const seriesData = {};
            const seriesDisplay = {};
            const timePeriods = new Set();

            records.forEach(record => {
                const sid = this.chartSeriesId(record);
                const timePeriod = record.time_period;
                const value = record.observation_value;

                if (!sid) {
                    return;
                }
                if (!seriesData[sid]) {
                    seriesData[sid] = {};
                    seriesDisplay[sid] = this.chartSeriesDisplay(record);
                }
                seriesData[sid][timePeriod] = value;
                timePeriods.add(timePeriod);
            });

            const labels = Array.from(timePeriods).sort();

            const datasets = [];
            const colors = ['#1976D2', '#4CAF50', '#FF9800', '#9C27B0', '#F44336', '#00BCD4', '#FFC107', '#795548'];
            let colorIdx = 0;

            Object.keys(seriesData).sort((a, b) =>
                String(seriesDisplay[a] || a).localeCompare(String(seriesDisplay[b] || b), undefined, { sensitivity: 'base' })
            ).forEach(sid => {
                const data = labels.map(timePeriod => ({
                    x: timePeriod,
                    y: seriesData[sid][timePeriod] !== undefined ? seriesData[sid][timePeriod] : null
                }));

                datasets.push({
                    label: seriesDisplay[sid] || sid,
                    data: data,
                    borderColor: colors[colorIdx % colors.length],
                    backgroundColor: colors[colorIdx % colors.length].replace(')', ', 0.1)'),
                    tension: 0,
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

            const seriesIds = new Set();
            const timePeriods = new Set();
            const dataMap = {};
            const idToDisplay = {};

            records.forEach(record => {
                const sid = this.chartSeriesId(record);
                const timePeriod = record.time_period;
                const value = record.observation_value;

                if (!sid) {
                    return;
                }
                seriesIds.add(sid);
                if (!idToDisplay[sid]) {
                    idToDisplay[sid] = this.chartSeriesDisplay(record);
                }
                timePeriods.add(timePeriod);

                if (!dataMap[timePeriod]) {
                    dataMap[timePeriod] = {};
                }
                dataMap[timePeriod][sid] = value;
            });

            const sortedTimePeriods = Array.from(timePeriods).sort();
            const sortedSeriesIds = Array.from(seriesIds).sort((a, b) =>
                String(idToDisplay[a] || a).localeCompare(String(idToDisplay[b] || b), undefined, { sensitivity: 'base' })
            );

            return sortedTimePeriods.map(timePeriod => {
                const row = {
                    time_period: timePeriod
                };
                sortedSeriesIds.forEach(sid => {
                    const value = dataMap[timePeriod] && dataMap[timePeriod][sid] !== undefined
                        ? dataMap[timePeriod][sid]
                        : null;
                    if (value !== null && typeof value === 'number') {
                        row[sid] = value.toLocaleString(undefined, {maximumFractionDigits: 2});
                    } else {
                        row[sid] = value !== null ? String(value) : '-';
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
                                text: vm.$t('field_time_period') || 'Time period'
                            },
                            grid: {
                                display: false
                            }
                        },
                        y: {
                            title: {
                                display: true,
                                text: vm.$t('dsd_role_measure') || 'Observation value'
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
            let any = (this.filters.geography && this.filters.geography.length > 0);
            if (!any && this.dimensionFilters) {
                any = Object.keys(this.dimensionFilters).some(k =>
                    Array.isArray(this.dimensionFilters[k]) && this.dimensionFilters[k].length > 0
                );
            }
            if (!any) {
                EventBus.$emit('onFail', this.$t('select_at_least_one_dimension_filter') || 'Select at least one value in geography or another dimension filter.');
                return;
            }
            this.loadChartData();
        },
        resetFilters: function() {
            const dim = {};
            Object.keys(this.dimensionFilters || {}).forEach(k => { dim[k] = []; });
            this.dimensionFilters = dim;
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
            const dimAny = this.dimensionFilters && Object.keys(this.dimensionFilters).some(k =>
                Array.isArray(this.dimensionFilters[k]) && this.dimensionFilters[k].length > 0
            );
            return (this.filters.geography && this.filters.geography.length > 0) ||
                   dimAny ||
                   this.filters.time_period_start ||
                   this.filters.time_period_end;
        },
        /** At least one geography or slice facet (FREQ, dimension/measure, attribute, annotation) selection. */
        hasDimensionFilterSelection: function() {
            if (this.filters.geography && this.filters.geography.length > 0) {
                return true;
            }
            if (!this.dimensionFilters) {
                return false;
            }
            return Object.keys(this.dimensionFilters).some(k =>
                Array.isArray(this.dimensionFilters[k]) && this.dimensionFilters[k].length > 0
            );
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

            const seriesIds = new Set();
            const idToDisplay = {};
            this.rawData.records.forEach(record => {
                if (!record) {
                    return;
                }
                const sid = this.chartSeriesId(record);
                if (!sid) {
                    return;
                }
                seriesIds.add(sid);
                if (!idToDisplay[sid]) {
                    idToDisplay[sid] = this.chartSeriesDisplay(record);
                }
            });

            const sortedIds = Array.from(seriesIds).sort((a, b) =>
                String(idToDisplay[a] || a).localeCompare(String(idToDisplay[b] || b), undefined, { sensitivity: 'base' })
            );

            const headers = [
                { text: this.$t('time_period') || 'Time Period', value: 'time_period', sortable: true }
            ];

            sortedIds.forEach(sid => {
                headers.push({
                    text: idToDisplay[sid] || sid,
                    value: sid,
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
                        <v-alert v-if="filterOptionsError" type="error" dense text class="mb-3">
                            {{ filterOptionsError }}
                        </v-alert>
                        <div class="facet-group-title">{{$t("viz_facets_core_sdmx") || "Core"}}</div>

                        <div class="mb-4">
                            <label class="font-weight-bold mb-2">{{$t("field_geography") || "Geography"}}</label>
                            <v-combobox
                                v-if="!(filterOptions.geography && filterOptions.geography.length)"
                                v-model="filters.geography"
                                multiple
                                chips
                                small-chips
                                deletable-chips
                                dense
                                outlined
                                hide-details
                                clearable
                                :placeholder="$t('geography_codes_combobox') || 'Enter geography codes (no codelist on DSD)'"
                            ></v-combobox>
                            <v-autocomplete
                                v-else
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
                                :no-data-text="$t('no_geography_options') || 'No geography options.'"
                            ></v-autocomplete>
                        </div>

                        <div class="mb-4" v-if="coreFacetFreq">
                            <label class="font-weight-bold mb-2">{{$t("field_freq") || "Periodicity"}}</label>
                            <v-autocomplete
                                v-model="dimensionFilters[coreFacetFreq.name]"
                                :items="coreFacetFreq.items"
                                item-value="code"
                                item-text="label"
                                multiple
                                chips
                                dense
                                outlined
                                hide-details
                                clearable
                                :placeholder="$t('select_freq_codes') || 'Select frequency codes'"
                                :no-data-text="$t('no_options') || 'No options'"
                            ></v-autocomplete>
                        </div>

                        <div class="mb-4">
                            <label class="font-weight-bold mb-2">{{$t("field_time_period") || "Time period"}}</label>
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

                        <div class="facet-group-title" v-if="facetDimensions.length">{{$t("viz_facets_dimensions_and_measures") || "Dimensions"}}</div>
                        <div class="mb-4" v-for="col in facetDimensions" :key="'dim-' + col.name">
                            <label class="font-weight-bold mb-2">{{ col.label }}</label>
                            <v-combobox
                                v-if="!col.items.length"
                                v-model="dimensionFilters[col.name]"
                                multiple
                                chips
                                small-chips
                                deletable-chips
                                dense
                                outlined
                                hide-details
                                clearable
                                :placeholder="$t('dimension_codes_combobox') || 'Type or paste codes (no codelist on DSD)'"
                            ></v-combobox>
                            <v-autocomplete
                                v-else
                                v-model="dimensionFilters[col.name]"
                                :items="col.items"
                                item-value="code"
                                item-text="label"
                                multiple
                                chips
                                dense
                                outlined
                                hide-details
                                clearable
                                :placeholder="$t('select_codes') || 'Select codes'"
                                :no-data-text="$t('no_options') || 'No options'"
                            ></v-autocomplete>
                        </div>

                        <div class="facet-group-title" v-if="facetAttributes.length">{{$t("viz_facets_attributes") || "Attributes"}}</div>
                        <div class="mb-4" v-for="col in facetAttributes" :key="'attr-' + col.name">
                            <label class="font-weight-bold mb-2">{{ col.label }}</label>
                            <v-autocomplete
                                v-model="dimensionFilters[col.name]"
                                :items="col.items"
                                item-value="code"
                                item-text="label"
                                multiple
                                chips
                                dense
                                outlined
                                hide-details
                                clearable
                                :placeholder="$t('select_codes') || 'Select codes'"
                                :no-data-text="$t('no_options') || 'No options'"
                            ></v-autocomplete>
                        </div>

                        <div class="facet-group-title" v-if="facetAnnotations.length">{{$t("viz_facets_annotations") || "Annotations"}}</div>
                        <div class="mb-4" v-for="col in facetAnnotations" :key="'ann-' + col.name">
                            <label class="font-weight-bold mb-2">{{ col.label }}</label>
                            <v-autocomplete
                                v-model="dimensionFilters[col.name]"
                                :items="col.items"
                                item-value="code"
                                item-text="label"
                                multiple
                                chips
                                dense
                                outlined
                                hide-details
                                clearable
                                :placeholder="$t('select_codes') || 'Select codes'"
                                :no-data-text="$t('no_options') || 'No options'"
                            ></v-autocomplete>
                        </div>

                        <!-- Action Buttons -->
                        <div class="d-flex align-center" style="gap: 8px;">
                            <v-btn 
                                color="primary"
                                depressed
                                small
                                @click="applyFilters"
                                :loading="loading"
                            >
                                <v-icon left small>mdi-filter</v-icon>
                                {{$t("apply_filters") || "Apply"}}
                            </v-btn>
                            <v-btn 
                                v-if="hasFilters"
                                text
                                small
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
                                <div class="mt-4">
                                    {{ hasDimensionFilterSelection ? ($t("no_data_available") || "No data available") : ($t("select_dimension_filters_to_view_chart") || "Select at least one geography or dimension filter to view the chart") }}
                                </div>
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
                                    <div class="mt-4">
                                        {{ hasDimensionFilterSelection ? ($t("no_data_available") || "No data available") : ($t("select_dimension_filters_to_view_chart") || "Select at least one geography or dimension filter to view the chart") }}
                                    </div>
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
