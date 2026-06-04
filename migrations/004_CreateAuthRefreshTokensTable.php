<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Refresh tokens. session_uuid + user_uuid FK their respective tables (both intra-package).
 */
class CreateAuthRefreshTokensTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('auth_refresh_tokens')) {
            return;
        }

        $schema->createTable('auth_refresh_tokens', function ($table) {
            $table->bigInteger('id')->unsigned()->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('session_uuid', 12);
            $table->string('user_uuid', 12);
            $table->string('token_hash', 64);
            $table->string('status', 20)->default('active');
            $table->string('parent_uuid', 12)->nullable();
            $table->string('replaced_by_uuid', 12)->nullable();
            $table->timestamp('issued_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

            $table->unique('uuid');
            $table->unique('token_hash');
            $table->index('session_uuid');
            $table->index('user_uuid');
            $table->index('status');
            $table->index('expires_at');
            $table->index('parent_uuid');

            $table->foreign('session_uuid')
                ->references('uuid')
                ->on('auth_sessions')
                ->restrictOnDelete();

            $table->foreign('user_uuid')
                ->references('uuid')
                ->on('users')
                ->restrictOnDelete();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('auth_refresh_tokens');
    }

    public function getDescription(): string
    {
        return 'Create the auth_refresh_tokens table (FKs to auth_sessions + users).';
    }
}
