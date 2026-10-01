<?php

declare(strict_types=1);

namespace Spora\Plugins\CustomSkills\Services;

use Spora\Plugins\CustomSkills\Models\CustomSkill;
use Spora\Plugins\CustomSkills\Models\CustomSkillFile;
use Spora\Skills\SkillProviderInterface;
use Spora\Skills\SkillValidator;

final class CustomSkillQuery implements CustomSkillQueryInterface
{
    public function __construct(
        private readonly SkillComposer $composer,
        private readonly SkillValidator $validator,
    ) {}

    /**
     * @return list<CustomSkill>
     */
    public function listForPrincipal(int $principalId): array
    {
        if ($principalId <= 0) {
            return [];
        }

        /** @var list<CustomSkill> $rows */
        $rows = CustomSkill::query()
            ->forPrincipal($principalId)
            ->with('files')
            ->orderBy('name')
            ->get()
            ->all();

        return $rows;
    }

    public function findForPrincipal(string $name, int $principalId): ?CustomSkill
    {
        if ($principalId <= 0 || $name === '') {
            return null;
        }

        /** @var CustomSkill|null $row */
        $row = CustomSkill::query()
            ->forPrincipal($principalId)
            ->where('name', $name)
            ->with('files')
            ->first();

        return $row;
    }

    /**
     * @return list<array{path: string, bytes: int}>
     */
    public function fileListing(CustomSkill $skill): array
    {
        $out = [[
            'path' => SkillComposer::ENTRY_FILE,
            'bytes' => $this->composer->entryBytes($skill),
        ]];

        foreach ($this->sidecars($skill) as $file) {
            $out[] = ['path' => $file->path, 'bytes' => $file->bytes];
        }

        return $out;
    }

    public function fileContent(CustomSkill $skill, string $path): ?string
    {
        if ($path === SkillComposer::ENTRY_FILE) {
            return $this->composer->compose($skill);
        }

        $file = $this->sidecarFor($skill, $path);
        if ($file === null) {
            return null;
        }

        // Checked from the stored byte count, before `content` is read: measuring a
        // `longText` blob means materialising it, the cost the cap exists to avoid. The
        // caller re-asserts the cap on what comes back.
        if ($file->bytes > SkillProviderInterface::MAX_FILE_BYTES) {
            return null;
        }

        return $file->content;
    }

    /**
     * @return list<array{code: string, severity: string, message: string, path?: string}>
     */
    public function warnings(CustomSkill $skill): array
    {
        return $this->validator
            ->validate($this->composer->frontmatter($skill), $skill->body, $skill->name)
            ->warnings();
    }

    /**
     * @return list<CustomSkillFile>
     */
    private function sidecars(CustomSkill $skill): array
    {
        $relation = $skill->relationLoaded('files')
            ? $skill->getRelation('files')
            : $skill->files()->orderBy('path')->get();

        $out = $relation instanceof \Illuminate\Support\Collection
            ? $relation->all()
            : iterator_to_array($relation, false);

        usort($out, static fn(CustomSkillFile $a, CustomSkillFile $b): int => strcmp($a->path, $b->path));

        return array_values(array_filter($out, static fn(mixed $f): bool => $f instanceof CustomSkillFile));
    }

    private function sidecarFor(CustomSkill $skill, string $path): ?CustomSkillFile
    {
        foreach ($this->sidecars($skill) as $file) {
            if ($file->path === $path) {
                return $file;
            }
        }

        return null;
    }

}
