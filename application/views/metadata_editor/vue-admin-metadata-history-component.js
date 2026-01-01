Vue.component('admin-metadata-history', {
    props: [],
    data() {
        return {
            is_loading: false,
            history: [],
            deep: 0
        }
    },
    mounted:function(){      
        this.$nextTick(() => {
            this.loadEditHistory();
        });
    },
    watch: {
        '$route'(to, from) {
            this.loadEditHistory();
        }
    },
    methods: {
        loadEditHistory: async function()
        {
            vm=this;            
            vm.is_loading=true;
            
            const projectId = this.ProjectID;
            const templateUid = this.MetadataTemplateUID;
            
            if (projectId === null || projectId === undefined || projectId === '' || 
                templateUid === null || templateUid === undefined || templateUid === '') {
                vm.is_loading=false;
                vm.history = [];
                return;
            }
            
            let url=CI.base_url + '/api/admin-metadata/edit_history/'+projectId+'/'+templateUid;
            
            try {
                let resp = await axios.get(url);
                
                // API returns {status: 'success', data: [...]}
                if (resp.data && resp.data.status === 'success') {
                    // Ensure data is an array
                    if (Array.isArray(resp.data.data)) {
                        vm.history = resp.data.data;                    
                    } else {
                        alert('Error: Unexpected response format');
                    }
                } else {
                    vm.history = [];
                }
            } catch (error) {
                console.error('Error loading admin metadata history:', error);
                vm.history = [];
            } finally {
                vm.is_loading=false;
            }
        },
        momentDate(date) {
            return moment(date).format("YYYY/MM/DD hh:mm A");
        },
                
    },
    computed: {    
        ProjectID(){
            return this.$store.state.project_id;
        },
        MetadataTemplateUID(){
            // Get template UID from route params (type_id)
            return this.$route.params.type_id;
        }
        
    },
    template: `
        <div class="vue-admin-metadata-history-component m-3 mt-5 ">
            <div class="bg-light p-3 mb-3">
                <h3>{{$t('Change log')}} - Admin Metadata</h3>
                <div v-if="MetadataTemplateUID">
                    <small>Template UID: {{MetadataTemplateUID}}</small>
                </div>
            </div>

            <div v-if="is_loading" class="text-center">
                <v-progress-circular
                    indeterminate
                    color="primary"
                ></v-progress-circular>
                <div class="mt-2">Loading history...</div>
            </div>


            <div v-if="!is_loading">
                <v-simple-table v-if="history && history.length>0">
                    <template v-slot:default>
                        <thead>
                            <tr>
                                <th class="text-left" style="width:200px">Date</th>    
                                <th class="text-left" style="width:100px">User</th>
                                <th class="text-left"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="revision in history">
                                <td>{{momentDate(revision.created)}}</td>
                                <td>{{revision.username}}</td>
                                <td>
                                    <div style="max-height:500px;overflow:auto">                                                
                                        <vue-json-pretty :data="revision.metadata" :deep="deep" />
                                    </div>                                            
                                </td>
                            </tr>
                        </tbody>
                    </template>
                </v-simple-table>
                <v-alert v-else outlined type="info" class="mt-3">
                    <v-icon left>mdi-information</v-icon>
                    {{$t("no_revisions_found")}}. History will appear here after you make changes to this admin metadata.
                </v-alert>
            </div>
            

        </div>
    `
});

