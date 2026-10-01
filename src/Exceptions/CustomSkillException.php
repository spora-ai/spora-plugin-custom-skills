<?php

declare(strict_types=1);

namespace Spora\Plugins\CustomSkills\Exceptions;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The single failure type for every custom-skill operation. Carries the HTTP
 * envelope rather than only a message, so the controller is a pure translator
 * and the LLM tool can render the same failure without knowing status codes.
 * One class rather than a per-code hierarchy: the codes are consumed as strings
 * on the wire, so a subclass per code would add indirection without adding a
 * decision.
 */
final class CustomSkillException extends RuntimeException
{
    /**
     * @param string               $errorCode Machine-readable code in the `{"error":{"code":…}}` envelope.
     * @param array<string, mixed> $data      Merged into the envelope's `data` key — `ValidationResult`
     *                                        entries for a `SKILL_INVALID`, for example.
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = Response::HTTP_UNPROCESSABLE_ENTITY,
        public readonly array $data = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function validation(string $message, array $data = []): self
    {
        return new self('VALIDATION_ERROR', $message, Response::HTTP_UNPROCESSABLE_ENTITY, $data);
    }

    /**
     * @param list<array<string, mixed>> $errors `ValidationResult::errors()`, verbatim.
     * @param list<array<string, mixed>> $warnings
     */
    public static function skillInvalid(array $errors, array $warnings = []): self
    {
        return new self(
            'SKILL_INVALID',
            'The skill failed frontmatter validation.',
            Response::HTTP_UNPROCESSABLE_ENTITY,
            ['errors' => $errors, 'warnings' => $warnings],
        );
    }

    public static function notFound(string $name): self
    {
        return new self('SKILL_NOT_FOUND', "Skill '{$name}' was not found.", Response::HTTP_NOT_FOUND);
    }

    public static function fileNotFound(string $path): self
    {
        return new self('FILE_NOT_FOUND', "'{$path}' is not a file of this skill.", Response::HTTP_NOT_FOUND);
    }

    public static function forbidden(string $message): self
    {
        return new self('FORBIDDEN', $message, Response::HTTP_FORBIDDEN);
    }

    public static function nameTaken(string $name): self
    {
        return new self('SKILL_NAME_TAKEN', "A skill named '{$name}' already exists on this principal.", Response::HTTP_CONFLICT);
    }

    /**
     * A collision with a shipped (filesystem) skill. A separate code from
     * {@see self::nameTaken()} because the fix differs: the other one means
     * pick a different name, this one means the name is permanently taken
     * by core and no retry will clear it.
     */
    public static function nameReserved(string $name): self
    {
        return new self(
            'SKILL_NAME_RESERVED',
            "'{$name}' is the name of a shipped skill. A custom skill cannot shadow it.",
            Response::HTTP_CONFLICT,
        );
    }

    public static function noPreviousVersion(string $name): self
    {
        return new self(
            'NO_PREVIOUS_VERSION',
            "Skill '{$name}' has no previous version to restore.",
            Response::HTTP_CONFLICT,
        );
    }

    public static function limitReached(int $limit): self
    {
        return new self(
            'SKILL_LIMIT_REACHED',
            "This principal already has the maximum of {$limit} custom skills. Delete one before creating another.",
        );
    }

    public static function tooManyFiles(int $limit): self
    {
        return new self('TOO_MANY_FILES', "A skill may have at most {$limit} sidecar files.");
    }

    public static function totalSizeExceeded(int $limit, int $actual): self
    {
        return new self(
            'TOTAL_SIZE_EXCEEDED',
            "Skill totals {$actual} bytes, over the {$limit}-byte budget.",
        );
    }

    public static function fileTooLarge(string $path, int $limit): self
    {
        return new self(
            'FILE_TOO_LARGE',
            "'{$path}' exceeds the {$limit}-byte per-file limit.",
        );
    }

    public static function descriptionTooLong(int $limit): self
    {
        return new self(
            'DESCRIPTION_TOO_LONG',
            "description must be at most {$limit} characters.",
        );
    }
}
