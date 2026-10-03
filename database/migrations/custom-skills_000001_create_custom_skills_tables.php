<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Custom skills live in the database, not on disk, because the skill scan
 * roots have no principal dimension and core's `Skill` value object is bound
 * to a real directory (the read path does `realpath()` containment against
 * it). A row has no directory and cannot satisfy that contract, which is why
 * these arrive through a `SkillProviderInterface` instead of a scan root.
 *
 * The filename prefix is the manifest slug verbatim, hyphen included:
 * `DatabaseSchemaInstaller::validateMigrationFilenames()` requires
 * `custom-skills_` and throws `SchemaInstallFailedException` otherwise. It
 * reads as unusual next to every other plugin, whose slugs have no hyphen.
 *
 * `SKILL.md` is synthesised on read from the frontmatter columns plus `body`,
 * and sidecars are rows. The writer forces `name === slug`, so the
 * frontmatter name and the URL segment can never diverge.
 *
 * No `status` column: `manage_skill` writes go live on approval, so a
 * draft/published gate would be a second approval in front of the first.
 * `provenance` records who authored the current version and
 * `previous_snapshot` holds the replaced state for one-step restore.
 */
return new class extends Migration {
    public function up(): void
    {
        $schema = Capsule::schema();
        if (!$schema->hasTable('custom_skills')) {
            $schema->create('custom_skills', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('principal_id');
                $table->string('name', 64);
                $table->string('description', 1024);
                $table->string('license')->nullable();
                $table->string('compatibility', 500)->nullable();
                $table->text('allowed_tools')->nullable();
                $table->json('metadata')->nullable();
                $table->longText('body');
                $table->longText('previous_snapshot')->nullable();
                $table->string('provenance', 16)->default('human');
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->unsignedBigInteger('updated_by_user_id')->nullable();
                $table->timestamps();

                $table->foreign('principal_id')->references('id')->on('principals')->onDelete('cascade');
                $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('set null');
                $table->foreign('updated_by_user_id')->references('id')->on('users')->onDelete('set null');

                // The registry keys on the frontmatter `name`, and this is the
                // index the per-tick LLM projection reads through.
                $table->unique(['principal_id', 'name']);
            });
        }

        if (!$schema->hasTable('custom_skill_files')) {
            $schema->create('custom_skill_files', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('custom_skill_id');
                $table->string('path', 255);
                $table->longText('content');
                $table->unsignedInteger('bytes');
                $table->timestamps();

                $table->foreign('custom_skill_id')
                    ->references('id')
                    ->on('custom_skills')
                    ->onDelete('cascade');
                $table->unique(['custom_skill_id', 'path']);
            });
        }
    }

    public function down(): void
    {
        // Files first: they cascade from custom_skills, but dropping the
        // parent while the child's FK still points at it is engine-dependent.
        Capsule::schema()->dropIfExists('custom_skill_files');
        Capsule::schema()->dropIfExists('custom_skills');
    }
};
