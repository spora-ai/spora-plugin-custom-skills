<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Plugins\CustomSkills\Exceptions\CustomSkillException;
use Spora\Plugins\CustomSkills\Models\CustomSkill;
use Spora\Plugins\CustomSkills\Models\CustomSkillFile;
use Spora\Plugins\CustomSkills\Providers\CustomSkillProvider;
use Spora\Plugins\CustomSkills\Services\CustomSkillLimits;
use Spora\Plugins\CustomSkills\Services\CustomSkillQueryInterface;
use Spora\Plugins\CustomSkills\Services\CustomSkillWriterInterface;
use Spora\Plugins\CustomSkills\Services\SkillComposer;
use Spora\Services\ToolConfigServiceInterface;
use Spora\Skills\Providers\FilesystemSkillProvider;
use Spora\Skills\SkillProviderInterface;
use Spora\Skills\SkillProviderRegistry;
use Spora\Skills\SkillScanner;

/**
 * The write path, pinned against D12's undo, D14's upsert strictness, D15's
 * caps, D19's persistence boundary, D20's key mapping, D5's shipped-name
 * collision, and the sidecar path rules.
 *
 * Failures are asserted by error code, not exception type: every one is a
 * `CustomSkillException`, so the code is the only signal of which rule fired.
 *
 * @return array{config: ToolConfigServiceInterface, query: CustomSkillQueryInterface, writer: CustomSkillWriterInterface}
 */
function writerGraph(): array
{
    $config = toolConfig();
    $query = skillQuery();

    return [
        'config' => $config,
        'query'  => $query,
        'writer' => skillWriter($query, $config, skillRegistry($query)),
    ];
}

/**
 * A real user row plus its user-principal: `created_by_user_id` has an FK to
 * `users`, so something must exist before a write can land. Seeded through the
 * table rather than `bootAuth()` so the auth stack's vendor deprecation stays
 * out of every result line.
 *
 * @return array{userId: int, principalId: int}
 */
function seededPrincipal(): array
{
    $userId = (int) Capsule::table('users')->insertGetId([
        'email'      => 'writer-' . bin2hex(random_bytes(4)) . '@spora.test',
        'username'   => null,
        'password'   => str_repeat("\0", 60),
        'status'     => 1,
        'verified'   => 1,
        'resettable' => 1,
        'roles_mask' => 0,
        'registered' => time(),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    return ['userId' => $userId, 'principalId' => createUserPrincipal($userId)];
}

/**
 * The failure a write path refused with. Every rule throws the same class, so the code is the assertion.
 */
function refusal(callable $operation): CustomSkillException
{
    try {
        $operation();
    } catch (CustomSkillException $e) {
        return $e;
    }

    throw new LogicException('The write was expected to be refused, and was not.');
}

/**
 * @return list<string>
 */
function storedSidecarPaths(int $principalId, string $name): array
{
    $skill = CustomSkill::query()
        ->forPrincipal($principalId)
        ->where('name', $name)
        ->firstOrFail();

    return CustomSkillFile::query()
        ->where('custom_skill_id', $skill->id)
        ->orderBy('path')
        ->pluck('path')
        ->all();
}

function storedSidecarContent(int $principalId, string $name, string $path): ?string
{
    $skill = CustomSkill::query()
        ->forPrincipal($principalId)
        ->where('name', $name)
        ->firstOrFail();

    return CustomSkillFile::query()
        ->where('custom_skill_id', $skill->id)
        ->where('path', $path)
        ->value('content');
}

dataset('unsafe sidecar paths', [
    'absolute'       => ['/etc/passwd'],
    'parent segment' => ['../x.md'],
    'backslash'      => ['notes\\invoice.md'],
    'empty segment'  => ['examples//invoice.md'],
]);

it('refuses to create a name the principal already owns', function (): void {
    ['userId' => $userId, 'principalId' => $principalId] = seededPrincipal();
    ['writer' => $writer] = writerGraph();

    makeSkill($writer, $principalId, 'alpha');

    $refusal = refusal(fn() => $writer->create($principalId, $userId, [
        'name'        => 'alpha',
        'description' => 'A different skill that wants the same name.',
        'body'        => "# Different\n",
    ], CustomSkill::PROVENANCE_HUMAN));

    expect($refusal->errorCode)->toBe('SKILL_NAME_TAKEN');
});

it('refuses to update a name the principal does not own', function (): void {
    ['userId' => $userId, 'principalId' => $principalId] = seededPrincipal();
    ['writer' => $writer] = writerGraph();

    $refusal = refusal(fn() => $writer->update('ghost', $principalId, $userId, [
        'body' => "# Nothing to update\n",
    ], CustomSkill::PROVENANCE_HUMAN));

    expect($refusal->errorCode)->toBe('SKILL_NOT_FOUND');
});

it('refuses a rename on update and leaves the stored name alone', function (): void {
    ['userId' => $userId, 'principalId' => $principalId] = seededPrincipal();
    ['query' => $query, 'writer' => $writer] = writerGraph();

    makeSkill($writer, $principalId, 'alpha');

    $refusal = refusal(fn() => $writer->update('alpha', $principalId, $userId, [
        'name' => 'beta',
        'body' => "# Steps\n\n1. Do the thing.\n",
    ], CustomSkill::PROVENANCE_HUMAN));

    expect($refusal->errorCode)->toBe('VALIDATION_ERROR');

    expect($query->findForPrincipal('alpha', $principalId))->not->toBeNull()
        ->and($query->findForPrincipal('beta', $principalId))->toBeNull();
});

it('replaces the whole sidecar set when update supplies files', function (): void {
    ['userId' => $userId, 'principalId' => $principalId] = seededPrincipal();
    ['writer' => $writer] = writerGraph();

    makeSkill($writer, $principalId, 'alpha', [
        'files' => ['keep.md' => 'Keep.', 'drop.md' => 'Drop.'],
    ]);

    $writer->update('alpha', $principalId, $userId, [
        'body'  => "# Steps\n\n1. Do the thing.\n",
        'files' => ['keep.md' => 'Keep, revised.'],
    ], CustomSkill::PROVENANCE_HUMAN);

    expect(storedSidecarPaths($principalId, 'alpha'))->toBe(['keep.md']);
});

it('leaves the sidecar set untouched when update omits files', function (): void {
    // `null` means "caller said nothing about files", not an empty map ("remove all").
    ['userId' => $userId, 'principalId' => $principalId] = seededPrincipal();
    ['writer' => $writer] = writerGraph();

    makeSkill($writer, $principalId, 'alpha', ['files' => ['keep.md' => 'Keep.']]);

    $writer->update('alpha', $principalId, $userId, [
        'body' => "# Revised\n\n1. Do the other thing.\n",
    ], CustomSkill::PROVENANCE_HUMAN);

    expect(storedSidecarPaths($principalId, 'alpha'))->toBe(['keep.md']);
});

it('removes every sidecar when update supplies an empty files map', function (): void {
    ['userId' => $userId, 'principalId' => $principalId] = seededPrincipal();
    ['writer' => $writer] = writerGraph();

    makeSkill($writer, $principalId, 'alpha', [
        'files' => ['a.md' => 'A.', 'b.md' => 'B.'],
    ]);

    $writer->update('alpha', $principalId, $userId, [
        'body'  => "# SKILL.md only\n",
        'files' => [],
    ], CustomSkill::PROVENANCE_HUMAN);

    expect(storedSidecarPaths($principalId, 'alpha'))->toBe([]);
});

it('records no previous version on create', function (): void {
    ['principalId' => $principalId] = seededPrincipal();
    ['writer' => $writer] = writerGraph();

    $skill = makeSkill($writer, $principalId, 'alpha');

    expect($skill->previous_snapshot)->toBeNull();
});

it('snapshots the pre-update frontmatter, body and files', function (): void {
    ['userId' => $userId, 'principalId' => $principalId] = seededPrincipal();
    ['writer' => $writer] = writerGraph();

    makeSkill($writer, $principalId, 'alpha', [
        'body'  => "# Original\n\n1. Do the thing.\n",
        'files' => ['notes.md' => 'Original notes.'],
    ]);

    $updated = $writer->update('alpha', $principalId, $userId, [
        'description' => 'A revised skill named alpha.',
        'body'        => "# Revised\n\n1. Do the other thing.\n",
        'files'       => ['notes.md' => 'Revised notes.'],
    ], CustomSkill::PROVENANCE_HUMAN);

    expect($updated->previous_snapshot)->toEqual([
        'name'          => 'alpha',
        'description'   => 'A test skill named alpha.',
        'license'       => null,
        'compatibility' => null,
        'allowed_tools' => null,
        'metadata'      => null,
        'body'          => "# Original\n\n1. Do the thing.\n",
        'files'         => ['notes.md' => 'Original notes.'],
        // Bookkeeping, not content: it dates the rollback copy and names whoever
        // overwrote it, so the desk can label the restore. The exact stamp is not
        // pinned — a relative label in the UI would make it unstable to assert on.
        'captured_at'   => $updated->previous_snapshot['captured_at'] ?? null,
        'captured_by'   => $userId,
    ]);

    expect($updated->previous_snapshot['captured_at'])->toBeString();
});

it('restores the previous body and files, and snapshots the live state so a restore is itself undoable', function (): void {
    ['userId' => $userId, 'principalId' => $principalId] = seededPrincipal();
    ['writer' => $writer] = writerGraph();

    makeSkill($writer, $principalId, 'alpha', [
        'body'  => "# Original\n\n1. Do the thing.\n",
        'files' => ['notes.md' => 'Original notes.'],
    ]);
    $writer->update('alpha', $principalId, $userId, [
        'body'  => "# Revised\n\n1. Do the other thing.\n",
        'files' => ['notes.md' => 'Revised notes.'],
    ], CustomSkill::PROVENANCE_HUMAN);

    $restored = $writer->restore('alpha', $principalId, $userId, CustomSkill::PROVENANCE_HUMAN);

    expect($restored->body)->toBe("# Original\n\n1. Do the thing.\n");
    expect(storedSidecarPaths($principalId, 'alpha'))->toBe(['notes.md']);
    expect(storedSidecarContent($principalId, 'alpha', 'notes.md'))->toBe('Original notes.');

    // The re-snapshot must hold the state live *before* this restore, not the restored one.
    expect($restored->previous_snapshot)->toMatchArray([
        'body'  => "# Revised\n\n1. Do the other thing.\n",
        'files' => ['notes.md' => 'Revised notes.'],
    ]);

    $restoredAgain = $writer->restore('alpha', $principalId, $userId, CustomSkill::PROVENANCE_HUMAN);

    expect($restoredAgain->body)->toBe("# Revised\n\n1. Do the other thing.\n");
    expect(storedSidecarContent($principalId, 'alpha', 'notes.md'))->toBe('Revised notes.');
});

it('records when the rollback copy was taken, so the desk can say what it restores', function (): void {
    ['userId' => $userId, 'principalId' => $principalId] = seededPrincipal();
    ['writer' => $writer] = writerGraph();

    makeSkill($writer, $principalId, 'alpha', ['body' => "# Original\n"]);
    $updated = $writer->update('alpha', $principalId, $userId, [
        'body' => "# Revised\n",
    ], CustomSkill::PROVENANCE_HUMAN);

    // The desk's restore label is built from this. It is deliberately not part of
    // the restored content: `WRITABLE_COLUMNS` drops it on the way back in.
    expect($updated->previous_snapshot)->toHaveKey('captured_at')
        ->and($updated->previous_snapshot)->toHaveKey('captured_by')
        ->and($updated->previous_snapshot['captured_by'])->toBe($userId);

    $restored = $writer->restore('alpha', $principalId, $userId, CustomSkill::PROVENANCE_HUMAN);
    // Restoring must not write the bookkeeping keys onto the skill row.
    expect($restored->getAttributes())->not->toHaveKey('captured_at');
});

it('refuses to restore a skill that has no previous version', function (): void {
    ['userId' => $userId, 'principalId' => $principalId] = seededPrincipal();
    ['writer' => $writer] = writerGraph();

    makeSkill($writer, $principalId, 'alpha');

    $refusal = refusal(fn() => $writer->restore('alpha', $principalId, $userId, CustomSkill::PROVENANCE_HUMAN));

    expect($refusal->errorCode)->toBe('NO_PREVIOUS_VERSION');
});

it('rejects a name a shipped skill already owns', function (): void {
    ['userId' => $userId, 'principalId' => $principalId] = seededPrincipal();
    ['config' => $config, 'query' => $query] = writerGraph();

    // Built through the registry the host assembles, so this proves the real collision check.
    $root = sys_get_temp_dir() . '/custom-skills-shipped-' . bin2hex(random_bytes(4));
    mkdir($root . '/typst', 0777, true);
    file_put_contents(
        $root . '/typst/SKILL.md',
        "---\nname: typst\ndescription: A shipped skill.\n---\n\nShipped body.\n",
    );

    $writer = skillWriter($query, $config, new SkillProviderRegistry([
        new FilesystemSkillProvider(new SkillScanner([['path' => $root, 'source' => 'core']])),
        new CustomSkillProvider($query),
    ]));

    try {
        $refusal = refusal(fn() => $writer->create($principalId, $userId, [
            'name'        => 'typst',
            'description' => 'A custom skill trying to shadow the shipped one.',
            'body'        => "# Custom\n",
        ], CustomSkill::PROVENANCE_HUMAN));

        expect($refusal->errorCode)->toBe('SKILL_NAME_RESERVED');
    } finally {
        unlink($root . '/typst/SKILL.md');
        rmdir($root . '/typst');
        rmdir($root);
    }
});

it('caps the number of custom skills a principal may own', function (): void {
    ['userId' => $userId, 'principalId' => $principalId] = seededPrincipal();
    ['writer' => $writer] = writerGraph();

    // Seeded as rows: the cap counts the principal's rows, so the write path is not under test.
    $rows = [];
    for ($i = 0; $i < CustomSkillLimits::SKILLS_PER_PRINCIPAL; $i++) {
        $rows[] = [
            'principal_id' => $principalId,
            'name'         => "cap-{$i}",
            'description'  => "Seeded skill {$i}.",
            'body'         => "# Steps\n",
            'provenance'   => CustomSkill::PROVENANCE_HUMAN,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ];
    }
    Capsule::table('custom_skills')->insert($rows);

    $refusal = refusal(fn() => $writer->create($principalId, $userId, [
        'name'        => 'one-too-many',
        'description' => 'A skill past the cap.',
        'body'        => "# Steps\n\n1. Do the thing.\n",
    ], CustomSkill::PROVENANCE_HUMAN));

    expect($refusal->errorCode)->toBe('SKILL_LIMIT_REACHED');
});

it('caps the number of sidecar files a skill may carry', function (): void {
    ['userId' => $userId, 'principalId' => $principalId] = seededPrincipal();
    ['writer' => $writer] = writerGraph();

    $files = [];
    for ($i = 0; $i <= CustomSkillLimits::FILES_PER_SKILL; $i++) {
        $files["notes-{$i}.md"] = "Note {$i}.";
    }

    $refusal = refusal(fn() => $writer->create($principalId, $userId, [
        'name'        => 'alpha',
        'description' => 'A skill with one file too many.',
        'body'        => "# Steps\n\n1. Do the thing.\n",
        'files'       => $files,
    ], CustomSkill::PROVENANCE_HUMAN));

    expect($refusal->errorCode)->toBe('TOO_MANY_FILES');
});

it('caps a single sidecar file at the provider read limit', function (): void {
    // The read path's own constant, so a writable skill is also a readable one.
    ['userId' => $userId, 'principalId' => $principalId] = seededPrincipal();
    ['writer' => $writer] = writerGraph();

    $refusal = refusal(fn() => $writer->create($principalId, $userId, [
        'name'        => 'alpha',
        'description' => 'A skill with one oversized sidecar.',
        'body'        => "# Steps\n\n1. Do the thing.\n",
        'files'       => ['big.md' => str_repeat('x', SkillProviderInterface::MAX_FILE_BYTES + 1)],
    ], CustomSkill::PROVENANCE_HUMAN));

    expect($refusal->errorCode)->toBe('FILE_TOO_LARGE');
});

it('caps the total bytes of body, frontmatter and sidecars', function (): void {
    ['userId' => $userId, 'principalId' => $principalId] = seededPrincipal();
    ['writer' => $writer] = writerGraph();

    $refusal = refusal(fn() => $writer->create($principalId, $userId, [
        'name'        => 'alpha',
        'description' => 'A skill over the total budget.',
        'body'        => str_repeat('x', CustomSkillLimits::TOTAL_BYTES + 1),
    ], CustomSkill::PROVENANCE_HUMAN));

    expect($refusal->errorCode)->toBe('TOTAL_SIZE_EXCEEDED');
});

it('caps the description length', function (): void {
    ['userId' => $userId, 'principalId' => $principalId] = seededPrincipal();
    ['writer' => $writer] = writerGraph();

    $refusal = refusal(fn() => $writer->create($principalId, $userId, [
        'name'        => 'alpha',
        'description' => str_repeat('d', CustomSkillLimits::DESCRIPTION_LENGTH + 1),
        'body'        => "# Steps\n\n1. Do the thing.\n",
    ], CustomSkill::PROVENANCE_HUMAN));

    expect($refusal->errorCode)->toBe('DESCRIPTION_TOO_LONG');
});

it('ignores server-assigned columns supplied in the input', function (): void {
    ['userId' => $userId, 'principalId' => $principalId] = seededPrincipal();
    ['query' => $query, 'writer' => $writer] = writerGraph();

    // A second real principal, so an honoured `created_by_user_id` is wrong, not an FK failure.
    $other = seededPrincipal();

    $skill = $writer->create($principalId, $userId, [
        'name'               => 'alpha',
        'description'        => 'A test skill named alpha.',
        'body'               => "# Steps\n\n1. Do the thing.\n",
        'id'                 => 4242,
        'principal_id'       => $other['principalId'],
        'provenance'         => CustomSkill::PROVENANCE_AGENT,
        'created_by_user_id' => $other['userId'],
    ], CustomSkill::PROVENANCE_HUMAN);

    expect($skill->principal_id)->toBe($principalId)
        ->and($skill->provenance)->toBe(CustomSkill::PROVENANCE_HUMAN)
        ->and($skill->created_by_user_id)->toBe($userId)
        ->and($skill->id)->not->toBe(4242)
        ->and($query->findForPrincipal('alpha', $other['principalId']))->toBeNull();
});

it('keeps every server-assigned column out of the model fillable list', function (): void {
    // Structural half of D19: the writer's allowlist is only the first layer, so
    // fillable server columns reopen the hole for any other `create()`-shaped path.
    $fillable = (new CustomSkill())->getFillable();

    expect($fillable)->toBe([
        'name', 'description', 'license', 'compatibility', 'allowed_tools', 'metadata', 'body',
    ]);

    foreach (['id', 'principal_id', 'provenance', 'created_by_user_id', 'updated_by_user_id', 'previous_snapshot'] as $column) {
        expect($fillable)->not->toContain($column);
    }
});

it('rejects a sidecar path that is not a plain relative path', function (string $path): void {
    ['userId' => $userId, 'principalId' => $principalId] = seededPrincipal();
    ['writer' => $writer] = writerGraph();

    $refusal = refusal(fn() => $writer->create($principalId, $userId, [
        'name'        => 'alpha',
        'description' => 'A test skill named alpha.',
        'body'        => "# Steps\n\n1. Do the thing.\n",
        'files'       => [$path => 'Content.'],
    ], CustomSkill::PROVENANCE_HUMAN));

    expect($refusal->errorCode)->toBe('VALIDATION_ERROR');
})->with('unsafe sidecar paths');

it('rejects SKILL.md as a sidecar path because the entry file is synthesised', function (): void {
    ['userId' => $userId, 'principalId' => $principalId] = seededPrincipal();
    ['writer' => $writer] = writerGraph();

    $refusal = refusal(fn() => $writer->create($principalId, $userId, [
        'name'        => 'alpha',
        'description' => 'A test skill named alpha.',
        'body'        => "# Steps\n\n1. Do the thing.\n",
        'files'       => [SkillComposer::ENTRY_FILE => 'A hand-written entry file.'],
    ], CustomSkill::PROVENANCE_HUMAN));

    expect($refusal->errorCode)->toBe('VALIDATION_ERROR');
});

it('accepts a nested sidecar path', function (): void {
    ['principalId' => $principalId] = seededPrincipal();
    ['writer' => $writer] = writerGraph();

    makeSkill($writer, $principalId, 'alpha', ['files' => ['examples/invoice.md' => '# Invoice']]);

    expect(storedSidecarPaths($principalId, 'alpha'))->toBe(['examples/invoice.md']);
});

it('strips files before validating the frontmatter', function (): void {
    // `SkillValidator::ALLOWED_TOP_KEYS` has no `files` and raises
    // UNKNOWN_TOP_LEVEL_KEY as a hard error, so it must never reach the validator.
    ['principalId' => $principalId] = seededPrincipal();
    ['query' => $query, 'writer' => $writer] = writerGraph();

    $skill = makeSkill($writer, $principalId, 'alpha', ['files' => ['notes.md' => 'Notes.']]);

    $frontmatter = skillComposer()->frontmatter($skill);

    expect($frontmatter)->not->toHaveKey('files')
        ->and($query->warnings($skill))->toBe([])
        ->and(array_keys($frontmatter))->toBe(['name', 'description']);
});

it('stores allowed_tools on the column and emits it as allowed-tools', function (): void {
    ['principalId' => $principalId] = seededPrincipal();
    ['writer' => $writer] = writerGraph();

    $skill = makeSkill($writer, $principalId, 'alpha', ['allowed_tools' => 'read_email, send_email']);

    $frontmatter = skillComposer()->frontmatter($skill);

    expect($skill->allowed_tools)->toBe('read_email, send_email')
        ->and($frontmatter['allowed-tools'])->toBe('read_email, send_email')
        ->and($frontmatter)->not->toHaveKey('allowed_tools');
});

it('passes a frontmatter validation failure through with its errors', function (string $name): void {
    ['userId' => $userId, 'principalId' => $principalId] = seededPrincipal();
    ['writer' => $writer] = writerGraph();

    $refusal = refusal(fn() => $writer->create($principalId, $userId, [
        'name'        => $name,
        'description' => 'A test skill with a name the slug rule rejects.',
        'body'        => "# Steps\n\n1. Do the thing.\n",
    ], CustomSkill::PROVENANCE_HUMAN));

    expect($refusal->errorCode)->toBe('SKILL_INVALID')
        ->and($refusal->data['errors'])->not->toBeEmpty();
})->with([
    'uppercase letter'   => 'Invoice',
    'consecutive hyphen' => 'in--voice',
]);

it('stringifies metadata scalars and refuses a nested one', function (): void {
    ['userId' => $userId, 'principalId' => $principalId] = seededPrincipal();
    ['writer' => $writer] = writerGraph();

    $skill = makeSkill($writer, $principalId, 'alpha', [
        'metadata' => ['on' => true, 'off' => false, 'count' => 3, 'ratio' => 1.5],
    ]);

    // The column is a JSON string map, so a bool has to become `true`/`false`
    // rather than `1`/`""` as a bare cast would.
    expect($skill->refresh()->metadata)->toBe([
        'on' => 'true',
        'off' => 'false',
        'count' => '3',
        'ratio' => '1.5',
    ]);

    $refusal = refusal(fn() => $writer->update('alpha', $principalId, $userId, [
        'metadata' => ['nested' => ['no' => 'nested maps']],
    ], CustomSkill::PROVENANCE_HUMAN));

    expect($refusal->errorCode)->toBe('VALIDATION_ERROR')
        ->and($refusal->getMessage())->toContain('metadata.nested');
});

it('reads an empty metadata object as unset', function (): void {
    ['userId' => $userId, 'principalId' => $principalId] = seededPrincipal();
    ['writer' => $writer] = writerGraph();

    $skill = makeSkill($writer, $principalId, 'alpha', ['metadata' => '']);

    expect($skill->refresh()->metadata)->toBeNull()
        ->and(skillComposer()->frontmatter($skill->refresh()))->not->toHaveKey('metadata');
});
