///vue component for prop key field
Vue.component('vue-prop-key-field', {
    props:['value', 'parent'],
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
        isKeyValid(){
            // key must be unique
            // key must not contain spaces
            // key can only contain dot, letters, numbers, underscores, dashes, or colons
            // key cannot be empty            
            let key=this.local_value;

            //break key into parts using dot as separator
            let parts=key.split('.');

            //check if key has any empty parts
            if (parts.indexOf('')!==-1){
                return false;
            }

            //check all parts only contain letters, numbers, underscores, dashes, or colons
            for(let i=0;i<parts.length;i++){
                if (parts[i].match(/^[a-zA-Z0-9:_-]+$/)==null){
                    return false;
                }
            }

            //check if key is unique for the current array element
            for(let i=0;i<this.parent.props.length;i++){
                if (this.parent.props[i].key==key){
                    return false;
                }
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
            this.validation_errors=[];
            
            if (!this.ValidateKey()){
                return;
            }
            
            this.$emit('input', this.local_value);

        },
        ValidateKey: function()
        {
            // key must be unique
            // key must not contain spaces
            // key can only contain dot, letters, numbers, underscores, dashes, or colons
            // key cannot be empty

            this.validation_errors=[];

            let key=this.local_value.trim();

            if (key==''){
                this.validation_errors.push('Key cannot be empty');
            }

            //key can only contain letters, numbers, underscores, dashes, or colons
            if (key.match(/^[a-zA-Z0-9:_-]+$/)==null){
                this.validation_errors.push('Key can only contain letters, numbers, underscores, dashes, or colons');
            }
           
            return this.validation_errors.length==0;
        }
    },
    template: `
        <div class="vue-prop-key-field">

            <v-text-field
                id="key"
                placeholder="Key"
                v-model="local_value"
                @blur="UpdateKeyValue"
                outlined
                dense
            ></v-text-field>
                
            <div class="text-secondary font-small" style="margin-top:4px;font-size:small">
                <div v-for="error in validation_errors" class="text-danger">{{error}}</div>
            </div>

        </div>          
            `    
});

