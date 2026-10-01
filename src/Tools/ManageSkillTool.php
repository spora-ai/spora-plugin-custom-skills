<?php

declare(strict_types=1);

namespace Spora\Plugins\CustomSkills\Tools;

use Spora\Plugins\CustomSkills\Exceptions\CustomSkillException;
use Spora\Plugins\CustomSkills\Models\CustomSkill;
use Spora\Plugins\CustomSkills\Services\CustomSkillWriterInterface;
use Spora\Services\PrincipalContext;
use Spora\Services\PrincipalResolver;
use Spora\Tools\AbstractTool;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Lets an agent author, revise and delete its own principal's skills.
 *
 * Write-only by design: reading is already gated by `allowed_skills` and the
 * provider's own scoping, and a second read tool would be one more way for a
 * model to see a skill it was not granted. `delete` ships
 * `enabledByDefault: false` and every operation is approval-gated, following
 * the `write_notes_overwrite` precedent.
 *
 * `$userId` is never used for scoping — in the tool-execute path that is the
 * runner, not the principal, so it would write a group agent's skills onto
 * whichever member triggered the run.
 */
#[Tool(
    name: 'manage_skill',
    description: 'Author, revise and delete the skills available to this principal. Use it to capture a repeatable procedure as a skill the agent can load later. Reading skills is a separate concern: the `skill` tool already serves the ones this agent is allowed to use.',
    displayName: 'Manage Skill',
    category: 'agent',
    icon: 'sparkles',
)]
#[ToolOperation(
    name: 'create',
    description: 'Create a new skill on this principal. Fails if the name is taken. Requires `name`, `description` and `body`.',
    operatorDescription: 'Create a custom skill',
    enabledByDefault: true,
    requiresApprovalByDefault: true,
)]
#[ToolOperation(
    name: 'update',
    description: 'Replace an existing skill. Fails if the name is unknown, and refuses to rename — the name is the key every agent\'s allowed_skills refers to. `files` replaces the whole sidecar set.',
    operatorDescription: 'Update a custom skill',
    enabledByDefault: true,
    requiresApprovalByDefault: true,
)]
#[ToolOperation(
    name: 'delete',
    description: 'Delete a skill and remove it from the allowed_skills of every agent on this principal. Irreversible unless the skill has a previous version to restore.',
    operatorDescription: 'Delete a custom skill',
    enabledByDefault: false,
    requiresApprovalByDefault: true,
)]
#[ToolParameter(name: 'name', type: 'string', description: 'Skill name: lowercase alphanumeric and hyphens, 1-64 chars, no leading, trailing or consecutive hyphen. Immutable once created.', required: ['create', 'update', 'delete'])]
#[ToolParameter(name: 'description', type: 'string', description: 'One line, at most 1024 characters. This is the text the agent sees when choosing a skill, so make it say when to reach for it.', required: ['create', 'update'])]
#[ToolParameter(name: 'body', type: 'string', description: 'The skill itself, as markdown. The procedure, the constraints, the examples. This is loaded into context in full, so be specific rather than exhaustive.', required: ['create', 'update'])]
#[ToolParameter(name: 'license', type: 'string', description: 'License identifier, e.g. "MIT".', required: false)]
#[ToolParameter(name: 'compatibility', type: 'string', description: 'Version constraint the skill applies to, e.g. "spora>=0.29".', required: false)]
#[ToolParameter(name: 'allowed_tools', type: 'string', description: 'Comma-separated tool names the skill needs, e.g. "read_email, send_email".', required: false)]
#[ToolParameter(name: 'metadata', type: 'object', description: 'Extra frontmatter as scalar key/value pairs.', required: false)]
#[ToolParameter(name: 'files', type: 'object', description: 'Sidecar files as path => content. Replaces the entire existing set, so include the files that should survive. Not needed for a SKILL.md-only skill.', required: false)]
final class ManageSkillTool extends AbstractTool
{
    public function __construct(
        private readonly CustomSkillWriterInterface $writer,
        private readonly PrincipalResolver $principals,
    ) {}

    public function execute(
        array $arguments,
        int $agentId,
        ?int $userId = null,
        ?int $taskId = null,
        ?PrincipalContext $context = null,
    ): ToolResult {
        $operation = (string) ($arguments['action'] ?? '');
        $name = trim((string) ($arguments['name'] ?? ''));

        try {
            $principalId = $this->principalId($agentId, $context);

            return match ($operation) {
                'create' => $this->create($principalId, $userId, $arguments, $name),
                'update' => $this->update($principalId, $userId, $arguments, $name),
                'delete' => $this->delete($principalId, $userId, $name),
                default => new ToolResult(false, "Invalid action '{$operation}'. Must be create, update, or delete."),
            };
        } catch (CustomSkillException $e) {
            return new ToolResult(false, "Error ({$e->errorCode}): {$e->getMessage()}");
        }
    }

    /**
     * The approval card and the timeline row. Never includes the body: up to
     * 200 KB of markdown is unreadable in a prompt, so byte counts stand in.
     *
     * A `delete` cannot state how many allowlists it will rewrite:
     * `describeAction()` gets the LLM's arguments and nothing else, so the only
     * principal id reachable is one the model supplied, and a count derived
     * from it would be unauthenticated and a cross-tenant disclosure. The blast
     * radius is surfaced instead from the two places that know the principal —
     * `GET …/{name}/allowlist` and the `delete` result.
     */
    public function describeAction(array $arguments): string
    {
        $operation = (string) ($arguments['action'] ?? '');
        $name = (string) ($arguments['name'] ?? '(unnamed)');
        $files = is_array($arguments['files'] ?? null) ? count($arguments['files']) : 0;

        if ($operation === 'delete') {
            return "Skill delete: {$name} — also removes it from the allowed_skills of any agent that references it";
        }

        $bytes = strlen((string) ($arguments['body'] ?? ''));

        return "Skill {$operation}: {$name} ({$bytes}B body"
            . ($files > 0 ? ", {$files} file(s)" : '')
            . ')';
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function create(int $principalId, ?int $userId, array $arguments, string $name): ToolResult
    {
        if ($name === '') {
            return new ToolResult(false, 'Error (VALIDATION_ERROR): name is required.');
        }

        $skill = $this->writer->create(
            $principalId,
            $userId ?? 0,
            $this->payload($arguments, $name),
            CustomSkill::PROVENANCE_AGENT,
        );

        return new ToolResult(true, "Created skill [{$skill->name}]. "
            . "Add it to an agent's `allowed_skills` before the agent can load it — "
            . 'authoring it does not grant it.');
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function update(int $principalId, ?int $userId, array $arguments, string $name): ToolResult
    {
        if ($name === '') {
            return new ToolResult(false, 'Error (VALIDATION_ERROR): name is required.');
        }

        $skill = $this->writer->update(
            $name,
            $principalId,
            $userId ?? 0,
            $this->payload($arguments, null),
            CustomSkill::PROVENANCE_AGENT,
        );

        return new ToolResult(true, "Updated skill [{$skill->name}]. The previous version is one `restore` away.");
    }

    private function delete(int $principalId, ?int $userId, string $name): ToolResult
    {
        if ($name === '') {
            return new ToolResult(false, 'Error (VALIDATION_ERROR): name is required.');
        }

        $touched = $this->writer->delete($name, $principalId, $userId ?? 0);

        $agents = array_values(array_filter($touched, static fn(array $t): bool => $t['scope'] === 'agent'));
        $detail = $agents === []
            ? 'No agent allowlists referenced it.'
            : 'Removed from the allowed_skills of: ' . implode(
                ', ',
                array_map(static fn(array $t): string => sprintf('%s (#%d)', $t['name'] ?? '?', $t['id']), $agents),
            ) . '.';

        return new ToolResult(true, "Deleted skill [{$name}]. {$detail}");
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function payload(array $arguments, ?string $name): array
    {
        $payload = $arguments;

        // `action` is the discriminator, not content: the persistence allowlist
        // would silently drop it, which hides that the allowlist is doing work.
        unset($payload['action']);

        if ($name !== null) {
            $payload['name'] = $name;
        }

        // Never honoured — the principal comes from the execution context
        // precisely so a model cannot redirect its write to another tenant.
        unset($payload['principal_id']);

        return $payload;
    }

    /**
     * `$context` when the host resolved one, else the agent's own principal.
     * Both sentinels are rejected: `0` for a missing agent, and a dangling
     * non-zero id for a missing principal row, which only `<= 0` misses and
     * which would otherwise surface as a foreign-key 500.
     */
    private function principalId(int $agentId, ?PrincipalContext $context): int
    {
        $principalId = $context !== null
            ? $context->principalId
            : $this->principals->resolveForToolExecute($agentId)->principalId;

        if ($principalId <= 0) {
            throw CustomSkillException::validation(
                'Cannot resolve a principal for this agent, so no skill can be written. '
                . 'This usually means the agent\'s principal row is missing.',
            );
        }

        return $principalId;
    }
}
