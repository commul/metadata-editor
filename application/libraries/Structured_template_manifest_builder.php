<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Build a metadata-editor template payload directly from the compact schema
 * manifest. This keeps the schema as the source of truth while allowing
 * package-defined grouping that the stock schema template generator cannot infer.
 */
class Structured_template_manifest_builder
{
    public function build($manifest)
    {
        if (!is_array($manifest)) {
            throw new Exception('Template manifest must be an array.');
        }

        if (empty($manifest['title'])) {
            throw new Exception('Template manifest is missing title.');
        }

        if (empty($manifest['sections']) || !is_array($manifest['sections'])) {
            throw new Exception('Template manifest must define at least one section.');
        }

        $items = array();

        foreach ($manifest['sections'] as $section) {
            if (empty($section['key']) || empty($section['title'])) {
                throw new Exception('Each template section requires key and title.');
            }

            $pages = $this->build_pages($section);
            if (empty($pages)) {
                throw new Exception('Each template section requires fields or pages.');
            }

            $items[] = array(
                'type' => 'section_container',
                'key' => $section['key'],
                'title' => $section['title'],
                'help_text' => isset($section['description']) ? $section['description'] : '',
                'expanded' => true,
                'items' => $pages
            );
        }

        return array(
            'type' => 'template',
            'title' => $manifest['title'],
            'description' => isset($manifest['description']) ? $manifest['description'] : '',
            'items' => $items
        );
    }

    protected function build_pages($section)
    {
        $pages = array();

        if (!empty($section['pages']) && is_array($section['pages'])) {
            foreach ($section['pages'] as $page) {
                if (empty($page['key']) || empty($page['title']) || empty($page['fields']) || !is_array($page['fields'])) {
                    throw new Exception('Each template page requires key, title, and fields.');
                }

                $pages[] = $this->build_page($section, $page);
            }

            return $pages;
        }

        if (!empty($section['fields']) && is_array($section['fields'])) {
            $pages[] = $this->build_page($section, array(
                'key' => 'section',
                'title' => isset($section['page_title']) && $section['page_title'] !== ''
                    ? $section['page_title']
                    : 'Overview',
                'description' => isset($section['description']) ? $section['description'] : '',
                'fields' => $section['fields']
            ));
        }

        return $pages;
    }

    protected function build_page($section, $page)
    {
        $field_items = array();
        foreach ($page['fields'] as $field) {
            $field_items[] = $this->build_field($section, $field);
        }

        return array(
            'type' => 'section',
            'key' => $section['key'] . '.' . $page['key'],
            'title' => $page['title'],
            'help_text' => isset($page['description']) && $page['description'] !== ''
                ? $page['description']
                : (isset($section['description']) ? $section['description'] : ''),
            'expanded' => true,
            'items' => $field_items
        );
    }

    protected function build_field($section, $field)
    {
        if (empty($field['key'])) {
            throw new Exception('Template field key is required.');
        }

        $schema = isset($field['schema']) && is_array($field['schema']) ? $field['schema'] : array();
        $template = isset($field['template']) && is_array($field['template']) ? $field['template'] : array();

        $type = isset($field['type']) && $field['type'] !== '' ? $field['type'] : 'string';
        $repeatable = !empty($field['repeatable']);
        $display_type = $this->resolve_display_type($type, $schema, $template, $repeatable);
        $template_field = array(
            'key' => $section['key'] . '.' . $field['key'],
            'title' => isset($field['title']) ? $field['title'] : $field['key'],
            'type' => $repeatable ? 'simple_array' : $type,
            'required' => !empty($field['required']),
            'is_required' => !empty($field['required']),
            'help_text' => isset($field['description']) ? $field['description'] : '',
            'display_type' => $display_type
        );

        $enum = $this->build_enum($schema, $template);
        if (!empty($enum)) {
            $template_field['enum'] = $enum;
            $template_field['enum_store_column'] = 'code';
        }

        if (!empty($template['content_format'])) {
            $template_field['content_format'] = $template['content_format'];
        }

        return $template_field;
    }

    protected function resolve_display_type($type, $schema, $template, $repeatable)
    {
        if (!empty($template['display_type'])) {
            return $template['display_type'];
        }

        if (!empty($schema['enum']) && is_array($schema['enum'])) {
            return 'dropdown';
        }

        if (!$repeatable && !empty($schema['format']) && in_array($schema['format'], array('date', 'date-time'), true)) {
            return 'date';
        }

        if ($type === 'integer' || $type === 'number') {
            return 'number';
        }

        return 'text';
    }

    protected function build_enum($schema, $template)
    {
        if (empty($schema['enum']) || !is_array($schema['enum'])) {
            return array();
        }

        $labels = array();
        if (!empty($template['enum_labels']) && is_array($template['enum_labels'])) {
            $labels = $template['enum_labels'];
        }

        $enum = array();
        foreach ($schema['enum'] as $value) {
            $enum[] = array(
                'code' => $value,
                'label' => isset($labels[(string)$value]) ? $labels[(string)$value] : (string)$value
            );
        }

        return $enum;
    }
}
