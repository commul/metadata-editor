/// view treeview component
Vue.component('nada-treeview-field', {
    props:['value'],
    data: function () {    
        return {
            template: this.value,
            initiallyOpen:[],
            files: {
              html: 'mdi-language-html5',
              js: 'mdi-nodejs',
              json: 'mdi-code-json',
              md: 'mdi-language-markdown',
              pdf: 'mdi-file-pdf',
              png: 'mdi-file-image',
              txt: 'mdi-file-document-outline',
              xls: 'mdi-file-excel',
            },
            selected_item:{},
            activeNode:{},
            switchShowAll:false
        }
    },
    mounted: function(){
      //this.initiallyOpen.push(this.Items[0].key);
      window._items=this.Items;
    },
    
    computed: {
        Items(){
          if (!this.TemplateActiveNode) {
            return [];
          }
          
          const activeNode = this.TemplateActiveNode;
          const nodeKey = activeNode.key || activeNode.prop_key;
          
          if (!nodeKey) {
            return [];
          }
          
          // Handle different node types
          if (activeNode.type === 'section') {
            // For section: use current logic - find parent section_container
            const parent = this.findNodeParent(this.UserTemplate, nodeKey);
            if (parent && parent.key && this.coreTemplateParts[parent.key]) {
              return this.coreTemplateParts[parent.key].items || [];
            }
            return [];
          }
          
          // For array or nested_array: lookup from core template
          if (activeNode.type === 'array' || activeNode.type === 'nested_array') {
            // Get the core template definition for this array/nested_array
            const coreArrayDef = this.coreTemplateParts[nodeKey];
            
            if (!coreArrayDef) {
              return [];
            }
            
            // For nested_array root: flatten all fields (show all fields directly, ignore sections)
            // Also include sections themselves so users can add sections if needed
            if (activeNode.type === 'nested_array') {
              const availableItems = [];
              
              // Helper function to recursively flatten items and extract all fields
              const flattenItems = (items) => {
                if (!items || !Array.isArray(items)) return;
                
                items.forEach(item => {
                  // If it's a section, always expand it to show its fields
                  if (item.type === 'section' || item.type === 'section_container') {
                    // Sections can have either items or props (for sections inside nested_array)
                    // Always expand sections to show their children/fields
                    if (item.items && Array.isArray(item.items) && item.items.length > 0) {
                      // Section has items - recursively flatten them
                      flattenItems(item.items);
                    }
                    // Also check for props (sections inside nested_array often have props)
                    if (item.props && Array.isArray(item.props) && item.props.length > 0) {
                      // Convert props to items format and add them
                      item.props.forEach(prop => {
                        availableItems.push({
                          key: prop.prop_key || prop.key,
                          title: prop.title || prop.key,
                          type: prop.type,
                          help_text: prop.help_text,
                          // Mark as prop for identification
                          isProp: true,
                          originalProp: prop,
                          // Mark that it came from a section
                          _fromSection: item.key,
                          _sectionTitle: item.title
                        });
                      });
                    }
                    // Also add the section itself so users can create sections if needed
                    availableItems.push(item);
                  } else {
                    // It's a field - add it directly
                    availableItems.push({
                      ...item
                    });
                  }
                });
              };
              
              // Process props from core template
              // For nested_array, props can include both regular props and sections
              if (coreArrayDef.props && Array.isArray(coreArrayDef.props)) {
                coreArrayDef.props.forEach(prop => {
                  // Check if this prop is actually a section
                  if (prop.type === 'section' || prop.type === 'section_container') {
                    // It's a section in props - expand it to show its fields
                    if (prop.props && Array.isArray(prop.props) && prop.props.length > 0) {
                      // Convert section's props to items format
                      prop.props.forEach(sectionProp => {
                        availableItems.push({
                          key: sectionProp.prop_key || sectionProp.key,
                          title: sectionProp.title || sectionProp.key,
                          type: sectionProp.type,
                          help_text: sectionProp.help_text,
                          isProp: true,
                          originalProp: sectionProp,
                          _fromSection: prop.key,
                          _sectionTitle: prop.title
                        });
                      });
                    }
                    // Also add the section itself
                    availableItems.push(prop);
                  } else {
                    // Regular prop - add it directly
                    availableItems.push({
                      key: prop.prop_key || prop.key,
                      title: prop.title || prop.key,
                      type: prop.type,
                      help_text: prop.help_text,
                      isProp: true,
                      originalProp: prop
                    });
                  }
                });
              }
              
              // Flatten all items from core template (sections and their children)
              if (coreArrayDef.items && Array.isArray(coreArrayDef.items)) {
                flattenItems(coreArrayDef.items);
              }
              
              return availableItems;
            }
            
            // For regular array: return props from core template
            if (coreArrayDef.props && Array.isArray(coreArrayDef.props)) {
              // Convert props to items format for display
              return coreArrayDef.props.map(prop => {
                return {
                  key: prop.prop_key || prop.key,
                  title: prop.title || prop.key,
                  type: prop.type,
                  help_text: prop.help_text,
                  isProp: true,
                  originalProp: prop
                };
              });
            }
            
            return [];
          }
          
          return [];
        },
        filteredItems()
        {
          if (this.switchShowAll){
            return this.Items;
          }

          return this.filterUnused(this.Items);          
        },
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
    },
    methods:{
       filterUnused: function(node)
       {
         let vm=this;
         if (!node || !Array.isArray(node)) {
           return [];
         }
         
         return node.reduce((acc,obj)=>{
           // Handle items with children
           if (obj.items && Array.isArray(obj.items)){
            const result = {...obj, items: this.filterUnused(obj.items)};

            if (result.items && result.items.length > 0){
              return [...acc, result];
            } else {
              return acc;
            }
           }
           // Handle props (marked with isProp)
           else if (obj.isProp) {
             // For props, check if prop_key or key is in use
             const propKey = obj.prop_key || obj.key;
             if (!vm.isItemInUse(propKey)) {
               return [...acc, obj];
             } else {
               return acc;
             }
           }
           // Handle regular items
           else if (!vm.isItemInUse(obj.key)){
               return [...acc, obj];
           }
           else{
             return acc;
           }
       
         },[]);
       },

      findNodeParent: function(tree,node_key)
          {
            found='';
            for(var i=0;i<tree.items.length;i++){
              let item=tree.items[i];
                if (item.key && item.key==node_key){
                    found=tree;
                    return tree;
                }

                if (item.items){
                  result=this.findNodeParent(item,node_key);
                  if (result!=''){
                    return result;
                  }
                }
            }
            return found;
          },
      isItemInUse: function(item_key){
        if (!item_key) return false;
        
        const activeNode = this.TemplateActiveNode;
        
        // For nested_array, check within the active nested_array's context first
        if (activeNode && activeNode.type === 'nested_array') {
          // Check if item is in use within this specific nested_array instance
          const checkInNestedArray = (items) => {
            if (!items || !Array.isArray(items)) return false;
            
            for (let item of items) {
              // Check if this item's key matches
              if (item.key === item_key) {
                return true;
              }
              
              // Check items recursively (including sections and their children)
              if (item.items && Array.isArray(item.items)) {
                if (checkInNestedArray(item.items)) {
                  return true;
                }
              }
            }
            return false;
          };
          
          // Check in the active nested_array's items
          if (activeNode.items && checkInNestedArray(activeNode.items)) {
            return true;
          }
          
          // Check in the active nested_array's props
          if (activeNode.props && Array.isArray(activeNode.props)) {
            for (let prop of activeNode.props) {
              const pKey = prop.prop_key || prop.key;
              if (pKey === item_key) {
                return true;
              }
            }
          }
        }
        
        // Check the actual tree structure directly (most reliable - always reflects current state)
        const checkInTree = (items) => {
          if (!items || !Array.isArray(items)) return false;
          
          for (let item of items) {
            // Check if this item's key matches
            if (item.key === item_key) {
              return true;
            }
            
            // Check props in array/nested_array
            if ((item.type === 'array' || item.type === 'nested_array') && item.props && Array.isArray(item.props)) {
              for (let prop of item.props) {
                const pKey = prop.prop_key || prop.key;
                if (pKey === item_key) {
                  return true;
                }
                // Check nested props recursively
                if (prop.props && Array.isArray(prop.props)) {
                  for (let nestedProp of prop.props) {
                    const nestedKey = nestedProp.prop_key || nestedProp.key;
                    if (nestedKey === item_key) {
                      return true;
                    }
                    // Recursively check deeper nested props
                    if (nestedProp.props && Array.isArray(nestedProp.props)) {
                      if (checkInTree(nestedProp.props.map(p => ({ key: p.prop_key || p.key })))) {
                        return true;
                      }
                    }
                  }
                }
              }
            }
            
            // Check items recursively (including sections and their children)
            if (item.items && Array.isArray(item.items)) {
              if (checkInTree(item.items)) {
                return true;
              }
            }
          }
          
          return false;
        };
        
        // Always check the actual tree structure (most reliable)
        return checkInTree(this.UserTreeItems);
      },
      // Check if item is in use in sections other than the specified one
      isItemInUseInOtherSections: function(item_key, excludeSectionKey) {
        if (!item_key) return false;
        
        const checkInTree = (items, excludeKey) => {
          if (!items || !Array.isArray(items)) return false;
          
          for (let item of items) {
            // Skip the section we're adding to
            if (item.key === excludeKey) {
              continue;
            }
            
            // Check if this item's key matches
            if (item.key === item_key) {
              return true;
            }
            
            // Check props in array/nested_array
            if ((item.type === 'array' || item.type === 'nested_array') && item.props && Array.isArray(item.props)) {
              for (let prop of item.props) {
                const pKey = prop.prop_key || prop.key;
                if (pKey === item_key) {
                  return true;
                }
                // Check nested props recursively
                if (prop.props && Array.isArray(prop.props)) {
                  for (let nestedProp of prop.props) {
                    const nestedKey = nestedProp.prop_key || nestedProp.key;
                    if (nestedKey === item_key) {
                      return true;
                    }
                    // Recursively check deeper nested props
                    if (nestedProp.props && Array.isArray(nestedProp.props)) {
                      if (checkInTree(nestedProp.props.map(p => ({ key: p.prop_key || p.key })), excludeKey)) {
                        return true;
                      }
                    }
                  }
                }
              }
            }
            
            // Check items recursively
            if (item.items && Array.isArray(item.items)) {
              if (checkInTree(item.items, excludeKey)) {
                return true;
              }
            }
          }
          
          return false;
        };
        
        return checkInTree(this.UserTreeItems, excludeSectionKey);
      },
      isItemContainer: function(item){
        if (item.type=='section' || item.type=='section_container' || item.type=='nested_array_'){
          return true;
        }
        return false;
      },
      // Check if a section is in use within a specific nested_array instance
      isSectionInUseInNestedArray: function(sectionKey, nestedArrayNode) {
        if (!sectionKey || !nestedArrayNode || nestedArrayNode.type !== 'nested_array') {
          return false;
        }
        
        // Check if the section exists in this nested_array's items
        if (nestedArrayNode.items && Array.isArray(nestedArrayNode.items)) {
          for (let item of nestedArrayNode.items) {
            if (item.key === sectionKey) {
              return true; // Section is in use in this nested_array
            }
          }
        }
        
        return false; // Section is not in use in this nested_array
      },
      handleAddItemClick: function(item, event) {
        if (event) {
          event.stopPropagation();
          event.preventDefault();
        }
        this.addItem(item, event);
      },
      addItem: function (item, event){
        // Prevent event propagation if event is provided
        if (event) {
          event.stopPropagation();
          event.preventDefault();
        }
        
        const activeNode = this.TemplateActiveNode;
        
        if (!activeNode) {
          alert('No active node selected. Please select a section first.');
          return false;
        }

        if (!item || !item.key) {
          return false;
        }

        // Handle different node types
        if (activeNode.type === 'section') {          
          // For section: only check if key already exists in THIS section's items
          const keyExists = this.checkNodeKeyExists(activeNode, item.key);
          
          if (keyExists === true) {
            return false;
          }
          
          this.selected_item = item;
          if (!activeNode.items) {
            this.$set(activeNode, "items", []);
          }
          
          // Clone the item to avoid reference issues
          const itemToAdd = JSON.parse(JSON.stringify(item));
          // Remove any temporary properties that might have been added
          delete itemToAdd.isProp;
          delete itemToAdd.originalProp;
                    
          // Find the actual node in UserTreeItems to ensure we're modifying the right reference
          const findNodeInTree = (items, targetKey) => {
            if (!items || !Array.isArray(items)) return null;
            for (let item of items) {
              if (item.key === targetKey) {
                return item;
              }
              if (item.items && Array.isArray(item.items)) {
                const found = findNodeInTree(item.items, targetKey);
                if (found) return found;
              }
            }
            return null;
          };
          
          const nodeKey = activeNode.key;
          const actualNode = findNodeInTree(this.UserTreeItems, nodeKey) || activeNode;
                    
          // Ensure items array exists
          if (!actualNode.items) {
            this.$set(actualNode, "items", []);
          }
          
          // Use Vue.set to add the item at the new index to ensure reactivity
          const newIndex = actualNode.items.length;
          this.$set(actualNode.items, newIndex, itemToAdd);
          
          // Also update activeNode if it's different
          if (actualNode !== activeNode && activeNode.items) {
            activeNode.items = actualNode.items;
          }
          
          // Force update to ensure UI reflects changes
          this.$nextTick(() => {
            this.$forceUpdate();
          });
          
          return true;
        } else if (activeNode.type === 'array' || activeNode.type === 'nested_array') {
          // For nested_array: check if item is a section (should go to items) or field (should go to items) or prop (should go to props)
          if (activeNode.type === 'nested_array') {
            // For nested_array: sections and regular fields go to items array
            if (item.type === 'section' || item.type === 'section_container') {
              // Section: add to items array
              if (this.checkNodeKeyExists(activeNode, item.key) == true) {
                return false;
              }

              this.selected_item = item;
              if (!activeNode.items) {
                this.$set(activeNode, "items", []);
              }
              
              // Clone the item to avoid reference issues
              const itemToAdd = JSON.parse(JSON.stringify(item));
              // Remove temporary properties
              delete itemToAdd.isProp;
              delete itemToAdd.originalProp;
              
              activeNode.items.push(itemToAdd);
            } else if (item.isProp) {
              // This is a prop (from props array) - add to props
              if (!activeNode.props) {
                this.$set(activeNode, "props", []);
              }
              
              // Check if prop already exists
              const propExists = activeNode.props.some(p => {
                const pKey = p.prop_key || p.key;
                return pKey === (item.prop_key || item.key);
              });
              
              if (propExists) {
                return false;
              }
              
              // Clone the original prop
              let newProp;
              if (item.originalProp) {
                newProp = JSON.parse(JSON.stringify(item.originalProp));
                if (!newProp.prop_key && activeNode.key && newProp.key) {
                  newProp.prop_key = `${activeNode.key}.${newProp.key}`;
                }
              } else {
                newProp = {
                  key: item.key,
                  prop_key: activeNode.key ? `${activeNode.key}.${item.key}` : item.key,
                  title: item.title || item.key,
                  type: item.type || 'string',
                  help_text: item.help_text || ''
                };
              }
              
              this.selected_item = newProp;
              activeNode.props.push(newProp);
            } else {
              // Regular field (not a prop, not a section) - add directly to items array
              if (this.checkNodeKeyExists(activeNode, item.key) == true) {
                return false;
              }

              this.selected_item = item;
              if (!activeNode.items) {
                this.$set(activeNode, "items", []);
              }
              
              // Clone the item and remove temporary properties
              const itemToAdd = JSON.parse(JSON.stringify(item));
              delete itemToAdd.isProp;
              delete itemToAdd.originalProp;
              
              activeNode.items.push(itemToAdd);
            }
          } else {
            // For array or nested_array props: add to props array
            if (!activeNode.props) {
              this.$set(activeNode, "props", []);
            }
            
            // Check if prop already exists
            const propExists = activeNode.props.some(p => {
              const pKey = p.prop_key || p.key;
              return pKey === item.key;
            });
            
            if (propExists) {
              return false;
            }
            
            // If item has originalProp, use it; otherwise create new prop
            let newProp;
            if (item.originalProp) {
              // Clone the original prop
              newProp = JSON.parse(JSON.stringify(item.originalProp));
              // Ensure prop_key is set
              if (!newProp.prop_key && activeNode.key && newProp.key) {
                newProp.prop_key = `${activeNode.key}.${newProp.key}`;
              }
            } else {
              // Create new prop from item
              newProp = {
                key: item.key,
                prop_key: activeNode.key ? `${activeNode.key}.${item.key}` : item.key,
                title: item.title || item.key,
                type: item.type || 'string',
                help_text: item.help_text || ''
              };
            }
            
            this.selected_item = newProp;
            activeNode.props.push(newProp);
          }
        }
      },
      checkNodeKeyExists: function(node,key)
       {
         if (!node || !key) return false;
         
         // For sections, only check items array (not props)
         if (node.type === 'section') {
           if (node.items && Array.isArray(node.items)) {
             return node.items.some(item => {
               return item && item.key === key;
             });
           }
           return false;
         }
         
         // For array/nested_array, check both items and props
         // Check in items array
         if (node.items && Array.isArray(node.items)) {
           let exists = node.items.some(item => {
             return item && item.key === key;
           });
           if (exists) return true;
         }
         
         // Check in props array (for array/nested_array)
         if (node.props && Array.isArray(node.props)) {
           let exists = node.props.some(prop => {
             if (!prop) return false;
             const pKey = prop.prop_key || prop.key;
             return pKey === key;
           });
           if (exists) return true;
         }
         
         return false;
       },
      treeClick: function (node)
      {
        this.activeNode=node;
        this.initiallyOpen.push(node.key);

        if (this.isItemInUse(node.key)){
          store.commit('activeCoreNode',{});
        }else{
          store.commit('activeCoreNode',node);
        }        
      },
    },
    template: `
            <div class="nada-treeview-component">

            <v-container fluid class="p-1 pt-2">
            
            <v-switch
              v-model="switchShowAll"
              :label="$t('show_all_elements')"
            ></v-switch>
            

            <v-row>
              <v-col cols="12" md="6" style="height: 100vh; overflow: auto;">

                <div class="p-3 border m-3 text-center" v-if="filteredItems.length==0">{{$t("no_items_available")}}</div>

                <v-treeview                   
                    color="warning"
                    open-all
                    :open.sync="initiallyOpen" 
                    :items="filteredItems" 
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
                      <span @click.stop="treeClick(item)" :title="item.title" >
                          <span v-if="isItemInUse(item.key) && !isItemContainer(item)" style="color:gray;">{{item.title}}</span>
                          <span v-else>{{item.title}}</span>
                      </span>
                  </template>

                  <template v-slot:prepend="{ item, open }">
                    <div style="display: flex; align-items: center; gap: 4px;">
                      <!-- Add button for non-container items -->
                      <span 
                        v-if="!isItemContainer(item)" 
                        @click.stop="handleAddItemClick(item, $event)"
                        @mousedown.stop
                        @mouseup.stop
                        style="cursor: pointer; display: inline-flex; align-items: center; padding: 2px; position: relative; z-index: 100;"
                        :title="!isItemInUse(item.key) ? 'Add item' : 'Item already in use'"
                      >
                        <v-icon 
                          small 
                          :color="!isItemInUse(item.key) ? '#007bff' : 'grey'"
                        >
                          {{ !isItemInUse(item.key) ? 'mdi-plus-box' : 'mdi-checkbox-marked' }}
                        </v-icon>
                      </span>
                      
                      <!-- Item type icon -->
                      <v-icon v-if="isItemContainer(item)">
                        {{ open ? 'mdi-folder-open' : 'mdi-folder' }}
                      </v-icon>
                      <v-icon v-else-if="item.isProp">
                        mdi-table-large
                      </v-icon>
                      <v-icon v-else-if="item.file">
                        {{ files[item.file] }}
                      </v-icon>
                      <v-icon v-else>
                        mdi-note-text-outline
                      </v-icon>
                    </div>
                  </template>

                </v-treeview>
              </v-col>
              <v-col cols="12" md="6" style="height: 100vh; overflow: auto;">
                <div v-if="activeNode.key" class="p-3">
                
                  <div><strong>{{$t("description")}}</strong></div>
                  <v-simple-table dense>
                    <tbody>
                    <tr>
                      <td><strong>{{$t("field")}}</strong></td>
                      <td>{{activeNode.key}}</td>
                    </tr>
                    <tr>
                      <td><strong>{{$t("type")}}</strong></td>
                      <td>{{activeNode.type}}</td>
                    </tr>
                    <tr>
                      <td><strong>{{$t("title")}}</strong></td>
                      <td>{{activeNode.title}}</td>
                    </tr>
                    <tr>
                      <td><strong>{{$t("description")}}</strong></td>
                      <td><div style="white-space: pre-wrap;">{{activeNode.help_text}}</div></td>
                    </tr>                    
                    </tbody>
                  </v-simple-table>

                  <div v-if="activeNode.props" >
                      <strong>{{$t("array_properties")}}</strong>
                      
                          <v-simple-table dense>
                            <thead>
                            <tr>
                              <th>{{$t("key")}}</th>
                              <th>{{$t("title")}}</th>
                              <th>{{$t("type")}}</th>
                              <th>{{$t("description")}}</th>
                            </tr>
                          </thead>
                          <tbody>
                          <tr v-for="(prop, idx) in activeNode.props" :key="idx">
                            <td>{{prop.key}}</td>
                            <td>{{prop.title}}</td>
                            <td>{{prop.type}}</td>
                            <td><div style="white-space: pre-wrap;">{{prop.help_text}}</div></td>
                          </tr>
                          </tbody>
                          </v-simple-table>
                    </div>
                
                </div>
                <div v-else class="p-3 border m-3 text-center">
                  <template v-if="switchShowAll==true">{{$t("click_to_edit")}}</template>
                </div>
              </v-col>
            </v-row>
            </v-container>
            </div>          
            `    
});

