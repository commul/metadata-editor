/// Geospatial feature catalogue description component
Vue.component('geospatial-feature-description', {
    data: function () {    
        return {
            loading: false,
            saving: false,
            successMessage: '',
            errorMessage: ''
        }
    },
    computed: {
        projectMetadata() {
            return this.$store.state.formData || {};
        },
        featureCatalogue() {
            // Access nested path: description.feature_catalogue
            return this.projectMetadata.description?.feature_catalogue || {};
        },
        ProjectID() {
            return this.$store.state.project_id;
        },
        ProjectType() {
            return this.$store.state.project_type;
        },
        isFieldReadOnly() {
            if (!this.$store.getters.getUserHasEditAccess) {
                return true;
            }
            return false;
        },
        // Fallback template with just name and scope
        fallbackFields() {
            return [
                {
                    key: 'description.feature_catalogue.name',
                    title: 'Name',
                    type: 'string',
                    display_type: 'text',
                    help_text: 'The name of the geospatial feature catalogue'
                },
                {
                    key: 'description.feature_catalogue.scope',
                    title: 'Scope',
                    type: 'string',
                    display_type: 'textarea',
                    help_text: 'The scope of the geospatial feature catalogue'
                }
            ];
        },
        // Get feature catalogue fields from template or use fallback
        featureCatalogueFields() {
            const templateFields = this.getFeatureCatalogueFieldsFromTemplate();
            if (templateFields && templateFields.length > 0) {
                return templateFields;
            }
            return this.fallbackFields;
        }
    },
    methods: {
        // Find feature catalogue section in template and extract fields
        getFeatureCatalogueFieldsFromTemplate: function() {
            try {
                const formTemplate = this.$store.state.formTemplate;
                if (!formTemplate || !formTemplate.template || !formTemplate.template.items) {
                    return null;
                }
                
                // Find the feature catalogue section
                const section = this.findTemplateByItemKey(
                    formTemplate.template.items,
                    'description.feature_catalogue_section'
                );
                
                if (!section || !section.items || !Array.isArray(section.items)) {
                    return null;
                }
                
                // Filter out featureType field and return remaining fields
                return section.items.filter(field => {
                    // Exclude exact match: description.feature_catalogue.featureType
                    return field.key !== 'description.feature_catalogue.featureType';
                });
            } catch (error) {
                console.error('Error getting feature catalogue fields from template:', error);
                return null;
            }
        },
        // Helper method to find template item by key (recursive)
        findTemplateByItemKey: function(items, key) {
            if (!items || !Array.isArray(items)) {
                return null;
            }
            
            for (let i = 0; i < items.length; i++) {
                if (items[i].key === key) {
                    return items[i];
                }
                if (items[i].items) {
                    const found = this.findTemplateByItemKey(items[i].items, key);
                    if (found) {
                        return found;
                    }
                }
            }
            return null;
        },
        // Get value for a field key from formData
        getFieldValue: function(fieldKey) {
            // Remove the 'description.feature_catalogue.' prefix to get the actual key
            const actualKey = fieldKey.replace('description.feature_catalogue.', '');
            return _.get(this.featureCatalogue, actualKey);
        },
        // Update value for a field key in formData
        updateFieldValue: function(fieldKey, value) {
            // Remove the 'description.feature_catalogue.' prefix to get the actual key
            const actualKey = fieldKey.replace('description.feature_catalogue.', '');
            
            // Ensure description.feature_catalogue exists
            if (!this.projectMetadata.description) {
                Vue.set(this.projectMetadata, 'description', {});
            }
            if (!this.projectMetadata.description.feature_catalogue) {
                Vue.set(this.projectMetadata.description, 'feature_catalogue', {});
            }
            
            // Handle nested paths (like versionDate.date, versionDate.type)
            // If key contains dots and there's a flat key with the same name, delete it first
            if (actualKey.indexOf('.') !== -1 && this.projectMetadata.description.feature_catalogue[actualKey]) {
                delete this.projectMetadata.description.feature_catalogue[actualKey];
            }
            
            // Use _.set() to handle nested paths with dot notation
            _.set(this.projectMetadata.description.feature_catalogue, actualKey, value);
            
            // Trigger reactivity by cloning (similar to admin-metadata-edit-component)
            this.projectMetadata.description.feature_catalogue = _.cloneDeep(this.projectMetadata.description.feature_catalogue);
        }        
    },
    template: `
        <div class="geospatial-feature-description pa-4">
            <v-card>
                <v-card-title class="d-flex justify-space-between align-center">
                    <div>
                        <v-icon left>mdi-information-outline</v-icon>
                        {{$t('feature_catalogue')}}
                    </div>
                </v-card-title>
                
                <v-divider></v-divider>
                
                <v-card-text class="pt-4">                    
                    <!-- Dynamically render fields from template or fallback -->
                    <div v-for="field in featureCatalogueFields" :key="field.key" class="mb-3">
                        <form-input
                            :value="getFieldValue(field.key)"
                            :field="field"
                            @input="updateFieldValue(field.key, $event)"
                        ></form-input>
                    </div>
                    
                    <v-alert
                        v-if="featureCatalogueFields.length === 0"
                        type="info"
                        outlined
                        class="mt-4"
                    >
                        No description fields available.
                    </v-alert>
                </v-card-text>
                                
            </v-card>
        </div>
    `
});

