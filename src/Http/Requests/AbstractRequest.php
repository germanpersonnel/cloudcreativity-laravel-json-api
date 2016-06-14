<?php

/**
 * Copyright 2016 Cloud Creativity Limited
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace CloudCreativity\LaravelJsonApi\Http\Requests;

use CloudCreativity\JsonApi\Contracts\Authorizer\AuthorizerInterface;
use CloudCreativity\JsonApi\Contracts\Object\DocumentInterface;
use CloudCreativity\JsonApi\Contracts\Store\StoreInterface;
use CloudCreativity\JsonApi\Contracts\Validators\DocumentValidatorInterface;
use CloudCreativity\JsonApi\Contracts\Validators\ValidatorProviderInterface;
use CloudCreativity\JsonApi\Object\Document;
use CloudCreativity\JsonApi\Object\ResourceIdentifier;
use CloudCreativity\LaravelJsonApi\Contracts\Http\Requests\RequestHandlerInterface;
use CloudCreativity\LaravelJsonApi\Contracts\Pagination\PageParameterHandlerInterface;
use CloudCreativity\LaravelJsonApi\Exceptions\RequestException;
use Exception;
use Illuminate\Http\Request as HttpRequest;
use Neomerx\JsonApi\Contracts\Encoder\Parameters\EncodingParametersInterface;
use Neomerx\JsonApi\Exceptions\JsonApiException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Class Request
 * @package CloudCreativity\LaravelJsonApi
 */
abstract class AbstractRequest implements RequestHandlerInterface
{

    use InterpretsHttpRequests,
        DecodesDocuments,
        ParsesQueryParameters;

    /**
     * A list of has-one relationships that are expected as endpoints.
     *
     * @var array
     */
    protected $hasOne = [];

    /**
     * A list of has-many relationships that are exposed as endpoints.
     *
     * @var array
     */
    protected $hasMany = [];

    /**
     * @var string[]|null
     * @see ParsesQueryParameters::allowedIncludePaths()
     */
    protected $allowedIncludePaths = [];

    /**
     * @var array|null
     * @see ParsesQueryParameters::allowedFieldSetTypes()
     */
    protected $allowedFieldSetTypes = null;

    /**
     * @var string[]|null
     * @see ParsesQueryParameters::allowedSortParameters()
     */
    protected $allowedSortParameters = [];

    /**
     * @var string[]|null
     * @see ParsesQueryParameters::allowedFilteringParameters()
     */
    protected $allowedFilteringParameters = [];

    /**
     * @var bool
     * @see ParsesQueryParameters::allowUnrecognizedParameters()
     */
    protected $allowUnrecognizedParams = false;

    /**
     * @var ValidatorProviderInterface|null
     */
    private $validators;

    /**
     * @var AuthorizerInterface|null
     */
    private $authorizer;

    /**
     * @var HttpRequest
     */
    private $request;

    /**
     * @var EncodingParametersInterface
     */
    private $encodingParameters;

    /**
     * @var DocumentInterface
     */
    private $document;

    /**
     * @var object|null
     */
    private $record;

    /**
     * AbstractRequest constructor.
     * @param ValidatorProviderInterface $validators
     * @param AuthorizerInterface|null $authorizer
     */
    public function __construct(
        ValidatorProviderInterface $validators = null,
        AuthorizerInterface $authorizer = null
    ) {
        $this->validators = $validators;
        $this->authorizer = $authorizer;
    }

    /**
     * Validate the given class instance.
     *
     * @return void
     * @throws Exception
     */
    public function validate()
    {
        /** Check the URI is valid */
        $this->record = !empty($this->getResourceId()) ? $this->findRecord() : null;
        $this->validateRelationshipUrl();

        /** Check request parameters are acceptable. */
        $this->encodingParameters = $this->validateParameters();

        /** Do any pre-document authorization */
        if (!$this->authorizeBeforeValidation()) {
            throw $this->authorizer->denied();
        }

        /** If a document is expected from the client, validate it. */
        if ($this->isExpectingDocument()) {
            $this->document = $this->decodeDocument($this->getHttpRequest());
            $this->validateDocument();
        }

        /** Do any post-document authorization. */
        if (!$this->authorizeAfterValidation()) {
            throw $this->authorizer->denied();
        }

        /** Register the current request in the container. */
        app()->instance(RequestHandlerInterface::class, $this);
    }

    /**
     * @return HttpRequest
     */
    public function getHttpRequest()
    {
        if (!$this->request) {
            $this->request = app(HttpRequest::class);
        }

        return $this->request;
    }

    /**
     * @return object
     */
    public function getRecord()
    {
        if (!is_object($this->record)) {
            throw new RequestException('This request does not relate to a record.');
        }

        return $this->record;
    }

    /**
     * @return DocumentInterface
     */
    public function getDocument()
    {
        return $this->document ?: new Document();
    }

    /**
     * @return EncodingParametersInterface
     */
    public function getEncodingParameters()
    {
        return $this->encodingParameters;
    }

    /**
     * @return bool
     */
    protected function authorizeBeforeValidation()
    {
        if (!$this->authorizer) {
            return true;
        }

        $parameters = $this->getEncodingParameters();

        /** Index */
        if ($this->isIndex()) {
            return $this->authorizer->canReadMany($parameters);
        } /** Read Resource */
        elseif ($this->isReadResource()) {
            return $this->authorizer->canRead($this->getRecord(), $parameters);
        } /** Update Resource */
        elseif ($this->isUpdateResource()) {
            return $this->authorizer->canUpdate($this->getRecord(), $parameters);
        } /** Delete Resource */
        elseif ($this->isDeleteResource()) {
            return $this->authorizer->canDelete($this->getRecord(), $parameters);
        } elseif ($this->isReadRelatedResource()) {
            return $this->authorizer->canReadRelatedResource($this->getRelationshipName(), $this->getRecord(), $parameters);
        } /** Read Relationship Data */
        elseif ($this->isReadRelationship()) {
            return $this->authorizer->canReadRelationship($this->getRelationshipName(), $this->getRecord(), $parameters);
        } /** Replace Relationship Data */
        elseif ($this->isModifyRelationship()) {
            return $this->authorizer->canModifyRelationship($this->getRelationshipName(), $this->getRecord(), $parameters);
        }

        return true;
    }

    /**
     * Is the request authorized?
     *
     * @return bool
     */
    protected function authorizeAfterValidation()
    {
        if ($this->authorizer && $this->isCreateResource()) {
            return $this->authorizer->canCreate($this->getDocument()->resource(), $this->getEncodingParameters());
        }

        return true;
    }

    /**
     * @return object
     * @throws NotFoundHttpException
     */
    protected function findRecord()
    {
        /** @var StoreInterface $store */
        $store = app(StoreInterface::class);
        $identifier = ResourceIdentifier::create($this->getResourceType(), $this->getResourceId());

        $record = $store->find($identifier);

        if (!$record) {
            throw new NotFoundHttpException();
        }

        return $record;
    }

    /**
     * @return void
     * @throws NotFoundHttpException
     */
    protected function validateRelationshipUrl()
    {
        if (!$this->isRelationship()) {
            return;
        }

        $name = $this->getRelationshipName();

        if (!in_array($name, $this->hasOne) && !in_array($name, $this->hasMany)) {
            throw new NotFoundHttpException();
        }
    }

    /**
     * @return EncodingParametersInterface
     */
    protected function validateParameters()
    {
        $parameters = $this->parseQueryParameters();
        $this->checkQueryParameters($parameters);

        /** If we are on an index route, we also validate the filter parameters. */
        $validator = ($this->isIndex() && $this->validators) ?
            $this->validators->filterResources() : null;

        if ($validator && !$validator->isValid((array) $parameters->getFilteringParameters())) {
            throw new JsonApiException($validator->errors());
        }

        return $parameters;
    }

    /**
     * @return DocumentValidatorInterface|null
     */
    protected function validator()
    {
        if (!$this->validators) {
            return null;
        }

        /** Create Resource */
        if ($this->isCreateResource()) {
            return $this->validators->createResource();
        } /** Update Resource */
        elseif ($this->isUpdateResource()) {
            return $this->validators->updateResource($this->getRecord(), $this->getResourceId());
        } /** Replace Relationship */
        elseif ($this->isModifyRelationship()) {
            return $this->validators->modifyRelationship($this->getRelationshipName(), $this->getRecord());
        }

        return null;
    }

    /**
     * @return void
     * @throws JsonApiException
     */
    protected function validateDocument()
    {
        $validator = $this->validator();

        if ($validator && !$validator->isValid($this->getDocument())) {
            throw new JsonApiException($validator->errors());
        }
    }

    /**
     * @return bool
     */
    protected function allowUnrecognizedParameters()
    {
        return $this->allowUnrecognizedParams;
    }

    /**
     * @return string[]|null
     */
    protected function allowedIncludePaths()
    {
        return $this->allowedIncludePaths;
    }

    /**
     * @return array|null
     */
    protected function allowedFieldSetTypes()
    {
        return $this->allowedFieldSetTypes;
    }

    /**
     * @return string[]|null
     */
    protected function allowedSortParameters()
    {
        return $this->allowedSortParameters;
    }

    /**
     * @return string[]|null
     */
    protected function allowedFilteringParameters()
    {
        return $this->allowedFilteringParameters;
    }

    /**
     * @return string[]|null
     */
    protected function allowedPagingParameters()
    {
        /** @var PageParameterHandlerInterface $param */
        $param = app(PageParameterHandlerInterface::class);

        return $param->getAllowedPagingParameters();
    }

}
