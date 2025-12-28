<?php 
if (!isset($data) || empty($data) || !is_array($data)){
    return;
}
/**
 * 
 * nested repeatd field
 *
 *  options
 * 
 *  - hide_column_headings - hide column headings 
 */

 $columns=$template['props'];
 $name=$template['title'];
 $hide_field_title=false;
 $hide_column_headings=false;
?>


<div id="<?php echo str_replace(".","_",$template['key']);?>" class="mb-3 field-accordion">
  <h4 class="field-title"><?php echo t($template['title']);?></h4>
  <?php 
    // Filter out empty rows first
    $non_empty_rows = array();
    foreach($data as $idx=>$row):
      if (!is_array($row)) continue;
      $has_content = false;
      foreach($row as $val) {
        if (!empty($val)) {
          $has_content = true;
          break;
        }
      }
      if ($has_content) {
        $non_empty_rows[$idx] = $row;
      }
    endforeach;
  ?>
  <?php foreach($non_empty_rows as $idx=>$row):?>
  <div class="card">
    <div class="card-header-x card-heading bg-light border-bottom" id="heading-<?php echo str_replace(".","_",$template['key'].$idx);?>">
      <h5 class="mb-0">
        <button class="btn btn-sm btn-link" data-toggle="collapse" data-target="#collapse-<?php echo str_replace(".","_",$template['key'].$idx);?>" aria-expanded="true" aria-controls="collapseOne">
          <span class="mdi mdi-chevron-down-circle"></span> 

          <?php if (isset($template['display_options']['header_fields'])):?>
            <?php foreach($template['display_options']['header_fields'] as $header_field):?>
              <?php echo isset($row[$header_field]) ? html_escape($row[$header_field]) : '';?>
            <?php endforeach;?>
          <?php else:?>
            <?php echo html_escape($template['title']);?>
          <?php endif;?>          
        </button>
      </h5>
    </div>

    <div id="collapse-<?php echo str_replace(".","_",$template['key'].$idx);?>" class="collapse " aria-labelledby="headingOne" data-parent="#<?php echo str_replace(".","_",$template['key']);?>">
      <div class="card-body">
          <?php foreach($columns as $column):?>        
            <div>
                <?php if (in_array($column['type'],array('array','nested_array','simple_array', 'section'))):?>
                    <?php 
                        $column['hide_column_headings']=false;
                        $column['hide_field_title']=false;
                        $display_field=isset($template['display_field']) ? $template['display_field'] : '';
                        $item_data = isset($row[$column['key']]) ? $row[$column['key']] : null;

                        if (empty($item_data)){
                            continue;
                        }
                    ?>
                    <?php  echo $this->load->view('project_preview/fields/field_'.$column['type'],array('data'=>$item_data ,'template'=>$column),true);?>
                <?php else:?>
                    <?php if(isset($row[$column['key']])):?>
                    <div class="mb-3">
                      <div class="font-weight-bold field-label"><?php echo html_escape($column['title']);?></div>
                      <div><?php echo isset($row[$column['key']]) ? html_escape($row[$column['key']]) : '';?></div>
                    </div>
                    <?php endif;?>    
                <?php endif;?>
            </div>
            <?php endforeach;?>        
      </div>
    </div>
  </div>
  <?php endforeach;?>
  
</div>