<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\base;

use Craft;
use craft\elements\db\ElementQuery;
use craft\elements\db\ElementQueryInterface;
use craft\events\DefineFieldHtmlEvent;
use craft\events\DefineFieldKeywordsEvent;
use craft\events\FieldElementEvent;
use craft\gql\types\QueryArgument;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\helpers\FieldHelper;
use craft\helpers\Html;
use craft\helpers\StringHelper;
use craft\models\GqlSchema;
use craft\records\Field as FieldRecord;
use craft\validators\HandleValidator;
use craft\validators\UniqueValidator;
use GraphQL\Type\Definition\Type;
use yii\base\Arrayable;
use yii\base\ErrorHandler;
use yii\base\NotSupportedException;
use yii\db\Schema;

/**
 * Field is the base class for classes representing fields in terms of objects.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0.0
 */
abstract class Field extends SavableComponent implements FieldInterface
{
    use FieldTrait;

    // Events
    // -------------------------------------------------------------------------

    /**
     * @event FieldElementEvent The event that is triggered before the element is saved
     * You may set [[FieldElementEvent::isValid]] to `false` to prevent the element from getting saved.
     */
    const EVENT_BEFORE_ELEMENT_SAVE = 'beforeElementSave';

    /**
     * @event FieldElementEvent The event that is triggered after the element is saved
     */
    const EVENT_AFTER_ELEMENT_SAVE = 'afterElementSave';

    /**
     * @event FieldElementEvent The event that is triggered after the element is fully saved and propagated to other sites
     * @since 3.2.0
     */
    const EVENT_AFTER_ELEMENT_PROPAGATE = 'afterElementPropagate';

    /**
     * @event FieldElementEvent The event that is triggered before the element is deleted
     * You may set [[FieldElementEvent::isValid]] to `false` to prevent the element from getting deleted.
     */
    const EVENT_BEFORE_ELEMENT_DELETE = 'beforeElementDelete';

    /**
     * @event FieldElementEvent The event that is triggered after the element is deleted
     */
    const EVENT_AFTER_ELEMENT_DELETE = 'afterElementDelete';

    /**
     * @event FieldElementEvent The event that is triggered before the element is restored
     * You may set [[FieldElementEvent::isValid]] to `false` to prevent the element from getting restored.
     * @since 3.1.0
     */
    const EVENT_BEFORE_ELEMENT_RESTORE = 'beforeElementRestore';

    /**
     * @event FieldElementEvent The event that is triggered after the element is restored
     * @since 3.1.0
     */
    const EVENT_AFTER_ELEMENT_RESTORE = 'afterElementRestore';

    /**
     * @event DefineFieldKeywordsEvent The event that is triggered when defining the field’s search keywords for an
     * element.
     *
     * Note that you _must_ set [[Event::$handled]] to `true` if you want the field to accept your custom
     * [[DefineFieldKeywordsEvent::$keywords|$keywords]] value.
     *
     * ```php
     * Event::on(
     *     craft\fields\Lightswitch::class,
     *     craft\base\Field::EVENT_DEFINE_KEYWORDS,
     *     function(craft\events\DefineFieldKeywordsEvent $e
     * ) {
     *     // @var craft\fields\Lightswitch $field
     *     $field = $e->sender;
     *
     *     if ($field->handle === 'fooOrBar') {
     *         // Override the keywords depending on whether the lightswitch is enabled or not
     *         $e->keywords = $e->value ? 'foo' : 'bar';
     *         $e->handled = true;
     *     }
     * });
     * ```
     *
     * @since 3.5.0
     */
    const EVENT_DEFINE_KEYWORDS = 'defineKeywords';

    /**
     * @event DefineFieldHtmlEvent The event that is triggered when defining the field’s input HTML.
     * @since 3.5.0
     */
    const EVENT_DEFINE_INPUT_HTML = 'defineInputHtml';

    // Translation methods
    // -------------------------------------------------------------------------

    const TRANSLATION_METHOD_NONE = 'none';
    const TRANSLATION_METHOD_SITE = 'site';
    const TRANSLATION_METHOD_SITE_GROUP = 'siteGroup';
    const TRANSLATION_METHOD_LANGUAGE = 'language';
    const TRANSLATION_METHOD_CUSTOM = 'custom';

    /**
     * @inheritdoc
     */
    public static function hasContentColumn(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function supportedTranslationMethods(): array
    {
        if (!static::hasContentColumn()) {
            return [
                self::TRANSLATION_METHOD_NONE,
            ];
        }

        return [
            self::TRANSLATION_METHOD_NONE,
            self::TRANSLATION_METHOD_SITE,
            self::TRANSLATION_METHOD_SITE_GROUP,
            self::TRANSLATION_METHOD_LANGUAGE,
            self::TRANSLATION_METHOD_CUSTOM,
        ];
    }

    /**
     * @inheritdoc
     */
    public static function valueType(): string
    {
        return 'mixed';
    }

    /**
     * @var bool|null Whether the field is fresh.
     * @see isFresh()
     * @see setIsFresh()
     */
    private $_isFresh;

    /**
     * Use the translated field name as the string representation.
     *
     * @return string
     */
    public function __toString()
    {
        try {
            return (string)Craft::t('site', $this->name) ?: static::class;
        } catch (\Exception $e) {
            ErrorHandler::convertExceptionToError($e);
        }
    }

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();

        // Validate the translation method
        $supportedTranslationMethods = static::supportedTranslationMethods() ?: [self::TRANSLATION_METHOD_NONE];
        if (!in_array($this->translationMethod, $supportedTranslationMethods, true)) {
            $this->translationMethod = reset($supportedTranslationMethods);
        }

        if ($this->translationMethod !== self::TRANSLATION_METHOD_CUSTOM) {
            $this->translationKeyFormat = null;
        }
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        // Make sure the column name is under the database’s maximum allowed column length, including the column prefix/suffix lengths
        $maxHandleLength = Craft::$app->getDb()->getSchema()->maxObjectNameLength;

        if (static::hasContentColumn()) {
            $maxHandleLength -= strlen(Craft::$app->getContent()->fieldColumnPrefix);

            FieldHelper::ensureColumnSuffix($this);
            if ($this->columnSuffix) {
                $maxHandleLength -= strlen($this->columnSuffix) + 1;
            }
        }

        $rules[] = [['name'], 'string', 'max' => 255];
        $rules[] = [['handle'], 'string', 'max' => $maxHandleLength];
        $rules[] = [['name', 'handle', 'translationMethod'], 'required'];
        $rules[] = [['groupId'], 'number', 'integerOnly' => true];
        $rules[] = [
            ['translationMethod'],
            'in',
            'range' => [
                self::TRANSLATION_METHOD_NONE,
                self::TRANSLATION_METHOD_SITE,
                self::TRANSLATION_METHOD_SITE_GROUP,
                self::TRANSLATION_METHOD_LANGUAGE,
                self::TRANSLATION_METHOD_CUSTOM,
            ],
        ];
        $rules[] = [
            ['handle'],
            HandleValidator::class,
            'reservedWords' => [
                'ancestors',
                'archived',
                'attributeLabel',
                'attributes',
                'behavior',
                'behaviors',
                'children',
                'contentTable',
                'dateCreated',
                'dateUpdated',
                'descendants',
                'enabled',
                'enabledForSite',
                'error',
                'errors',
                'errorSummary',
                'fieldValue',
                'fieldValues',
                'id',
                'language',
                'level',
                'localized',
                'lft',
                'link',
                'localized',
                'name', // global set-specific
                'next',
                'nextSibling',
                'owner',
                'parent',
                'parents',
                'postDate', // entry-specific
                'prev',
                'prevSibling',
                'ref',
                'rgt',
                'root',
                'scenario',
                'searchScore',
                'siblings',
                'site',
                'slug',
                'sortOrder',
                'status',
                'title',
                'uid',
                'uri',
                'url',
                'username', // user-specific
            ],
        ];
        $rules[] = [
            ['handle'],
            UniqueValidator::class,
            'targetClass' => FieldRecord::class,
            'targetAttribute' => ['handle', 'context'],
            'message' => Craft::t('yii', '{attribute} "{value}" has already been taken.'),
        ];

        // Only validate the ID if it's not a new field
        if (!$this->getIsNew()) {
            $rules[] = [['id'], 'number', 'integerOnly' => true];
        }

        if ($this->translationMethod === self::TRANSLATION_METHOD_CUSTOM) {
            $rules[] = [['translationKeyFormat'], 'required'];
        }

        return $rules;
    }

    /**
     * @inheritdoc
     */
    public function getContentColumnType()
    {
        return Schema::TYPE_STRING;
    }

    /**
     * @inheritdoc
     */
    public function getIsTranslatable(ElementInterface $element = null): bool
    {
        if ($this->translationMethod === self::TRANSLATION_METHOD_CUSTOM) {
            return $element === null || $this->getTranslationKey($element) !== '';
        }
        return $this->translationMethod !== self::TRANSLATION_METHOD_NONE;
    }

    /**
     * @inheritdoc
     */
    public function getTranslationDescription(ElementInterface $element = null)
    {
        if (!$this->getIsTranslatable($element)) {
            return null;
        }

        return ElementHelper::translationDescription($this->translationMethod);
    }

    /**
     * @inheritdoc
     */
    public function getTranslationKey(ElementInterface $element): string
    {
        return ElementHelper::translationKey($element, $this->translationMethod, $this->translationKeyFormat);
    }

    /**
     * @inheritdoc
     */
    public function getStatus(ElementInterface $element): ?array
    {
        if ($element->isFieldModified($this->handle)) {
            return [
                Element::ATTR_STATUS_MODIFIED,
                Craft::t('app', 'This field has been modified.'),
            ];
        }

        if ($element->isFieldOutdated($this->handle)) {
            return [
                Element::ATTR_STATUS_OUTDATED,
                Craft::t('app', 'This field was updated in the Current revision.'),
            ];
        }

        return null;
    }

    /**
     * @inheritdoc
     */
    public function useFieldset(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function normalizeValue($value, ElementInterface $element = null)
    {
        return $value;
    }

    /**
     * @inheritdoc
     */
    public function getInputHtml($value, ElementInterface $element = null): string
    {
        $html = $this->inputHtml($value, $element);

        // Give plugins a chance to modify it
        $event = new DefineFieldHtmlEvent([
            'value' => $value,
            'element' => $element,
            'html' => $html,
        ]);

        $this->trigger(self::EVENT_DEFINE_INPUT_HTML, $event);
        return $event->html;
    }

    /**
     * Returns the field’s input HTML.
     *
     * @param mixed $value The field’s value. This will either be the [[normalizeValue()|normalized value]],
     * raw POST data (i.e. if there was a validation error), or null
     * @param ElementInterface|null $element The element the field is associated with, if there is one
     * @return string The input HTML.
     * @see getInputHtml()
     * @since 3.5.0
     */
    protected function inputHtml($value, ElementInterface $element = null): string
    {
        return Html::textarea($this->handle, $value);
    }

    /**
     * @inheritdoc
     */
    public function getStaticHtml($value, ElementInterface $element): string
    {
        // Just return the input HTML with disabled inputs by default
        Craft::$app->getView()->startJsBuffer();
        $inputHtml = $this->getInputHtml($value, $element);
        $inputHtml = preg_replace('/<(?:input|textarea|select)\s[^>]*/i', '$0 disabled', $inputHtml);
        Craft::$app->getView()->clearJsBuffer();

        return $inputHtml;
    }

    /**
     * @inheritdoc
     */
    public function getElementValidationRules(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function isValueEmpty($value, ElementInterface $element): bool
    {
        $reflection = new \ReflectionMethod($this, 'isEmpty');
        if ($reflection->getDeclaringClass()->getName() !== self::class) {
            Craft::$app->getDeprecator()->log('Field::isEmpty()', 'Fields’ `isEmpty()` method has been deprecated. Use `isValueEmpty()` instead.');
        }

        return $this->isEmpty($value);
    }

    /**
     * @param mixed $value
     * @return bool
     * @deprecated in 3.0.0-RC15. Use [[isValueEmpty()]] instead.
     */
    public function isEmpty($value): bool
    {
        // Default to yii\validators\Validator::isEmpty()'s behavior
        return $value === null || $value === [] || $value === '';
    }

    /**
     * @inheritdoc
     */
    public function getSearchKeywords($value, ElementInterface $element): string
    {
        // Give plugins/modules a chance to define custom keywords
        if ($this->hasEventHandlers(self::EVENT_DEFINE_KEYWORDS)) {
            $event = new DefineFieldKeywordsEvent([
                'value' => $value,
                'element' => $element,
            ]);
            $this->trigger(self::EVENT_DEFINE_KEYWORDS, $event);
            if ($event->handled) {
                return $event->keywords;
            }
        }
        return $this->searchKeywords($value, $element);
    }

    /**
     * Returns the search keywords that should be associated with this field.
     *
     * The keywords can be separated by commas and/or whitespace; it doesn’t really matter. [[\craft\services\Search]]
     * will be able to find the individual keywords in whatever string is returned, and normalize them for you.
     *
     * @param mixed $value The field’s value
     * @param ElementInterface $element The element the field is associated with, if there is one
     * @return string A string of search keywords.
     * @since 3.5.0
     */
    protected function searchKeywords($value, ElementInterface $element): string
    {
        return StringHelper::toString($value, ' ');
    }

    /**
     * Returns the HTML that should be shown for this field in Table View.
     *
     * @param mixed $value The field’s value
     * @param ElementInterface $element The element the field is associated with
     * @return string The HTML that should be shown for this field in Table View
     */
    public function getTableAttributeHtml($value, ElementInterface $element): string
    {
        $value = (string)$value;

        return Html::encode(StringHelper::stripHtml($value));
    }

    /**
     * Returns the sort option array that should be included in the element’s
     * [[\craft\base\ElementInterface::sortOptions()|sortOptions()]] response.
     *
     * @return array
     * @see \craft\base\SortableFieldInterface::getSortOption()
     * @since 3.2.0
     */
    public function getSortOption(): array
    {
        $column = ElementHelper::fieldColumnFromField($this);

        if ($column === null) {
            throw new NotSupportedException('getSortOption() not supported by ' . $this->name);
        }

        return [
            'label' => Craft::t('site', $this->name),
            'orderBy' => [$column, 'elements.id'],
            'attribute' => 'field:' . $this->id,
        ];
    }

    /**
     * @inheritdoc
     */
    public function serializeValue($value, ElementInterface $element = null)
    {
        // If the object explicitly defines its savable value, use that
        if ($value instanceof Serializable) {
            return $value->serialize();
        }

        // If it's "arrayable", convert to array
        if ($value instanceof Arrayable) {
            return $value->toArray();
        }

        // Only DateTime objects and ISO-8601 strings should automatically be detected as dates
        if ($value instanceof \DateTime || DateTimeHelper::isIso8601($value)) {
            return Db::prepareDateForDb($value);
        }

        return $value;
    }

    /**
     * @inheritdoc
     */
    public function copyValue(ElementInterface $from, ElementInterface $to): void
    {
        $value = $this->serializeValue($from->getFieldValue($this->handle), $from);
        $to->setFieldValue($this->handle, $value);
    }

    /**
     * @inheritdoc
     */
    public function modifyElementsQuery(ElementQueryInterface $query, $value)
    {
        /** @var ElementQuery $query */
        if ($value !== null) {
            $column = ElementHelper::fieldColumnFromField($this);

            // If the field type doesn't have a content column, it *must* override this method
            // if it wants to support a custom query criteria attribute
            if ($column === null) {
                return false;
            }

            $query->subQuery->andWhere(Db::parseParam("content.$column", $value));
        }

        return null;
    }

    /**
     * @inheritdoc
     */
    public function modifyElementIndexQuery(ElementQueryInterface $query)
    {
        if ($this instanceof EagerLoadingFieldInterface) {
            $query->andWith($this->handle);
        }
    }

    /**
     * @inheritdoc
     */
    public function setIsFresh(bool $isFresh = null)
    {
        $this->_isFresh = $isFresh;
    }

    /**
     * @inheritdoc
     */
    public function getGroup()
    {
        return Craft::$app->getFields()->getGroupById($this->groupId);
    }

    /**
     * @inheritdoc
     */
    public function includeInGqlSchema(GqlSchema $schema): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function getContentGqlType()
    {
        return Type::string();
    }

    /**
     * @inheritdoc
     * @since 3.5.0
     */
    public function getContentGqlMutationArgumentType()
    {
        return [
            'name' => $this->handle,
            'type' => Type::string(),
            'description' => $this->instructions,
        ];
    }

    /**
     * @inheritdoc
     * @since 3.5.0
     */
    public function getContentGqlQueryArgumentType()
    {
        return [
            'name' => $this->handle,
            'type' => Type::listOf(QueryArgument::getType()),
        ];
    }

    // Events
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */
    public function beforeSave(bool $isNew): bool
    {
        // Set the field context if it's not set
        if (!$this->context) {
            $this->context = Craft::$app->getContent()->fieldContext;
        }

        return parent::beforeSave($isNew);
    }

    /**
     * @inheritdoc
     */
    public function beforeElementSave(ElementInterface $element, bool $isNew): bool
    {
        // Trigger a 'beforeElementSave' event
        $event = new FieldElementEvent([
            'element' => $element,
            'isNew' => $isNew,
        ]);
        $this->trigger(self::EVENT_BEFORE_ELEMENT_SAVE, $event);

        return $event->isValid;
    }

    /**
     * @inheritdoc
     */
    public function afterElementSave(ElementInterface $element, bool $isNew)
    {
        // Trigger an 'afterElementSave' event
        if ($this->hasEventHandlers(self::EVENT_AFTER_ELEMENT_SAVE)) {
            $this->trigger(self::EVENT_AFTER_ELEMENT_SAVE, new FieldElementEvent([
                'element' => $element,
                'isNew' => $isNew,
            ]));
        }
    }

    /**
     * @inheritdoc
     */
    public function afterElementPropagate(ElementInterface $element, bool $isNew)
    {
        // Trigger an 'afterElementPropagate' event
        if ($this->hasEventHandlers(self::EVENT_AFTER_ELEMENT_PROPAGATE)) {
            $this->trigger(self::EVENT_AFTER_ELEMENT_PROPAGATE, new FieldElementEvent([
                'element' => $element,
                'isNew' => $isNew,
            ]));
        }
    }

    /**
     * @inheritdoc
     */
    public function beforeElementDelete(ElementInterface $element): bool
    {
        // Trigger a 'beforeElementDelete' event
        $event = new FieldElementEvent([
            'element' => $element,
        ]);
        $this->trigger(self::EVENT_BEFORE_ELEMENT_DELETE, $event);

        return $event->isValid;
    }

    /**
     * @inheritdoc
     */
    public function afterElementDelete(ElementInterface $element)
    {
        // Trigger an 'afterElementDelete' event
        if ($this->hasEventHandlers(self::EVENT_AFTER_ELEMENT_DELETE)) {
            $this->trigger(self::EVENT_AFTER_ELEMENT_DELETE, new FieldElementEvent([
                'element' => $element,
            ]));
        }
    }

    /**
     * @inheritdoc
     */
    public function beforeElementRestore(ElementInterface $element): bool
    {
        // Trigger a 'beforeElementRestore' event
        $event = new FieldElementEvent([
            'element' => $element,
        ]);
        $this->trigger(self::EVENT_BEFORE_ELEMENT_RESTORE, $event);

        return $event->isValid;
    }

    /**
     * @inheritdoc
     */
    public function afterElementRestore(ElementInterface $element)
    {
        // Trigger an 'afterElementRestore' event
        if ($this->hasEventHandlers(self::EVENT_AFTER_ELEMENT_RESTORE)) {
            $this->trigger(self::EVENT_AFTER_ELEMENT_RESTORE, new FieldElementEvent([
                'element' => $element,
            ]));
        }
    }

    /**
     * Returns an array that lists the scopes this custom field allows when eager-loading or false if eager-loading
     * should not be allowed in the GraphQL context.
     *
     * @return array|false
     * @since 3.3.0
     */
    public function getEagerLoadingGqlConditions()
    {
        // No restrictions
        return [];
    }

    /**
     * Returns the field’s param name on the request.
     *
     * @param ElementInterface $element The element this field is associated with
     * @return string|null The field’s param name on the request
     */
    protected function requestParamName(ElementInterface $element)
    {
        if (!$element) {
            return null;
        }

        $namespace = $element->getFieldParamNamespace();

        if (!$namespace === null) {
            return null;
        }

        return ($namespace ? $namespace . '.' : '') . $this->handle;
    }

    /**
     * Returns whether this is the first time the element's content has been edited.
     *
     * @param ElementInterface|null $element
     * @return bool
     */
    protected function isFresh(ElementInterface $element = null): bool
    {
        if ($this->_isFresh !== null) {
            return $this->_isFresh;
        }

        if ($element) {
            return $element->getHasFreshContent();
        }

        return true;
    }
}
