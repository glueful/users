<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * User profiles. user_uuid FKs users (intra-package). photo_uuid is an INDEXED uuid with NO FK —
 * blobs is an app/platform table (skeleton), and cross-package FKs are disallowed (spec §2);
 * integrity for photo_uuid is enforced at the service layer.
 */
class CreateProfilesTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('profiles')) {
            return;
        }

        $schema->createTable('profiles', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('user_uuid', 12);
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('photo_uuid', 12)->nullable();
            $table->string('photo_url', 255)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('deleted_at')->nullable();

            $table->unique('uuid');
            $table->unique('user_uuid');
            $table->index('photo_uuid'); // indexed only — no cross-package FK to blobs (spec §2)

            // Intra-package FK to users.
            $table->foreign('user_uuid')
                ->references('uuid')
                ->on('users')
                ->restrictOnDelete();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('profiles');
    }

    public function getDescription(): string
    {
        return 'Create the profiles table (FK to users; photo_uuid indexed, no cross-package FK).';
    }
}
