<?php

declare(strict_types=1);

namespace Spora\Plugins\CustomSkills;

use Spora\Apps\VueAppInterface;

/**
 * `name()` must equal the manifest slug: the host resolves `/plugins/<slug>/<entry()>` and
 * `/apps/<slug>` from the same value, and migration filenames carry it verbatim.
 */
final class CustomSkillsApp implements VueAppInterface
{
    public function name(): string
    {
        return 'custom-skills';
    }

    public function displayName(): string
    {
        return 'Custom Skills';
    }

    public function description(): string
    {
        return 'Author skills for this principal, or ask an agent to.';
    }

    public function icon(): string
    {
        return 'sparkles';
    }

    /**
     * `rose`; `violet` is taken by team-graph. An unknown token is coerced to `primary`
     * rather than rejected, so a typo silently repaints the tile.
     */
    public function accent(): string
    {
        return 'rose';
    }

    public function entry(): string
    {
        return 'main.js';
    }
}
