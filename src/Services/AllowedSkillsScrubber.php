<?php

declare(strict_types=1);

namespace Spora\Plugins\CustomSkills\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Models\Agent;
use Spora\Services\ToolConfigServiceInterface;
use Spora\Tools\SkillTool;

/**
 * Removes a deleted skill's name from every `allowed_skills` array carrying it for one
 * principal — the only host data this feature changes, so it stays as narrow as the
 * decision allows: the principal default and that principal's agent overrides, never the
 * global `SkillTool` row, which is not principal-owned and so would reach every other
 * tenant's agents. Custom skills only; a shipped one is not deletable here.
 *
 * Writes go through `ToolConfigService`, never the `settings` column: the accessor throws
 * by design, values are encrypted at rest, and `putAgentOverride` merges.
 */
final class AllowedSkillsScrubber implements AllowedSkillsScrubberInterface
{
    public function __construct(private readonly ToolConfigServiceInterface $toolConfig) {}

    /**
     * @return list<array{id: int, name: string|null, scope: 'agent'|'principal'}>
     */
    public function scrub(string $name, int $principalId): array
    {
        if ($name === '' || $principalId <= 0) {
            return [];
        }

        /** @var list<array{id: int, name: string|null, scope: 'agent'|'principal'}> $touched */
        $touched = [];

        $principalSettings = $this->toolConfig->getPrincipalSettings(SkillTool::class, $principalId);
        $remaining = self::withoutName($principalSettings['allowed_skills'] ?? null, $name);
        if ($remaining !== null) {
            $this->toolConfig->putPrincipalSettings(SkillTool::class, $principalId, ['allowed_skills' => $remaining]);
            $touched[] = ['id' => 0, 'name' => null, 'scope' => 'principal'];
        }

        /** @var list<Agent> $agents */
        $agents = Agent::query()->where('principal_id', $principalId)->orderBy('id')->get()->all();

        foreach ($agents as $agent) {
            $agentId = (int) $agent->id;
            $override = $this->toolConfig->getRawAgentOverride(SkillTool::class, $agentId);
            $remaining = self::withoutName($override['allowed_skills'] ?? null, $name);
            if ($remaining === null) {
                continue;
            }
            $this->toolConfig->putAgentOverride(SkillTool::class, $agentId, ['allowed_skills' => $remaining]);
            $touched[] = ['id' => $agentId, 'name' => (string) $agent->name, 'scope' => 'agent'];
        }

        return $touched;
    }

    /**
     * The allowlist without `$name`, or null when unchanged so the caller skips the write.
     *
     * A multi-select round-trips as a JSON-encoded *string* as often as an array, and
     * iterating it undecoded yields nothing — a silent no-op on the rows this exists for.
     *
     * @return list<string>|null
     */
    private static function withoutName(mixed $stored, string $name): ?array
    {
        $entries = self::toNameList($stored);
        if (!in_array($name, $entries, true)) {
            return null;
        }

        return array_values(array_filter($entries, static fn(string $e): bool => $e !== $name));
    }

    /**
     * Coerce a stored `allowed_skills` value to names. Public because
     * {@see SkillAllowlistReader::forSkill()} reads through the same decoder, and a preview
     * that disagrees with the rewrite is worse than none.
     *
     * @return list<string>
     */
    public static function toNameList(mixed $stored): array
    {
        if (is_string($stored)) {
            $decoded = json_decode($stored, true);
            $stored = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($stored)) {
            return [];
        }

        $out = [];
        foreach ($stored as $entry) {
            if (is_string($entry) && $entry !== '') {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * Roll `$operation` back as a unit: a skill must never be deleted while its name survives.
     *
     * @template T
     * @param callable():T $operation
     * @return T
     */
    public function transactionally(callable $operation): mixed
    {
        return Capsule::connection()->transaction($operation);
    }
}
