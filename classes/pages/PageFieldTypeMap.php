<?php namespace Renick\TailorCompanion\Classes\Pages;

/**
 * PageFieldTypeMap classifies October syntax-field types (the `{variable}`
 * tags declared in static page layouts) into the same wire "kinds" the app
 * already renders for Tailor fields (see FieldTypeRegistry). Unknown types
 * degrade to the lossless `unknown` kind — never dropped.
 */
class PageFieldTypeMap
{
    /**
     * @var array scalarTypes render with the app's plain field editors.
     */
    protected array $scalarTypes = [
        'text',
        'textarea',
        'richeditor',
        'markdown',
        'dropdown',
        'checkbox',
        'switch',
        'datepicker',
        'colorpicker',
        'number',
        'balloon-selector',
        'radio',
        'email',
        'password',
    ];

    /**
     * kindFor maps a syntax-field type to a wire kind.
     */
    public function kindFor(string $type): string
    {
        if (in_array($type, $this->scalarTypes, true)) {
            return 'scalar';
        }

        if ($type === 'mediafinder') {
            return 'media';
        }

        // Repeater (list of items) and nestedform/nesteditems (a single nested
        // object) all map to the nested kind — the app renders them recursively.
        if (in_array($type, ['repeater', 'nestedform', 'nesteditems'], true)) {
            return 'nested';
        }

        // fileupload included: attachment semantics on file-based pages are
        // unclear, so it stays an opaque (and readonly) unknown in v1.
        return 'unknown';
    }

    /**
     * isReadonly flags types the app may display but must not write.
     */
    public function isReadonly(string $type): bool
    {
        return $type === 'fileupload';
    }
}
