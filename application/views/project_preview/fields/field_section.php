<?php
if (isset($data) && empty($data)){
    return;
}

    /**
 * Section field view
 * 
 * Renders a section with title and nested items
 */
?>

<?php 
$item_prop_key = isset($template['prop_key']) ? $template['prop_key'] : $template['key'];
$item_key= isset($template['key']) ? $template['key'] : '';

$item_types=array(
    'number'=>'text',
    'string'=>'text',
    'text'=>'text',
    'date'=>'text',
    'array'=>'array',
    'nested_array'=>'nested_array',
    'simple_array'=>'simple_array',
    'section'=>'section'
);

?>

<div id="<?php echo html_escape($item_prop_key); ?>" class="field-section-wrapper">
    <h4 class="field-section mt-3"><?php echo html_escape($template['title']); ?></h4>
    <?php if (isset($template['props']) && is_array($template['props']) && count($template['props']) > 0): ?>
        <?php foreach($template['props'] as $item): ?>
            <?php 
                $key = isset($item['key']) ? $item['key'] : '';

                //remove parent key from nested data key
                $key=str_replace($item_key.'.','',$key);
                $nested_data = isset($data[$key]) ? $data[$key] : null;

            ?>
            <div class="section-item">
                <?php echo $this->load->view('project_preview/fields/field_'.$item_types[$item['type']], array('data' => $nested_data, 'template' => $item), true); ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

