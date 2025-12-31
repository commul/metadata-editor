<div v-if="!ActiveNode || (!ActiveNode.key && !ActiveNode.prop_key)" class="m-3 p-3">{{$t("click_on_sidebar_to_edit")}}</div>

<!--key editing / display-->
    <div class="mb-3" v-if="ActiveNode && (ActiveNode.key || ActiveNode.prop_key) && !ActiveNodeIsProp">

        <vue-custom-key-field
            :field="ActiveNode" 
            :key="ActiveNode.key"
            :value="ActiveNode.key"
            @input="UpdateActiveNodeKey"
            >
        </vue-custom-key-field>
        <div class="text-secondary font-small mb-2">Recommended to use a namespace for custom fields.</div>
    </div>


<!--item-->
<div v-if="ActiveNode && (ActiveNode.key || ActiveNode.prop_key)">

<!--section container fields - only show for non-prop nodes -->
<div v-if="ActiveNode && !ActiveNodeIsProp" class="mb-3">
    <label class="mb-1 d-block">{{$t('label')}}:</label>
    <v-text-field
        v-model="ActiveNode.title"
        @input="markDirty"
        placeholder="Label"
        outlined
        dense
        hide-details
        :disabled="!user_has_edit_access"
    ></v-text-field>
</div>
<div v-if="ActiveNode && !ActiveNodeIsProp && ActiveNode.key && coreTemplateParts[ActiveNode.key]" class="text-secondary font-small mb-3" style="font-size:small">Original label: {{coreTemplateParts[ActiveNode.key].title}} <span class="pl-3">Name: {{ActiveNode.key}}</span> <span class="pl-3">Type: {{ActiveNode.type}}</span>  </div>

<div v-if="ActiveNode && !ActiveNodeIsProp && (ActiveNode.type=='section' || ActiveNode.type=='section_container' || ActiveNode.type=='nested_array')" class="mb-3">
    <label class="mb-1 d-block">{{$t('type')}}:</label>
    <v-text-field
        v-model="ActiveNode.type"
        disabled
        outlined
        dense
        hide-details
    ></v-text-field>
</div>
<div v-else-if="ActiveNode && !ActiveNodeIsProp" class="mb-3">
    <label class="mb-1 d-block">{{$t('type')}}:</label>
    <v-select
        v-model="ActiveNode.type"
        @change="markDirty"
        :items="field_types"
        outlined
        dense
        hide-details
        :disabled="!user_has_edit_access"
    ></v-select>
</div>

<v-row v-if="ActiveNode && !ActiveNodeIsProp" class="mb-3">
    <v-col cols="auto">
        <v-checkbox
            v-if="ActiveNode.type!=='section' &&  ActiveNode.type!=='section_container'"
            v-model="ActiveNode.is_required"
            @change="markDirty"
            :label="$t('required')"
            hide-details
            :disabled="!user_has_edit_access"
        ></v-checkbox>
    </v-col>

    <v-col cols="auto">
        <v-checkbox
            v-if="ActiveNode.type!=='section' &&  ActiveNode.type!=='section_container'"
            v-model="ActiveNode.is_recommended"
            @change="markDirty"
            :label="$t('recommended')"
            hide-details
            :disabled="!user_has_edit_access"
        ></v-checkbox>
    </v-col>

    <v-col cols="auto">
        <v-checkbox
            v-if="ActiveNode.type!=='section' &&  ActiveNode.type!=='section_container'"
            v-model="ActiveNode.is_private"
            @change="markDirty"
            :label="$t('private')"
            hide-details
            :disabled="!user_has_edit_access"
        ></v-checkbox>
    </v-col>

    <v-col cols="auto">
        <v-checkbox
            v-if="ActiveNode.type!=='section' &&  ActiveNode.type!=='section_container'"
            v-model="ActiveNode.is_readonly"
            @change="markDirty"
            :label="$t('readonly')"
            hide-details
            :disabled="!user_has_edit_access"
        ></v-checkbox>
    </v-col>
</v-row>

<div v-if="ActiveNode && (ActiveNode.key || ActiveNode.prop_key) && !ActiveNodeIsProp" class="mb-3">
    <label class="mb-1 d-block">{{$t('description')}}:</label>
    <v-textarea
        v-model="ActiveNode.help_text"
        @input="markDirty"
        outlined
        rows="8"
        hide-details
        :disabled="!user_has_edit_access"
    ></v-textarea>
</div>
<div v-if="ActiveNode && (ActiveNode.key || ActiveNode.prop_key) && !ActiveNodeIsProp && ActiveNode.key" class="text-secondary p-1 mb-3" style="font-size:small;">
    <div>{{$t("original_description")}}:</div>
    <div v-if="coreTemplatePartsHelpText(coreTemplateParts[ActiveNode.key])">            
        <div style="white-space: pre-wrap;">{{coreTemplatePartsHelpText(coreTemplateParts[ActiveNode.key])}}</div>
    </div>
    <div v-else>{{$t("na")}}</div>
</div>


<!-- Removed props-treeview - all properties are now accessible from main sidebar tree -->

<!-- Show prop editing interface when a prop is selected from main tree -->
<!-- Only show prop-edit if this is actually a prop (inside an array's props array), not just a field with prop_key -->
<div class="mt-2 pb-5" v-if="ActiveNode && ActiveNode.prop_key && ActiveNodeIsProp">
    <!-- Use prop-edit component for individual prop editing -->
    <prop-edit 
        :key="ActiveNode.prop_key" 
        :parent="propParentNode"
        v-model="ActiveNode"
    ></prop-edit>
</div>

<template v-if="ActiveNode && ActiveNode.type!=='section_container' && ActiveNode.type!=='section' && !ActiveNodeIsProp && !ActiveNodeIsInsideNestedArray && ActiveNode.key">
    <v-tabs background-color="transparent" class="mb-5" :key="ActiveNode.key">
        <v-tab v-if="ActiveNode.key && isControlField(ActiveNode.type) == true">{{$t("display")}}</v-tab>
        <v-tab v-if="!ActiveArrayNodeIsNested"><span v-if="ActiveNodeEnumCount>0"><v-icon style="color:green;">mdi-circle-medium</v-icon></span>{{$t("controlled_vocabulary")}}</v-tab>
        <v-tab v-if="!ActiveArrayNodeIsNested || (ActiveNode && isControlField(ActiveNode.type) == true)"><span v-if="ActiveNode && ActiveNode.default"><v-icon style="color:green;">mdi-circle-medium</v-icon></span>{{$t("default")}}</v-tab>
        <v-tab v-if="ActiveNode && isControlField(ActiveNode.type)"><span v-if="ActiveNode && ActiveNode.rules && Object.keys(ActiveNode.rules).length>0"><v-icon style="color:green;">mdi-circle-medium</v-icon></span>{{$t("validation_rules")}}</v-tab>
        <v-tab>{{$t("json")}}</v-tab>

        <v-tab-item class="p-3 tab-display" v-if="ActiveNode.key && isControlField(ActiveNode.type) == true">
            <!--display-->
            <div v-if="ActiveNode.type!='simple_array'" class="mb-3">
                <label class="mb-1 d-block">{{$t('data_type')}}:</label>
                <v-select
                    v-model="ActiveNode.type"
                    @change="markDirty"
                    :items="field_data_types"
                    outlined
                    dense
                    hide-details
                    :disabled="!user_has_edit_access"
                ></v-select>
            </div>

            <div class="mb-3">
                <label class="mb-1 d-block">{{$t('display')}}:</label>
                <v-select
                    v-model="ActiveNode.display_type"
                    @change="markDirty"
                    :items="field_display_types"
                    outlined
                    dense
                    hide-details
                    :disabled="!user_has_edit_access"
                ></v-select>
            </div>

            <div v-if="ActiveNode.display_type=='textarea'" class="mb-3">
                <label class="mb-1 d-block">{{$t("field_content_format")}}:</label>
                <div class="text-secondary font-small mb-2">{{$t("field_content_format_help")}}</div>
                <v-select
                    v-model="ActiveNode.content_format"
                    @change="markDirty"
                    :items="[{ value: '', text: 'None' }, ...Object.keys(field_content_formats).map(key => ({ value: key, text: field_content_formats[key] }))]"
                    item-text="text"
                    item-value="value"
                    outlined
                    dense
                    hide-details
                    :disabled="!user_has_edit_access"
                ></v-select>
            </div>

            <!--end display -->
        </v-tab-item>

        <v-tab-item class="p-3 tab-cv" v-if="!ActiveArrayNodeIsNested">
            <!-- controlled vocab -->
            <template >
            <div class="mb-3" >
                <label for="controlled_vocab">{{$t("controlled_vocabulary")}}:</label>
                <div class="bg-white border " style="max-height:300px;overflow:auto;">


                    <template v-if="!ActiveNodeControlledVocabColumns"> 

                        <div>

                            <div class="m-3">
                                <div>{{$t("enum_store_options_label")}}:</div>

                                <v-select
                                    style="max-width:300px;"
                                    v-model="ActiveNodeEnumStoreColumn"
                                    :items="enum_store_options"
                                    :item-text="item => item.label"
                                    :item-value="item => item.value"
                                    dense 
                                    outlined
                                    clearable
                                    label=""
                                    :disabled="!user_has_edit_access"
                                ></v-select>
                            </div>
                        </div>

                        <table-grid-component
                            v-if="ActiveNode && ActiveNode.key"
                            :key="ActiveNode.key"
                            :columns="ActiveNodeSimpleControlledVocabColumns" 
                            v-model="ActiveNodeEnum"
                            @update:value="EnumUpdate"
                            class="border m-2 pb-2"
                        ></table-grid-component>
                         
                    </template>
                    <template v-else>

                        <table-grid-component
                            v-if="ActiveNode && ActiveNode.key"
                            :key="ActiveNode.key"
                            :columns="ActiveNodeControlledVocabColumns" 
                            v-model="ActiveNodeEnum"
                            @update:value="EnumUpdate"
                            class="border m-2 pb-2"
                        ></table-grid-component>
                        
                    </template>
                </div>

            </div>
            </template>
            <!-- end controlled vocab -->
        </v-tab-item>
        <v-tab-item class="p-3 tab-default" v-if="!ActiveArrayNodeIsNested || (ActiveNode && isControlField(ActiveNode.type) == true)">
            <!-- default -->
            <template >
                <div class="mb-3" >
                    <label for="controlled_vocab">{{$t("default")}}:</label>
                    <div class="bg-white" style="max-height:300px;overflow:auto;" v-if="ActiveNode && ActiveNode.type=='array'">
                        
                        <table-grid-component
                            v-if="ActiveNode && ActiveNode.key"
                            :key="ActiveNode.key"
                            :columns="ActiveNodeControlledVocabColumns" 
                            v-model="ActiveNode.default"                            
                            class="border m-2 pb-2"
                        ></table-grid-component>

                    </div>
                    <div class="bg-white" v-else>
                        
                        <v-textarea
                            v-if="ActiveNode && ActiveNode.type=='textarea'"
                            v-model="ActiveNode.default"
                            outlined
                            rows="8"
                            hide-details
                            class="mt-2"
                            :disabled="!user_has_edit_access"
                        ></v-textarea>
                        <v-select
                            v-else-if="ActiveNode && ActiveNode.type=='boolean'"
                            v-model="ActiveNode.default"
                            :items="['true', 'false']"
                            dense 
                            outlined
                            clearable
                            hide-details
                            class="mt-2"
                            :disabled="!user_has_edit_access"
                        ></v-select>
                        <v-text-field
                            v-else
                            dense 
                            outlined
                            clearable
                            v-model="ActiveNode.default"
                            hide-details
                            class="mt-2"
                            :disabled="!user_has_edit_access"
                        ></v-text-field>
                    </div>
                </div>
            </template>
            <!-- end default -->
        </v-tab-item>
        <v-tab-item class="p-3 tab-rules" v-if="ActiveNode && isControlField(ActiveNode.type)">
            <div class="mb-3" >
                <label for="controlled_vocab">{{$t("validation_rules")}}:</label>
                <div class="bg-white border">
                    <validation-rules-component @update:value="RulesUpdate"  v-model="ActiveNode.rules"  class="m-2 pb-2" />
                </div>
            </div>
        </v-tab-item>

        <v-tab-item class="p-3 tab-json">
            <div class="mb-3" >
                <label for="controlled_vocab">{{$t("json")}}:</label>
                <div class="bg-white border" :style="ActiveNode && ActiveNode.type === 'nested_array' ? 'max-height: 300px; overflow-y: auto;' : ''">
                    <pre>{{ActiveNode}}</pre>
                </div>
            </div>
        </v-tab-item>
    </v-tabs>

</template>


<div class="mb-3 p-2 elevation-2" v-if="ActiveNode && (ActiveNode.type=='section' || ActiveNode.type=='array' || ActiveNode.type=='nested_array')">
    <label for="name">{{$t("available_items")}}:</label>
    <div class="border bg-light">        
    <nada-treeview-field v-model="CoreTreeItems"></nada-treeview-field>
    <?php /* <pre>{{CoreTreeItems}}</pre> */ ?>
    </div>
</div>

<?php /*  [<pre>{{ActiveNode}}</pre>] */ ?>

</div>
<!-- end item -->