<?php namespace Renick\TailorCompanion\Classes\Schema;

use Tailor\Classes\ContentFieldBase;

/**
 * FieldTypeRegistry classifies Tailor content fields for the wire schema.
 *
 * kind — what the app must do with the value:
 *   scalar     one primitive value (string/number/bool/date)
 *   json       array value in a jsonable column (checkboxlist, taglist, …)
 *   media      media-library path string(s) — resolved against the base URL
 *   relation   ids referencing entries of another blueprint
 *   attachment uploaded files (system_files) with URLs
 *   nested     repeater/nestedform child items, transported inline
 *   unknown    unclassified — app shows a placeholder, round-trips losslessly
 *
 * custom — true when the type is not an October core content field. Detection:
 * core Tailor fields resolve to classes in the Tailor\ContentFields namespace;
 * everything else (third-party classes, or unresolved types falling back to
 * FallbackField with an unrecognized code) is custom.
 */
class FieldTypeRegistry
{
    /**
     * @var array kindByClass maps core field classes to kinds. MediaFinder and
     * GenericField need config inspection and are handled separately.
     */
    protected array $kindByClass = [
        \Tailor\ContentFields\NumberField::class => 'scalar',
        \Tailor\ContentFields\DatePickerField::class => 'scalar',
        \Tailor\ContentFields\RichEditorField::class => 'scalar',
        \Tailor\ContentFields\MarkdownField::class => 'scalar',
        \Tailor\ContentFields\PageFinderField::class => 'scalar',
        \Tailor\ContentFields\TagListField::class => 'json',
        \Tailor\ContentFields\DataTableField::class => 'json',
        \Tailor\ContentFields\FileUploadField::class => 'attachment',
        \Tailor\ContentFields\EntriesField::class => 'relation',
        \Tailor\ContentFields\RecordFinderField::class => 'relation',
        \Tailor\ContentFields\RepeaterField::class => 'nested',
        \Tailor\ContentFields\NestedFormField::class => 'nested',
        \Tailor\ContentFields\NestedItemsField::class => 'nested',
    ];

    /**
     * @var array genericJsonTypes are GenericField type codes stored as json
     */
    protected array $genericJsonTypes = ['checkboxlist'];

    /**
     * @var array fallbackScalarTypes are core October form-widget types without
     * a dedicated content field class — FallbackField stores them as one text
     * column and we can safely treat them as scalars.
     */
    protected array $fallbackScalarTypes = ['colorpicker', 'currency', 'codeeditor', 'sensitive'];

    /**
     * kindFor returns the storage/transport kind for a field object.
     */
    public function kindFor(ContentFieldBase $field): string
    {
        $class = get_class($field);

        if (isset($this->kindByClass[$class])) {
            return $this->kindByClass[$class];
        }

        if ($field instanceof \Tailor\ContentFields\MediaFinderField) {
            return 'media';
        }

        if ($field instanceof \Tailor\ContentFields\GenericField) {
            return in_array($field->type, $this->genericJsonTypes, true) ? 'json' : 'scalar';
        }

        // FallbackField: known core widget types are scalars, the rest is opaque
        if ($class === \Tailor\ContentFields\FallbackField::class) {
            return in_array($field->type, $this->fallbackScalarTypes, true) ? 'scalar' : 'unknown';
        }

        // Third-party content field class — transported as opaque value
        return 'unknown';
    }

    /**
     * isCustom returns true when the field type is not an October core field.
     */
    public function isCustom(ContentFieldBase $field): bool
    {
        $class = get_class($field);

        // Non-core class namespace → third-party content field
        if (!str_starts_with($class, 'Tailor\\ContentFields\\')) {
            return true;
        }

        // FallbackField with a type we don't recognize → unregistered custom type
        if ($class === \Tailor\ContentFields\FallbackField::class) {
            return !in_array($field->type, $this->fallbackScalarTypes, true);
        }

        return false;
    }

    /**
     * isMixin — mixin fields are expanded server-side and never serialized.
     */
    public function isMixin(ContentFieldBase $field): bool
    {
        return $field instanceof \Tailor\ContentFields\MixinField;
    }
}
