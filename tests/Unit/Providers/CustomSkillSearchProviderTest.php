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
 * The skill section of the palette, moved here from core — so its ranking, folding, scoping and dedup are
 * pinned here too. Why the file exists: search asks every visible principal, not the one a UI has.
 */

/**
 * A provider that answers for one principal, or every principal at once. `onlyVisibleTo === null` is how
 * core's `FilesystemSkillProvider` behaves, so the dedup needs no real filesystem.
 */
final class SearchStubSkillProvider implements SkillProviderInterface
{
    /** @var list<SkillSummary> */
    private array $summaries = [];

    public ?int $onlyVisibleTo = null;

    public function __construct(private readonly string $source) {}

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

/** @param list<array{0: string}|array{0: string, 1: string}> $skills name + description. */
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

/** @return list<string> The hit ids, in palette order. */
function searchIds(CustomSkillSearchProvider $provider, string $query, SearchContext $context): array
{
    return array_map(static fn($hit) => $hit->id, $provider->search($query, $context));
}

const SEARCH_OWNER = 4242;
const SEARCH_STRANGER = 9999;

const SEARCH_GROUP = 4243;

it('claims the skill palette bucket', function (): void {
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
    $provider = searchProvider([
        searchSkills(CustomSkillProvider::SOURCE, SEARCH_OWNER, [['mine', 'Personal.']]),
        searchSkills('studio', SEARCH_STRANGER, [['theirs', 'Group.']]),
    ]);

    $ids = searchIds($provider, 'e', new SearchContext([SEARCH_OWNER, SEARCH_STRANGER]));

    expect($ids)->toContain('mine')->toContain('theirs');
});

it('returns one hit per skill when the provider ignores the principal', function (): void {
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

it('routes a skill this plugin owns to the writable desk, naming the principal', function (): void {
    $provider = searchProvider([
        searchSkills(CustomSkillProvider::SOURCE, SEARCH_OWNER, [['invoice-drafting']]),
    ]);

    expect($provider->search('invoice', new SearchContext([SEARCH_OWNER]))[0]->href)
        ->toBe('/apps/custom-skills/p/' . SEARCH_OWNER . '/skill/invoice-drafting');
});

it('names the principal that owns the skill, not merely one the caller can see', function (): void {
    $provider = searchProvider([
        searchSkills(CustomSkillProvider::SOURCE, SEARCH_OWNER, [['mine', 'Personal.']]),
        searchSkills(CustomSkillProvider::SOURCE, SEARCH_GROUP, [['theirs', 'A group\'s.']]),
    ]);

    expect($provider->search('mine', new SearchContext([SEARCH_OWNER, SEARCH_GROUP]))[0]->href)
        ->toBe('/apps/custom-skills/p/' . SEARCH_OWNER . '/skill/mine');
    expect($provider->search('theirs', new SearchContext([SEARCH_OWNER, SEARCH_GROUP]))[0]->href)
        ->toBe('/apps/custom-skills/p/' . SEARCH_GROUP . '/skill/theirs');
});

it('keeps both copies when two principals own a skill of the same name', function (): void {
    // `unique(principal_id, name)` constrains the PAIR, so these are two distinct resources; the fold is
    // for principal-agnostic providers and on owned skills dropped the second.
    $provider = searchProvider([
        searchSkills(CustomSkillProvider::SOURCE, SEARCH_OWNER, [['report', 'Mine.']]),
        searchSkills(CustomSkillProvider::SOURCE, SEARCH_GROUP, [['report', 'Theirs.']]),
    ]);

    $hits = $provider->search('report', new SearchContext([SEARCH_OWNER, SEARCH_GROUP]));

    expect(array_map(static fn($hit) => $hit->href, $hits))
        ->toBe([
            '/apps/custom-skills/p/' . SEARCH_OWNER . '/skill/report',
            '/apps/custom-skills/p/' . SEARCH_GROUP . '/skill/report',
        ]);
});

it('still folds a shipped skill that resolves for every principal', function (): void {
    $provider = searchProvider([
        searchSkills('filesystem', SEARCH_OWNER, [['typst', 'Typeset.']]),
    ]);

    $hits = $provider->search('typst', new SearchContext([SEARCH_OWNER, SEARCH_GROUP]));

    expect($hits)->toHaveCount(1)
        ->and($hits[0]->href)->toBe('/apps/custom-skills/p/' . SEARCH_OWNER . '/library/typst');
});

it('routes a shipped skill to the catalogue viewer instead of returning no href', function (): void {
    $provider = searchProvider([
        searchSkills('filesystem', SEARCH_OWNER, [['typst', 'Typeset documents.']]),
    ]);

    expect($provider->search('typst', new SearchContext([SEARCH_OWNER]))[0]->href)
        ->toBe('/apps/custom-skills/p/' . SEARCH_OWNER . '/library/typst');
});

it('routes another plugin\'s skill to the catalogue viewer too', function (): void {
    $provider = searchProvider([
        searchSkills('spora-plugin-calendar', SEARCH_OWNER, [['quarterly-planning', 'Plan the quarter.']]),
    ]);

    expect($provider->search('quarterly', new SearchContext([SEARCH_OWNER]))[0]->href)
        ->toBe('/apps/custom-skills/p/' . SEARCH_OWNER . '/library/quarterly-planning');
});

it('routes a summary carrying no source, rather than handing out no href', function (): void {
    $orphan = new SearchStubSkillProvider('unlabelled');
    $orphan->add('sourceless', 'No source on the summary.', unlabelled: true);

    $provider = searchProvider([$orphan]);

    expect($provider->search('sourceless', new SearchContext([SEARCH_OWNER]))[0]->href)
        ->toBe('/apps/custom-skills/p/' . SEARCH_OWNER . '/library/sourceless');
});

it('reads the app slug off the app, so a rename cannot strand the links', function (): void {
    $provider = searchProvider([
        searchSkills(CustomSkillProvider::SOURCE, SEARCH_OWNER, [['invoice-drafting']]),
    ]);

    expect($provider->search('invoice', new SearchContext([SEARCH_OWNER]))[0]->href)
        ->toBe('/apps/' . (new CustomSkillsApp())->name() . '/p/' . SEARCH_OWNER . '/skill/invoice-drafting');
});

it('encodes a name that would otherwise forge a path', function (): void {
    $provider = searchProvider([
        searchSkills(CustomSkillProvider::SOURCE, SEARCH_OWNER, [['invoice drafting', 'Spaces.']]),
        searchSkills('filesystem', SEARCH_OWNER, [['a/b', 'A slash.']]),
    ]);

    $hits = $provider->search('a', new SearchContext([SEARCH_OWNER]));

    expect($hits)->toHaveCount(2)
        ->and($hits[0]->href)->toBe('/apps/custom-skills/p/' . SEARCH_OWNER . '/library/a%2Fb')
        ->and($hits[1]->href)->toBe('/apps/custom-skills/p/' . SEARCH_OWNER . '/skill/invoice%20drafting');
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
    $builder = new DI\ContainerBuilder();
    $builder->addDefinitions([
        SkillProviderRegistry::class => \DI\factory(static fn(): SkillProviderRegistry
            => new SkillProviderRegistry([])),
    ]);

    $container = $builder->useAutowiring(true)->build();

    expect($container->get(CustomSkillSearchProvider::class))
        ->toBeInstanceOf(CustomSkillSearchProvider::class);
});
