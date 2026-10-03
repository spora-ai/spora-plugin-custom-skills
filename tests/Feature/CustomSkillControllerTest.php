<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Spora\Auth\AuthService;
use Spora\Plugins\CustomSkills\Http\CustomSkillController;
use Spora\Plugins\CustomSkills\Http\CustomSkillResource;
use Spora\Plugins\CustomSkills\Models\CustomSkill;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Spora\Services\ToolConfigServiceInterface;
use Spora\Tools\SkillTool;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The REST surface, driven against the real controller and genuine collaborators.
 *
 * The two-gate table (D10) is the spine: read resolves through
 * `visiblePrincipalIdsFor`, write through `callerControlsPrincipal`. They
 * disagree on purpose, so they are exercised separately.
 */

/**
 * A request for `…/{name}`, with the path variable the router would have set.
 */
function skillRequest(string $method, string $uri, string $name, array $body = []): Request
{
    $request = jsonRequest($method, $uri, $body);
    $request->attributes->set('name', $name);

    return $request;
}

/**
 * A request for `…/{name}/files/{path}`, with `$path` the *encoded* URL segment
 * urldecoded exactly as `Router::handleFound()` does — `getPathInfo()` does not.
 */
function skillFileRequest(string $method, string $uri, string $name, string $encodedPath, array $body = []): Request
{
    $request = skillRequest($method, $uri, $name, $body);
    $request->attributes->set('path', urldecode($encodedPath));

    return $request;
}

/**
 * @return array<string, mixed>
 */
function skillBody(JsonResponse $response): array
{
    $decoded = json_decode((string) $response->getContent(), true);

    expect($decoded)->toBeArray();

    return $decoded;
}

/**
 * @return array<string, mixed>
 */
function skillData(JsonResponse $response): array
{
    $body = skillBody($response);

    expect($body)->toHaveKey('data');

    return $body['data'];
}

function skillError(JsonResponse $response): array
{
    $body = skillBody($response);

    expect($body)->toHaveKey('error')
        ->and($body['error'])->toBeArray()
        ->and($body['error'])->toHaveKey('code')
        ->and($body['error'])->toHaveKey('message');

    return ['status' => $response->getStatusCode(), 'code' => $body['error']['code']];
}

/**
 * A sidecar row inserted directly, so read-path assertions cannot inherit a write-path failure.
 */
function seedSidecar(int $skillId, string $path, string $content): void
{
    Capsule::table('custom_skill_files')->insert([
        'custom_skill_id' => $skillId,
        'path'            => $path,
        'content'         => $content,
        'bytes'           => strlen($content),
        'created_at'      => date('Y-m-d H:i:s'),
        'updated_at'      => date('Y-m-d H:i:s'),
    ]);
}

/**
 * A second, unrelated user's principal; the session stays on the original caller.
 */
function createUnrelatedPrincipal(AuthService $auth, int $currentUserId, string $currentEmail): int
{
    $otherUserId = $auth->register('other@example.com', 'Password1!', 'Other User');
    simulateLoggedInSession($otherUserId, 'other@example.com');
    $otherPrincipalId = createUserPrincipal($otherUserId);

    simulateLoggedInSession($currentUserId, $currentEmail);

    return $otherPrincipalId;
}

beforeEach(function (): void {
    // The plugin's unpatched v9.0.0 of `delight-im/auth` raises implicit-nullable
    // deprecations on PHP 8.5, and PHPUnit installs its handler after
    // `tests/Pest.php`, so the filter there is re-installed for each test.
    set_error_handler(
        static function (int $errno, string $_errstr, string $errfile): bool {
            return $errno === E_DEPRECATED
                && str_contains($errfile, DIRECTORY_SEPARATOR . 'delight-im' . DIRECTORY_SEPARATOR);
        },
        E_DEPRECATED,
    );

    $this->config = toolConfig();
    $this->query = skillQuery();
    $this->writer = skillWriter($this->query, $this->config, skillRegistry($this->query));
    $this->resource = new CustomSkillResource($this->query);
    $this->auth = bootAuthLayer();
    $this->controller = new CustomSkillController(
        $this->auth,
        new PrincipalService(new PrincipalResolver()),
        $this->query,
        $this->writer,
        $this->resource,
        skillAllowlist($this->config),
    );
});

afterEach(function (): void {
    clearSession();
    restore_error_handler();
});

it('reads and writes the caller\'s own user-principal', function (): void {
    $userId = bootAuth($this->auth);
    $principalId = createUserPrincipal($userId);

    $skill = makeSkill($this->writer, $principalId, 'own-skill');

    $index = $this->controller->index(jsonRequest('GET', '/api/v1/custom-skills'));
    expect($index->getStatusCode())->toBe(200)
        ->and(skillData($index)['skills'])->toHaveCount(1)
        ->and(skillData($index)['skills'][0]['name'])->toBe($skill->name);

    $show = $this->controller->show(skillRequest('GET', '/api/v1/custom-skills/own-skill', 'own-skill'));
    expect($show->getStatusCode())->toBe(200)
        ->and(skillData($show)['skill']['principal_id'])->toBe($principalId);

    $stored = $this->controller->store(jsonRequest('POST', '/api/v1/custom-skills', [
        'name' => 'authored-skill', 'description' => 'Written through the API.', 'body' => "# Steps\n\n1. Go.\n",
    ]));

    expect($stored->getStatusCode())->toBe(201)
        ->and(skillData($stored)['skill']['name'])->toBe('authored-skill')
        ->and(skillData($stored)['skill']['principal_id'])->toBe($principalId);
});

it('lets a plain group member read the group\'s skills and refuses the write', function (): void {
    $userId = bootAuth($this->auth);
    $ownPrincipalId = createUserPrincipal($userId);
    ['principalId' => $groupPrincipalId] = createGroupWithPrincipal($userId, 'member');

    makeSkill($this->writer, $groupPrincipalId, 'group-skill');
    makeSkill($this->writer, $ownPrincipalId, 'private-skill');

    $index = $this->controller->index(jsonRequest('GET', "/api/v1/custom-skills?principal_id={$groupPrincipalId}"));
    expect($index->getStatusCode())->toBe(200)
        ->and(array_column(skillData($index)['skills'], 'name'))->toBe(['group-skill']);

    $show = $this->controller->show(skillRequest(
        'GET',
        "/api/v1/custom-skills/group-skill?principal_id={$groupPrincipalId}",
        'group-skill',
    ));
    expect($show->getStatusCode())->toBe(200)
        ->and(skillData($show)['skill']['principal_id'])->toBe($groupPrincipalId);

    $files = $this->controller->files(skillRequest(
        'GET',
        "/api/v1/custom-skills/group-skill/files?principal_id={$groupPrincipalId}",
        'group-skill',
    ));
    expect($files->getStatusCode())->toBe(200);

    $stored = $this->controller->store(jsonRequest(
        'POST',
        "/api/v1/custom-skills?principal_id={$groupPrincipalId}",
        ['name' => 'member-write', 'description' => 'Should not land.', 'body' => "b\n"],
    ));
    $error = skillError($stored);

    expect($error['status'])->toBe(403)
        ->and($error['code'])->toBe('FORBIDDEN')
        // The gate is on the principal, so no row was created anywhere.
        ->and(skillData($this->controller->index(jsonRequest('GET', "/api/v1/custom-skills?principal_id={$groupPrincipalId}")))['skills'])
        ->toHaveCount(1)
        ->and($this->query->findForPrincipal('member-write', $groupPrincipalId))->toBeNull();
});

it('lets a group owner write into the group', function (): void {
    $userId = bootAuth($this->auth);
    createUserPrincipal($userId);
    ['principalId' => $groupPrincipalId] = createGroupWithPrincipal($userId, 'owner');

    $index = $this->controller->index(jsonRequest('GET', "/api/v1/custom-skills?principal_id={$groupPrincipalId}"));
    expect($index->getStatusCode())->toBe(200);

    $stored = $this->controller->store(jsonRequest(
        'POST',
        "/api/v1/custom-skills?principal_id={$groupPrincipalId}",
        ['name' => 'owner-write', 'description' => 'Owner may author.', 'body' => "b\n"],
    ));

    expect($stored->getStatusCode())->toBe(201)
        ->and(skillData($stored)['skill']['principal_id'])->toBe($groupPrincipalId)
        ->and(skillData($stored)['skill']['name'])->toBe('owner-write');
});

it('hides an unrelated principal: 404 on single resources, empty list on index, 403 on write', function (): void {
    $userId = bootAuth($this->auth, 'test@example.com');
    $ownPrincipalId = createUserPrincipal($userId);
    $otherPrincipalId = createUnrelatedPrincipal($this->auth, $userId, 'test@example.com');

    makeSkill($this->writer, $otherPrincipalId, 'secret-skill', [
        'body' => "# Secret\n\nCONFIDENTIAL-MARKER\n",
    ]);
    makeSkill($this->writer, $ownPrincipalId, 'own-skill');

    // 404, not 403: a 403 would confirm the name exists on a principal the caller
    // cannot see, so "no such skill" and "not yours" must be one answer.
    foreach (['show', 'files', 'file', 'allowlist'] as $method) {
        $response = match ($method) {
            'show' => $this->controller->show(skillRequest('GET', '/api/v1/custom-skills/secret-skill?principal_id=' . $otherPrincipalId, 'secret-skill')),
            'files' => $this->controller->files(skillRequest('GET', '/api/v1/custom-skills/secret-skill/files?principal_id=' . $otherPrincipalId, 'secret-skill')),
            'file' => $this->controller->file(skillFileRequest('GET', '/api/v1/custom-skills/secret-skill/files/SKILL.md?principal_id=' . $otherPrincipalId, 'secret-skill', 'SKILL.md')),
            'allowlist' => $this->controller->allowlist(skillRequest('GET', '/api/v1/custom-skills/secret-skill/allowlist?principal_id=' . $otherPrincipalId, 'secret-skill')),
        };

        $error = skillError($response);
        expect($error['status'])->toBe(404, "{$method} must not confirm the principal exists")
            ->and($error['code'])->toBe('SKILL_NOT_FOUND')
            ->and((string) $response->getContent())->not->toContain('CONFIDENTIAL-MARKER');
    }

    $index = $this->controller->index(jsonRequest('GET', "/api/v1/custom-skills?principal_id={$otherPrincipalId}"));
    expect($index->getStatusCode())->toBe(200)
        ->and(skillData($index)['skills'])->toBe([]);

    // Writes say 403: the caller already knows what they asked to change.
    $stored = $this->controller->store(jsonRequest(
        'POST',
        "/api/v1/custom-skills?principal_id={$otherPrincipalId}",
        ['name' => 'intruder', 'description' => 'Should not land.', 'body' => "b\n"],
    ));
    $updated = $this->controller->update(skillRequest(
        'PUT',
        "/api/v1/custom-skills/secret-skill?principal_id={$otherPrincipalId}",
        'secret-skill',
        ['description' => 'Rewritten.'],
    ));
    $destroyed = $this->controller->destroy(skillRequest(
        'DELETE',
        "/api/v1/custom-skills/secret-skill?principal_id={$otherPrincipalId}",
        'secret-skill',
    ));

    foreach ([$stored, $updated, $destroyed] as $response) {
        expect(skillError($response))->toBe(['status' => 403, 'code' => 'FORBIDDEN']);
    }

    expect($this->query->findForPrincipal('secret-skill', $otherPrincipalId))->not->toBeNull()
        ->and($this->query->findForPrincipal('intruder', $otherPrincipalId))->toBeNull();
});

// Frozen contract.

it('wraps index() in {"data":{"skills":[…]}}, name-ordered', function (): void {
    $userId = bootAuth($this->auth);
    $principalId = createUserPrincipal($userId);

    makeSkill($this->writer, $principalId, 'zebra-skill');
    makeSkill($this->writer, $principalId, 'alpha-skill');

    $response = $this->controller->index(jsonRequest('GET', '/api/v1/custom-skills'));
    $body = skillBody($response);

    expect($response->getStatusCode())->toBe(200)
        ->and(array_keys($body))->toBe(['data'])
        ->and(array_keys($body['data']))->toBe(['skills'])
        ->and(array_column($body['data']['skills'], 'name'))->toBe(['alpha-skill', 'zebra-skill']);
});

it('returns every contract key from show(), with SKILL.md first and metadata an object', function (): void {
    $userId = bootAuth($this->auth);
    $principalId = createUserPrincipal($userId);

    $skill = makeSkill($this->writer, $principalId, 'invoice-drafting', [
        'license'       => 'MIT',
        'compatibility' => 'spora>=0.29',
        'metadata'      => ['tier' => 'pro'],
    ]);
    seedSidecar((int) $skill->id, 'examples/invoice.md', "Sample.\n");

    $response = $this->controller->show(skillRequest('GET', '/api/v1/custom-skills/invoice-drafting', 'invoice-drafting'));
    $payload = skillData($response)['skill'];

    expect($response->getStatusCode())->toBe(200)
        ->and(array_keys($payload))->toBe([
            'id', 'principal_id', 'name', 'slug', 'description', 'license', 'compatibility',
            'metadata', 'body', 'body_bytes', 'provenance',
            'created_by_user_id', 'updated_by_user_id', 'created_at', 'updated_at',
            'files', 'has_previous', 'previous_at', 'previous_by', 'warnings', 'warning_count',
        ])
        ->and($payload['id'])->toBe((int) $skill->id)
        ->and($payload['principal_id'])->toBe($principalId)
        ->and($payload['name'])->toBe('invoice-drafting')
        // The writer forces name === slug, so the URL segment and frontmatter cannot drift.
        ->and($payload['slug'])->toBe($payload['name'])
        ->and($payload['license'])->toBe('MIT')
        ->and($payload['compatibility'])->toBe('spora>=0.29')
        // An object on the wire, not a PHP-empty array serialised as `[]`.
        ->and(json_encode($payload['metadata']))->toBe('{"tier":"pro"}')
        ->and($payload['body'])->toBe("# Steps\n\n1. Do the thing.\n")
        ->and($payload['body_bytes'])->toBe(strlen($payload['body']))
        ->and($payload['provenance'])->toBe(CustomSkill::PROVENANCE_HUMAN)
        ->and($payload['created_at'])->toBeString()
        ->and($payload['updated_at'])->toBeString()
        ->and($payload['has_previous'])->toBeFalse()
        ->and($payload['warnings'])->toBeArray()
        ->and($payload['warning_count'])->toBe(0)
        ->and(array_column($payload['files'], 'path'))->toBe(['SKILL.md', 'examples/invoice.md'])
        ->and($payload['files'][0]['bytes'])->toBeInt()
        ->and($payload['files'][1]['bytes'])->toBe(8);
});

it('serialises metadata as a JSON object, {} when unset', function (): void {
    $userId = bootAuth($this->auth);
    $principalId = createUserPrincipal($userId);

    makeSkill($this->writer, $principalId, 'no-metadata-skill');
    makeSkill($this->writer, $principalId, 'has-metadata-skill', ['metadata' => ['tier' => 'pro']]);

    $unset = $this->controller->show(skillRequest('GET', '/api/v1/custom-skills/no-metadata-skill', 'no-metadata-skill'));
    $set = $this->controller->show(skillRequest('GET', '/api/v1/custom-skills/has-metadata-skill', 'has-metadata-skill'));

    // Pinned on raw JSON: `[]` and `{}` are indistinguishable after
    // `json_decode(..., true)`, so only the wire form proves the object contract.
    expect((string) $set->getContent())->toContain('"metadata":{"tier":"pro"}')
        ->and(skillData($set)['skill']['metadata'])->toBe(['tier' => 'pro'])
        ->and((string) $unset->getContent())->toContain('"metadata":{}')
        ->and(skillData($unset)['skill']['metadata'])->toBe([]);
});

it('stamps the acting user on the skill a REST write creates', function (): void {
    $userId = bootAuth($this->auth);
    $principalId = createUserPrincipal($userId);

    $created = skillData($this->controller->store(jsonRequest('POST', '/api/v1/custom-skills', [
        'name' => 'invoice-drafting', 'description' => 'Authored through the API.', 'body' => "# Steps\n\n1. Go.\n",
    ])))['skill'];

    expect($created['created_by_user_id'])->toBe($userId)
        ->and($created['updated_by_user_id'])->toBe($userId)
        ->and($created['provenance'])->toBe(CustomSkill::PROVENANCE_HUMAN)
        ->and($created['id'])->toBeInt()
        ->and($created['principal_id'])->toBe($principalId);
});

it('lists a skill\'s files and serves the entry file and a percent-encoded nested path', function (): void {
    $userId = bootAuth($this->auth);
    $principalId = createUserPrincipal($userId);

    $skill = makeSkill($this->writer, $principalId, 'invoice-drafting');
    seedSidecar((int) $skill->id, 'examples/invoice.md', "Sample.\n");

    $listing = $this->controller->files(skillRequest('GET', '/api/v1/custom-skills/invoice-drafting/files', 'invoice-drafting'));
    expect($listing->getStatusCode())->toBe(200)
        ->and(skillData($listing)['files'][0]['path'])->toBe('SKILL.md');

    $entry = $this->controller->file(skillFileRequest(
        'GET',
        '/api/v1/custom-skills/invoice-drafting/files/SKILL.md',
        'invoice-drafting',
        'SKILL.md',
    ));
    $entryData = skillData($entry);

    // The entry file is synthesised from the columns, never a stored row.
    expect($entry->getStatusCode())->toBe(200)
        ->and($entryData['path'])->toBe('SKILL.md')
        ->and($entryData['content'])->toStartWith("---\nname: invoice-drafting\n")
        ->and($entryData['bytes'])->toBe(strlen($entryData['content']));

    $nested = $this->controller->file(skillFileRequest(
        'GET',
        '/api/v1/custom-skills/invoice-drafting/files/examples%2Finvoice.md',
        'invoice-drafting',
        'examples%2Finvoice.md',
    ));
    $nestedData = skillData($nested);

    expect($nested->getStatusCode())->toBe(200)
        // The router decodes the segment before the controller sees it.
        ->and($nestedData['path'])->toBe('examples/invoice.md')
        ->and($nestedData['content'])->toBe("Sample.\n")
        ->and($nestedData['bytes'])->toBe(8);
});

it('404s FILE_NOT_FOUND for a path that is not a member of the skill', function (): void {
    $userId = bootAuth($this->auth);
    $principalId = createUserPrincipal($userId);

    $skill = makeSkill($this->writer, $principalId, 'invoice-drafting');
    seedSidecar((int) $skill->id, 'examples/invoice.md', "Sample.\n");

    $response = $this->controller->file(skillFileRequest(
        'GET',
        '/api/v1/custom-skills/invoice-drafting/files/examples%2Fabsent.md',
        'invoice-drafting',
        'examples%2Fabsent.md',
    ));

    expect(skillError($response))->toBe(['status' => 404, 'code' => 'FILE_NOT_FOUND']);
});

it('404s a foreign skill on the file routes without leaking its body', function (): void {
    $userId = bootAuth($this->auth, 'test@example.com');
    $ownPrincipalId = createUserPrincipal($userId);
    $otherPrincipalId = createUnrelatedPrincipal($this->auth, $userId, 'test@example.com');

    $foreign = makeSkill($this->writer, $otherPrincipalId, 'secret-skill', [
        'body' => "# Secret\n\nFOREIGN-BODY-MARKER\n",
    ]);
    seedSidecar((int) $foreign->id, 'examples/secret.md', "FOREIGN-FILE-MARKER\n");

    $own = makeSkill($this->writer, $ownPrincipalId, 'own-skill');

    // The path exists only in the *other* principal's file table.
    $response = $this->controller->file(skillFileRequest(
        'GET',
        '/api/v1/custom-skills/own-skill/files/examples%2Fsecret.md',
        'own-skill',
        'examples%2Fsecret.md',
    ));
    expect(skillError($response))->toBe(['status' => 404, 'code' => 'FILE_NOT_FOUND']);

    // The foreign *skill name* 404s as SKILL_NOT_FOUND, not FILE_NOT_FOUND: an
    // invisible skill's file set is never consulted.
    $foreignByName = $this->controller->file(skillFileRequest(
        'GET',
        '/api/v1/custom-skills/secret-skill/files/examples%2Fsecret.md',
        'secret-skill',
        'examples%2Fsecret.md',
    ));
    $error = skillError($foreignByName);

    expect($error['status'])->toBe(404)
        ->and($error['code'])->toBe('SKILL_NOT_FOUND')
        ->and((string) $foreignByName->getContent())->not->toContain('FOREIGN-BODY-MARKER')
        ->and((string) $foreignByName->getContent())->not->toContain('FOREIGN-FILE-MARKER');
});

it('409s a taken name on POST and 404s a missing name on PUT', function (): void {
    $userId = bootAuth($this->auth);
    $principalId = createUserPrincipal($userId);

    makeSkill($this->writer, $principalId, 'invoice-drafting');

    $taken = $this->controller->store(jsonRequest('POST', '/api/v1/custom-skills', [
        'name' => 'invoice-drafting', 'description' => 'Second one.', 'body' => "b\n",
    ]));
    expect(skillError($taken))->toBe(['status' => 409, 'code' => 'SKILL_NAME_TAKEN']);

    $missing = $this->controller->update(skillRequest(
        'PUT',
        '/api/v1/custom-skills/absent-skill',
        'absent-skill',
        ['description' => 'Nowhere.'],
    ));
    expect(skillError($missing))->toBe(['status' => 404, 'code' => 'SKILL_NOT_FOUND']);

    $destroyed = $this->controller->destroy(skillRequest('DELETE', '/api/v1/custom-skills/absent-skill', 'absent-skill'));
    expect(skillError($destroyed))->toBe(['status' => 404, 'code' => 'SKILL_NOT_FOUND']);
});

it('refuses a rename on PUT with 422, and 404s a missing skill', function (): void {
    $userId = bootAuth($this->auth);
    $principalId = createUserPrincipal($userId);

    makeSkill($this->writer, $principalId, 'invoice-drafting');

    $renamed = $this->controller->update(skillRequest(
        'PUT',
        '/api/v1/custom-skills/invoice-drafting',
        'invoice-drafting',
        ['name' => 'other-name', 'description' => 'Renamed.'],
    ));
    $error = skillError($renamed);

    expect($error['status'])->toBe(422)
        ->and($error['code'])->toBe('VALIDATION_ERROR')
        // The old name is untouched: a rename must never half-apply.
        ->and($this->query->findForPrincipal('invoice-drafting', $principalId))->not->toBeNull()
        ->and($this->query->findForPrincipal('other-name', $principalId))->toBeNull();

    $updated = $this->controller->update(skillRequest(
        'PUT',
        '/api/v1/custom-skills/invoice-drafting',
        'invoice-drafting',
        ['name' => 'invoice-drafting', 'description' => 'Updated in place.'],
    ));
    expect($updated->getStatusCode())->toBe(200)
        ->and(skillData($updated)['skill']['description'])->toBe('Updated in place.')
        ->and(skillData($updated)['skill']['has_previous'])->toBeTrue();
});

it('returns the deleted name and the agents whose allowlist it rewrote', function (): void {
    $userId = bootAuth($this->auth);
    $principalId = createUserPrincipal($userId);

    makeSkill($this->writer, $principalId, 'invoice-drafting');

    /** @var ToolConfigServiceInterface $config */
    $config = $this->config;
    $invoicer = createAgentForPrincipal($principalId, 'Invoicer');
    $bystander = createAgentForPrincipal($principalId, 'Bystander');

    $config->putAgentOverride(SkillTool::class, $invoicer, [
        'allowed_skills' => ['invoice-drafting', 'some-other-skill'],
    ]);
    $config->putAgentOverride(SkillTool::class, $bystander, [
        'allowed_skills' => ['some-other-skill'],
    ]);

    $response = $this->controller->destroy(skillRequest('DELETE', '/api/v1/custom-skills/invoice-drafting', 'invoice-drafting'));
    $data = skillData($response);

    expect($response->getStatusCode())->toBe(200)
        ->and($data['deleted'])->toBeTrue()
        ->and($data['name'])->toBe('invoice-drafting')
        // Agent ids only: the principal-level default is reported separately.
        ->and($data['scrubbed_agents'])->toBe([['id' => $invoicer, 'name' => 'Invoicer', 'scope' => 'agent']])
        ->and($data['scrubbed_principal_default'])->toBeFalse()
        ->and($this->query->findForPrincipal('invoice-drafting', $principalId))->toBeNull();

    // The name is gone and the rest of the list survived: `putAgentOverride` merges.
    $after = $config->getRawAgentOverride(SkillTool::class, $invoicer);
    expect($after['allowed_skills'])->toBe(['some-other-skill']);

    expect($config->getRawAgentOverride(SkillTool::class, $bystander)['allowed_skills'])
        ->toBe(['some-other-skill']);
});

it('reports an empty scrub when nobody allowlists the skill, and rewrites nothing', function (): void {
    $userId = bootAuth($this->auth);
    $principalId = createUserPrincipal($userId);

    makeSkill($this->writer, $principalId, 'invoice-drafting');

    /** @var ToolConfigServiceInterface $config */
    $config = $this->config;
    $agentId = createAgentForPrincipal($principalId, 'Invoicer');
    $config->putAgentOverride(SkillTool::class, $agentId, [
        'allowed_skills' => ['some-other-skill'],
        'enabled' => true,
    ]);
    $before = $config->getRawAgentOverride(SkillTool::class, $agentId);

    $response = $this->controller->destroy(skillRequest('DELETE', '/api/v1/custom-skills/invoice-drafting', 'invoice-drafting'));
    $data = skillData($response);

    expect($data['deleted'])->toBeTrue()
        ->and($data['scrubbed_agents'])->toBe([])
        ->and($data['scrubbed_principal_default'])->toBeFalse()
        // No write at all: the whole settings blob is intact.
        ->and($config->getRawAgentOverride(SkillTool::class, $agentId))->toBe($before);
});

it('reports agent-scoped and principal-scoped allowlist entries, array form', function (): void {
    $userId = bootAuth($this->auth);
    $principalId = createUserPrincipal($userId);

    makeSkill($this->writer, $principalId, 'invoice-drafting');

    /** @var ToolConfigServiceInterface $config */
    $config = $this->config;
    $agentId = createAgentForPrincipal($principalId, 'Invoicer');
    createAgentForPrincipal($principalId, 'Unrelated');

    $config->putAgentOverride(SkillTool::class, $agentId, ['allowed_skills' => ['invoice-drafting']]);
    $config->putPrincipalSettings(SkillTool::class, $principalId, ['allowed_skills' => ['invoice-drafting']]);

    $response = $this->controller->allowlist(skillRequest(
        'GET',
        '/api/v1/custom-skills/invoice-drafting/allowlist',
        'invoice-drafting',
    ));
    $data = skillData($response);

    expect($response->getStatusCode())->toBe(200)
        ->and($data['agents'])->toContain(['id' => $agentId, 'name' => 'Invoicer', 'scope' => 'agent'])
        // The principal default carries id 0 and a null name: it is not an agent.
        ->and($data['agents'])->toContain(['id' => 0, 'name' => null, 'scope' => 'principal'])
        ->and($data['agents'])->toHaveCount(2);
});

/**
 * The same blast radius, stored the way a multi-select round-trips through the
 * form layer: a JSON string. `ToolConfigService` does not normalise on write and
 * core decodes both shapes, so preview and scrub must agree.
 */
it('reports allowlist entries stored as a JSON string, the form layer\'s round-trip shape', function (): void {
    $userId = bootAuth($this->auth);
    $principalId = createUserPrincipal($userId);

    makeSkill($this->writer, $principalId, 'invoice-drafting');

    /** @var ToolConfigServiceInterface $config */
    $config = $this->config;
    $agentId = createAgentForPrincipal($principalId, 'Invoicer');

    $config->putAgentOverride(SkillTool::class, $agentId, [
        'allowed_skills' => json_encode(['invoice-drafting', 'some-other-skill']),
    ]);
    $config->putPrincipalSettings(SkillTool::class, $principalId, [
        'allowed_skills' => json_encode(['invoice-drafting']),
    ]);

    // Guard the premise: the rows really do hold a string, not an array.
    expect($config->getRawAgentOverride(SkillTool::class, $agentId)['allowed_skills'])->toBeString()
        ->and($config->getPrincipalSettings(SkillTool::class, $principalId)['allowed_skills'])->toBeString();

    $response = $this->controller->allowlist(skillRequest(
        'GET',
        '/api/v1/custom-skills/invoice-drafting/allowlist',
        'invoice-drafting',
    ));
    $data = skillData($response);

    expect($response->getStatusCode())->toBe(200)
        ->and($data['agents'])->toContain(['id' => $agentId, 'name' => 'Invoicer', 'scope' => 'agent'])
        ->and($data['agents'])->toContain(['id' => 0, 'name' => null, 'scope' => 'principal']);
});

it('scrubs a JSON-string allowlist, so the delete really removes the name', function (): void {
    $userId = bootAuth($this->auth);
    $principalId = createUserPrincipal($userId);

    makeSkill($this->writer, $principalId, 'invoice-drafting');

    /** @var ToolConfigServiceInterface $config */
    $config = $this->config;
    $agentId = createAgentForPrincipal($principalId, 'Invoicer');
    $config->putAgentOverride(SkillTool::class, $agentId, [
        'allowed_skills' => json_encode(['invoice-drafting', 'some-other-skill']),
    ]);

    $response = $this->controller->destroy(skillRequest('DELETE', '/api/v1/custom-skills/invoice-drafting', 'invoice-drafting'));

    expect(skillData($response)['scrubbed_agents'])->toBe([['id' => $agentId, 'name' => 'Invoicer', 'scope' => 'agent']])
        ->and($config->getRawAgentOverride(SkillTool::class, $agentId)['allowed_skills'])
        ->toBe(['some-other-skill']);
});

/**
 * D12's one-step restore. `update()` must snapshot what it replaces *before*
 * applying the new state, or `restore()` puts back what is already live.
 */
it('restores the previous body after an update', function (): void {
    $userId = bootAuth($this->auth);
    $principalId = createUserPrincipal($userId);

    makeSkill($this->writer, $principalId, 'invoice-drafting', ['body' => "# Original\n\n1. Original step.\n"]);

    $updated = $this->controller->update(skillRequest(
        'PUT',
        '/api/v1/custom-skills/invoice-drafting',
        'invoice-drafting',
        ['body' => "# Rewritten\n\n1. New step.\n"],
    ));
    expect($updated->getStatusCode())->toBe(200)
        ->and(skillData($updated)['skill']['body'])->toBe("# Rewritten\n\n1. New step.\n")
        ->and(skillData($updated)['skill']['has_previous'])->toBeTrue();

    $response = $this->controller->restore(skillRequest(
        'POST',
        '/api/v1/custom-skills/invoice-drafting/restore',
        'invoice-drafting',
    ));
    $restored = skillData($response)['skill'];

    expect($response->getStatusCode())->toBe(200)
        ->and($restored['body'])->toBe("# Original\n\n1. Original step.\n")
        ->and($restored['name'])->toBe('invoice-drafting')
        // Restore is itself undoable: the live state is re-snapshotted first.
        ->and($restored['has_previous'])->toBeTrue();

    $again = $this->controller->restore(skillRequest(
        'POST',
        '/api/v1/custom-skills/invoice-drafting/restore',
        'invoice-drafting',
    ));
    expect($again->getStatusCode())->toBe(200)
        ->and(skillData($again)['skill']['body'])->toBe("# Rewritten\n\n1. New step.\n");
});

it('409s a restore on a skill that was never edited', function (): void {
    $userId = bootAuth($this->auth);
    $principalId = createUserPrincipal($userId);

    makeSkill($this->writer, $principalId, 'never-edited-skill');

    $noPrevious = $this->controller->restore(skillRequest(
        'POST',
        '/api/v1/custom-skills/never-edited-skill/restore',
        'never-edited-skill',
    ));

    expect(skillError($noPrevious))->toBe(['status' => 409, 'code' => 'NO_PREVIOUS_VERSION']);
});

it('422s a malformed JSON body instead of 500ing', function (): void {
    $userId = bootAuth($this->auth);
    createUserPrincipal($userId);

    $request = jsonRequest('POST', '/api/v1/custom-skills');
    $request->initialize([], [], [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"name": "invoice-drafting"');

    $response = $this->controller->store($request);
    $error = skillError($response);

    expect($error['status'])->toBe(422)
        ->and($error['code'])->toBe('VALIDATION_ERROR')
        ->and($response->getStatusCode())->not->toBe(500);

    $updateRequest = skillRequest('PUT', '/api/v1/custom-skills/invoice-drafting', 'invoice-drafting');
    $updateRequest->initialize([], [], [], [], [], ['CONTENT_TYPE' => 'application/json'], 'not json at all');

    $updateError = skillError($this->controller->update($updateRequest));
    expect($updateError['status'])->toBe(422)
        ->and($updateError['code'])->toBe('VALIDATION_ERROR');
});

it('persists the sidecar files supplied on POST and lists them after SKILL.md', function (): void {
    $userId = bootAuth($this->auth);
    $principalId = createUserPrincipal($userId);

    $response = $this->controller->store(jsonRequest('POST', '/api/v1/custom-skills', [
        'name' => 'invoice-drafting',
        'description' => 'How to draft an invoice.',
        'body' => "# Steps\n\n1. Draft.\n",
        'files' => [
            'examples/invoice.md' => "Sample.\n",
            'reference/rates.md' => "2026 rates.\n",
        ],
    ]));

    expect($response->getStatusCode())->toBe(201);

    $payload = skillData($response)['skill'];
    expect(array_column($payload['files'], 'path'))->toBe(['SKILL.md', 'examples/invoice.md', 'reference/rates.md'])
        ->and($payload['files'][1]['bytes'])->toBe(8);

    $fetched = $this->controller->file(skillFileRequest(
        'GET',
        '/api/v1/custom-skills/invoice-drafting/files/reference%2Frates.md',
        'invoice-drafting',
        'reference%2Frates.md',
    ));
    expect(skillData($fetched))->toBe([
        'path' => 'reference/rates.md',
        'content' => "2026 rates.\n",
        'bytes' => 12,
    ]);
});
