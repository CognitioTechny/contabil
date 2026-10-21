<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Infrastructure\CodeIgniter\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use CognitioTechny\Contabil\Application\Chart\ChartImportException;
use CognitioTechny\Contabil\Application\Ports\AuthorizerInterface;
use CognitioTechny\Contabil\Application\Ports\CurrentEntityInterface;
use CognitioTechny\Contabil\Domain\Exception\AccessDeniedException;
use CognitioTechny\Contabil\Domain\Exception\ContabilException;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;
use CognitioTechny\Contabil\Infrastructure\CodeIgniter\Http\ExceptionStatusMap;
use CognitioTechny\Contabil\Infrastructure\CodeIgniter\UseCases;
use JsonException;
use ReflectionClass;

/**
 * Shared plumbing of the API controllers: entity resolution, authorization,
 * the JSON envelope and the mapping of domain exceptions to HTTP.
 *
 * Success is `{"data": ...}`; failure is `{"error": {"type", "message", "details"?}}`.
 */
abstract class ApiController extends Controller
{
    /**
     * Runs an action for the current entity once the permission is granted.
     *
     * @param callable(int): mixed $action Returns the data to send, or a ready response.
     */
    protected function handle(string $permission, callable $action, int $status = 200): ResponseInterface
    {
        try {
            $entityId = $this->resolveEntityId();

            if (! $this->authorizer()->can($permission, $entityId)) {
                throw new AccessDeniedException(sprintf('The permission "%s" is required.', $permission));
            }

            if ($entityId === null) {
                throw new ValidationException('No entity is selected; pass entity_id.');
            }

            $result = $action($entityId);

            return $result instanceof ResponseInterface ? $result : $this->json(['data' => $result], $status);
        } catch (ContabilException $e) {
            $error = ['type' => (new ReflectionClass($e))->getShortName(), 'message' => $e->getMessage()];

            if ($e instanceof ChartImportException) {
                $error['details'] = $e->errors();
            }

            return $this->json(['error' => $error], ExceptionStatusMap::statusFor($e));
        }
    }

    protected function useCases(): UseCases
    {
        return service('contabil');
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function json(array $payload, int $status = 200): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON($payload);
    }

    /**
     * The JSON body of the request as an array (empty when there is none).
     *
     * @return array<string, mixed>
     */
    protected function jsonBody(): array
    {
        $raw = trim((string) $this->request->getBody());

        if ($raw === '') {
            return [];
        }

        try {
            $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ValidationException('The request body is not valid JSON.');
        }

        if (! is_array($data) || array_is_list($data) && $data !== []) {
            throw new ValidationException('The request body must be a JSON object.');
        }

        return $data;
    }

    private function authorizer(): AuthorizerInterface
    {
        return service('contabilAuthorizer');
    }

    private function resolveEntityId(): ?int
    {
        /** @var CurrentEntityInterface $current */
        $current = service('contabilCurrentEntity');
        $id      = $current->id();

        // The host's current entity wins; the explicit parameter is only a fallback.
        if ($id === null) {
            $id = $this->request->getGet('entity_id');
        }

        if ($id === null || $id === '') {
            return null;
        }

        if ((is_int($id) && $id > 0) || (is_string($id) && ctype_digit($id) && (int) $id > 0)) {
            return (int) $id;
        }

        throw new ValidationException('The entity id must be a positive integer.');
    }
}
