<?php

declare(strict_types=1);

use Spora\Plugins\CustomSkills\CustomSkillsApp;
use Spora\Plugins\CustomSkills\Providers\CustomSkillProvider;
use Spora\Plugins\CustomSkills\Providers\CustomSkillSearchProvider;
use Spora\Search\SearchContext;
use Spora\Skills\SkillDescriptor;
use Spora\Skills\SkillProviderInterface;
use Spora\Skills\SkillProviderRegistry;
use Spora\Skills\SkillSummary;

/**
 * The skill section of the palette, and it moved here from core — so the ranking,
 * folding, scoping and dedup of the provider that was deleted are pinned here too.
 * The cross-tenant assertion is the one this file exists for: search asks every
 * visible principal rather than the one a UI happens to have selected, so a scope
 * bug here leaks instead of mislisting.
 */

/**
 * A provider that answers for one principal, or for every principal at once.
 *
 * `onlyVisibleTo === null` is how core's `FilesystemSkillProvider` behaves: it
 * ignores the principal because operator-authored content is identical for
 * everyone. Stated here rather than with a real filesystem so the dedup is
 * observable from a two-line fixture.
 */
final class SearchStubSkillProvider implements SkillProviderInterface
{
    /** @var list<SkillSummary> */
    private array $summaries = [];

    public ?int $onlyVisibleTo = null;

    public function __construct(private readonly string $source) {}

    /** A skill with this provider's `source` on its summary, unless asked to omit it. */
    public function add(
        string $name,
        ?string $description = null,
        bool $warnings = false,
        bool $unlabelled = false,
    ): self {
        $this->summaries[] = new SkillSummary(
            name: $name,
            description: $description ?? "About {$name}.",
            source: $unlabelled ? null : $this->source,
            hasWarnings: $warnings,
        );

        return $this;
    }

    public function source(): string
    {
        return $this->source;
    }

    /**
     * @return list<SkillSummary>
     */
    public function getSkills(?int $principalId): array
    {
        if ($this->onlyVisibleTo !== null && $this->onlyVisibleTo !== $principalId) {
            return [];
        }

        return $this->summaries;
    }

    public function getSkillDetails(string $name, ?int $principalId): ?SkillDescriptor
    {
        return null;
    }

    /**
     * @return list<array{path: string, bytes: int}>|null
     */
    public function getSkillFiles(string $name, ?int $principalId): ?array
    {
        return null;
    }

    public function getSkillFile(string $name, string $path, ?int $principalId): ?string
    {
        return null;
    }
}

/** @param list<array{0: string}|array{0: string, 1: string}> $skills name + optional description. */
function searchSkills(string $source, ?int $owner, array $skills): SearchStubSkillProvider
{
    $provider = new SearchStubSkillProvider($source);
    foreach ($skills as $skill) {
        $provider->add($skill[0], $skill[1] ?? null);
    }
    $provider->onlyVisibleTo = $owner;

    return $provider;
}

function searchProvider(array $providers): CustomSkillSearchProvider
{
    return new CustomSkillSearchProvider(new SkillProviderRegistry($providers));
}

/** @return list<string> The ids of the hits, in the order the palette would show them. */
function searchIds(CustomSkillSearchProvider $provider, string $query, SearchContext $context): array
{
    return array_map(static fn($hit) => $hit->id, $provider->search($query, $context));
}

const SEARCH_OWNER = 4242;
const SEARCH_STRANGER = 9999;

it('claims the skill palette bucket', function (): void {
    // Core's provider is gone, so this is the only `skill` type — a collision would
    // make the host drop one of the two providers' sections by dedup.
    expect(searchProvider([])->type())->toBe('skill');
});

it('finds a skill belonging to a principal the caller can see', function (): void {
    $provider = searchProvider([
        searchSkills(CustomSkillProvider::SOURCE, SEARCH_OWNER, [['invoice-drafting', 'How to draft an invoice.']]),
    ]);

    $hits = $provider->search('invoice', new SearchContext([SEARCH_OWNER]));

    expect($hits)->toHaveCount(1)
        ->and($hits[0]->type)->toBe('skill')
        ->and($hits[0]->id)->toBe('invoice-drafting')
        ->and($hits[0]->label)->toBe('invoice-drafting')
        ->and($hits[0]->subLabel)->toBe('How to draft an invoice.');
});

it('never returns a skill belonging to a principal the caller cannot see', function (): void {
    $provider = searchProvider([
        searchSkills(CustomSkillProvider::SOURCE, SEARCH_OWNER, [['my-notes', 'Mine.']]),
        searchSkills('studio', SEARCH_STRANGER, [['team-playbook', 'Theirs.']]),
    ]);

    expect(searchIds($provider, 'team', new SearchContext([SEARCH_OWNER])))->toBe([])
        ->and(searchIds($provider, 'playbook', new SearchContext([SEARCH_OWNER])))->toBe([])
        // Sanity: the provider is not simply returning nothing at all.
        ->and(searchIds($provider, 'mine', new SearchContext([SEARCH_OWNER])))->toBe(['my-notes']);
});

it('returns nothing for an empty principal set', function (): void {
    $provider = searchProvider([
        searchSkills(CustomSkillProvider::SOURCE, SEARCH_OWNER, [['invoice-drafting', 'How to draft.']]),
    ]);

    expect($provider->search('invoice', new SearchContext()))->toBe([])
        ->and($provider->search('invoice', new SearchContext([0])))->toBe([]);
});

it('spans every visible principal, not just one', function (): void {
    // An operator looks for a skill whether it is theirs or a group's, which is why
    // the context is a set rather than a nullable id.
    $provider = searchProvider([
        searchSkills(CustomSkillProvider::SOURCE, SEARCH_OWNER, [['mine', 'Personal.']]),
        searchSkills('studio', SEARCH_STRANGER, [['theirs', 'Group.']]),
    ]);

    $ids = searchIds($provider, 'e', new SearchContext([SEARCH_OWNER, SEARCH_STRANGER]));

    expect($ids)->toContain('mine')->toContain('theirs');
});

it('returns one hit per skill when the provider ignores the principal', function (): void {
    // `FilesystemSkillProvider` answers identically for every principal, so the
    // per-principal loop sees the same summary N times. `onlyVisibleTo` stays null
    // here, which is how the stub models that.
    $agnostic = (new SearchStubSkillProvider('filesystem'))->add('typst', 'Typeset documents.');

    $provider = searchProvider([$agnostic]);

    expect(searchIds($provider, 'typst', new SearchContext([SEARCH_OWNER, 4243, 4244])))->toBe(['typst']);
});

it('ranks a name match above a description match', function (): void {
    $provider = searchProvider([
        searchSkills(CustomSkillProvider::SOURCE, SEARCH_OWNER, [
            ['invoice-drafting', 'Nothing relevant.'],
            ['quarterly-close', 'Mentions invoice once.'],
        ]),
    ]);

    expect(searchIds($provider, 'invoice', new SearchContext([SEARCH_OWNER])))
        ->toBe(['invoice-drafting', 'quarterly-close']);
});

it('ranks an exact name above a prefix above a substring', function (): void {
    $provider = searchProvider([
        searchSkills(CustomSkillProvider::SOURCE, SEARCH_OWNER, [['draft'], ['drafting'], ['redraft']]),
    ]);

    expect(searchIds($provider, 'draft', new SearchContext([SEARCH_OWNER])))
        ->toBe(['draft', 'drafting', 'redraft']);
});

it('matches case-insensitively and ignores surrounding whitespace', function (): void {
    $provider = searchProvider([
        searchSkills(CustomSkillProvider::SOURCE, SEARCH_OWNER, [['Invoice-Drafting']]),
    ]);

    expect($provider->search('  INVOICE  ', new SearchContext([SEARCH_OWNER])))->toHaveCount(1);
});

it('returns nothing for an empty query rather than every skill', function (): void {
    $provider = searchProvider([
        searchSkills(CustomSkillProvider::SOURCE, SEARCH_OWNER, [['a'], ['b']]),
    ]);

    expect($provider->search('', new SearchContext([SEARCH_OWNER])))->toBe([])
        ->and($provider->search('   ', new SearchContext([SEARCH_OWNER])))->toBe([]);
});

it('routes a skill this plugin owns to the writable desk', function (): void {
    $provider = searchProvider([
        searchSkills(CustomSkillProvider::SOURCE, SEARCH_OWNER, [['invoice-drafting']]),
    ]);

    expect($provider->search('invoice', new SearchContext([SEARCH_OWNER]))[0]->href)
        ->toBe('/apps/custom-skills/skill/invoice-drafting');
});

it('routes a shipped skill to the catalogue viewer instead of returning no href', function (): void {
    // The whole reason this provider is here. Core's built href from the skill's own
    // source and returned null unless an app was registered under it — and AppRegistry
    // only ever holds plugin apps, so every `filesystem` skill was a hit the palette
    // could list but not open.
    $provider = searchProvider([
        searchSkills('filesystem', SEARCH_OWNER, [['typst', 'Typeset documents.']]),
    ]);

    expect($provider->search('typst', new SearchContext([SEARCH_OWNER]))[0]->href)
        ->toBe('/apps/custom-skills/library/typst');
});

it('routes another plugin\'s skill to the catalogue viewer too', function (): void {
    // Not just `filesystem`: anything this plugin does not own is read-only content,
    // and the desk would render it under a scope bar for a principal it has no
    // relationship with.
    $provider = searchProvider([
        searchSkills('spora-plugin-calendar', SEARCH_OWNER, [['quarterly-planning', 'Plan the quarter.']]),
    ]);

    expect($provider->search('quarterly', new SearchContext([SEARCH_OWNER]))[0]->href)
        ->toBe('/apps/custom-skills/library/quarterly-planning');
});

it('routes a summary carrying no source, rather than handing out no href', function (): void {
    // `SkillSummary::$source` is nullable, and a provider that omits it is not evidence
    // of ownership — so it lands on the viewer, and still gets a link.
    $orphan = new SearchStubSkillProvider('unlabelled');
    $orphan->add('sourceless', 'No source on the summary.', unlabelled: true);

    $provider = searchProvider([$orphan]);

    expect($provider->search('sourceless', new SearchContext([SEARCH_OWNER]))[0]->href)
        ->toBe('/apps/custom-skills/library/sourceless');
});

it('reads the app slug off the app, so a rename cannot strand the links', function (): void {
    $provider = searchProvider([
        searchSkills(CustomSkillProvider::SOURCE, SEARCH_OWNER, [['invoice-drafting']]),
    ]);

    expect($provider->search('invoice', new SearchContext([SEARCH_OWNER]))[0]->href)
        ->toBe('/apps/' . (new CustomSkillsApp())->name() . '/skill/invoice-drafting');
});

it('encodes a name that would otherwise forge a path', function (): void {
    $provider = searchProvider([
        searchSkills(CustomSkillProvider::SOURCE, SEARCH_OWNER, [['invoice drafting', 'Spaces.']]),
        searchSkills('filesystem', SEARCH_OWNER, [['a/b', 'A slash.']]),
    ]);

    $hits = $provider->search('a', new SearchContext([SEARCH_OWNER]));

    expect($hits)->toHaveCount(2)
        // A slash in a name segment would address a different resource entirely.
        ->and($hits[0]->href)->toBe('/apps/custom-skills/library/a%2Fb')
        ->and($hits[1]->href)->toBe('/apps/custom-skills/skill/invoice%20drafting');
});

it('surfaces a warning as a badge', function (): void {
    $noisy = new SearchStubSkillProvider(CustomSkillProvider::SOURCE);
    $noisy->add('noisy', warnings: true);
    $noisy->add('quiet');

    $provider = searchProvider([$noisy]);
    $hits = $provider->search('o', new SearchContext([SEARCH_OWNER]));

    $badges = [];
    foreach ($hits as $hit) {
        $badges[$hit->id] = $hit->badge;
    }

    expect($badges['noisy'])->toBe('1 warning')
        ->and($badges['quiet'])->toBeNull();
});

it('caps the hits it hands the palette', function (): void {
    $many = new SearchStubSkillProvider(CustomSkillProvider::SOURCE);
    for ($i = 0; $i < 40; $i++) {
        $many->add("draft-{$i}");
    }

    $provider = searchProvider([$many]);

    expect($provider->search('draft', new SearchContext([SEARCH_OWNER])))->toHaveCount(20);
});

it('resolves from a container that binds nothing of its own', function (): void {
    // Core merges `searchProviders()` into the palette list and resolves each entry
    // with `$container->get($class)`, so the class has to build from a real container
    // carrying only core's own bindings — which is why `onContainerBuilding()` has
    // no entry for it: the sole argument is core's `SkillProviderRegistry`.
    $builder = new DI\ContainerBuilder();
    $builder->addDefinitions([
        SkillProviderRegistry::class => \DI\factory(static fn(): SkillProviderRegistry
            => new SkillProviderRegistry([])),
    ]);

    $container = $builder->useAutowiring(true)->build();

    expect($container->get(CustomSkillSearchProvider::class))
        ->toBeInstanceOf(CustomSkillSearchProvider::class);
});
