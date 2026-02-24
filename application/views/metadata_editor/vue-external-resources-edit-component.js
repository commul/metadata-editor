//external resources
const VueExternalResourcesEdit= Vue.component('external-resources-edit', {
    props: ['index'],
    data() {
        return {
            file:null,
            uploadedFileName:'',
            errors:[],
            is_dirty:false,
            is_saving:false,
            is_uploading:false,
            attachment_type:'',
            attachment_url:'',
            resource_template:'',
            resource_template_custom_fields:[ "filename" ],
            file_exists:null,
            file_info:null,
            upload_file_exists:false,
            dc_types:{                
                "doc/adm":"Document, Administrative [doc/adm]",
                "doc/anl":"Document, Analytical [doc/anl]",
                "doc/oth":"Document, Other [doc/oth]",
                "doc/qst":"Document, Questionnaire [doc/qst]",
                "doc/ref":"Document, Reference [doc/ref]",
                "doc/rep":"Document, Report [doc/rep]",
                "doc/tec":"Document, Technical [doc/tec]",
                "aud":"Audio [aud]",
                "dat":"Database [dat]",
                "map":"Map [map]",
                "dat/micro":"Microdata File [dat/micro]",
                "pic":"Photo [pic]",
                "prg":"Program [prg]",
                "tbl":"Table [tbl]",
                "vid":"Video [vid]",
                "web":"Web Site [web]"
            }
        }
    },
    mounted: function(){
        this.loadResourceTemplate();
    }, 
    watch: {
        Resource: {
            handler: function (val, oldVal) {
                if (!oldVal){
                    this.checkExistingResourceFile();
                    return;
                }
                this.is_dirty=true;
                this.errors='';
            },
            deep: true,
            immediate: true
        },
        attachment_url: function(val){
            this.is_dirty=true;
            this.errors='';
        },
        file: function(val){
            this.is_dirty=true;
            this.errors='';
        }
    },
    beforeRouteLeave(to, from, next) {
        if (!this.showUnsavedMessage()){
            return false;
        }
        next();
    },
    beforeRouteUpdate(to, from, next) {
        if (!this.showUnsavedMessage()){
            return false;
        }
        next();
    },
    methods: {
        localValue: function(key)
        {
            console.log("searching for local value path",key,this.Resource, _.get(this.Resource,key));
            //remove 'variable_groups.' from key
            //key=key.replace('variable_groups.','');
            return _.get(this.Resource,key);
        },
        showUnsavedMessage: function(){
            if (this.is_dirty){
                // Also warn if file is selected but not uploaded (only for file attachment type)
                if (this.attachment_type=='file' && this.file && this.file instanceof File && !this.uploadedFileName){
                    if (!confirm(this.$t("confirm_unsaved_changes_file_not_uploaded") || "You have unsaved changes and a file selected but not uploaded. Are you sure you want to leave?")){
                        return false;
                    }
                } else {
                    if (!confirm(this.$t("confirm_unsaved_changes"))){
                        return false;
                    }
                }
            } else if (this.attachment_type=='file' && this.file && this.file instanceof File && !this.uploadedFileName){
                // File selected but not uploaded, and no other changes
                if (!confirm(this.$t("confirm_file_not_uploaded") || "You have selected a file but not uploaded it. Are you sure you want to leave?")){
                    return false;
                }
            }
            return true;
        },
        getResourceByID: function(){
            this.ExternalResources.forEach((resource, index) => {                
                if (resource.id==this.ActiveResourceIndex){
                    console.log(":resource",resource, this.ActiveResourceIndex);
                    return this.ExternalResources[index];
                }
            });
        },
        loadResourceTemplate: function(){
            vm=this;
            let url=CI.base_url + '/api/templates/default/resource';

            axios.get( url
            ).then(function(response){
                vm.resource_template=response.data.result;
            })
            .catch(function(response){
                console.log("loadResourceTemplate",response);
                alert(vm.$t("failed_to_load_template"));
            });
        },
        saveResource: function()
        {
            this.errors='';
            let formData = new FormData();

            if (this.attachment_type=='url'){
                this.Resource.filename=this.attachment_url;
            }else if (this.attachment_type=='file'){
                // Validate that file was actually uploaded before saving
                // Check that file is actually a File object, not just a truthy value
                if (this.file && this.file instanceof File && !this.uploadedFileName){
                    this.errors = this.$t("file_must_be_uploaded_before_saving");
                    this.is_saving = false;
                    alert(this.$t("Please upload the file first") || "Please upload the file first. Click the 'Upload' button in the file upload component.");
                    return;
                }
                // Use uploaded filename (or existing filename if no new file uploaded)
                this.Resource.filename = this.uploadedFileName || (this.Resource.filename || '');
            } else {
                // No attachment type selected or URL attachment - allow saving without file
                this.Resource.filename = this.attachment_type == 'url' ? this.attachment_url : (this.Resource.filename || '');
            }

            formData=this.Resource;

            if (this.errors!=''){
                return false;
            }            

            vm=this;
            let url=CI.base_url + '/api/resources/'+ this.ProjectID + '/' + this.Resource['id'];

            axios.post( url,
                formData,
                {
                    headers: {
                        'Content-Type': 'multipart/form-data'
                    }
                }
            ).then(function(response){
                vm.$store.dispatch('loadExternalResources',{dataset_id:vm.ProjectID});
                vm.is_dirty=false;
                vm.is_saving=false;
                vm.is_uploading=false;
                router.push('/external-resources/');
            })
            .catch(function(response){
                vm.errors=response;
                vm.is_saving=false;
                vm.is_uploading=false;
            });    
        },
        cancelSave: function(){
            this.$store.dispatch('loadExternalResources',{dataset_id:this.ProjectID});
            router.push('/external-resources/');
        },
        uploadFile: function ()
        {
            this.is_saving=true;
            this.errors='';
            
            // If file attachment type and file is selected but not uploaded yet, prevent saving
            // Check that file is actually a File object, not just a truthy value
            if (this.attachment_type=='file' && this.file && this.file instanceof File && !this.uploadedFileName){
                this.is_saving=false;
                alert(this.$t("Please upload the file first") || "Please upload the file first. Click the 'Upload' button in the file upload component.");
                return;
            }
            
            // Save resource (works for URL attachments, no attachment, or already uploaded files)
            this.saveResource();
        },
        handleFileUploadComplete: function(event){
            // Called when resumable upload completes
            this.uploadedFileName = event.filename;
            this.Resource.filename = event.filename;
            this.file = null; // Clear file reference since it's now uploaded
            this.is_uploading = false;
            this.is_dirty = true;
            // Recheck file existence after upload
            this.checkExistingResourceFile();
            
            // Don't automatically save - let user click Save button manually
        },
        handleFileUploadError: function(event){
            // Called when upload fails
            this.is_uploading = false;
            this.errors = event;
            this.is_saving = false;
            alert(this.$t("failed_to_upload_file") + ": " + (event.message || this.$t("unknown_error")));
        },
        handleFileUploadProgress: function(event){
            // Called during upload progress
            this.is_uploading = true;
            // Optionally show progress to user
        },
        handleFileSelect: function(event){
            // Called when file is selected (before upload)
            // event contains: { file, filename, size }
            const selectedFile = event.file || event;
            
            // Only set file if it's actually a File object
            if (selectedFile && selectedFile instanceof File) {
                this.file = selectedFile;
                this.uploadedFileName = '';
                this.errors = '';
                // Automatically set attachment_type to 'file' when file is selected
                this.attachment_type = 'file';
                this.resourceFileExists();
            } else {
                // Clear file if invalid or null
                this.handleFileCleared();
            }
        },
        handleFileCleared: function(){
            // Called when file is cleared/cancelled in the upload component
            this.file = null;
            this.uploadedFileName = '';
            this.errors = '';
            // Reset attachment_type if it was set to 'file'
            if (this.attachment_type == 'file') {
                this.attachment_type = '';
            }
            // Don't clear Resource.filename in edit mode - keep existing filename
            this.upload_file_exists = false;
        },
        isValidUrl: function(string) {
            let url;
            
            try {
              url = new URL(string);
            } catch (_) {
              return false;  
            }
          
            return url.protocol === "http:" || url.protocol === "https:";
        },
        resourceFileExists: function()
        {
            const fileName = this.file ? (this.file instanceof File ? this.file.name : this.file) : (this.uploadedFileName || '');
            
            if (!fileName){
                this.upload_file_exists = false;
                return false;
            }

            formData= new FormData();
            formData.append('file_name', fileName);
            formData.append('doc_type', 'documentation');

            vm=this;
            let url=CI.base_url + '/api/files/exists/'+ this.ProjectID;

            axios.post( url,
                formData,
                {
                    headers: {
                        'Content-Type': 'multipart/form-data'
                    }
                }
            ).then(function(response){
                vm.upload_file_exists = response.data.exists ? true : false;
            })
            .catch(function(response){
                console.log("resourceFileExists",response);
                vm.upload_file_exists = false;
            });    
        },
        resourceDeleteFile: function()
        {
            if (!confirm(this.$t("confirm_delete_file_resource"))){
                return false;
            }

            vm=this;
            let formData= new FormData();
            let url=CI.base_url + '/api/files/delete_resource_file/'+ this.ProjectID + '/' + this.Resource['id'];

            axios.post( url,
                formData,
                {
                    headers: {
                        'Content-Type': 'multipart/form-data'
                    }
                }
            ).then(function(response){
                vm.Resource.filename='';
                vm.file_exists=null;
                vm.file_info=null;
                vm.file = null;
                vm.uploadedFileName = '';
                vm.upload_file_exists = false;
                vm.attachment_type = '';
                if (vm.$refs.fileUpload) {
                    vm.$refs.fileUpload.clearFile();
                }
            })
            .catch(function(response){
                console.log("resourceFileDeleted",response);
            });    
        },
        checkExistingResourceFile: function()
        {
            // Only check for file attachments, not URLs
            if (!this.Resource || !this.Resource.filename || this.isValidUrl(this.Resource.filename)) {
                this.file_exists=null;
                this.file_info=null;
                return;
            }

            vm=this;
            let url=CI.base_url + '/api/resources/file/'+ this.ProjectID + '/' + this.Resource['id'];

            axios.get(url)
            .then(function(response){
                if (response.data.status=='success'){
                    vm.file_info=response.data.file_info;
                    vm.file_exists=response.data.file_info.exists;
                }
            })
            .catch(function(response){
                console.log("checkExistingResourceFile",response);
                vm.file_exists=false;
                vm.file_info=null;
            });    
        },
        findTemplateByItemKey: function (items,key){
            let item=null;
            let found=false;
            let i=0;

            while(!found && i<items.length){
                console.log("searching", items[i].key, key);
                if (items[i].key==key){
                    item=items[i];
                    found=true;
                }else{
                    if (items[i].items){
                        item=this.findTemplateByItemKey(items[i].items,key);
                        if (item){
                            found=true;
                        }
                    }
                }
                i++;                        
            }
            return item;
        },
        updateSection: function (obj)
        {            
            if (obj.key.indexOf(".") !== -1 && this.Resource[obj.key]){
                delete this.Resource[obj.key];
            }
            Vue.set(this.Resource,obj.key,obj.value);
        },
        formatFileSize: function(bytes) {
            if (!bytes || bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
        },
    },
    computed: {
        isProjectEditable(){
            return this.$store.getters.getUserHasEditAccess;
        },
        ExternalResources(){
          return this.$store.state.external_resources;
        },
        ActiveResourceIndex(){
            return this.$route.params.index;
        },
        Resource(){
            return this.$store.state.external_resources.find(resource => {
                return resource.id == this.ActiveResourceIndex
            });
        },        
        ResourceAttachmentType()
        {
            if (this.isValidUrl(this.Resource.filename)){
                return 'url';
            }

            return 'file';
        },
        ProjectID(){
            return this.$store.state.project_id;
        },
        ResourceFileExists(){
            return this.resourceFileExists();
        },
        ResourceTemplate(){
            let key='resource_container';                
            //let items=this.$store.state.formTemplate.template.items;
            let items=[]
            if (this.resource_template && this.resource_template.template && this.resource_template.template.items){
                items= this.resource_template.template.items;
            }
            
            let item=this.findTemplateByItemKey(items,key);
            return item;        
        },

    },
    template: `
        <div class="container-fluid edit-resource-container">

            <section style="display: flex; flex-flow: column;height: calc(100vh - 140px);" v-if="Resource">

            <v-card class="mt-4 mb-2">                    
                    <v-card-title class="d-flex justify-space-between">
                        <div style="font-weight:normal">{{$t("edit_resource")}}</div>

                        <div>
                            <v-btn 
                                color="primary" 
                                small 
                                @click="uploadFile" 
                                :disabled="!isProjectEditable || is_saving || is_uploading || (attachment_type=='file' && file && file instanceof File && !uploadedFileName)"
                                :loading="is_saving || is_uploading">
                                {{$t("Save")}} <span v-if="is_dirty || (attachment_type=='file' && file && file instanceof File && !uploadedFileName)">*</span>
                            </v-btn>
                            <v-btn @click="cancelSave" small :disabled="is_saving">{{$t("cancel")}}</v-btn>
                        </div>
                    </v-card-title>

                    <v-card-text v-if="errors && errors.response">
                        <v-alert type="error" v-if="errors.response.data && errors.response.data.errors">
                            <div v-if="typeof errors.response.data.errors === 'object'">
                                <div v-for="(error, key) in errors.response.data.errors" :key="key">
                                    {{ error }}
                                </div>
                            </div>
                            <div v-else>{{errors.response.data.errors}}</div>
                        </v-alert>
                        <v-alert type="error" v-else-if="errors.response.data && errors.response.data.message">
                            {{errors.response.data.message}}
                        </v-alert>
                        <v-alert type="error" v-else>{{errors.response}}</v-alert>
                    </v-card-text>
                </v-card>


            <v-card style="flex: 1;overflow:auto;">
            <v-card-text class="mb-5" v-if="ResourceTemplate && ResourceTemplate.items">

            <div  v-for="(column,idx_col) in ResourceTemplate.items" scope="row" :key="column.key"  >
            
                <template v-if="column.type=='section'">
                
                    <form-section
                        :parentElement="Resource"
                        :value="localValue(column.key)"
                        :columns="column.items"
                        :title="column.title"
                        :path="column.key"
                        :field="column"                            
                        @sectionUpdate="updateSection($event)"
                    ></form-section>  
                    
                </template>
                <template v-else>
                                          {{column.key}}      
                    <form-input
                        :value="localValue(column.key)"
                        :field="column"
                        @input="update(column.key, $event)"
                    ></form-input>                              
                    
                </template>
            </div>
            
            

            <v-card class="mt-2">
                <v-card-title class="d-flex justify-space-between">
                    <div style="font-weight:normal">{{$t("resource_attachment")}}</div>
                </v-card-title>

            <v-card-text>
            <div>                
                <div class="bg-light border p-2 text-small" style="font-size:12px;">
                    <!-- File status indicator icon before filename -->
                    <v-icon 
                        v-if="ResourceAttachmentType=='file' && Resource.filename && file_exists===true" 
                        small 
                        color="success" 
                        :title="$t('file_exists')"
                        style="margin-right:4px;">
                        mdi-check-circle
                    </v-icon>
                    <v-icon 
                        v-if="ResourceAttachmentType=='file' && Resource.filename && file_exists===false" 
                        small 
                        color="error" 
                        :title="$t('file_not_found_on_server')"
                        style="margin-right:4px;">
                        mdi-alert-circle
                    </v-icon>
                    
                    <!-- Filename with color based on file status -->
                    <span :style="ResourceAttachmentType=='file' && Resource.filename && file_exists===true ? 'color: green;' : (ResourceAttachmentType=='file' && Resource.filename && file_exists===false ? 'color: red;' : '')">
                        {{Resource.filename}}
                    </span>
                    
                    <span v-if="Resource.filename">
                        <i class="mdi mdi-check-circle text-success" title="File attached"></i>
                        <button type="button" class="btn btn-link btn-sm" @click="resourceDeleteFile">{{$t("remove")}}</button>
                    </span>
                    <span v-else class="text-muted">{{$t("no_file_attached")}}</span>

                    <!-- File info when exists -->
                    <div v-if="ResourceAttachmentType=='file' && Resource.filename && file_exists===true && file_info" 
                         class="small text-muted mt-1">
                        <span v-if="file_info.size">{{formatFileSize(file_info.size)}}</span>
                        <span v-if="file_info.size && file_info.modified_date"> • </span>
                        <span v-if="file_info.modified_date">{{file_info.modified_date}}</span>
                    </div>
                    
                    <div v-if="attachment_type=='file' && file && file instanceof File && !uploadedFileName" class="border bg-info text-dark p-2 m-2">
                        <strong>{{file.name}}</strong> {{$t("file_selected_but_not_uploaded") || "File selected but not uploaded. Click 'Upload' button to upload the file."}}
                    </div>
                </div>

                <div class="form-check mt-2" >
                    <input class="form-check-input" type="radio" name="gridRadios" id="gridRadios1" value="file" v-model="attachment_type" >
                    <label class="form-check-label" for="gridRadios1">
                    {{$t("upload_file")}}
                    </label>
                </div>

                <div class="file-group form-field m-1 p-3 border-bottom">
                    <div class="bg-white">
                    
                        <resumable-file-upload
                            ref="fileUpload"
                            :project-id="ProjectID"
                            file-type="documentation"
                            :disabled="!isProjectEditable || is_saving"
                            @file-selected="handleFileSelect"
                            @file-cleared="handleFileCleared"
                            @upload-complete="handleFileUploadComplete"
                            @upload-error="handleFileUploadError"
                            @upload-progress="handleFileUploadProgress"
                        ></resumable-file-upload>
                        
                    </div>     
                </div>

                <div class="form-check">
                    <input class="form-check-input" type="radio" name="gridRadios" id="gridRadios2" value="url" v-model="attachment_type">
                    <label class="form-check-label" for="gridRadios2">
                    {{$t("url")}}
                    </label>
                </div>

                <div class="form-group form-field  m-1 p-3 ">
                    <span><input type="text" id="url" class="form-control" v-model="attachment_url" @click="attachment_type='url'"/></span> 
                </div>

            </div>
            </v-card-text>
            </v-card>

            

            </v-card-text>

            </v-card>
            
        </section>
        </div>
    `
});


