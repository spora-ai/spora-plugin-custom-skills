<?php

declare(strict_types=1);

namespace Spora\Plugins\CustomSkills\Providers;

use Spora\Plugins\CustomSkills\CustomSkillsApp;
use Spora\Search\SearchContext;
use Spora\Search\SearchHit;
use Spora\Search\SearchProviderInterface;
use Spora\Skills\SkillProviderRegistry;
use Spora\Skills\SkillSummary;

/**
 * The skill section of the host palette. Here rather than in core because a hit needs somewhere to
 * open: core built its href from the skill's `source`, so every `filesystem` skill — which registers no
 * app — was a hit the palette could not open.
 */
final readonly class CustomSkillSearchProvider implements SearchProviderInterface
{
    private const MAX_HITS = 20;

    private string $appSlug;

    public function __construct(private SkillProviderRegistry $skills)
    {
        // Read from the app rather than repeated: a copied slug 404s on rename.
        $this->appSlug = (new CustomSkillsApp())->name();
    }

    public function type(): string
    {
        return 'skill';
    }

    /**
     * @return list<SearchHit>
     */
    public function search(string $query, SearchContext $context): array
    {
        $needle = mb_strtolower(trim($query));
        if ($needle === '') {
            return [];
        }

        /** @var list<array{int, string, SearchHit}> $scored */
        $scored = [];

        /** @var array<string, true> $seen */
        $seen = [];

        foreach ($context->principalIds() as $principalId) {
            foreach ($this->skills->getSkills($principalId) as $summary) {
                $rank = $this->rank($summary->name, $summary->description, $needle);
                if ($rank === null) {
                    continue;
                }

                $key = $this->dedupeKey($summary, $principalId);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $scored[] = [$rank, $summary->name, new SearchHit(
                    type: $this->type(),
                    id: $summary->name,
                    label: $summary->name,
                    subLabel: $summary->description,
                    badge: $summary->hasWarnings ? '1 warning' : null,
                    href: $this->hrefFor($summary, $principalId),
                )];
            }
        }

        usort(
            $scored,
            static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]],
        );

        return array_slice(array_column($scored, 2), 0, self::MAX_HITS);
    }

    /**
     * The key `search()` folds duplicates on. Shipped and foreign providers ignore the principal, so
     * the name alone folds them — otherwise each duplicate spends a `MAX_HITS` slot. An OWNED summary
     * is keyed with its principal too: `unique(principal_id, name)` permits one name under two
     * principals, and folding those drops one of the two from the palette entirely.
     */
    private function dedupeKey(SkillSummary $summary, int $principalId): string
    {
        $owned = $summary->source === CustomSkillProvider::SOURCE;

        return $summary->source . '::' . ($owned ? $principalId . '::' : '') . $summary->name;
    }

    /**
     * Lower is better; null means no match. The `$tiers` order *is* the ranking — without the
     * name-before-prose split, any descriptive word outranks the skill actually typed.
     */
    private function rank(string $name, string $description, string $needle): ?int
    {
        $subject = mb_strtolower($name);
        $prose = mb_strtolower($description);

        $tiers = [
            $subject === $needle,
            str_starts_with($subject, $needle),
            str_contains($subject, $needle),
            $description !== '' && str_contains($prose, $needle),
        ];

        foreach ($tiers as $rank => $matched) {
            if ($matched) {
                return $rank;
            }
        }

        return null;
    }

    /**
     * Never null — both branches are pages this app has, chosen on ownership: an owned skill opens on
     * the writable desk, anything else is read-only content the desk would render under a scope bar for
     * a principal it lacks. `rawurlencode`: a `/` or space in a name forges a different path.
     *
     * The principal differs per branch. Own-skill: the OWNER, load-bearing because
     * `unique(principal_id, name)` permits one name under two principals and the panel resolves a
     * principal-less URL to the caller's own. Library: neither owner nor the operator's current scope,
     * but the FIRST id in `SearchContext` — which `PrincipalResolver::visiblePrincipalIds()` puts as the
     * caller's own user-principal — so following the link *selects* it.
     */
    private function hrefFor(SkillSummary $summary, int $principalId): string
    {
        $route = $summary->source === CustomSkillProvider::SOURCE ? 'skill' : 'library';

        return '/apps/' . rawurlencode($this->appSlug)
            . '/p/' . $principalId
            . '/' . $route . '/' . rawurlencode($summary->name);
    }
}
