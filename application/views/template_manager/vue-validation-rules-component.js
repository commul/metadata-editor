//vue validation-rules component
Vue.component('validation-rules-component', {
    props:['value'],
    data: function () {    
        return {
            rule_selected:'',
            validation_rules:{
                "required":{
                    "rule":"required",
                    "description":"Must have a value",
                    "param":false,
                    "value_type":"regex"
                },
                "regex":{
                    "rule":"regex",
                    "description":"Regular expression - ",
                    "param":true,
                    "value_type":"regex"
                },
                "min":{
                    "rule":"min",
                    "description":"Minimum length of text",
                    "param":true,
                    "value_type":"integer"
                },
                "max":{
                    "rule":"max_length",
                    "description":"Maximum length of text",
                    "param":true,
                    "value_type":"integer"
                },
                "alpha":{
                    "rule":"alpha",
                    "description":"Allow only alphabets",
                    "param":false
                },
                "alpha_num":{
                    "rule":"alpha_num",
                    "description":"Allow only alphabets and numbers",
                    "param":false
                },
                "numeric":{
                    "rule":"numeric",
                    "description":"Allow only numeric values",
                    "param":false
                },
                "is_uri":{
                    "rule":"is_uri",
                    "description":"Must be a valid URL",
                    "param":false
                },



            }
        }
    },
    created: function () {           
    },
    computed: {
        /*local()
        {
            return this.value ? this.value : {};
            if (this.isValidFormat(this.value)){
                return this.value;
            }
            

            return {};
        },*/
        local:{
            get(){                
                if (this.isValidFormat(this.value)){
                    return this.value;
                }
                return {};
            },
            set(val){
                this.$emit('update:value', val);
            }
        },
        ValidationRules()
        {
            return this.validation_rules;            
            /*let filtered_={};
            let keys_=Object.keys(this.validation_rules);
            for(i=0;i<keys_.length;i++)
            {
                if (!this.isRuleInUse(keys_[i])){
                 filtered_[keys_[i]]=this.validation_rules[keys_[i]];
                }
            }
            console.log("filtered",filtered_);
            return filtered_;
            */
        }
    },
    methods:{
        isValidFormat: function(value)
        {            
            if (typeof value=='string' || Array.isArray(value) || !value)
            {
                return false;
            }
            return true;
        },
        update(key, value) {
            this.$emit('input', { ...this.value, [key]: value })
        },
        validateRuleValue: function(idx)
        {
            if (!this.field_data[idx]['rule']){
                return true;
            }

            let rule_key=this.field_data[idx]['rule'];
            let rule=this.validation_rules[rule_key];
            let value=this.field_data[idx]['value']

            if (rule.value_type=='regex'){
                try {
                    new RegExp(value);
                    return true;
                } catch(e) {
                    return false;
                }                
            }
            return true;

        },
        isRuleInUse: function(rule_key){
            for(i=0;i<Object.keys(this.local).length;i++)
            {
                if (this.local["rule"]==rule_key){
                    return true;
                }
            }
            return false;
        },
        ruleHasParam: function(rule){
            if (this.validation_rules[rule] && this.validation_rules[rule].param){
                return this.validation_rules[rule].param==true;
            }

            return false;
        },
        RuleDescription: function(rule){
            if (this.validation_rules[rule] && this.validation_rules[rule].description){
                return this.validation_rules[rule].description;
            }

            return '';
        },
        remove: function (rule_name){
            Vue.delete(this.local, rule_name);
        },
        addRule: function ()
        {
            rule_info=this.validation_rules[this.rule_selected];
            this.local[this.rule_selected]=rule_info.param==true ? '' : true;
            this.rule_selected='';
            this.$emit('update:value', this.local);
        }
        
    },
    template: `
            <div class="validation-rules-component">

            <v-row class="p-2 mb-3" justify="end">
                <v-col cols="12" md="6">
                    <v-row align="center">
                        <v-col cols="auto" class="flex-grow-1">
                            <v-select
                                v-model="rule_selected"
                                :items="Object.keys(ValidationRules).map(key => ({ value: key, text: ValidationRules[key].rule }))"
                                item-text="text"
                                item-value="value"
                                placeholder="Select rule"
                                outlined
                                dense
                                hide-details
                            ></v-select>
                        </v-col>
                        <v-col cols="auto">
                            <v-btn color="primary" @click="addRule" :disabled="rule_selected==''" small>Add</v-btn>
                        </v-col>
                    </v-row>
                </v-col>
            </v-row>

            <v-simple-table>
                <thead>
                <tr>
                    <th>Rule</th>
                    <th>Value</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <tr v-for="(value_, name, index) in local" :key="name">
                    <td>
                        <div class="text-primary">{{name}}</div>
                        <div class="text-secondary" style="font-size:small;margin-top:5px;">{{RuleDescription(name)}}</div>
                    </td>
                    <td>
                        <div v-if="ruleHasParam(name)">
                            <v-text-field
                                :value="local[name]"
                                @input="update(name, $event)"
                                outlined
                                dense
                                hide-details
                                class="mt-2"
                            ></v-text-field>
                        </div>
                        <div v-else>{{local[name]}}</div>
                    </td>
                    <td>        
                        <v-btn icon small color="error" @click="remove(name)" class="float-right">
                            <v-icon>mdi-delete</v-icon>
                        </v-btn>
                    </td>
                </tr>
                </tbody>
            </v-simple-table>

            </div>  `    
});