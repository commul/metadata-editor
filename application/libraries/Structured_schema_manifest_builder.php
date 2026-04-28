<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Build a JSON Schema document from a compact manifest definition.
 *
 * The manifest format is intentionally simpler than raw JSON Schema so
 * schema packages can be added to the repository and imported by CLI.
 */
class Structured_schema_manifest_builder
{
    public function build($manifest)
    {
        if (!is_array($manifest)) {
            throw new Exception('Schema manifest must be an array.');
        }

        if (empty($manifest['uid'])) {
            throw new Exception('Schema manifest is missing uid.');
        }

        if (empty($manifest['title'])) {
            throw new Exception('Schema manifest is missing title.');
        }

        if (empty($manifest['sections']) || !is_array($manifest['sections'])) {
            throw new Exception('Schema manifest must define at least one section.');
        }

        $schema = array(
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'title' => $manifest['title'],
            'description' => isset($manifest['description']) ? $manifest['description'] : '',
            'additionalProperties' => false,
            'properties' => array()
        );

        $required_sections = array();

        foreach ($manifest['sections'] as $section) {
            $section_schema = $this->build_section_schema($section);
            $schema['properties'][$section['key']] = $section_schema;

            if (!empty($section_schema['required'])) {
                $required_sections[] = $section['key'];
            }
        }

        if (!empty($required_sections)) {
            $schema['required'] = array_values(array_unique($required_sections));
        }

        return $schema;
    }

    protected function build_section_schema($section)
    {
        if (empty($section['key'])) {
            throw new Exception('Section key is required.');
        }

        if (empty($section['title'])) {
            throw new Exception('Section title is required for section: ' . $section['key']);
        }

        $fields = $this->collect_section_fields($section);
        if (empty($fields)) {
            throw new Exception('Section "' . $section['key'] . '" must contain fields.');
        }

        $properties = array();
        $required = array();

        foreach ($fields as $field) {
            if (empty($field['key'])) {
                throw new Exception('Field key is required in section: ' . $section['key']);
            }

            $properties[$field['key']] = $this->build_field_schema($field);

            if (!empty($field['required'])) {
                $required[] = $field['key'];
            }
        }

        $section_schema = array(
            'type' => 'object',
            'title' => $section['title'],
            'description' => isset($section['description']) ? $section['description'] : '',
            'additionalProperties' => false,
            'properties' => $properties
        );

        if (!empty($required)) {
            $section_schema['required'] = array_values(array_unique($required));
        }

        $conditional_required = $this->collect_conditional_required($section);
        if (!empty($conditional_required)) {
            $all_of = array();

            foreach ($conditional_required as $rule) {
                if (empty($rule['if']['field']) || !array_key_exists('const', $rule['if']) || empty($rule['then_required'])) {
                    continue;
                }

                $all_of[] = array(
                    'anyOf' => array(
                        array(
                            'not' => array(
                                'required' => array($rule['if']['field']),
                                'properties' => array(
                                    $rule['if']['field'] => array(
                                        'const' => $rule['if']['const']
                                    )
                                )
                            )
                        ),
                        array(
                            'required' => array_values($rule['then_required'])
                        )
                    )
                );
            }

            if (!empty($all_of)) {
                $section_schema['allOf'] = $all_of;
            }
        }

        return $section_schema;
    }

    protected function collect_section_fields($section)
    {
        $fields = array();

        if (!empty($section['fields']) && is_array($section['fields'])) {
            $fields = array_merge($fields, $section['fields']);
        }

        if (!empty($section['pages']) && is_array($section['pages'])) {
            foreach ($section['pages'] as $page) {
                if (empty($page['fields']) || !is_array($page['fields'])) {
                    continue;
                }

                $fields = array_merge($fields, $page['fields']);
            }
        }

        return $fields;
    }

    protected function collect_conditional_required($section)
    {
        $conditional_required = array();

        if (!empty($section['conditional_required']) && is_array($section['conditional_required'])) {
            $conditional_required = array_merge($conditional_required, $section['conditional_required']);
        }

        if (!empty($section['pages']) && is_array($section['pages'])) {
            foreach ($section['pages'] as $page) {
                if (empty($page['conditional_required']) || !is_array($page['conditional_required'])) {
                    continue;
                }

                $conditional_required = array_merge($conditional_required, $page['conditional_required']);
            }
        }

        return $conditional_required;
    }

    protected function build_field_schema($field)
    {
        $base_type = isset($field['type']) && $field['type'] !== '' ? $field['type'] : 'string';
        $schema_fragment = isset($field['schema']) && is_array($field['schema']) ? $field['schema'] : array();
        $array_schema_fragment = isset($field['array_schema']) && is_array($field['array_schema']) ? $field['array_schema'] : array();
        $template_fragment = isset($field['template']) && is_array($field['template']) ? $field['template'] : array();

        if (!empty($field['repeatable'])) {
            $item_schema = array_merge(
                array(
                    'type' => $base_type,
                    'title' => isset($field['title']) ? $field['title'] : $field['key']
                ),
                $schema_fragment
            );

            $schema = array_merge(
                array(
                    'type' => 'array',
                    'title' => isset($field['title']) ? $field['title'] : $field['key'],
                    'description' => isset($field['description']) ? $field['description'] : '',
                    'items' => $item_schema
                ),
                $array_schema_fragment
            );

            if (isset($array_schema_fragment['items']) && is_array($array_schema_fragment['items'])) {
                $schema['items'] = array_merge($item_schema, $array_schema_fragment['items']);
            } else {
                $schema['items'] = $item_schema;
            }

            if (!empty($field['required']) && !isset($schema['minItems'])) {
                $schema['minItems'] = 1;
            }
        } else {
            $schema = array_merge(
                array(
                    'type' => $base_type,
                    'title' => isset($field['title']) ? $field['title'] : $field['key'],
                    'description' => isset($field['description']) ? $field['description'] : ''
                ),
                $schema_fragment
            );

            if (!empty($field['required']) && $base_type === 'string' && !isset($schema['minLength']) && !isset($schema['enum'])) {
                $schema['minLength'] = 1;
            }
        }

        if (!empty($field['examples']) && is_array($field['examples'])) {
            $schema['examples'] = $field['examples'];
        } elseif (!empty($field['example'])) {
            $schema['examples'] = array($field['example']);
        }

        if (!empty($template_fragment['display_type'])) {
            $schema['x-template-display_type'] = $template_fragment['display_type'];
        }

        if (!empty($template_fragment['content_format'])) {
            $schema['x-template-content_format'] = $template_fragment['content_format'];
        }

        if (!empty($schema['enum']) && is_array($schema['enum']) && !empty($template_fragment['enum_labels']) && is_array($template_fragment['enum_labels'])) {
            $schema['x-enum-labels'] = $template_fragment['enum_labels'];
        }

        if (!empty($field['repeatable']) && !empty($schema['items']['enum']) && !empty($template_fragment['enum_labels']) && is_array($template_fragment['enum_labels'])) {
            $schema['items']['x-enum-labels'] = $template_fragment['enum_labels'];
        }

        return $schema;
    }
}
