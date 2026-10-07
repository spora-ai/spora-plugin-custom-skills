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
 * The skill section of the host palette.
 *
 * It lives here rather than in core because a hit needs somewhere to open. Core has no
 * skills page, so its provider could only ever build a link into a plugin's app — and it
 * built that link from the skill's own `source`, which meant no href at all for every
 * skill core itself ships, since `filesystem` registers no app. This app has both
 * surfaces, a writable desk and a read-only catalogue viewer, so every hit it returns
 * is openable.
 *
 * Reads through {@see SkillProviderRegistry} rather than querying skills directly, so
 * shipped and custom skills appear in one list with no work from the other providers.
 * Scope is structural: it iterates {@see SearchContext::principalIds()} and nothing
 * else, so no branch here can be edited into widening.
 */
final readonly class CustomSkillSearchProvider implements SearchProviderInterface
{
    /** The palette shows a bounded list; a skill install is not a document set. */
    private const MAX_HITS = 20;

    private string $appSlug;

    public function __construct(private SkillProviderRegistry $skills)
    {
        // Read from the app rather than repeated as a literal: the host resolves
        // `/apps/<name>` from `CustomSkillsApp::name()`, so a second copy of the
        // slug here is a link that 404s the day the app is renamed.
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

                // A shipped skill resolves to the same summary for every principal,
                // because the filesystem provider ignores the principal. Without this
                // the loop emits one identical hit per visible principal, and each
                // duplicate also spends a slot of `MAX_HITS`. Keyed `source::name`,
                // the same key `SkillController::index()` keys its own loop, so the
                // two endpoints cannot disagree about what exists.
                $key = $summary->source . '::' . $summary->name;
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
     * Lower is better; null means no match.
     *
     * The tiers are ordered best-to-worst and the first match wins, so the order
     * here *is* the ranking. Name matches outrank description matches because
     * prose is weak evidence: without the split every descriptive word outranks
     * the one skill actually typed.
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
     * Route for a skill. Never null — both branches are pages this app actually has.
     *
     * The branch is on ownership, not on the skill's source being ours to guess: a
     * skill this plugin owns is principal-scoped and writable, so it opens on the
     * desk; anything else — a shipped `filesystem` skill, or another plugin's — is
     * read-only content, and the desk would render it under a scope bar announcing
     * a principal it does not have. The kind is in the path because the host names
     * it there, which keeps this a prefix map with no lookup to await; the frontend
     * parses the two prefixes itself, as the host router registers no child route.
     *
     * **The principal is in the path, on both branches.** A skill belongs to exactly
     * one principal (`unique(principal_id, name)`), so a href naming only a skill
     * cannot say whose it is — and the panel resolves an absent principal to the
     * *caller's own* rather than refusing. That silent default is what made a
     * group's skill arrive as an unopenable "No skill named … on this principal": the
     * hit was real, the link was not wrong-looking, and the read behind it went to
     * the wrong scope. `$principalId` is the principal whose `getSkills()` produced
     * this summary, so for an owned skill it is the owner.
     *
     * It rides on the `library` branch too, where it is the *acting* scope rather
     * than the owner: a shipped skill belongs to no principal, but the panel's
     * Duplicate writes a copy onto whatever principal is selected, so a viewer link
     * that dropped it would fork onto whichever principal the next reload defaulted
     * to.
     *
     * Path segments rather than a query parameter, which is what core's provider
     * emitted first: browser back/forward, a hard refresh and a pasted link all
     * carry a path and none of them carry a query parameter on an app route.
     * `rawurlencode` because a skill name is user-supplied and a name containing
     * `/` or a space would otherwise forge a different path.
     */
    private function hrefFor(SkillSummary $summary, int $principalId): string
    {
        $route = $summary->source === CustomSkillProvider::SOURCE ? 'skill' : 'library';

        return '/apps/' . rawurlencode($this->appSlug)
            . '/p/' . $principalId
            . '/' . $route . '/' . rawurlencode($summary->name);
    }
}
