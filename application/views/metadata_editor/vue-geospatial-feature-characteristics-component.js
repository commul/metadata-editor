/// Geospatial feature characteristics component
Vue.component('geospatial-feature-characteristics', {
    props: ['feature_id'],
    data: function () {    
        return {
            loading: true,
            characteristics: [],
            selectedCharacteristic: null,
            selectedRowIndex: -1,
            errors: '',
            success_message: '',
            headers: [
                { text: this.$t('name'), value: 'name', sortable: true },
                { text: this.$t('label'), value: 'label', sortable: true },
                { text: this.$t('data_type'), value: 'data_type', sortable: true }
            ],
            search: '',
            sortBy: 'name',
            sortDesc: false,
            // Data type mapping (pandas -> ISO)
            dataTypeMapping: {
                'object': { 'isoType': 'CharacterString', 'allowListed': true },
                'string': { 'isoType': 'string', 'allowListed': true },
                'category': { 'isoType': 'string', 'allowListed': true },
                'bool': { 'isoType': 'boolean', 'allowListed': true },
                'boolean': { 'isoType': 'boolean', 'allowListed': true },
                'int8': { 'isoType': 'integer', 'allowListed': true },
                'int16': { 'isoType': 'integer', 'allowListed': true },
                'int32': { 'isoType': 'integer', 'allowListed': true },
                'int64': { 'isoType': 'integer', 'allowListed': true },
                'uint8': { 'isoType': 'integer', 'allowListed': true },
                'uint16': { 'isoType': 'integer', 'allowListed': true },
                'uint32': { 'isoType': 'integer', 'allowListed': true },
                'uint64': { 'isoType': 'integer', 'allowListed': true },
                'float16': { 'isoType': 'real', 'allowListed': false },
                'float32': { 'isoType': 'real', 'allowListed': false },
                'float64': { 'isoType': 'real', 'allowListed': false },
                'datetime64[ns]': { 'isoType': 'datetime', 'allowListed': false },
                'datetime64[D]': { 'isoType': 'date', 'allowListed': false },
                'timedelta64[ns]': { 'isoType': 'duration', 'allowListed': false },
                'geometry': { 'isoType': 'string', 'allowListed': false }
            },
            // Available ISO types for dropdown
            isoTypes: ['string', 'boolean', 'integer', 'real', 'datetime', 'date', 'duration'],
            // Toggle for showing/hiding help text
            showHelp: false,
            // Track original values to detect changes
            originalValues: null,
            // Track if form has unsaved changes
            hasUnsavedChanges: false,
            // Local copy of characteristic data for editing
            editedCharacteristic: null
        }
    },
    mounted: function() {
        this.loadCharacteristics();
        
        // Add beforeunload event to warn user if they try to leave page with unsaved changes
        window.addEventListener('beforeunload', this.handleBeforeUnload);
    },
    beforeDestroy: function() {
        // Remove event listener when component is destroyed
        window.removeEventListener('beforeunload', this.handleBeforeUnload);
    },
    computed: {
        filteredCharacteristics: function() {
            let filtered = this.characteristics;
            
            // Apply search filter
            if (this.search) {
                const searchLower = this.search.toLowerCase();
                filtered = filtered.filter(char => 
                    (char.name && char.name.toLowerCase().includes(searchLower)) ||
                    (char.label && char.label.toLowerCase().includes(searchLower)) ||
                    (char.data_type && char.data_type.toLowerCase().includes(searchLower))
                );
            }
            
            // Apply sorting
            filtered.sort((a, b) => {
                let aVal = a[this.sortBy] || '';
                let bVal = b[this.sortBy] || '';
                
                if (typeof aVal === 'string') {
                    aVal = aVal.toLowerCase();
                    bVal = bVal.toLowerCase();
                }
                
                if (this.sortDesc) {
                    return bVal > aVal ? 1 : -1;
                } else {
                    return aVal > bVal ? 1 : -1;
                }
            });
            
            return filtered;
        }
    },
    methods: {
        loadCharacteristics: function() {
            this.loading = true;
            this.errors = '';
            
            if (!this.feature_id) {
                this.errors = 'Feature ID not provided';
                this.loading = false;
                return;
            }
            
            const url = CI.base_url + '/api/geospatial-features/chars/' + this.feature_id;
            
            axios.get(url)
            .then(response => {
                if (response.data.status === 'success') {
                    this.characteristics = response.data.characteristics || [];
                } else {
                    this.errors = response.data.message || 'Failed to load characteristics';
                }
                this.loading = false;
            })
            .catch(error => {
                this.errors = error.response?.data?.message || 'Failed to load characteristics';
                this.loading = false;
            });
        },
        
        selectCharacteristic: function(characteristic, skipWarning) {
            // Check for unsaved changes before switching (unless warning is skipped)
            if (!skipWarning && this.hasUnsavedChanges && this.selectedCharacteristic) {
                if (!confirm(this.$t('You have unsaved changes. Do you want to discard them and continue?'))) {
                    return; // User cancelled, don't switch
                }
            }
            
            this.selectedCharacteristic = characteristic;
            // Find the index of the selected characteristic
            this.selectedRowIndex = this.characteristics.findIndex(c => c.id === characteristic.id);
            
            // Create a local copy for editing
            this.editedCharacteristic = JSON.parse(JSON.stringify(characteristic));
            
            // Store original values for change detection
            this.storeOriginalValues(characteristic);
            this.hasUnsavedChanges = false;
        },
        
        // Store original values when characteristic is selected
        storeOriginalValues: function(characteristic) {
            if (!characteristic) {
                this.originalValues = null;
                return;
            }
            
            let metadata = characteristic.metadata || {};
            if (typeof metadata === 'string') {
                try {
                    metadata = JSON.parse(metadata);
                } catch (e) {
                    metadata = {};
                }
            }
            
            this.originalValues = {
                label: characteristic.label || '',
                data_type: characteristic.data_type || '',
                code: this.getMetadataField(characteristic, 'code') || '',
                valueMeasurementUnit: this.getMetadataField(characteristic, 'valueMeasurementUnit') || '',
                listedValue: JSON.parse(JSON.stringify(this.getListedValues(characteristic))) // Deep copy
            };
        },
        
        // Check if a field value has changed
        hasFieldChanged: function(fieldName, currentValue) {
            if (!this.originalValues) return false;
            
            if (fieldName === 'label') {
                return this.originalValues.label !== currentValue;
            } else if (fieldName === 'data_type') {
                return this.originalValues.data_type !== currentValue;
            } else if (fieldName === 'code') {
                return this.originalValues.code !== currentValue;
            } else if (fieldName === 'valueMeasurementUnit') {
                return this.originalValues.valueMeasurementUnit !== currentValue;
            } else if (fieldName === 'listedValue') {
                // Compare listed values arrays
                const original = this.originalValues.listedValue || [];
                const current = currentValue || [];
                if (original.length !== current.length) return true;
                for (let i = 0; i < original.length; i++) {
                    if (original[i].code !== current[i].code ||
                        original[i].label !== current[i].label ||
                        original[i].definition !== current[i].definition) {
                        return true;
                    }
                }
                return false;
            }
            return false;
        },
        
        updateLabel: function(characteristic, newLabel) {
            if (!this.editedCharacteristic) {
                return;
            }
            
            // Update local copy
            this.editedCharacteristic.label = newLabel;
            
            // Mark as having unsaved changes
            this.checkForChanges();
        },
        
        // Get metadata field value
        getMetadataField: function(characteristic, fieldName) {
            if (!characteristic || !characteristic.metadata) return null;
            
            try {
                let metadata = characteristic.metadata;
                if (typeof metadata === 'string') {
                    metadata = JSON.parse(metadata);
                }
                return metadata[fieldName] || null;
            } catch (e) {
                return null;
            }
        },
        
        // Check if data type supports listed values
        isAllowListed: function(dataType) {
            // Check if any mapping has this ISO type and allowListed = true
            for (let pandasType in this.dataTypeMapping) {
                if (this.dataTypeMapping[pandasType].isoType === dataType && 
                    this.dataTypeMapping[pandasType].allowListed === true) {
                    return true;
                }
            }
            return false;
        },
        
        // Get listed values from metadata
        getListedValues: function(characteristic) {
            if (!characteristic || !characteristic.metadata) {
                // Return array with empty object so table-grid can add rows
                return [{}];
            }
            
            try {
                let metadata = characteristic.metadata;
                if (typeof metadata === 'string') {
                    metadata = JSON.parse(metadata);
                }
                const listedValue = metadata.listedValue || [];
                // Ensure it's an array and has at least one empty object if empty
                if (!Array.isArray(listedValue) || listedValue.length === 0) {
                    return [{}];
                }
                return listedValue;
            } catch (e) {
                return [{}];
            }
        },
        
        // Update metadata via POST endpoint
        updateMetadata: function(characteristic, metadataUpdates) {
            const url = CI.base_url + '/api/geospatial-features/chars_metadata/' + characteristic.id;
            
            // Get current metadata
            let currentMetadata = characteristic.metadata || {};
            if (typeof currentMetadata === 'string') {
                try {
                    currentMetadata = JSON.parse(currentMetadata);
                } catch (e) {
                    currentMetadata = {};
                }
            }
            
            // Build the metadata object - start with current metadata and update fields
            const metadata = Object.assign({}, currentMetadata);
            
            // Update fields in metadata
            // Definition is always synced with label
            if (metadataUpdates.definition !== undefined) {
                metadata.definition = metadataUpdates.definition;
            } else {
                // If definition not provided, use label
                metadata.definition = characteristic.label || '';
            }
            if (metadataUpdates.code !== undefined) {
                metadata.code = metadataUpdates.code;
            }
            if (metadataUpdates.valueMeasurementUnit !== undefined) {
                metadata.valueMeasurementUnit = metadataUpdates.valueMeasurementUnit;
            }
            if (metadataUpdates.listedValue !== undefined) {
                metadata.listedValue = metadataUpdates.listedValue;
            }
            
            // Build the request payload
            // Definition is always synced with label
            const definitionValue = metadataUpdates.definition !== undefined ? metadataUpdates.definition : (characteristic.label || '');
            
            const payload = {
                memberName: characteristic.name,
                valueType: metadataUpdates.valueType || characteristic.data_type,
                definition: definitionValue,
                code: metadataUpdates.code || '',
                valueMeasurementUnit: metadataUpdates.valueMeasurementUnit || '',
                metadata: metadata
            };
            
            // Add listedValue if provided
            if (metadataUpdates.listedValue !== undefined) {
                payload.listedValue = metadataUpdates.listedValue;
            }
            
            axios.post(url, payload)
            .then(response => {
                if (response.data.status === 'success') {
                    // Reload characteristics to get updated data
                    this.loadCharacteristics();
                    // Update selected characteristic if it's the same one (skip warning since we just saved)
                    if (this.selectedCharacteristic && this.selectedCharacteristic.id === characteristic.id) {
                        const updatedChar = this.characteristics.find(c => c.id === characteristic.id);
                        if (updatedChar) {
                            this.selectCharacteristic(updatedChar, true);
                        }
                    }
                    this.success_message = 'Metadata updated successfully';
                    setTimeout(() => { this.success_message = ''; }, 3000);
                } else {
                    this.errors = response.data.message || 'Failed to update metadata';
                }
            })
            .catch(error => {
                this.errors = error.response?.data?.message || 'Failed to update metadata';
            });
        },
        
        // Update data type locally
        updateDataType: function(newDataType) {
            if (!this.editedCharacteristic) return;
            
            // Update local copy
            this.editedCharacteristic.data_type = newDataType;
            
            // Mark as having unsaved changes
            this.checkForChanges();
        },
        
        // Update a single metadata field locally
        updateMetadataField: function(fieldName, value) {
            if (!this.editedCharacteristic) return;
            
            // Get or create metadata object
            let metadata = this.editedCharacteristic.metadata || {};
            if (typeof metadata === 'string') {
                try {
                    metadata = JSON.parse(metadata);
                } catch (e) {
                    metadata = {};
                }
            }
            
            // Update the specific field in metadata
            if (fieldName === 'code') {
                metadata.code = value;
            } else if (fieldName === 'valueMeasurementUnit') {
                metadata.valueMeasurementUnit = value;
            }
            
            // Update the edited characteristic
            this.editedCharacteristic.metadata = metadata;
            
            // Mark as having unsaved changes
            this.checkForChanges();
        },
        
        
        hasFrequencies: function(characteristic) {
            if (!characteristic || !characteristic.metadata) return false;
            
            try {
                let metadata = characteristic.metadata;
                if (typeof metadata === 'string') {
                    metadata = JSON.parse(metadata);
                }
                return metadata && metadata.frequencies && Object.keys(metadata.frequencies).length > 0;
            } catch (e) {
                return false;
            }
        },
        
        getFrequencies: function(characteristic) {
            if (!characteristic || !characteristic.metadata) return null;
            
            try {
                let metadata = characteristic.metadata;
                if (typeof metadata === 'string') {
                    metadata = JSON.parse(metadata);
                }
                return metadata.frequencies || null;
            } catch (e) {
                return null;
            }
        },
        
        getValidCount: function(characteristic) {
            if (!characteristic || !characteristic.metadata) return 0;
            
            try {
                let metadata = characteristic.metadata;
                if (typeof metadata === 'string') {
                    metadata = JSON.parse(metadata);
                }
                return metadata.valid || 0;
            } catch (e) {
                return 0;
            }
        },
        
        hasSummaryStatistics: function(characteristic) {
            if (!characteristic || !characteristic.metadata) return false;
            
            try {
                let metadata = characteristic.metadata;
                if (typeof metadata === 'string') {
                    metadata = JSON.parse(metadata);
                }
                return metadata && metadata.summary_statistics && Object.keys(metadata.summary_statistics).length > 0;
            } catch (e) {
                return false;
            }
        },
        
        getSummaryStatistics: function(characteristic) {
            if (!characteristic || !characteristic.metadata) return null;
            
            try {
                let metadata = characteristic.metadata;
                if (typeof metadata === 'string') {
                    metadata = JSON.parse(metadata);
                }
                return metadata.summary_statistics || null;
            } catch (e) {
                return null;
            }
        },
        
        formatStatValue: function(value) {
            if (value === null || value === undefined) return 'N/A';
            if (typeof value === 'number') {
                return value.toFixed(2);
            }
            return value;
        },
        
        formatMetadata: function(metadata) {
            if (!metadata) return 'No metadata available';
            
            try {
                if (typeof metadata === 'string') {
                    const parsed = JSON.parse(metadata);
                    return JSON.stringify(parsed, null, 2);
                }
                return JSON.stringify(metadata, null, 2);
            } catch (e) {
                return metadata;
            }
        },
        
        getDataTypeColor: function(dataType) {
            const colorMap = {
                'int32': 'blue',
                'int64': 'blue',
                'float64': 'green',
                'object': 'orange',
                'string': 'purple',
                'bool': 'red'
            };
            return colorMap[dataType] || 'grey';
        },
        
        getDataTypeIcon: function(dataType) {
            const iconMap = {
                'int32': 'mdi-numeric',
                'int64': 'mdi-numeric',
                'float64': 'mdi-decimal',
                'object': 'mdi-text',
                'string': 'mdi-format-text',
                'bool': 'mdi-checkbox-marked'
            };
            return iconMap[dataType] || 'mdi-help-circle';
        },
        
        getRowClass: function(item) {
            const index = this.characteristics.findIndex(c => c.id === item.id);
            let classes = 'char_row';
            if (index === this.selectedRowIndex) {
                classes += ' selected-row';
            }
            return classes;
        },
        
        // Find template field by key
        findTemplateFieldByKey: function(key) {
            const formTemplate = this.$store.state.formTemplate;
            if (!formTemplate || !formTemplate.template || !formTemplate.template.items) {
                return null;
            }
            
            const findInItems = (items, searchKey) => {
                for (let i = 0; i < items.length; i++) {
                    const item = items[i];
                    
                    // Check if this item matches
                    if (item.key === searchKey || item.prop_key === searchKey) {
                        return item;
                    }
                    
                    // Check nested items
                    if (item.items && Array.isArray(item.items)) {
                        const found = findInItems(item.items, searchKey);
                        if (found) {
                            return found;
                        }
                    }
                    
                    // Check props array
                    if (item.props && Array.isArray(item.props)) {
                        const found = findInItems(item.props, searchKey);
                        if (found) {
                            return found;
                        }
                    }
                }
                return null;
            };
            
            return findInItems(formTemplate.template.items, key);
        },
        
        // Get field title from template
        getFieldTitle: function(fieldKey) {
            const field = this.findTemplateFieldByKey(fieldKey);
            return field ? (field.title || fieldKey) : fieldKey;
        },
        
        // Get field help text from template
        getFieldHelpText: function(fieldKey) {
            const field = this.findTemplateFieldByKey(fieldKey);
            return field ? (field.help_text || '') : 'no help text';
        },
        
        // Get columns configuration for listed values table
        getListedValueColumns: function() {
            return [
                {
                    key: 'code',
                    title: this.$t(this.getFieldTitle('description.feature_catalogue.featureType.carrierOfCharacteristics.listedValue.code')),
                    type: 'string',
                    display_type: 'text',
                    help_text: this.getFieldHelpText('description.feature_catalogue.featureType.carrierOfCharacteristics.listedValue.code')
                },
                {
                    key: 'label',
                    title: this.$t(this.getFieldTitle('description.feature_catalogue.featureType.carrierOfCharacteristics.listedValue.label')),
                    type: 'string',
                    display_type: 'text',
                    help_text: this.getFieldHelpText('description.feature_catalogue.featureType.carrierOfCharacteristics.listedValue.label')
                }
                /*,
                {
                    key: 'definition',
                    title: this.$t(this.getFieldTitle('description.feature_catalogue.featureType.carrierOfCharacteristics.listedValue.definition')),
                    type: 'string',
                    display_type: 'textarea',
                    help_text: this.getFieldHelpText('description.feature_catalogue.featureType.carrierOfCharacteristics.listedValue.definition')
                }*/
            ];
        },
        
        // Update listed values locally when table-grid-component emits input
        updateListedValues: function(newListedValues) {
            if (!this.editedCharacteristic) return;
            
            // Get or create metadata object
            let metadata = this.editedCharacteristic.metadata || {};
            if (typeof metadata === 'string') {
                try {
                    metadata = JSON.parse(metadata);
                } catch (e) {
                    metadata = {};
                }
            }
            
            // Update listed values in metadata
            metadata.listedValue = newListedValues;
            
            // Update the edited characteristic
            this.editedCharacteristic.metadata = metadata;
            
            // Mark as having unsaved changes
            this.checkForChanges();
        },
        
        // Check if there are unsaved changes
        checkForChanges: function() {
            if (!this.editedCharacteristic || !this.originalValues) {
                this.hasUnsavedChanges = false;
                return;
            }
            
            // Check all fields for changes
            const hasLabelChanged = this.originalValues.label !== (this.editedCharacteristic.label || '');
            const hasDataTypeChanged = this.originalValues.data_type !== (this.editedCharacteristic.data_type || '');
            const hasCodeChanged = this.originalValues.code !== (this.getMetadataField(this.editedCharacteristic, 'code') || '');
            const hasUnitChanged = this.originalValues.valueMeasurementUnit !== (this.getMetadataField(this.editedCharacteristic, 'valueMeasurementUnit') || '');
            const hasListedValuesChanged = this.hasFieldChanged('listedValue', this.getListedValues(this.editedCharacteristic));
            
            this.hasUnsavedChanges = hasLabelChanged || hasDataTypeChanged || hasCodeChanged || hasUnitChanged || hasListedValuesChanged;
        },
        
        // Save all changes
        saveChanges: function() {
            if (!this.editedCharacteristic || !this.hasUnsavedChanges) {
                return;
            }
            
            const characteristic = this.editedCharacteristic;
            const url = CI.base_url + '/api/geospatial-features/chars_metadata/' + characteristic.id;
            
            // Get current metadata
            let currentMetadata = characteristic.metadata || {};
            if (typeof currentMetadata === 'string') {
                try {
                    currentMetadata = JSON.parse(currentMetadata);
                } catch (e) {
                    currentMetadata = {};
                }
            }
            
            // Build the metadata object
            const metadata = Object.assign({}, currentMetadata);
            metadata.definition = characteristic.label || ''; // Sync definition with label
            if (metadata.code !== undefined) {
                metadata.code = metadata.code;
            }
            if (metadata.valueMeasurementUnit !== undefined) {
                metadata.valueMeasurementUnit = metadata.valueMeasurementUnit;
            }
            if (metadata.listedValue !== undefined) {
                metadata.listedValue = metadata.listedValue;
            }
            
            // Build the request payload
            const payload = {
                memberName: characteristic.name,
                valueType: characteristic.data_type,
                definition: characteristic.label || '',
                code: this.getMetadataField(characteristic, 'code') || '',
                valueMeasurementUnit: this.getMetadataField(characteristic, 'valueMeasurementUnit') || '',
                metadata: metadata
            };
            
            // Add listedValue if it exists
            const listedValues = this.getListedValues(characteristic);
            if (listedValues.length > 0) {
                payload.listedValue = listedValues;
            }
            
            axios.post(url, payload)
            .then(response => {
                if (response.data.status === 'success') {
                    // Reload characteristics to get updated data
                    this.loadCharacteristics();
                    // Update selected characteristic (skip warning since we just saved)
                    if (this.selectedCharacteristic && this.selectedCharacteristic.id === characteristic.id) {
                        const updatedChar = this.characteristics.find(c => c.id === characteristic.id);
                        if (updatedChar) {
                            this.selectCharacteristic(updatedChar, true);
                        }
                    }
                    this.success_message = 'Changes saved successfully';
                    setTimeout(() => { this.success_message = ''; }, 3000);
                    this.hasUnsavedChanges = false;
                } else {
                    this.errors = response.data.message || 'Failed to save changes';
                }
            })
            .catch(error => {
                this.errors = error.response?.data?.message || 'Failed to save changes';
            });
        },
        
        // Cancel changes and revert to original values
        cancelChanges: function() {
            if (!this.selectedCharacteristic || !this.hasUnsavedChanges) {
                return;
            }
            
            if (confirm(this.$t('Are you sure you want to discard all unsaved changes?'))) {
                // Reload the original characteristic (skip warning since user already confirmed)
                this.selectCharacteristic(this.characteristics.find(c => c.id === this.selectedCharacteristic.id), true);
                this.hasUnsavedChanges = false;
            }
        },
        
        // Handle beforeunload event to warn user about unsaved changes
        handleBeforeUnload: function(event) {
            if (this.hasUnsavedChanges) {
                // Modern browsers ignore custom message, but we can still trigger the dialog
                event.preventDefault();
                event.returnValue = ''; // Chrome requires returnValue to be set
                return ''; // Some browsers require return value
            }
        }
        
    },
    template: `
        <div class="geospatial-feature-characteristics">
            <v-row no-gutters class="fill-height mt-5" style="height: calc(100vh - 150px);overflow:hidden;">
                    <!-- Left Column - Data Grid -->
                    <v-col cols="12" lg="5" xl="4">
                        <v-card height="100%">
                            <v-card-title class="d-flex justify-space-between align-center">
                                <div>
                                    <v-icon class="mr-2">mdi-table</v-icon>
                                    {{$t('feature_characteristics')}}
                                </div>
                                <v-btn @click="loadCharacteristics" :loading="loading" small outlined>
                                    <v-icon left>mdi-refresh</v-icon>
                                    {{$t('refresh')}}
                                </v-btn>
                            </v-card-title>
                            
                            <v-card-text class="flex-grow-1 overflow-auto pa-4" style="height: calc(100vh - 200px);overflow:auto;">
                                <v-alert v-if="errors" type="error" class="mb-4">
                                    {{errors}}
                                </v-alert>
                                
                                <v-alert v-if="success_message" type="success" class="mb-4">
                                    {{success_message}}
                                </v-alert>
                                
                                <v-progress-linear v-if="loading" indeterminate></v-progress-linear>
                                
                                <!-- Search -->
                                <v-text-field
                                    v-model="search"
                                    :label="$t('search_characteristics')"
                                    prepend-inner-icon="mdi-magnify"
                                    clearable
                                    outlined
                                    dense
                                    class="mb-4"
                                ></v-text-field>
                                
                                <!-- Data Grid -->
                                <v-data-table
                                    :headers="headers"
                                    :items="filteredCharacteristics"
                                    :loading="loading"
                                    item-key="id"
                                    class="elevation-1 text-caption"
                                    @click:row="selectCharacteristic"
                                    :sort-by="sortBy"
                                    :sort-desc="sortDesc"
                                    @update:sort-by="sortBy = $event"
                                    @update:sort-desc="sortDesc = $event"
                                    :items-per-page="-1"
                                    hide-default-footer
                                    dense
                                    :item-class="getRowClass"
                                >
                                    <template v-slot:item.name="{ item }">
                                        <div class="d-flex align-center">
                                            <v-icon :color="getDataTypeColor(item.data_type)" class="mr-2">
                                                {{getDataTypeIcon(item.data_type)}}
                                            </v-icon>
                                            <span class="font-weight-normal">{{item.name}}</span>
                                        </div>
                                    </template>
                                    
                                    <template v-slot:item.label="{ item }">
                                        <!-- <input
                                            v-model="item.label"
                                            :placeholder="$t('label')"
                                            class="text-caption"
                                            style="font-size: 11px; height: 24px; padding: 4px 8px; border: 1px solid #ccc; border-radius: 4px; width: 100%;"
                                            @blur="updateLabel(item, item.label)"
                                            @keyup.enter="updateLabel(item, item.label)"
                                        /> -->
                                        {{item.label}}
                                    </template>
                                    
                                    <template v-slot:item.data_type="{ item }">                                        
                                            {{item.data_type}}                                        
                                    </template>
                                </v-data-table>
                                
                                <div v-if="!loading && characteristics.length === 0" class="text-center py-8">
                                    <v-icon size="64" color="grey">mdi-table-off</v-icon>
                                    <div class="text-h6 mt-4">{{$t('no_characteristics_found')}}</div>
                                    <div class="text-body-2 text--secondary">{{$t('This feature has no characteristics data.')}}</div>
                                </div>
                            </v-card-text>
                        </v-card>
                    </v-col>
                    
                    <!-- Right Column - Selected Characteristic Details -->
                    <v-col cols="12" lg="7" xl="8">
                        <v-card height="100%" style="height: calc(100vh - 100px);overflow:auto;padding-bottom:100px;">
                            <!-- Header Bar with Save/Cancel buttons -->
                            <v-card-title class="d-flex sticky-top justify-space-between align-center" style="background-color: #f5f5f5; border-bottom: 1px solid #e0e0e0;">
                                <div class="d-flex align-center">                                    
                                    <span>{{$t('characteristic_details')}}</span>
                                    <span>
                                        <v-icon title="Show/hide Help" class="ml-3 mr-2" @click="showHelp = !showHelp">mdi-help-circle</v-icon>                                        
                                    </span>
                                    <v-chip v-if="hasUnsavedChanges" small color="warning" class="ml-3">
                                        {{$t('unsaved_changes')}}
                                    </v-chip>
                                </div>
                                <div class="d-flex align-center">                                                                
                                    <v-btn
                                        v-if="hasUnsavedChanges"
                                        small
                                        outlined
                                        color="error"
                                        @click="cancelChanges"
                                        class="mr-2"
                                    >
                                        <v-icon left small>mdi-close</v-icon>
                                        {{$t('cancel')}}
                                    </v-btn>
                                    <v-btn
                                        v-if="hasUnsavedChanges"
                                        small
                                        color="primary"
                                        @click="saveChanges"
                                    >
                                        <v-icon left small>mdi-content-save</v-icon>
                                        {{$t('save')}}
                                    </v-btn>
                                </div>
                            </v-card-title>
                            
                            <v-card-text style="margin-top:20px;">
                                <div v-if="!selectedCharacteristic || !editedCharacteristic" class="text-center py-8">
                                    <v-icon size="64" color="grey">mdi-cursor-pointer</v-icon>
                                    <div class="text-h6 mt-4">{{$t('select_characteristic')}}</div>
                                    <div class="text-body-2 text--secondary">{{$t('Click on a row in the table to view details.')}}</div>
                                </div>
                                
                                <div v-else>
                                    <!-- Basic Information -->
                                    <v-row>
                                        <v-col cols="12" md="6">
                                            <v-text-field
                                                :value="editedCharacteristic.name"
                                                :label="$t(getFieldTitle('description.feature_catalogue.featureType.carrierOfCharacteristics.memberName'))"
                                                readonly
                                                outlined
                                                dense
                                            ></v-text-field>
                                            <div v-if="showHelp && getFieldHelpText('description.feature_catalogue.featureType.carrierOfCharacteristics.memberName')" class="text-caption text--secondary mt-1 mb-3">
                                                {{getFieldHelpText('description.feature_catalogue.featureType.carrierOfCharacteristics.memberName')}}
                                            </div>
                                        </v-col>
                                        <v-col cols="12" md="6">
                                            <v-select
                                                v-model="editedCharacteristic.data_type"
                                                :items="isoTypes"
                                                :label="$t(getFieldTitle('description.feature_catalogue.featureType.carrierOfCharacteristics.valueType'))"
                                                outlined
                                                dense
                                                @change="updateDataType(editedCharacteristic.data_type)"
                                            ></v-select>
                                            <div v-if="showHelp && getFieldHelpText('description.feature_catalogue.featureType.carrierOfCharacteristics.valueType')" class="text-caption text--secondary mt-1 mb-3">
                                                {{getFieldHelpText('description.feature_catalogue.featureType.carrierOfCharacteristics.valueType')}}
                                            </div>
                                        </v-col>
                                    </v-row>
                                    
                                    <v-row>
                                        <v-col cols="12">
                                            <v-text-field
                                                v-model="editedCharacteristic.label"
                                                :label="$t(getFieldTitle('description.feature_catalogue.featureType.carrierOfCharacteristics.definition'))"
                                                outlined
                                                dense
                                                @input="updateLabel(editedCharacteristic, editedCharacteristic.label)"
                                            ></v-text-field>
                                            <div v-if="showHelp && getFieldHelpText('description.feature_catalogue.featureType.carrierOfCharacteristics.definition')" class="text-caption text--secondary mt-1 mb-3">
                                                {{getFieldHelpText('description.feature_catalogue.featureType.carrierOfCharacteristics.definition')}}
                                            </div>
                                        </v-col>
                                    </v-row>
                                    
                                    <!-- Metadata Fields -->
                                    <v-row>
                                        <v-col cols="12" md="6">
                                            <v-text-field
                                                :value="getMetadataField(editedCharacteristic, 'code')"
                                                :label="$t(getFieldTitle('description.feature_catalogue.featureType.carrierOfCharacteristics.code'))"
                                                outlined
                                                dense
                                                @input="updateMetadataField('code', $event)"
                                            ></v-text-field>
                                            <div v-if="showHelp && getFieldHelpText('description.feature_catalogue.featureType.carrierOfCharacteristics.code')" class="text-caption text--secondary mt-1 mb-3">
                                                {{getFieldHelpText('description.feature_catalogue.featureType.carrierOfCharacteristics.code')}}
                                            </div>
                                        </v-col>
                                        <v-col cols="12" md="6">
                                            <v-text-field
                                                :value="getMetadataField(editedCharacteristic, 'valueMeasurementUnit')"
                                                :label="$t(getFieldTitle('description.feature_catalogue.featureType.carrierOfCharacteristics.valueMeasurementUnit'))"
                                                outlined
                                                dense
                                                @input="updateMetadataField('valueMeasurementUnit', $event)"
                                            ></v-text-field>
                                            <div v-if="showHelp && getFieldHelpText('description.feature_catalogue.featureType.carrierOfCharacteristics.valueMeasurementUnit')" class="text-caption text--secondary mt-1 mb-3">
                                                {{getFieldHelpText('description.feature_catalogue.featureType.carrierOfCharacteristics.valueMeasurementUnit')}}
                                            </div>
                                        </v-col>
                                    </v-row>

                                    <!-- Listed Values -->
                                    <v-card outlined class="mt-4" v-if="isAllowListed(editedCharacteristic.data_type)">
                                        <v-card-title class="text-subtitle-1">
                                            <v-icon class="mr-2">mdi-format-list-bulleted</v-icon>
                                            {{$t(getFieldTitle('description.feature_catalogue.featureType.carrierOfCharacteristics.listedValue'))}}
                                        </v-card-title>
                                        <v-card-text>
                                            <div v-if="showHelp && getFieldHelpText('description.feature_catalogue.featureType.carrierOfCharacteristics.listedValue')" class="text-caption text--secondary mb-3">
                                                {{getFieldHelpText('description.feature_catalogue.featureType.carrierOfCharacteristics.listedValue')}}
                                            </div>
                                            <table-grid-component
                                                :value="getListedValues(editedCharacteristic)"
                                                :columns="getListedValueColumns()"
                                                @input="updateListedValues"
                                                class="border elevation-1"
                                            ></table-grid-component>
                                        </v-card-text>
                                    </v-card>
                                    
                                    <!-- Frequencies -->
                                    <v-card outlined class="mt-4" v-if="hasFrequencies(editedCharacteristic)">
                                        <v-card-title class="text-subtitle-1">
                                            <v-icon class="mr-2">mdi-chart-bar</v-icon>
                                            {{$t('frequencies')}}
                                            <v-chip small class="ml-2">{{$t('valid')}}: {{getValidCount(editedCharacteristic)}}</v-chip>
                                        </v-card-title>
                                        <v-card-text>
                                            <table class="table table-sm variable-frequencies" style="width: 100%; font-size: 12px;">
                                                <thead>
                                                    <tr>
                                                        <th style="width: 40%;">{{$t('value')}}</th>
                                                        <th style="width: 15%; text-align: right;">{{$t('count')}}</th>
                                                        <th style="width: 45%;">{{$t('percentage')}}</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr v-for="(freq, value) in getFrequencies(editedCharacteristic)" :key="value">
                                                        <td>{{value}}</td>
                                                        <td style="text-align: right;">{{freq.count}}</td>
                                                        <td>
                                                            <div class="progress" style="height: 20px; position: relative;">
                                                                <div class="progress-bar bg-warning" 
                                                                     role="progressbar" 
                                                                     :style="'width: ' + freq.percentage + '%'" 
                                                                     :aria-valuenow="freq.percentage" 
                                                                     aria-valuemin="0" 
                                                                     aria-valuemax="100">
                                                                </div>
                                                                <span class="progress-text" style="position: absolute; left: 50%; top: 10%; transform: translate(-50%, -50%); font-size: 11px; font-weight: 500;">
                                                                    {{freq.percentage.toFixed(2)}}%
                                                                </span>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </v-card-text>
                                    </v-card>                                    
                                    
                                    <!-- Summary Statistics -->
                                    <v-card outlined class="mt-4" v-if="hasSummaryStatistics(editedCharacteristic)">
                                        <v-card-title class="text-subtitle-1">
                                            <v-icon class="mr-2">mdi-chart-line</v-icon>
                                            {{$t('summary_statistics')}}
                                        </v-card-title>
                                        <v-card-text>
                                            <table style="width: 100%; font-size: 12px;">
                                                <tbody>
                                                    <tr v-for="(value, key) in getSummaryStatistics(editedCharacteristic)" :key="key">
                                                        <td style="width: 150px; padding: 4px 8px;">
                                                            <strong>{{key.charAt(0).toUpperCase() + key.slice(1).replace(/_/g, ' ')}}</strong>
                                                        </td>
                                                        <td style="padding: 4px 8px;">
                                                            {{formatStatValue(value)}}
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </v-card-text>
                                    </v-card>
                                    
                                    <!-- Metadata -->
                                    <v-card outlined class="mt-4" v-show="false">
                                        <v-card-title class="text-subtitle-1">
                                            <v-icon class="mr-2">mdi-code-json</v-icon>
                                            {{$t('metadata')}}
                                        </v-card-title>
                                        <v-card-text>
                                            <pre class="metadata-display">{{formatMetadata(editedCharacteristic.metadata)}}</pre>
                                        </v-card-text>
                                    </v-card>
                                </div>
                            </v-card-text>
                        </v-card>
                    </v-col>
            </v-row>
        </div>
    `
});
