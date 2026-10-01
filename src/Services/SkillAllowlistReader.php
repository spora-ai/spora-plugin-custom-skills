<?php

declare(strict_types=1);

namespace Spora\Plugins\CustomSkills\Services;

use Spora\Models\Agent;
use Spora\Services\ToolConfigServiceInterface;
use Spora\Tools\SkillTool;

/**
 * Reports which `allowed_skills` rows currently reference a skill.
 *
 * Separate from {@see CustomSkillQueryInterface} because of a real dependency cycle: core
 * wires `SkillProviderRegistry` into `ToolConfigService`, so a query service taking
 * `ToolConfigServiceInterface` leaves the container unresolvable —
 *
 *   ToolConfigService → … → CustomSkillProvider → CustomSkillQuery
 *     → ToolConfigServiceInterface → ToolConfigService
 *
 * The provider only needs the skill tables, so the settings-cascade read lives here.
 */
final class SkillAllowlistReader
{
    public function __construct(private readonly ToolConfigServiceInterface $toolConfig) {}

    /**
     * Which agents resolve `$name` via the skill tool's `allowed_skills`, plus whether the
     * principal default carries it — the D11 blast radius. `ToolConfigService` is mandatory:
     * the override accessor throws by design.
     *
     * @return list<array{id: int, name: string|null, scope: 'agent'|'principal'}>
     */
    public function forSkill(string $name, int $principalId): array
    {
        if ($name === '' || $principalId <= 0) {
            return [];
        }

        $out = [];

        $principalSettings = $this->toolConfig->getPrincipalSettings(SkillTool::class, $principalId);
        if (self::allows($principalSettings['allowed_skills'] ?? null, $name)) {
            $out[] = ['id' => 0, 'name' => null, 'scope' => 'principal'];
        }

        /** @var list<Agent> $agents */
        $agents = Agent::query()->where('principal_id', $principalId)->orderBy('id')->get()->all();

        foreach ($agents as $agent) {
            $override = $this->toolConfig->getRawAgentOverride(SkillTool::class, (int) $agent->id);
            if (self::allows($override['allowed_skills'] ?? null, $name)) {
                $out[] = ['id' => (int) $agent->id, 'name' => (string) $agent->name, 'scope' => 'agent'];
            }
        }

        return $out;
    }

    /**
     * Decoded through the scrubber's decoder so preview and rewrite cannot disagree.
     */
    private static function allows(mixed $value, string $name): bool
    {
        return in_array($name, AllowedSkillsScrubber::toNameList($value), true);
    }
}
