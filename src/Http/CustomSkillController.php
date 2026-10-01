<?php

declare(strict_types=1);

namespace Spora\Plugins\CustomSkills\Http;

use JsonException;
use Spora\Auth\AuthService;
use Spora\Plugins\CustomSkills\Exceptions\CustomSkillException;
use Spora\Plugins\CustomSkills\Models\CustomSkill;
use Spora\Plugins\CustomSkills\Services\CustomSkillQueryInterface;
use Spora\Plugins\CustomSkills\Services\CustomSkillWriterInterface;
use Spora\Plugins\CustomSkills\Services\SkillAllowlistReader;
use Spora\Services\PrincipalService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Principal-scoped CRUD for custom skills.
 *
 * Read and write gate the target principal differently (D10): a custom skill is
 * instructions the LLM will follow, so reading a group needs only membership while
 * authoring needs owner/admin — one gate would either let any member rewrite the group's
 * agent behaviour or hide the group's skills from its members.
 *
 * An unreadable principal yields an empty list on `index()` and 404 elsewhere, not 403 —
 * "forbidden" would confirm the principal exists. Writes say 403, where the caller knows
 * the target.
 */
final class CustomSkillController
{
    private ?int $resolvedUserId = null;

    public function __construct(
        private readonly AuthService $auth,
        private readonly PrincipalService $principals,
        private readonly CustomSkillQueryInterface $query,
        private readonly CustomSkillWriterInterface $writer,
        private readonly CustomSkillResource $resource,
        private readonly SkillAllowlistReader $allowlist,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $principalId = $this->readablePrincipalId($request);
        if ($principalId === null) {
            return new JsonResponse(['data' => ['skills' => []]]);
        }

        $skills = array_map(
            fn(CustomSkill $s): array => $this->resource->toArray($s),
            $this->query->listForPrincipal($principalId),
        );

        return new JsonResponse(['data' => ['skills' => $skills]]);
    }

    public function show(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): JsonResponse {
            $skill = $this->findVisible($request);

            return new JsonResponse(['data' => ['skill' => $this->resource->toArray($skill)]]);
        });
    }

    public function files(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): JsonResponse {
            $skill = $this->findVisible($request);

            return new JsonResponse(['data' => ['files' => $this->query->fileListing($skill)]]);
        });
    }

    /**
     * `files/{path}` — a nested path arrives percent-encoded, decoded by the router.
     */
    public function file(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): JsonResponse {
            $skill = $this->findVisible($request);
            $path = (string) $request->attributes->get('path', '');

            $content = $this->query->fileContent($skill, $path);
            if ($content === null) {
                throw CustomSkillException::fileNotFound($path);
            }

            return new JsonResponse(['data' => [
                'path'    => $path,
                'content' => $content,
                'bytes'   => strlen($content),
            ]]);
        });
    }

    /**
     * `allowlist` — the D11 blast radius: exactly the rows a delete rewrites, so the dialog
     * can show the change first.
     */
    public function allowlist(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): JsonResponse {
            $principalId = $this->requireReadablePrincipal($request);
            $name = $this->requestedName($request);

            return new JsonResponse(['data' => ['agents' => $this->allowlist->forSkill($name, $principalId)]]);
        });
    }

    public function store(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): JsonResponse {
            $principalId = $this->writablePrincipalId($request);
            $input = $this->decodeBody($request);

            $skill = $this->writer->create(
                $principalId,
                $this->userId(),
                $input,
                CustomSkill::PROVENANCE_HUMAN,
            );

            return new JsonResponse(
                ['data' => ['skill' => $this->resource->toArray($skill)]],
                Response::HTTP_CREATED,
            );
        });
    }

    public function update(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): JsonResponse {
            $principalId = $this->writablePrincipalId($request);
            $input = $this->decodeBody($request);

            $skill = $this->writer->update(
                $this->requestedName($request),
                $principalId,
                $this->userId(),
                $input,
                CustomSkill::PROVENANCE_HUMAN,
            );

            return new JsonResponse(['data' => ['skill' => $this->resource->toArray($skill)]]);
        });
    }

    public function restore(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): JsonResponse {
            $principalId = $this->writablePrincipalId($request);

            $skill = $this->writer->restore(
                $this->requestedName($request),
                $principalId,
                $this->userId(),
                CustomSkill::PROVENANCE_HUMAN,
            );

            return new JsonResponse(['data' => ['skill' => $this->resource->toArray($skill)]]);
        });
    }

    /**
     * `destroy` — returns the rows the delete rewrote, so the caller can name who lost it.
     */
    public function destroy(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): JsonResponse {
            $principalId = $this->writablePrincipalId($request);
            $name = $this->requestedName($request);

            $touched = $this->writer->delete($name, $principalId, $this->userId());

            return new JsonResponse(['data' => [
                'deleted'          => true,
                'name'             => $name,
                'scrubbed_agents'  => array_values(array_filter(
                    $touched,
                    static fn(array $t): bool => $t['scope'] === 'agent',
                )),
                'scrubbed_principal_default' => in_array('principal', array_column($touched, 'scope'), true),
            ]]);
        });
    }

    private function run(callable $handler): JsonResponse
    {
        try {
            return $handler();
        } catch (CustomSkillException $e) {
            $body = ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]];
            if ($e->data !== []) {
                $body['data'] = $e->data;
            }

            return new JsonResponse($body, $e->status);
        }
    }

    private function readablePrincipalId(Request $request): ?int
    {
        $requested = $this->requestedPrincipalId($request);
        if ($requested === null) {
            return $this->ownPrincipalId();
        }

        if (!in_array($requested, $this->principals->visiblePrincipalIdsFor($this->userId()), true)) {
            return null;
        }

        return $requested;
    }

    private function requireReadablePrincipal(Request $request): int
    {
        $principalId = $this->readablePrincipalId($request);
        if ($principalId === null) {
            throw CustomSkillException::notFound($this->requestedName($request));
        }

        return $principalId;
    }

    private function writablePrincipalId(Request $request): int
    {
        $requested = $this->requestedPrincipalId($request);
        if ($requested === null) {
            return $this->ownPrincipalId();
        }

        if (!$this->principals->callerControlsPrincipal($this->userId(), $requested)) {
            throw CustomSkillException::forbidden(
                'Writing to a group principal requires group owner or admin rights.',
            );
        }

        return $requested;
    }

    private function ownPrincipalId(): int
    {
        return (int) $this->principals->ensureUserPrincipal($this->userId())->id;
    }

    private function userId(): int
    {
        if ($this->resolvedUserId !== null) {
            return $this->resolvedUserId;
        }

        $userId = $this->auth->currentUserId();
        if ($userId === null) {
            throw CustomSkillException::forbidden('Authenticated user required.');
        }

        return $this->resolvedUserId = $userId;
    }

    /**
     * `?principal_id=` as a positive int, or null. Non-positive is read as absent, not as
     * an attack: the own-principal fallback is the safer of the two readings.
     */
    private function requestedPrincipalId(Request $request): ?int
    {
        $raw = $request->query->get('principal_id');
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return null;
        }

        $value = (int) $raw;

        return $value > 0 ? $value : null;
    }

    private function requestedName(Request $request): string
    {
        $name = trim((string) $request->attributes->get('name', ''));
        if ($name === '') {
            throw CustomSkillException::notFound('');
        }

        return $name;
    }

    private function findVisible(Request $request): CustomSkill
    {
        $name = $this->requestedName($request);
        $principalId = $this->requireReadablePrincipal($request);

        $skill = $this->query->findForPrincipal($name, $principalId);
        if ($skill === null) {
            // One answer for "no such skill" and "not yours": 403 would confirm the name exists.
            throw CustomSkillException::notFound($name);
        }

        return $skill;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(Request $request): array
    {
        $content = $request->getContent();
        if ($content === '') {
            return [];
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw CustomSkillException::validation('Request body must be valid JSON.');
        }

        return is_array($decoded) ? $decoded : [];
    }
}
