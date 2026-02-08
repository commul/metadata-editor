// Indicator DSD edit form component
Vue.component('indicator-dsd-edit', {
    props: ['column', 'index_key'],
    data: function() {
        return {
            data_types: {
                "string": this.$t("string") || "String",
                "integer": this.$t("integer") || "Integer",
                "float": this.$t("float") || "Float",
                "double": this.$t("double") || "Double",
                "date": this.$t("date") || "Date",
                "boolean": this.$t("boolean") || "Boolean"
            },
            column_types: {
                "dimension": this.$t("dimension") || "Dimension",
                "time_period": this.$t("time_period") || "Time Period",
                "measure": this.$t("measure") || "Measure",
                "attribute": this.$t("attribute") || "Attribute",
                "indicator_id": this.$t("indicator_id") || "Indicator ID",
                "indicator_name": this.$t("indicator_name") || "Indicator Name",
                "annotation": this.$t("annotation") || "Annotation",
                "geography": this.$t("geography") || "Geography",
                "observation_value": this.$t("observation_value") || "Observation Value",
                "periodicity": this.$t("periodicity") || "Periodicity"
            },
            time_period_formats: {
                "YYYY": "YYYY",
                "YYYY-MM": "YYYY-MM",
                "YYYY-MM-DD": "YYYY-MM-DD",
                "YYYY-MM-DDTHH:MM:SS": "YYYY-MM-DDTHH:MM:SS",
                "YYYY-MM-DDTHH:MM:SSZ": "YYYY-MM-DDTHH:MM:SSZ"
            },
            code_list_template: [
                {
                    "key": "code",
                    "title": this.$t("code") || "Code",
                    "type": "text"
                },
                {
                    "key": "label",
                    "title": this.$t("label") || "Label",
                    "type": "text"
                },
                /*
                {
                    "key": "description",
                    "title": this.$t("description") || "Description",
                    "type": "textarea"
                }*/
            ]
        }
    },
    created: function() {
        // Initialize code_list if not present
        if (!this.column.code_list) {
            Vue.set(this.column, 'code_list', []);
        }

        // Initialize code_list_reference if not present
        if (!this.column.code_list_reference) {
            Vue.set(this.column, 'code_list_reference', {
                id: '',
                name: '',
                version: '',
                uri: '',
                note: ''
            });
        }

        // Initialize metadata if not present
        if (!this.column.metadata) {
            Vue.set(this.column, 'metadata', {});
        }
        if (!this.column.metadata.hasOwnProperty('value_label_column')) {
            Vue.set(this.column.metadata, 'value_label_column', this.column.metadata.value_label_column || '');
        }
    },
    watch: {
        column: {
            handler: function(newVal) {
                this.$emit('input', newVal);
            },
            deep: true
        }
    },
    methods: {
        OnValueUpdate: function() {
            this.$emit('input', this.column);
        },
        onCodeListInput: function(newList) {
            var list = newList && Array.isArray(newList) ? newList : [];
            var normalized = list.map(function(row) {
                return {
                    code: row && row.code != null ? row.code : '',
                    label: row && row.label != null ? row.label : '',
                    description: row && row.description != null ? row.description : ''
                };
            });
            Vue.set(this.column, 'code_list', normalized);
            this.OnValueUpdate();
        },
        clearCodeListReference: function() {
            Vue.set(this.column, 'code_list_reference', {
                id: '',
                name: '',
                version: '',
                uri: '',
                note: ''
            });
            this.OnValueUpdate();
        }
    },
    computed: {
        showTimePeriodFormat: function() {
            return this.column.column_type === 'time_period';
        },
        showCodeList: function() {
            // Show code list for dimensions and other column types that might use codes
            return ['dimension', 'attribute', 'geography'].includes(this.column.column_type);
        },
        codeListColumns: function() {
            var cols = this.code_list_template.slice();
            var codeCol = cols.find(function(c) { return c.key === 'code'; });
            if (codeCol) {
                codeCol = Object.assign({}, codeCol, { is_unique: true });
                cols = cols.map(function(c) { return c.key === 'code' ? codeCol : c; });
            }
            return cols;
        }
    },
    template: `
        <div class="indicator-dsd-edit-component" style="height:100vh" v-if="column">
            <div style="font-size:small;" class="mb-2">

                <div class="p-2">
                    <!-- Name -->
                    <div class="form-group form-field">
                        <label>{{$t("name") || "Name"}} <span class="text-danger">*</span></label>
                        <input 
                            type="text" 
                            class="form-control form-control-sm" 
                            v-model="column.name" 
                            :pattern="'^[a-zA-Z0-9_]*$'"
                            maxlength="100"
                            required
                        />
                        <small class="form-text text-muted">{{$t("column_name_hint") || "Alphanumeric and underscore only"}}</small>
                    </div>

                    <!-- Label -->
                    <div class="form-group form-field">
                        <label>{{$t("label") || "Label"}}</label>
                        <input 
                            type="text" 
                            class="form-control form-control-sm" 
                            v-model="column.label" 
                        />
                    </div>

                    <!-- Description -->
                    <div class="form-group form-field">
                        <label>{{$t("description") || "Description"}}</label>
                        <textarea 
                            class="form-control form-control-sm" 
                            v-model="column.description" 
                            rows="3"
                        ></textarea>
                    </div>

                    <!-- Data Type -->
                    <div class="form-group form-field">
                        <label>{{$t("data_type") || "Data Type"}} <span class="text-danger">*</span></label>
                        <select 
                            v-model="column.data_type" 
                            class="form-control form-control-sm form-field-dropdown"
                            required
                        >
                            <option value="">-</option>
                            <option v-for="(label, value) in data_types" :key="value" :value="value">
                                {{label}}
                            </option>
                        </select>
                    </div>

                    <!-- Column Type -->
                    <div class="form-group form-field">
                        <label>{{$t("column_type") || "Column Type"}} <span class="text-danger">*</span></label>
                        <select 
                            v-model="column.column_type" 
                            class="form-control form-control-sm form-field-dropdown"
                            required
                        >
                            <option value="">-</option>
                            <option v-for="(label, value) in column_types" :key="value" :value="value">
                                {{label}}
                            </option>
                        </select>
                    </div>

                    <!-- Value label column (CSV/data column used for value labels, e.g. for dimensions) -->
                    <div class="form-group form-field">
                        <label>{{$t("value_label_column") || "Value label column"}}</label>
                        <input 
                            type="text" 
                            class="form-control form-control-sm" 
                            v-model="column.metadata.value_label_column" 
                            :placeholder="$t('value_label_column_placeholder') || 'CSV column name for labels'"
                        />
                        <small class="form-text text-muted">{{$t("value_label_column_hint") || "Optional: column name in the data file that holds labels for this field (used for value_labels)"}}</small>
                    </div>

                    <!-- Time Period Format (only for time_period column type) -->
                    <div class="form-group form-field" v-if="showTimePeriodFormat">
                        <label>{{$t("time_period_format") || "Time Period Format"}}</label>
                        <select 
                            v-model="column.time_period_format" 
                            class="form-control form-control-sm form-field-dropdown"
                        >
                            <option value="">-</option>
                            <option v-for="(label, value) in time_period_formats" :key="value" :value="value">
                                {{label}}
                            </option>
                        </select>
                    </div>

                    <!-- Code List (for dimensions and other types) - uses table-grid for sort, copy/paste, unique validation -->
                    <div class="form-group form-field" v-if="showCodeList">
                        <label class="mb-2">{{$t("code_list") || "Code List"}} [{{column.code_list.length}}]</label>

                        <table-grid-component
                            :value="column.code_list"
                            @input="onCodeListInput"
                            :columns="codeListColumns"
                            :field="{}"
                            class="border elevation-1"
                            style="max-height: 400px; overflow-y: auto;"
                        >
                        </table-grid-component>
                    </div>

                    <!-- Code List Reference -->
                    <div class="form-group form-field" v-if="showCodeList">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label>{{$t("code_list_reference") || "Code List Reference"}}</label>
                            <button 
                                type="button" 
                                class="btn btn-sm btn-link" 
                                @click="clearCodeListReference"
                                v-if="column.code_list_reference && column.code_list_reference.uri"
                            >
                                {{$t("clear") || "Clear"}}
                            </button>
                        </div>
                        
                        <div v-if="column.code_list_reference">
                            <div class="form-group form-field">
                                <label>{{$t("uri") || "URI"}} <span class="text-danger">*</span></label>
                                <input 
                                    type="text" 
                                    class="form-control form-control-sm" 
                                    v-model="column.code_list_reference.uri" 
                                    placeholder="https://..."
                                />
                            </div>
                            <div class="form-group form-field">
                                <label>{{$t("id") || "ID"}}</label>
                                <input 
                                    type="text" 
                                    class="form-control form-control-sm" 
                                    v-model="column.code_list_reference.id" 
                                />
                            </div>
                            <div class="form-group form-field">
                                <label>{{$t("name") || "Name"}}</label>
                                <input 
                                    type="text" 
                                    class="form-control form-control-sm" 
                                    v-model="column.code_list_reference.name" 
                                />
                            </div>
                            <div class="form-group form-field">
                                <label>{{$t("version") || "Version"}}</label>
                                <input 
                                    type="text" 
                                    class="form-control form-control-sm" 
                                    v-model="column.code_list_reference.version" 
                                />
                            </div>
                            <div class="form-group form-field">
                                <label>{{$t("note") || "Note"}}</label>
                                <textarea 
                                    class="form-control form-control-sm" 
                                    v-model="column.code_list_reference.note" 
                                    rows="2"
                                ></textarea>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `
})
