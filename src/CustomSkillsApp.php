<?php

declare(strict_types=1);

namespace Spora\Plugins\CustomSkills;

use Spora\Apps\VueAppInterface;

/**
 * Admin-panel metadata for the Custom Skills feature.
 *
 * `name()` must equal the manifest slug: the host resolves
 * `/plugins/<slug>/<entry()>` and `/apps/<slug>` from the same value, and
 * migration filenames must carry it verbatim. The hyphen is load-bearing in all
 * three, which is why it reads oddly next to hyphen-free plugin slugs.
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
     * `rose` — free where `violet` is taken by team-graph. An unknown token is
     * coerced to `primary` rather than rejected, so a typo would silently
     * repaint the tile. See `AppInterface::ACCENT_TOKENS`.
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
