///prop edit componennt
Vue.component('prop-edit', {
    props:['value','parent'],
    data: function () {    
        return {          
          field_data_types: [
            "string",
            "number",
            "integer",
            "boolean"
          ],
          field_display_types: [
            "text",
            "textarea",
            "date",
            "dropdown",
            "dropdown-custom"
          ],
          enum_store_options:[
            {
              "value":"both",
              "label":"Label with code"
            },
            {
              "value":"code",
              "label":"Code"
            },
            {
              "value":"label",
              "label":"Label"
            }            
          ]
        }
    },
    mounted: function(){
      // Ensure prop_key is correct on mount (fixes any existing incorrect prop_keys)
      if (this.prop && this.prop.key && this.parent) {
        const parentPath = (this.parent.prop_key) ? this.parent.prop_key : (this.parent.key ? this.parent.key : '');
        const expectedPropKey = parentPath ? `${parentPath}.${this.prop.key}` : this.prop.key;
        // Only update if prop_key is missing or incorrect
        if (!this.prop.prop_key || this.prop.prop_key !== expectedPropKey) {
          this.prop.prop_key = expectedPropKey;
        }
      }
    },    
    computed: {        
        prop:{           
            get(){
              return this.value;
            },
            set(val){           
              this.$emit('input:value', val);
            }
        },
        SimpleControlledVocabColumns: function(){
          return [
            {
              'type':'text',
              'key':'code',
              'title':'Code'
            },
            {
              'type':'text',
              'key':'label',
              'title':'Label'
            }
          ]
        },
        PropEnum: {
          get() {
            if (!this.prop.enum) {
              return [{}];
            }

            if (this.prop.enum && this.prop.enum.length>0 && typeof(this.prop.enum[0]) =='string')
            {
              let enum_list=[];
              this.prop.enum.forEach(function(item){
                enum_list.push({
                  'code':item,
                  'label':item
                });
              });
              Vue.set(this.prop,"enum",enum_list);
              return enum_list;
            }
            return this.prop.enum;
          },
          set(newValue) {
            Vue.set(this.prop,"enum",newValue);
          }
        },
        PropEnumStoreColumn:{
          get: function(){
            if (this.prop.enum_store_column){
              return this.prop.enum_store_column;
            }
            return 'both';
          },
          set: function(newValue){
            Vue.set(this.prop, "enum_store_column", newValue);
          }
        },
    },
    methods:{
      TemplateDataType(){
        return this.$store.state.user_template_info.data_type;
      },
      isAdminMetaTemplate(){
        return this.$store.state.user_template_info.data_type=='admin_meta';
      },
      updatePropKey: function(e)
      {
        console.log("updating prop key", e);
        const cleanedKey = (e || '').trim();
        this.prop.key=cleanedKey;
        // prop_key is always the full path based on parent + key
        // Use parent.prop_key if available (for nested arrays), otherwise use parent.key
        const parentPath = (this.parent && this.parent.prop_key) ? this.parent.prop_key : (this.parent && this.parent.key ? this.parent.key : '');
        this.prop.prop_key = parentPath ? `${parentPath}.${cleanedKey}` : cleanedKey;
      },    
      isField: function(field_type){
        let field_types= [
          "text",
          "string",
          "number",
          "textarea",
          "dropdown",
          "date",
          "boolean",
          "integer"
        ];
        return field_types.includes(field_type);
      },
      isArrayField: function(prop){
        let array_types=['array', 'nested_array', 'simple_array'];

        if (array_types.includes(prop.type) && !prop.prop){
          return true;
        }

        return false;
      },
      EnumListUpdate: function(e) {
        if (Array.isArray(e)){
          this.$set(this.prop, "enum", e);
        }
        if (!this.prop.enum) {
          this.$set(this.prop, "enum", []);
        }
      },
      DefaultUpdate: function (e){
        if (Array.isArray(e)){
          this.$set(this.prop, "default", e);
        }
        if (!this.prop.default) {
          this.$set(this.prop, "default", []);
        }
      },
      RulesUpdate: function (e)
      {
        this.$set(this.prop, "rules", e);
      },
      HasAdditionalPrefix(value){
        return value.indexOf('additional.')==0;
      },
    },
    template: `<?php require_once 'vue-prop-edit-component-template.php';?>`    
});

