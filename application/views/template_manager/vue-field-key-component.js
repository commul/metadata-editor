///vue component for editing KEY field
Vue.component('vue-key-field', {
    props:['value'],
    data: function () {    
        return {
            template: this.value,
            validation_errors: [],
            local_value: JSON.parse(JSON.stringify(this.value)),
        }
    },
    mounted: function(){
      this.validation_errors=[];
    },
    
    computed: {
        coreTemplateParts(){
          return this.$store.state.core_template_parts;
        },
        TemplateActiveNode(){
          return this.$store.state.active_node;
        },
        ActiveCoreNode(){
          return this.$store.state.active_core_node;
        },
        UserTreeUsedKeys(){
          return this.$store.getters.getUserTreeKeys;
        },
        CoreTemplate(){
          return this.$store.state.core_template;
        },
        UserTemplate(){
          return this.$store.state.user_template;
        },
        CoreTreeItems(){
          return this.$store.state.core_tree_items;
        },
        UserTreeItems(){
          return this.$store.state.user_tree_items;
        },
        TemplateIsCustom(){
          return this.$store.state.user_template_info.data_type=='custom';
        },
        HasAdditionalPrefix(){
            return this.local_value.indexOf('additional.')==0;
        },
        isKeyValid(){
            // key must be unique, non-empty, no empty dot segments
            // allow namespaces (e.g., wb:org) and no prefix; permit letters, numbers, _, -, :
            let key=this.local_value;

            //break key into parts using dot as separator
            let parts=key.split('.');

            //check if key has any empty parts
            if (parts.indexOf('')!==-1){
                return false;
            }

            //check allowed characters for each part (allow namespace colon)
            for(let i=0;i<parts.length;i++){
                if (parts[i].match(/^[a-zA-Z0-9:_-]+$/)==null){
                    return false;
                }
            }

            //check if key is unique            
            if (this.UserTreeUsedKeys.indexOf(this.local_value)!==-1 && this.local_value!=this.value){
                return false;
            }

            return true;
        }
    },
    watch: {
        local_value: function(newVal, oldVal){
            //run validation
            this.ValidateKey();
        }
    },
    methods:{
        UpdateKeyValue: function(){
            console.log("UpdateKeyValue", this.local_value);
            this.validation_errors=[];
            
            if (!this.ValidateKey()){
                console.log("Validation failed", this.validation_errors);
                return;
            }
            console.log("Validation passed", this.local_value);
            this.$emit('input', this.local_value);

        },
        ValidateKey: function()
        {
            // key must be unique, non-empty, no empty dot segments
            // allow namespaces (e.g., wb:org) and no prefix; permit letters, numbers, _, -, :

            this.validation_errors=[];

            let key=this.local_value;

            if (key==''){
                this.validation_errors.push('Key cannot be empty');
            }

            //break key into parts using dot as separator
            let parts=key.split('.');

            //check if key has any empty parts
            if (parts.indexOf('')!==-1){
                this.validation_errors.push('Key must not contain empty parts');
            }

            //check all parts only contain letters, numbers, dash, underscore, colon
            for(let i=0;i<parts.length;i++){
                if (parts[i].match(/^[a-zA-Z0-9:_-]+$/)==null){
                    this.validation_errors.push('Key can only contain letters, numbers, underscores, dashes, or colons');
                    break;
                }
            }

            //check if key is unique            
            if (this.UserTreeUsedKeys.indexOf(this.local_value)!==-1 && this.local_value!=this.value){
                this.validation_errors.push('Key already exists');
            }

            return this.validation_errors.length==0;
        }
    },
    template: `
            <div class="vue-key-field">

              <div><label for="key">{{$t("key")}}:</label></div>

                <v-text-field
                    id="key"
                    placeholder="Key"
                    v-model="local_value"
                    @blur="UpdateKeyValue"
                    outlined
                    dense
                    class="mb-2"
                ></v-text-field>
                <div class="text-secondary font-small" style="font-size:small">{{this.value}}</div>

                <div class="text-secondary font-small" style="margin-bottom:15px;font-size:small">                    
                    <div v-for="error in validation_errors" class="text-danger">{{error}}</div>
                </div>  

            </div>          
            `    
});

