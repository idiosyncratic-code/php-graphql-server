<?php

declare(strict_types=1);

namespace Idiosyncratic\GraphQL\Server;

use GraphQL\Error\Error;
use GraphQL\Error\FormattedError;
use GraphQL\Error\InvariantViolation;
use GraphQL\Executor\ExecutionResult;
use GraphQL\Executor\Promise\Adapter\SyncPromiseAdapter;
use GraphQL\Executor\Promise\Promise;
use GraphQL\Executor\Promise\PromiseAdapter;
use GraphQL\GraphQL;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\Parser;
use GraphQL\Server\OperationParams;
use GraphQL\Server\RequestError;
use GraphQL\Utils\AST;
use GraphQL\Utils\Utils;

use function array_is_list;
use function array_map;
use function assert;
use function is_array;
use function is_callable;
use function is_string;
use function sprintf;

final class Operator
{
    public function __construct(
        private readonly ServerConfig $config,
    ) {
    }

    /**
     * @param OperationParams|array<OperationParams> $operations
     *
     * @return ExecutionResult|array<int, ExecutionResult>
     */
    public function execute(
        mixed $context,
        OperationParams|array $operations,
    ) : ExecutionResult|array {
        if (is_array($operations)) {
            return $this->executeBatch($context, $operations);
        }

        return $this->executeOperation($context, $operations);
    }

    /**
     * @param array<OperationParams> $operations
     *
     * @return array<int, ExecutionResult>
     */
    private function executeBatch(
        mixed $context,
        array $operations,
    ) : array {
        $promiseAdapter = new SyncPromiseAdapter();

        $result = [];

        foreach ($operations as $operation) {
            $result[] = $this->promiseToExecuteOperation($promiseAdapter, $this->config, $operation, true);
        }

        $result = $promiseAdapter->all($result);

        $result = $promiseAdapter->wait($result);

        assert(is_array($result) && array_is_list($result));

        return $result;
    }

    private function executeOperation(
        mixed $context,
        OperationParams $op,
    ) : ExecutionResult {
        $promiseAdapter = new SyncPromiseAdapter();

        $result = $this->promiseToExecuteOperation($promiseAdapter, $this->config, $op);

        $result = $promiseAdapter->wait($result);

        assert($result instanceof ExecutionResult);

        return $result;
    }

    private function promiseToExecuteOperation(
        PromiseAdapter $promiseAdapter,
        mixed $context,
        OperationParams $op,
        bool $isBatch = false,
    ) : Promise {
        try {
            if ($this->config->getSchema() === null) {
                throw new InvariantViolation('Schema is required for the server');
            }

            if ($isBatch && ! $this->config->getQueryBatching()) {
                throw new RequestError('Batched queries are not supported by this server');
            }

            $errors = $this->validateOperationParams($op);

            if ($errors !== []) {
                $locatedErrors = array_map(
                    [Error::class, 'createLocatedError'],
                    $errors,
                );

                return $promiseAdapter->createFulfilled(
                    new ExecutionResult(null, $locatedErrors),
                );
            }

            $doc = $op->queryId !== null
                ? $this->loadPersistedQuery($op)
                : $op->query;

            if (! $doc instanceof DocumentNode) {
                // @phpstan-ignore argument.type
                $doc = Parser::parse($doc);
            }

            // @phpstan-ignore argument.type
            $operationAST = AST::getOperationAST($doc, $op->operation);

            if ($operationAST === null) {
                throw new RequestError('Failed to determine operation type');
            }

            $operationType = $operationAST->operation;
            if ($operationType !== 'query' && $op->readOnly) {
                throw new RequestError('GET supports only query operation');
            }

            $result = GraphQL::promiseToExecute(
                $promiseAdapter,
                $this->config->getSchema(),
                $doc,
                $this->resolveRootValue($op, $doc, $operationType),
                $this->resolveContextValue($context, $op, $doc, $operationType),
                // @phpstan-ignore argument.type
                $op->variables,
                // @phpstan-ignore argument.type
                $op->operation,
                $this->config->getFieldResolver(),
                // @phpstan-ignore argument.type
                $this->resolveValidationRules($op, $doc, $operationType),
            );
        } catch (RequestError $e) {
            $result = $promiseAdapter->createFulfilled(
                new ExecutionResult(null, [Error::createLocatedError($e)]),
            );
        } catch (Error $e) {
            $result = $promiseAdapter->createFulfilled(
                new ExecutionResult(null, [$e]),
            );
        }

        $config = $this->config;

        $applyErrorHandling = static function (ExecutionResult $result) use ($config) : ExecutionResult {
            $result->setErrorsHandler($config->getErrorsHandler());

            $result->setErrorFormatter(
                FormattedError::prepareFormatter(
                    $config->getErrorFormatter(),
                    $config->getDebugFlag(),
                ),
            );

            return $result;
        };

        return $result->then($applyErrorHandling);
    }

    /**
     * Checks validity of OperationParams extracted from HTTP request and returns an array of errors
     * if params are invalid (or empty array when params are valid).
     *
     * @return array<int, RequestError>
     */
    private function validateOperationParams(
        OperationParams $params,
    ) : array {
        $errors = [];
        $query = $params->query ?? '';
        $queryId = $params->queryId ?? '';
        if ($query === '' && $queryId === '') {
            $errors[] = new RequestError('GraphQL Request must include at least one of those two parameters: "query" or "queryId"');
        }

        if (! is_string($query)) {
            $errors[] = new RequestError(
                'GraphQL Request parameter "query" must be string, but got '
                . Utils::printSafeJson($params->query),
            );
        }

        if (! is_string($queryId)) {
            $errors[] = new RequestError(
                'GraphQL Request parameter "queryId" must be string, but got '
                . Utils::printSafeJson($params->queryId),
            );
        }

        if ($params->operation !== null && ! is_string($params->operation)) {
            $errors[] = new RequestError(
                'GraphQL Request parameter "operation" must be string, but got '
                . Utils::printSafeJson($params->operation),
            );
        }

        if (
            $params->variables !== null &&
            (
                ! is_array($params->variables) ||
                isset($params->variables[0])
            )
        ) {
            $errors[] = new RequestError(
                'GraphQL Request parameter "variables" must be object or JSON string parsed to object, but got '
                . Utils::printSafeJson($params->originalInput['variables']),
            );
        }

        return $errors;
    }

    /** @throws RequestError */
    private function loadPersistedQuery(
        OperationParams $operationParams,
    ) : mixed {
        $loader = $this->config->getPersistedQueryLoader();

        if ($loader === null) {
            throw new RequestError('Persisted queries are not supported by this server');
        }

        // @phpstan-ignore argument.type
        $source = $loader($operationParams->queryId, $operationParams);

        if (! is_string($source) && ! $source instanceof DocumentNode) {
            $documentNode = DocumentNode::class;
            $safeSource = Utils::printSafe($source);

            throw new InvariantViolation(sprintf(
                'Persisted query loader must return query string or instance of %s but got: %s',
                $documentNode,
                $safeSource,
            ));
        }

        return $source;
    }

    private function resolveRootValue(
        OperationParams $params,
        DocumentNode $doc,
        string $operationType,
    ) : mixed {
        $rootValue = $this->config->getRootValue();

        if (is_callable($rootValue)) {
            $rootValue = $rootValue($params, $doc, $operationType);
        }

        return $rootValue;
    }

    /** @return mixed user defined */
    private function resolveContextValue(
        mixed $context,
        OperationParams $params,
        DocumentNode $doc,
        string $operationType,
    ) : mixed {
        if (is_callable($context)) {
            $context = $context($params, $doc, $operationType);
        }

        return $context;
    }

    /** @return array<mixed>|null */
    private function resolveValidationRules(
        OperationParams $params,
        DocumentNode $doc,
        string $operationType,
    ) : array|null {
        $validationRules = $this->config->getValidationRules();

        if (is_callable($validationRules)) {
            $validationRules = $validationRules($params, $doc, $operationType);
        }

        if ($validationRules !== null && ! is_array($validationRules)) {
            $safeValidationRules = Utils::printSafe($validationRules);

            throw new InvariantViolation(sprintf(
                'Expecting validation rules to be array or callable returning array, but got: %s',
                $safeValidationRules,
            ));
        }

        return $validationRules;
    }
}
