/// view treeview component
Vue.component('nada-treeview', {
    props:['value','initially_open','tree_active_items','cut_fields'],
    data: function () {    
        return {
            template: this.value,
            initiallyOpen:[],
            //tree_active_items:[],
            files: {
              html: 'mdi-language-html5',
              js: 'mdi-nodejs',
              json: 'mdi-code-json',
              md: 'mdi-language-markdown',
              pdf: 'mdi-file-pdf',
              png: 'mdi-file-image',
              txt: 'mdi-file-document-outline',
              xls: 'mdi-file-excel',
            }
        }
    },
    created: function(){
      this.initiallyOpen=this.initially_open;
    },
    watch:{
      initiallyOpen: function(val) {
        this.$emit('initially-open',this.initiallyOpen);
      }
    },
    computed: {
        TreeActiveItems: {
          get: function() {
            return this.tree_active_items;
          },
          set: function(newValue) {
            //todo
          }          
        },
        Items(){
            // Normalize tree items to include props from array/nested_array as children
            return this.normalizeTreeItems(this.value);
        },
        ActiveNode: {
          get: function() {
            return this.$store.state.active_node;
          },
          set: function(newValue) {
            this.$store.state.active_node = newValue;
          }
        },
        UserTreeItems() {
          return this.$store.state.user_tree_items;
        }
    },
    methods:{
      // Normalize tree items: convert props to items for array/nested_array nodes
      normalizeTreeItems: function(items, parentArray = null) {
        if (!items || !Array.isArray(items)) {
          return [];
        }
        
        return items.map(item => {
          // Keep a reference to the real node so edits stay reactive
          let normalizedItem = { ...item, _originalItem: item._originalItem || item };
          
          // If item has items, normalize them recursively first
          if (item.items && Array.isArray(item.items)) {
            normalizedItem.items = this.normalizeTreeItems(item.items, parentArray);
          }
          
          // If item is array or nested_array and has props, convert props to items
          if ((item.type === 'array' || item.type === 'nested_array') && item.props && Array.isArray(item.props) && item.props.length > 0) {
            // Convert props to items format
            const propsAsItems = item.props.map((prop, index) => {
              // Generate prop_key if it doesn't exist (format: parentKey.propKey)
              let propKey = prop.prop_key;
              if (!propKey && item.key && prop.key) {
                propKey = `${item.key}.${prop.key}`;
              } else if (!propKey && prop.key) {
                propKey = prop.key;
              } else if (!propKey) {
                propKey = item.key ? `${item.key}.prop${index}` : `prop${index}`;
              }
              
              // Ensure prop.key exists for treeview
              const propKeyForTree = prop.key || propKey.split('.').pop() || `prop${index}`;
              
              // Normalize prop structure to match item structure
              // IMPORTANT: Keep reference to original prop object for deletion to work
                const normalizedProp = Object.assign({}, prop, {
                // Always set key for treeview (use the last part of prop_key or prop.key)
                key: propKeyForTree,
                // Ensure prop_key is set
                prop_key: propKey,
                // Mark as prop for identification
                isProp: true,
                // Store original parent reference (the array node) - use original item, not normalized
                  parentArray: item._originalItem || item,
                // Store reference to original prop for deletion/selection
                _originalProp: prop._originalProp || prop,
                // Also keep generic original pointer for non-prop paths
                _originalItem: prop._originalItem || prop,
                // Recursively normalize nested props (for nested_array within props)
                items: prop.props && Array.isArray(prop.props) && prop.props.length > 0 
                  ? this.normalizeTreeItems(prop.props.map((p, pIdx) => {
                      // Generate nested prop_key
                      let nestedPropKey = p.prop_key;
                      if (!nestedPropKey && propKey && p.key) {
                        nestedPropKey = `${propKey}.${p.key}`;
                      } else if (!nestedPropKey && p.key) {
                        nestedPropKey = p.key;
                      } else if (!nestedPropKey) {
                        nestedPropKey = `${propKey}.prop${pIdx}`;
                      }
                      return { 
                        ...p, 
                        key: p.key || nestedPropKey.split('.').pop() || `prop${pIdx}`,
                        prop_key: nestedPropKey,
                        _originalProp: p._originalProp || p,
                        _originalItem: p._originalItem || p,
                        parentArray: item._originalItem || item,
                        isProp: true
                      };
                    }), item) 
                  : undefined
              });
              
              // Remove undefined or empty items
              if (!normalizedProp.items || normalizedProp.items.length === 0) {
                delete normalizedProp.items;
              }
              
              return normalizedProp;
            });
            
            // Merge props with existing items if any
            if (normalizedItem.items && normalizedItem.items.length > 0) {
              normalizedItem.items = [...normalizedItem.items, ...propsAsItems];
            } else {
              normalizedItem.items = propsAsItems;
            }
          }
          
          return normalizedItem;
        });
      },
      treeClick: function (node){
        // Handle both regular nodes and prop nodes
        const nodeKey = node.key || node.prop_key;
        if (nodeKey) {
          this.initiallyOpen.push(nodeKey);
        }
        
        // If this is a prop node (has isProp flag AND parentArray), we need the original prop reference
        // Note: Just having prop_key doesn't make it a prop - it must be inside an array's props array
        if (node.isProp && node.parentArray && node.parentArray.props) {
          // Prefer the stored original reference
          let actualProp = node._originalProp || node._originalItem;

          if (!actualProp) {
            // Fallback: locate prop in parent props
            actualProp = node.parentArray.props.find(p => {
              if (!p) return false;
              const propKey = p.prop_key || p.key;
              const nodePropKey = node.prop_key || node.key;
              return propKey === nodeKey || propKey === nodePropKey || p === node._originalProp || p === node._originalItem;
            });
          }

          if (actualProp) {
            actualProp.isProp = true;
            store.commit('activeNode', actualProp);
            return;
          }
        }

        // For regular nodes (including those with prop_key but not actually props), commit the original reference
        const nodeToCommit = node._originalItem || node._originalProp || node;
        store.commit('activeNode', nodeToCommit);        
      },
      onTreeOpen: function (node){
      },      
      getNodePath: function(arr,name)
      {
          if (!arr){
            return false;
          }

          for(let item of arr){
              const itemKey = item.key || item.prop_key;
              if (!itemKey) continue;
              
              if(itemKey === name) return `/${itemKey}`;
              if(item.items) {
                  const child = this.getNodePath(item.items, name);
                  if(child) return `/${itemKey}${child}`
              }
          }
          return false;
      },
      getNodeContainerKey: function(tree,node_key)
      {
        if (!node_key) return null;
        let el_path = this.getNodePath(tree, node_key);
        if (!el_path || typeof el_path !== 'string') {
          return null;
        }
        const parts = el_path.split("/");
        return parts.length > 1 ? parts[1] : null;
      },
      //check if an item is selected for cut/paste        
      isItemCut: function(item)
      {
        const itemKey = item.key || item.prop_key;
        if (!itemKey) return false;
        
        let active_container_key = this.getNodeContainerKey(this.UserTreeItems, itemKey);
        if (!active_container_key) return false;

        for(i=0;i<this.cut_fields.length;i++){
          if (active_container_key==this.cut_fields[i].container){
             const cutNodeKey = this.cut_fields[i].node.key || this.cut_fields[i].node.prop_key;
             if (itemKey==cutNodeKey){
              return true;
             }
          }
        }
        return false;
      },
      isItemAdditional: function(item){
        // highlight custom/additional fields
        const itemKey = item.key || item.prop_key;
        const hasPrefix = itemKey ? itemKey.startsWith('additional.') : false;
        const flagged = !!(item.is_additional || (item._originalItem && item._originalItem.is_additional));
        return hasPrefix || flagged;
      },
      getItemClasses: function(item){
        let classes=[];
        if (this.isItemCut(item)){
          classes.push('iscut');
        }
        if (this.isItemAdditional(item)){
          classes.push('additional-item');
        }
        // Add class for prop items to distinguish them visually
        if (item.isProp){
          classes.push('prop-item');
        }
        return classes;
      },
      getItemKey: function(item){
        // Handle both key and prop_key
        return item.key || item.prop_key || '';
      }
    },
    template: `
            <div class="nada-treeview-component">
            <template>            
              <v-treeview                   
                  color="warning"
                  :open.sync="initiallyOpen" 
                  :active.sync="TreeActiveItems"
                  @update:open="onTreeOpen" 
                  :items="Items" 
                  activatable dense 
                  item-key="key" 
                  item-text="title"  
                  expand-icon="mdi-chevron-down"
                  indeterminate-icon="mdi-bookmark-minus"
                  on-icon="mdi-bookmark"
                  off-icon="mdi-bookmark-outline"
                  item-children="items"                  
              >

                <template #label="{ item }" >
                    <span @click="treeClick(item)" :title="item.title" class="tree-item-label" :class="getItemClasses(item)" >
                        <span v-if="item.type=='resource'" >{{item.title | truncate(23, '...') }}</span>
                        <span v-else>{{item.title}} <template v-if="item.title==''">Untitled</template></span>
                        <span v-if="isItemCut(item)">*</span>                        
                    </span>
                </template>

                <template v-slot:prepend="{ item, open }" >
                  <v-icon v-if="item.type=='section_container'" :class="{'additional-item': isItemAdditional(item)}">
                    {{ open ? 'mdi-dresser' : 'mdi-dresser' }}
                  </v-icon> 
                  <v-icon v-else-if="item.type=='section'" :class="{'additional-item': isItemAdditional(item)}">
                    {{ open ? 'mdi-folder-open' : 'mdi-folder' }}
                  </v-icon>
                  
                  <v-icon v-else-if="item.type=='nested_array'" :class="{'additional-item': isItemAdditional(item)}" >
                    {{ open ? 'mdi-file-tree-outline' : 'mdi-file-tree' }}
                  </v-icon> 
                  <v-icon v-else-if="item.type=='array'" :class="{'additional-item': isItemAdditional(item)}" >
                    {{ open ? 'mdi-folder-table-outline' : 'mdi-folder-table' }}
                  </v-icon> 

                  <v-icon v-else-if="item.isProp && (item.type=='nested_array' || item.type=='array')" :class="{'additional-item': isItemAdditional(item)}">
                    {{ open ? 'mdi-file-tree-outline' : 'mdi-file-tree' }}
                  </v-icon>
                  
                  <v-icon v-else-if="item.isProp" :class="{'additional-item': isItemAdditional(item)}">
                    mdi-file-document-outline
                  </v-icon>

                  <v-icon v-else :class="{'additional-item': isItemAdditional(item)}">
                    mdi-note-text-outline
                  </v-icon>
                </template>
              </v-treeview>
            </template>

            </div>          
            `    
});

