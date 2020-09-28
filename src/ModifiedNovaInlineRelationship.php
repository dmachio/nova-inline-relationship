<?php

namespace KirschbaumDevelopment\NovaInlineRelationship;

use Illuminate\Http\Request;
use Laravel\Nova\Http\Requests\ResourceDetailRequest;
use stdClass;
use Carbon\Carbon;
use App\Nova\Resource;
use Laravel\Nova\Nova;
use Illuminate\Support\Str;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\File;
use Laravel\Nova\Fields\Field;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Laravel\Nova\ResourceToolElement;
use Illuminate\Database\Eloquent\Model;
use Laravel\Nova\Contracts\ListableField;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Fields\ResolvesReverseRelation;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use KirschbaumDevelopment\NovaInlineRelationship\Rules\RelationshipRule;
use Illuminate\Database\Eloquent\Relations\Concerns\SupportsDefaultModels;
use KirschbaumDevelopment\NovaInlineRelationship\Traits\RequireRelationship;
use KirschbaumDevelopment\NovaInlineRelationship\Observers\NovaInlineRelationshipObserver;

class ModifiedNovaInlineRelationship extends Field
{
    use RequireRelationship;

    /**
     * The field's component.
     *
     * @var string
     */
    public $component = 'nova-inline-relationship';

    /**
     * All the Models observed by the field
     *
     * @var array
     */
    public static $observedModels = [];

    /**
     * Name of resource class to be used
     *
     * @var string
     */
    private $resourceClass;

    /**
     * Original request passed by ->inline().
     *
     * @var
     */
    protected $request;

    /**
     * Set original request.
     *
     * @param Request $Request
     * @return $this
     */
    public function setRequest(Request $Request)
    {
        $this->request = $Request;
        return $this;
    }

    /**
     * Pass resourceClass to NovaInlineRelationship.
     *
     * @param string $class
     *
     * @return NovaInlineRelationship
     */
    public function resourceClass(string $class): self
    {
        $this->resourceClass = $class;

        return $this;
    }

    /**
     * Resolve the field's value for display.
     *
     * @param  mixed  $resource
     * @param  string|null  $attribute
     *
     * @return void
     */
    public function resolveForDisplay($resource, $attribute = null)
    {
        parent::resolveForDisplay($resource, $attribute);

        $attribute = $attribute ?? $this->attribute;

        $properties = $this->getPropertiesWithMetaForDisplay($resource, $attribute);

        $this->resolveResourceFields($resource, $attribute, $properties);
    }

    /**
     * Resolve the field's value.
     *
     * @param  mixed  $resource
     * @param  string|null  $attribute
     *
     * @return void
     */
    public function resolve($resource, $attribute = null)
    {
        parent::resolve($resource, $attribute);

        $request = $this->getNovaRequest();

        if ($request->isCreateOrAttachRequest() || $request->isUpdateOrUpdateAttachedRequest()) {
            $attribute = $attribute ?? $this->attribute;

            $properties = $this->getPropertiesWithMetaForForms($resource, $attribute);

            $this->resolveResourceFields($resource, $attribute, $properties);
        }
    }

    /**
     * Fetch default values from collection
     *
     * @param Collection $properties
     *
     * @return Collection
     */
    public function getDefaultsFromProperties(Collection $properties): Collection
    {
        return $properties->map(function ($value) {
            return $value['default'] ?? '';
        });
    }

    /**
     * Get Properties From Resource with Meta Information
     *
     * @param  mixed  $resource
     * @param  string  $attribute
     *
     * @return Collection
     */
    public function getPropertiesWithMetaForDisplay($resource, $attribute): Collection
    {
        list($fields, $related_resource) = $this->getFieldsFromResource($resource, $attribute);

        $fields
            ->filter->authorize(app(NovaRequest::class))
            ->filter(function ($field) use ($related_resource) {
                return $field->showOnDetail;
            });

        return $this->getPropertiesFromFields($fields)
            ->keyBy('attribute')
            ->map(function ($value, $key) {
                return $this->setMetaFromClass($value, $key);
            });
    }

    /**
     * Get Properties From Resource with Meta Information
     *
     * @param  mixed  $resource
     * @param  string  $attribute
     *
     * @return Collection
     */
    public function getPropertiesWithMetaForForms($resource, $attribute): Collection
    {
        list($fields, $related_resource) = $this->getFieldsFromResource($resource, $attribute);

        $request = $this->getNovaRequest();

        $fields
            ->filter->authorize($request) // app(NovaRequest::class)
            ->filter(function ($field) use ($request, $related_resource) {

                return ($request->isCreateOrAttachRequest() && $field->showOnCreation)
                    || ($request->isUpdateOrUpdateAttachedRequest() && $field->showOnUpdate);
            });

        return $this->getPropertiesFromFields($fields)
            ->keyBy('attribute')
            ->map(function ($value, $key) {
                return $this->setMetaFromClass($value, $key);
            });
    }

    /**
     * Checks if a relationship is singular
     *
     * @param Model $model
     * @param $relation
     *
     * @return bool
     */
    public function isSingularRelationship(Model $model, $relation): bool
    {
        return collect(class_uses($model->{$relation}()))->contains(SupportsDefaultModels::class);
    }

    /**
     * Checks if a relationship is deletable
     *
     * @param Model $model
     * @param $relation
     *
     * @return bool
     */
    public function isRelationshipDeletable(Model $model, $relation): bool
    {
        return !($model->{$relation}() instanceof BelongsTo);
    }

    /**
     * Create a duplicate request object using the base request
     *
     * @param NovaRequest $request
     * @param array $item
     *
     * @return NovaInlineRelationshipRequest
     */
    public function getDuplicateRequest(NovaRequest $request, array $item): NovaInlineRelationshipRequest
    {
        $files = collect($item)->filter(function ($itemData) {
            return $itemData instanceof UploadedFile;
        });

        // Create a New Request
        $newRequest = NovaInlineRelationshipRequest::createFrom($request)
            ->duplicate($item, array_merge($request->all(), $item));

        // Update List of converted Files
        $newRequest->updateConvertedFiles($files);

        return $newRequest;
    }

    /**
     * Get Value for the Child attribute from field
     *
     * @param Field $field
     * @param NovaInlineRelationshipRequest $request
     * @param string $attribute
     *
     * @return mixed|null
     */
    public function getValueFromField(Field $field, NovaInlineRelationshipRequest $request, string $attribute)
    {
        $temp = new stdClass();

        // Fill Attributes in Field
        $field->fillAttribute($request, $attribute, $temp, $attribute);

        return $temp->{$attribute} ?? null;
    }

    /**
     * Resolve the fields for the resource.
     *
     * @param $resource
     * @param $attribute
     * @param $properties
     */
    protected function resolveResourceFields($resource, $attribute, $properties)
    {
        $this->rules = [$this->getRelationshipRule($attribute, $properties)];
        $modelKey = optional($this->value)->first() ?? $resource->{$attribute}()->getRelated()->newInstance();

        $this->withMeta([
            'defaults' => $this->getDefaultsFromProperties($properties)->all(),
            'settings' => $properties->all(),
            'models' => $this->modelIds(),
            'modelKey' => Str::plural(Str::kebab(class_basename($modelKey))),
            'singularLabel' => Str::title(Str::singular($this->name)),
            'pluralLabel' => Str::title(Str::plural($this->name)),
            'singular' => $this->isSingularRelationship($resource, $attribute),
            'deletable' => $this->isRelationshipDeletable($resource, $attribute),
            'addChildAtStart' => $this->requireChild,
        ]);

        $this->updateFieldValue($resource, $attribute, $properties);
    }

    /**
     * Pluck id's from the model or collection.
     *
     * @return array
     */
    protected function modelIds()
    {
        if ($this->value instanceof Model) {
            $models = [$this->value->{$this->value->getKeyName()}];
        } elseif ($this->value instanceof Collection && $this->value->isNotEmpty()) {
            $key = $this->value->first()->getKeyName();
            $models = $this->value->pluck($key)->all();
        }

        return $models ?? [];
    }

    /**
     * Set Meta Values using Field
     *
     * @param array $item
     * @param $attrib
     * @param null $value
     *
     * @return array
     */
    protected function setMetaFromClass(array $item, $attrib, $value = null)
    {
        $field = $item['field'];

        $item['meta'] = $field->jsonSerialize();

        // We are using Singular Label instead of name to display labels as compound name will be used in Vue
        $item['meta']['singularLabel'] = Str::title(Str::singular(str_replace('_', ' ', $item['label'] ?? $attrib)));

        $item['meta']['placeholder'] = 'Add ' . $item['meta']['singularLabel'];

        return $item;
    }

    /**
     * Hydrate the given attribute on the model based on the incoming request.
     *
     * @param NovaRequest $request
     * @param  string  $requestAttribute
     * @param  object  $model
     * @param  string  $attribute
     *
     * @return mixed
     */
    protected function fillAttributeFromRequest(NovaRequest $request, $requestAttribute, $model, $attribute)
    {
        if ($request->exists($requestAttribute)) {
            $response = is_array($request[$requestAttribute])
                ? $request[$requestAttribute]
                : json_decode($request[$requestAttribute], true);

            $properties = $this->getPropertiesFromResource($model, $attribute);

            $modResponse = $this->getResourceResponse($request, $response, $properties);

            if ($model instanceof Model) {
                $this->setModelAttributeValue($model, $attribute, $modResponse);
            }
        }
    }

    /**
     * Resolve Field class from child Resource attribute
     *
     * @param array $props
     * @param string $key
     *
     * @return Field
     */
    protected function getResourceField($props, $key): Field
    {
        /** @var newField Field */
        $newField = clone $props['field'];
        $newField->name = $key;
        $newField->attribute = $key;
        return $newField;
    }

    /**
     * Return Relationship rule from properties
     *
     * @param string $attribute
     * @param Collection $properties
     *
     * @return RelationshipRule
     */
    protected function getRelationshipRule($attribute, Collection $properties): RelationshipRule
    {
        $ruleArray = [];
        $messageArray = [];
        $attribArray = [];

        $properties->each(function ($child, $childAttribute) use ($attribute, &$ruleArray, &$messageArray, &$attribArray) {
            if (!empty($child['rules'])) {
                $name = "{$attribute}.*.{$childAttribute}";
                $ruleArray[$name] = $child['rules'];
                $attribArray[$name] = $child['label'] ?? $childAttribute;

                if (!empty($child['messages']) && is_array($child['messages'])) {
                    foreach ($child['messages'] as $rule => $message) {
                        $messageArray["{$name}.{$rule}"] = $message;
                    }
                }
            }
        });

        return new RelationshipRule($ruleArray, $messageArray, $attribArray);
    }

    /**
     * Get Properties From Resource File
     *
     * @param $model
     * @param $attribute
     *
     * @return Collection
     */
    protected function getPropertiesFromResource($model, $attribute): Collection
    {
        list($fields) = $this->getFieldsFromResource($model, $attribute);

        return $this->getPropertiesFromFields($fields)
            ->keyBy('attribute');
    }

    /**
     * Get all fields from resource.
     *
     * @param $model
     * @param $attribute
     *
     * @return Collection
     */
    protected function getFieldsFromResource($model, $attribute): Collection
    {
        $related_model = !empty($this->resourceClass)
            // @note: this is probably wrong, but it works for one to one relations.
            ? isset($model->{$attribute}) ? $model->{$attribute} : $model
            : $model->{$attribute}()->getRelated();

        if (is_countable(($related_model))) {
            $related_model = count($related_model) > 0 ? $related_model[0] : null;
        }

        $resource = !empty($this->resourceClass)
            // @note: this is probably wrong, but it works for one to one relations.
            ? new $this->resourceClass($related_model)
            : Nova::newResourceFromModel($related_model);

        return collect([collect($resource->availableFields($this->getNovaRequest()))
            ->reject(function ($field) use ($resource) {
                return $field instanceof ListableField ||
                    $field instanceof ResourceToolElement ||
                    $field->attribute === 'ComputedField' ||
                    ($field instanceof ID && $field->attribute === $resource->resource->getKeyName()) ||
                    collect(class_uses($field))->contains(ResolvesReverseRelation::class) ||
                    $field instanceof self;
            }), $resource]);
    }

    /**
     * Get properties for each field.
     *
     * @param Collection $fields
     *
     * @return Collection
     */
    protected function getPropertiesFromFields(Collection $fields): Collection
    {
        return $fields->map(function ($field) {
            return [
                'field' => $field,
                'component' => get_class($field),
                'label' => $field->name,
                'options' => $field->meta,
                'rules' => $field->rules,
                'attribute' => $field->attribute,
            ];
        });
    }

    /**
     * Update value for the field
     *
     * @param $resource
     * @param $attribute
     * @param Collection $properties
     */
    protected function updateFieldValue($resource, $attribute, Collection $properties): void
    {
        if ($this->isSingularRelationship($resource, $attribute)) {
            $this->value = collect($this->value ? [$this->value] : []);
        }

        $this->value = $this->value->map(function ($related_model, $index) use ($properties) {
            $related_resource = !empty($this->resourceClass)
                ? new $this->resourceClass($related_model)
                : Nova::newResourceFromModel($related_model);

            $properties = $properties->map(function ($property, $attrib) use ($related_resource) {
                $request = $this->getNovaRequest();

                if ($request->isCreateOrAttachRequest() || $request->isUpdateOrUpdateAttachedRequest()) {
                    $property['field']->resolve($related_resource);
                } else {
                    $property['field']->resolveForDisplay($related_resource);
                }

                $property = $this->setMetaFromClass($property, $attrib, null);

                return $property;
            });

            // @note For some reason, returning just $properties won't work as
            //       some fields get inadvertently change
            return collect(json_decode(json_encode($properties), true));
        });
    }

    /**
     * Generate response object for a child resource by passing request to each field
     *
     * @param NovaRequest $request
     * @param $response
     * @param Collection $properties
     *
     * @return array
     */
    protected function getResourceResponse(NovaRequest $request, $response, Collection $properties): array
    {
        return collect($response)->map(function ($item) use ($properties, $request) {
            return collect($item)->map(function ($value, $key) use ($properties, $request, $item) {
                if ($properties->has($key)) {
                    $field = $this->getResourceField($properties->get($key), $key);
                    $newRequest = $this->getDuplicateRequest($request, $item);

                    return $this->getValueFromField($field, $newRequest, $key)
                        ?? ($field instanceof File) && !empty($value)
                        ? $value
                        : null;
                }

                return $value;
            })->all();
        })->all();
    }

    /**
     * save model attribute to a static array
     *
     * @param Model $model
     * @param $attribute
     * @param array $value
     */
    protected function setModelAttributeValue(Model $model, $attribute, array $value): void
    {
        $modelClass = get_class($model);

        if (!array_key_exists($modelClass, static::$observedModels)) {
            $model::observe(NovaInlineRelationshipObserver::class);
            $model->updated_at = Carbon::now();
        }

        static::$observedModels[$modelClass][$attribute] = $this->isNullValue($value) ? null : $value;
    }

    /**
     * Get Nova Request. Should be passed by ->inine().
     */
    protected function getNovaRequest()
    {
        if ($this->request === null) {
            $this->request = app(NovaRequest::class);
        }
        if (!$this->request instanceof NovaRequest) {
            $this->request = new NovaRequest($this->request->all());
        }
        if (!$this->request->has('viaInlineRelationship')) {
            $this->request->merge([
                'viaInlineRelationship' => true
            ]);
        }
        return $this->request;
    }
}
