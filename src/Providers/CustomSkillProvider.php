<?php

declare(strict_types=1);

namespace Spora\Plugins\CustomSkills\Providers;

use Spora\Plugins\CustomSkills\Models\CustomSkill;
use Spora\Plugins\CustomSkills\Services\CustomSkillQueryInterface;
use Spora\Skills\SkillDescriptor;
use Spora\Skills\SkillProviderInterface;
use Spora\Skills\SkillSummary;

/**
 * Serves database-backed, principal-scoped skills to core's `skill` tool.
 *
 * Every method fails closed on an unresolvable principal. Core calls the
 * provider with `null` on operator-default and strict-mode paths, and
 * `resolveForToolExecute()` also yields a dangling non-zero id for an agent
 * whose principal row is gone — so the guard is `<= 0` *and* a lookup that
 * misses.
 */
final class CustomSkillProvider implements SkillProviderInterface
{
    /** The `source` reported per skill. Also the admin app's grouping key. */
    public const SOURCE = 'custom-skills';

    public function __construct(private readonly CustomSkillQueryInterface $query) {}

    public function source(): string
    {
        return self::SOURCE;
    }

    /**
     * @return list<SkillSummary>
     */
    public function getSkills(?int $principalId): array
    {
        if ($principalId === null || $principalId <= 0) {
            return [];
        }

        $out = [];
        foreach ($this->query->listForPrincipal($principalId) as $skill) {
            $out[] = $this->summaryOf($skill);
        }

        return $out;
    }

    public function getSkillDetails(string $name, ?int $principalId): ?SkillDescriptor
    {
        $skill = $this->resolve($name, $principalId);
        if ($skill === null) {
            return null;
        }

        $warnings = $this->query->warnings($skill);

        return new SkillDescriptor(
            summary: $this->summaryOf($skill, $warnings !== []),
            body: $skill->body,
            compatibility: $skill->compatibility,
            allowedTools: $skill->allowed_tools,
            // The descriptor's `metadata` is a non-nullable `array` while the
            // model's cast reads an unset column as null — a TypeError on the
            // first skill written without it, which is the common case.
            metadata: $skill->metadata ?? [],
            files: $this->query->fileListing($skill),
            warnings: $warnings,
        );
    }

    /**
     * @return list<array{path: string, bytes: int}>|null
     */
    public function getSkillFiles(string $name, ?int $principalId): ?array
    {
        $skill = $this->resolve($name, $principalId);
        if ($skill === null) {
            return null;
        }

        // Always at least the entry file, so a caller can tell "has files" from
        // "does not exist" without a second lookup.
        return $this->query->fileListing($skill);
    }

    public function getSkillFile(string $name, string $path, ?int $principalId): ?string
    {
        // Defence in depth, and cheap: `$path` only reaches a database equality
        // match. A provider holding an attacker-supplied path has no business
        // returning a `../` segment to a caller that may render a file tree.
        if (!self::isSafePath($path)) {
            return null;
        }

        $skill = $this->resolve($name, $principalId);
        if ($skill === null) {
            return null;
        }

        return $this->query->fileContent($skill, $path);
    }

    /**
     * A skill the principal owns, or null. This is the tenant boundary — the
     * provider is the only place that decides visibility, so never a name-only
     * lookup.
     */
    private function resolve(string $name, ?int $principalId): ?CustomSkill
    {
        if ($principalId === null || $principalId <= 0 || $name === '') {
            return null;
        }

        return $this->query->findForPrincipal($name, $principalId);
    }

    private function summaryOf(CustomSkill $skill, ?bool $hasWarnings = null): SkillSummary
    {
        $warnings = $hasWarnings ?? $this->query->warnings($skill) !== [];

        return new SkillSummary(
            name: $skill->name,
            description: $skill->description,
            license: $skill->license,
            source: self::SOURCE,
            // The writer forces `name === slug`, so the URL segment and the
            // frontmatter name cannot drift as they legally can for a
            // filesystem skill.
            slug: $skill->name,
            fileCount: count($this->query->fileListing($skill)),
            hasWarnings: $warnings,
        );
    }

    /**
     * Rejects anything that is not a plain relative path: absolute paths,
     * `.`/`..` and empty segments, backslashes, control characters.
     */
    public static function isSafePath(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\')) {
            return false;
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }
}
