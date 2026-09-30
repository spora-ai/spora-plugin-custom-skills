<?php

declare(strict_types=1);

namespace Spora\Plugins\Skeleton;

use Spora\Services\PrincipalContext;
use Spora\Tools\AbstractTool;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\ValueObjects\ToolResult;

/**
 * Fork-and-go example that ships a tool + skill pair.
 *
 * The bundled skill lives at `skills/companion-skill/SKILL.md` and is
 * loaded by Spora's SkillScanner at boot. Once `#[Tool(recommendsSkills: ...)]`
 * ships in a public spora-core release (gated on
 * {@link https://github.com/spora-ai/spora-core/pull/269}), add the
 * `recommendsSkills: ['companion-skill']` argument here so the operator
 * UI's "Enable skill" affordance picks the skill up automatically.
 */
#[Tool(
    name: 'companion',
    description: 'Demo tool that bundles a skill — fork-and-go example.',
)]
final class CompanionTool extends AbstractTool
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function execute(
        array $arguments,
        int $agentId,
        ?int $userId = null,
        ?int $taskId = null,
        ?PrincipalContext $context = null,
    ): ToolResult {
        return ToolResult::ok(
            content: 'companion ok',
            data: ['status' => 'ok'],
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function describeAction(array $arguments): string
    {
        return 'Companion: bundle-skill demo';
    }
}
