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
 * The skill section of the host palette. It lives here rather than in core because a hit needs somewhere
 * to open: core built its href from the skill's own `source`, so every skill core ships — `filesystem`
 * registers no app — was a hit the palette could not open. Scope is structural, too.
 */
final readonly class CustomSkillSearchProvider implements SearchProviderInterface
{
    /** The palette shows a bounded list; a skill install is not a document set. */
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
     * The key `search()` folds duplicates on.
     *
     * A shipped or foreign summary stands for every principal — those providers ignore the principal, so
     * the name alone folds them, each duplicate otherwise spending a `MAX_HITS` slot. Keyed as
     * `SkillController::index()` keys its loop, so the endpoints cannot disagree. An OWNED summary is
     * keyed with its principal too, not out of tidiness: `unique(principal_id, name)` permits one name
     * under two principals — two skills, two hrefs, folding drops one.
     */
    private function dedupeKey(SkillSummary $summary, int $principalId): string
    {
        $owned = $summary->source === CustomSkillProvider::SOURCE;

        return $summary->source . '::' . ($owned ? $principalId . '::' : '') . $summary->name;
    }

    /**
     * Lower is better; null means no match. The tiers run best-to-worst and the first match wins, so the
     * order here *is* the ranking — without the split any descriptive word outranks the typed skill.
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
     * Route for a skill. Never null — both branches are pages this app has. The branch is on ownership:
     * an owned skill opens on the writable desk; anything else is read-only, and the desk would render it
     * under a scope bar for a principal it lacks.
     *
     * **The principal means something different on each branch.** On the own-skill branch it is the OWNER
     * — the principal whose `getSkills()` produced this summary — and it is load-bearing:
     * `unique(principal_id, name)` constrains the *pair*, so a href naming only a skill cannot say whose it
     * is, and the panel resolves an absent principal to the *caller's own*.
     *
     * On the `library` branch it is neither an owner nor the panel's scope: shipped providers ignore the
     * principal and `search()` folds them on `source::name`, so the id here is the FIRST principal in
     * `SearchContext`, which `PrincipalResolver::visiblePrincipalIds()` puts as the caller's own user-
     * principal — following such a link *selects* it, a stable scope for a Duplicate but this
     * provider's decision, not the operator's last page. `rawurlencode`: `/` or a space forges a path.
     */
    private function hrefFor(SkillSummary $summary, int $principalId): string
    {
        $route = $summary->source === CustomSkillProvider::SOURCE ? 'skill' : 'library';

        return '/apps/' . rawurlencode($this->appSlug)
            . '/p/' . $principalId
            . '/' . $route . '/' . rawurlencode($summary->name);
    }
}
