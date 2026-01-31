//file manager
Vue.component('file-manager', {
    props: ['index', 'id'],
    data() {
        return {
            files: [],
            errors: '',
            selectedFolderPath: null,  // null = "All files", else folder path (e.g. 'data', 'documentation', '.')
            selectedFiles: [],         // array of file path strings "dir_path/name" for batch actions
        }
    }, 
    mounted () {
        this.loadFiles();
    },   
    methods: {
        buildFolderTree(paths) {
            const normalized = paths.filter(p => p !== undefined && p !== null).map(p => (p === '.' || p === '') ? '.' : p);
            const unique = [...new Set(normalized)];
            const pathToNode = {};
            const root = { name: this.$t('Project root') || 'Project root', path: '.', children: [] };
            pathToNode['.'] = root;
            const ensurePath = (path) => {
                if (pathToNode[path]) return pathToNode[path];
                const p = path === '.' ? '' : path;
                const parts = p.split('/').filter(Boolean);
                if (parts.length === 0) return root;
                const parentPath = parts.length === 1 ? '.' : parts.slice(0, -1).join('/');
                const parent = ensurePath(parentPath);
                const node = { name: parts[parts.length - 1], path: path === '.' ? '.' : path, children: [] };
                pathToNode[path] = node;
                parent.children.push(node);
                return node;
            };
            unique.sort().forEach(p => ensurePath(p || '.'));
            root.children.sort((a, b) => (a.name || '').localeCompare(b.name || ''));
            return root;
        },
        flattenFolderTree(node, indent) {
            if (!node) return [];
            indent = indent || 0;
            const list = [];
            if (node.path !== '.' || indent === 0) {
                list.push({ path: node.path, name: node.name, indent });
            }
            (node.children || []).sort((a, b) => (a.name || '').localeCompare(b.name || '')).forEach(c => {
                list.push(...this.flattenFolderTree(c, indent + 1));
            });
            return list;
        },
        selectFolder(path) {
            this.selectedFolderPath = path;
        },
        downloadFileUrl(file) {
            const path = (file.dir_path || '') + '/' + file.name;
            return CI.base_url + '/api/files/download/' + this.ProjectID + '?file=' + encodeURIComponent(path);
        },
        fileKey(file) {
            return (file.dir_path || '') + '/' + file.name;
        },
        isSelected(file) {
            return this.selectedFiles.indexOf(this.fileKey(file)) !== -1;
        },
        toggleSelect(file) {
            const key = this.fileKey(file);
            const i = this.selectedFiles.indexOf(key);
            if (i === -1) this.selectedFiles.push(key);
            else this.selectedFiles.splice(i, 1);
        },
        toggleSelectAll() {
            if (this.isAllSelected) {
                this.selectedFiles = [];
            } else {
                this.selectedFiles = this.filesToShow.map(f => this.fileKey(f));
            }
        },
        batchDelete() {
            const n = this.selectedFiles.length;
            if (!n || !confirm(this.$t('confirm_delete') + ' ' + n + ' ' + this.$t('files') + '?')) return;
            const vm = this;
            const url = CI.base_url + '/api/files/delete/' + this.ProjectID;
            const toDelete = this.filesToShow.filter(f => vm.selectedFiles.indexOf(vm.fileKey(f)) !== -1);
            let done = 0;
            const next = function() {
                if (done >= toDelete.length) {
                    vm.selectedFiles = [];
                    vm.loadFiles();
                    return;
                }
                const formData = new FormData();
                formData.append('file', toDelete[done].dir_path + '/' + toDelete[done].name);
                axios.post(url, formData)
                    .then(function() { done++; next(); })
                    .catch(function(err) { vm.errors = err; });
            };
            next();
        },      
        momentDate(date) {
            return moment.utc(date).format("YYYY-MM-DD HH:mm:ss");
        },  
        addFile:function(){
            alert("TODO");
            return;
        },
        deleteFile: function(file){
            if (!confirm(this.$t("confirm_delete") + ' ' + file.name)){
                return;
            }

            vm=this;
            let url=CI.base_url + '/api/files/delete/'+ this.ProjectID;
            let formData=new FormData();
            formData.append('file', file.dir_path+'/'+file.name);

            axios.post( url, formData,
            ).then(function(response){
                vm.loadFiles();
            })
            .catch(function(response){
                vm.errors=response;
            });
        },
        importGeospatialMetadata: function(file){
            vm=this;
            let url=CI.base_url + '/api/geospatial/extract_metadata/'+ this.ProjectID;
            let formData=new FormData();
            formData.append('file_path', encodeURIComponent(file.dir_path+'/'+file.name));

            axios.post( url, formData,
            ).then(function(response){
                vm.loadFiles();
            })
            .catch(function(response){
                vm.errors=response;
            });
        },
        loadFiles: function(){
                vm=this;
                let url=CI.base_url + '/api/files/'+ this.ProjectID;
    
                axios.get(url)
                .then(function(response){
                    vm.files=response.data.files;                    
                })
                .catch(function(response){
                    vm.errors=response;
                });
        },
        getFileType: function(filename){
            let parts=filename.split('.');
            let ext=parts[parts.length-1];
            return ext;
        },
        isZip: function(filename){
            let ext=this.getFileType(filename);
            return ext=='zip';
        },
        isData: function(filepath){
            let parts=filepath.split('/');
            //if first part is data, then it is a data file
            return parts[0]=='data';
        },

        colorByFolderType: function(dir_path){
            let parts=dir_path.split('/');

            if (dir_path=='data/tmp' || dir_path=='.'){
                return 'red';
            }

            if (parts[0]=='data'){
                return 'purple';
            }

            if (parts[0]=='documentation'){
                return 'green';
            }

            return 'black';
        },

        isTypeGeospatial: function(fileName){
            let geospatial_types=['shp','tiff','geotiff','tif'];
            let ext=this.getFileExtension(fileName);
            return geospatial_types.includes(ext);
        },
        getFileExtension: function(fileName){
            let parts=fileName.split('.');
            let ext=parts[parts.length-1];
            return ext;
        },        
        extractZip:function(file)
        {
            if (!confirm(this.$t("confirm_extract"))){
                return;
            }

            vm=this;
            let url=CI.base_url + '/api/files/unzip/'+ this.ProjectID;
            let formData=new FormData();
            formData.append('file_name', encodeURIComponent(file.dir_path+'/'+file.name));

            axios.post( url, formData,
            ).then(function(response){
                vm.loadFiles();
            })
            .catch(function(response){
                vm.errors=response;
            });            
        }        
    },
    computed: {
        ExternalResources()
        {
          return this.$store.state.external_resources;
        },
        ActiveResourceIndex(){
            return this.$route.params.index;
        },
        ProjectID(){
            return this.$store.state.project_id;
        },
        ProjectType(){
            return this.$store.state.project_type;
            },
        uniqueFolderPaths() {
            const paths = this.files.map(f => f.dir_path).filter(Boolean);
            return [...new Set(paths)];
        },
        folderTreeRoot() {
            return this.buildFolderTree(this.uniqueFolderPaths);
        },
        flattenedFolders() {
            const list = [{ path: null, name: this.$t('All files') || 'All files', indent: 0 }];
            list.push(...this.flattenFolderTree(this.folderTreeRoot, 0));
            return list;
        },
        FilesFlatView() {
            return this.files;
        },
        filesToShow() {
            const filesOnly = this.files.filter(f => !f.is_dir);
            if (this.selectedFolderPath === null) return filesOnly;
            return filesOnly.filter(f => f.dir_path === this.selectedFolderPath);
        },
        isAllSelected() {
            if (!this.filesToShow.length) return false;
            return this.filesToShow.every(f => this.isSelected(f));
        },
        isSomeSelected() {
            return this.selectedFiles.length > 0;
        },
    },
    template: `
        <div class="file-manager container-fluid mt-5">

        <v-card>
            <v-card-title>{{$t("file_manager")}}</v-card-title>
            <v-card-text>

                <div v-if="errors" class="mb-3">
                    <pre>{{errors}}</pre>
                </div>

                <v-row no-gutters>
                    <!-- Left sidebar: Files view | Tree view -->
                    <v-col cols="12" md="3" class="pr-md-3">
                        <v-card class="mb-4">
                            <v-card-text class="pt-5 pb-2">
                                <v-list dense class="py-0">
                                    <v-list-item
                                        v-for="item in flattenedFolders"
                                        :key="item.path === null ? '_all_' : item.path"
                                        @click="selectFolder(item.path)"
                                        :class="{ 'primary lighten-4': (selectedFolderPath === item.path) || (item.path === null && selectedFolderPath === null) }"
                                        class="file-manager-folder-item"
                                        :style="{ paddingLeft: (8 + item.indent * 20) + 'px' }"
                                    >
                                        <v-list-item-icon class="mr-2" style="min-width: 24px;">
                                            <v-icon v-if="item.path === null" small>mdi-folder-open</v-icon>
                                            <v-icon v-else small>mdi-folder</v-icon>
                                        </v-list-item-icon>
                                        <v-list-item-content>
                                            <v-list-item-title class="subtitle-2">
                                                {{ item.name.toUpperCase() }}
                                            </v-list-item-title>
                                        </v-list-item-content>
                                    </v-list-item>
                                </v-list>
                            </v-card-text>
                        </v-card>
                    </v-col>

                    <!-- Main content: files table -->
                    <v-col cols="12" md="9">
                        <v-card>
                            <v-card-title class="text-subtitle-1 py-2 d-flex align-center flex-wrap">
                                <span class="mr-2" v-if="selectedFolderPath === null">{{$t('All files')}} ({{ filesToShow.length }})</span>
                                <span class="mr-2" v-else>{{ selectedFolderPath === '.' ? ($t('Project root') || 'Project root') : selectedFolderPath.toUpperCase() }} ({{ filesToShow.length }} {{$t('files')}})</span>
                                <v-btn
                                    v-if="selectedFiles.length > 0"
                                    color="error"
                                    small
                                    outlined
                                    @click="batchDelete"
                                    class="mt-1 mt-sm-0"
                                >
                                    <v-icon left small>mdi-trash-can-outline</v-icon>
                                    {{$t('Delete')}} {{ selectedFiles.length }} {{$t('selected')}}
                                </v-btn>
                            </v-card-title>
                            <v-card-text>

                            <v-simple-table class="elevation-1 border file-manager-table" dense>
                                <template v-slot:default>
                                    <thead>
                                        <tr>
                                            <th style="width:48px;" class="pl-2">
                                                <v-checkbox
                                                    :input-value="isAllSelected"
                                                    :indeterminate="isSomeSelected && !isAllSelected"
                                                    hide-details
                                                    dense
                                                    @change="toggleSelectAll"
                                                ></v-checkbox>
                                            </th>
                                            <th style="width:40px;"></th>
                                            <!--
                                            <th class="text-left">{{$t('Type')}}</th>
                                            -->
                                            <th class="text-left">{{$t('Name')}}</th>
                                            <th class="text-left">{{$t('Size')}}</th>
                                            <th class="text-left">{{$t('Created')}}</th>
                                            <th class="text-left" style="width:100px;"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="(file, index) in filesToShow" :key="(file.dir_path || '') + '/' + file.name" class="resource-row">
                                            <td class="pl-2">
                                                <v-checkbox
                                                    :input-value="isSelected(file)"
                                                    hide-details
                                                    dense
                                                    @change="toggleSelect(file)"
                                                ></v-checkbox>
                                            </td>
                                            <td>
                                                <v-icon :title="file.dir_path" style="font-size:22px;" :color="colorByFolderType(file.dir_path)">mdi-file-document-outline</v-icon>
                                            </td>
                                            <!--
                                            <td>
                                                <v-chip :color="colorByFolderType(file.dir_path)" small outlined class="text-caption text-uppercase">
                                                    <span v-if="file.dir_path=='data/tmp' || file.dir_path=='.'">{{$t('temporary')}}</span>
                                                    <span v-else>{{ file.dir_path }}</span>
                                                </v-chip>
                                            </td>
                                            -->
                                            <td>
                                            {{ file.name }}
                                             </td>
                                            <td>{{ file.size_human }}</td>
                                            <td>{{ momentDate(file.timestamp) }}</td>
                                            <td>
                                                <v-btn icon x-small :href="downloadFileUrl(file)" :title="$t('download')" target="_blank" rel="noopener">
                                                    <v-icon small>mdi-download</v-icon>
                                                </v-btn>
                                                <v-btn icon x-small color="error" @click="deleteFile(file)" :title="$t('Delete')">
                                                    <v-icon small>mdi-trash-can-outline</v-icon>
                                                </v-btn>
                                                <v-btn v-if="ProjectType=='geospatial' && isZip(file.name)" x-small text color="primary" @click="extractZip(file)">
                                                    {{$t('extract_zip')}}
                                                </v-btn>
                                            </td>
                                        </tr>
                                        <tr v-if="filesToShow.length === 0">
                                            <td colspan="7" class="text-center text--secondary py-4">
                                                {{ selectedFolderPath === null ? $t('no_files_found') : $t('No files in this folder') }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </template>
                            </v-simple-table>

                            </v-card-text>
                        </v-card>
                    </v-col>
                </v-row>

                </v-card-text>
            </v-card>
        </div>
    `
})


