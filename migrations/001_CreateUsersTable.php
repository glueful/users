<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Canonical users table (glueful/users). Owns identity + credentials + account status, plus the
 * two_factor_enabled flag (folded into the create — no separate ALTER needed for a fresh table).
 */
class CreateUsersTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('users')) {
            return;
        }

        $schema->createTable('users', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('username', 255);
            $table->string('email', 255);
            $table->string('password', 100)->nullable();
            $table->string('status', 20)->default('active');
            $table->boolean('two_factor_enabled')->notNull()->default(false);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('deleted_at')->nullable();

            $table->unique('uuid');
            $table->unique('username');
            $table->unique('email');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('users');
    }

    public function getDescription(): string
    {
        return 'Create the canonical users table (identity, credentials, status, 2FA flag).';
    }
}
