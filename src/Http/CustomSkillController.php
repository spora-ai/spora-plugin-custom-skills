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
 * Read and write resolve the target principal through different gates (D10). A
 * custom skill is instructions the LLM will follow, so reading a group's needs
 * only membership while authoring into it needs owner/admin. One gate for both
 * would either let any member rewrite a group's agent behaviour or hide the
 * group's skills from the members who run its agents.
 *
 * An unreadable principal yields an empty list on `index()` and 404 elsewhere
 * rather than 403: "forbidden" would confirm the principal exists. Writes say
 * 403, where the caller already knows what they asked to change.
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

    /**
     * GET /api/v1/custom-skills
     */
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

    /**
     * GET /api/v1/custom-skills/{name}
     */
    public function show(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): JsonResponse {
            $skill = $this->findVisible($request);

            return new JsonResponse(['data' => ['skill' => $this->resource->toArray($skill)]]);
        });
    }

    /**
     * GET /api/v1/custom-skills/{name}/files
     */
    public function files(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): JsonResponse {
            $skill = $this->findVisible($request);

            return new JsonResponse(['data' => ['files' => $this->query->fileListing($skill)]]);
        });
    }

    /**
     * GET /api/v1/custom-skills/{name}/files/{path} — a nested path arrives
     * percent-encoded and is decoded by the router.
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
     * GET /api/v1/custom-skills/{name}/allowlist — the D11 blast radius, exactly
     * the rows the scrubber rewrites on delete, so the confirmation dialog can
     * show the multi-agent config change before it happens.
     */
    public function allowlist(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): JsonResponse {
            $principalId = $this->requireReadablePrincipal($request);
            $name = $this->requestedName($request);

            return new JsonResponse(['data' => ['agents' => $this->allowlist->forSkill($name, $principalId)]]);
        });
    }

    /**
     * POST /api/v1/custom-skills
     */
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

    /**
     * PUT /api/v1/custom-skills/{name}
     */
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

    /**
     * POST /api/v1/custom-skills/{name}/restore
     */
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
     * DELETE /api/v1/custom-skills/{name} — returns the rows the delete rewrote
     * so the caller can name the agents that lost the skill.
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

    /** One error translation for every route. */
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

    /** The principal this request may read, or null when it named one it cannot see. */
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

    /**
     * The principal this request may write to. Throws 403 otherwise.
     */
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
     * `?principal_id=` as a positive int, or null when absent or malformed. A
     * non-positive value is treated as absent rather than as an attack — the
     * own-principal fallback is the safer of the two readings.
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
            // One answer for "no such skill" and "not yours" — a 403 would
            // confirm the name exists on a principal the caller cannot read.
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
