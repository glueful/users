<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Repositories;

use Glueful\Repository\BaseRepository;
use Glueful\Helpers\Utils;
use Glueful\DTOs\{UsernameDTO, EmailDTO};
use Glueful\Validation\Validator;
use Glueful\Database\Connection;
use Glueful\Http\Exceptions\Domain\DatabaseException;
use Glueful\Bootstrap\ApplicationContext;

/**
 * User Repository
 *
 * Handles all database operations related to users:
 * - User retrieval by various identifiers
 * - Profile data management
 * - User data management (role functionality moved to RBAC extension)
 * - Password management
 *
 * This repository extends BaseRepository to leverage common CRUD operations
 * and audit logging functionality.
 *
 * @package Glueful\Repository
 */
class UserRepository extends BaseRepository
{
    // Note: Role functionality migrated to RBAC extension

    /** @var Validator Data validator instance */
    private Validator $validator;

    /** @var array<string> Standard profile fields to retrieve */
    private array $userProfileFields = ['first_name', 'last_name', 'photo_uuid', 'photo_url'];

    /**
     * Initialize repository
     *
     * Sets up database connection and dependencies with optional dependency injection
     *
     * @param Connection|null $connection Database connection instance
     * @param Validator|null $validator Validator instance
     */
    public function __construct(
        ?Connection $connection = null,
        ?Validator $validator = null,
        ?ApplicationContext $context = null
    ) {
        // Configure repository settings before calling parent
        $this->defaultFields = ['*'];
        $this->hasUpdatedAt = false; // users table doesn't have updated_at column

        // Call parent constructor to set up database connection
        parent::__construct($connection, $context);

        // Initialize validator with dependency injection or fallback
        $this->validator = $validator ?? $this->createValidatorInstance();
        // Role repository functionality moved to RBAC extension
    }

    /**
     * Get the table name for this repository
     *
     * @return string The table name
     */
    public function getTableName(): string
    {
        return 'users';
    }

    /**
     * Find user by username
     *
     * Retrieves user record using the username identifier.
     * Performs validation on the username format before querying.
     *
     * @param string $username Username to search for
     * @return array<string, mixed>|null User data or null if not found, or validation errors array
     */
    public function findByUsername(string $username): ?array
    {
        // Validate username format using new Validation rules
        try {
            UsernameDTO::from(['username' => $username]);
        } catch (\Glueful\Validation\ValidationException $e) {
            return $e->errors();
        }

        // Use BaseRepository's findBy method
        return $this->findBy('username', $username);
    }



    /**
     * Find user by email address
     *
     * Retrieves user record using the email identifier.
     * Performs validation on the email format before querying.
     *
     * @param string $email Email address to search for
     * @return array<string, mixed>|null User data or null if not found, or validation errors array
     */
    public function findByEmail(string $email): ?array
    {
        // Validate email format using new Validation rules
        try {
            EmailDTO::from(['email' => $email]);
        } catch (\Glueful\Validation\ValidationException $e) {
            // Invalid email format - return null (user not found)
            return null;
        }

        // Use BaseRepository's findBy method
        return $this->findBy('email', $email);
    }

    /**
     * Find user by UUID
     *
     * Retrieves user record using the unique identifier.
     * Direct lookup without additional validation.
     *
     * @param string $uuid User UUID to search for
     * @param array<string>|null $fields Fields to select
     * @return array<string, mixed>|null User data or null if not found
     */
    public function findByUuid(string $uuid, ?array $fields = null): ?array
    {
        // Use BaseRepository's findRecordByUuid method since UUID is our primary key
        return $this->findRecordByUuid($uuid, $fields);
    }

    /**
     * Get user profile data
     *
     * Retrieves extended profile information for a user.
     * Includes personal details and profile images.
     *
     * @param string $uuid User UUID to get profile for
     * @return array<string, mixed>|null Profile data or null if not found
     */
    public function getProfile(string $uuid): ?array
    {
        // Create a query but use a different table than the default one
        $query = $this->db->table('profiles')
            ->select($this->userProfileFields)
            ->where(['user_uuid' => $uuid])
            ->limit(1)
            ->get();

        return $query !== [] ? $query[0] : null;
    }

    /**
     * Read a single non-deleted user row selecting ONLY the given columns (never SELECT *).
     *
     * @param list<string> $columns
     * @return array<string,mixed>|null
     */
    public function findAccountRow(string $uuid, array $columns): ?array
    {
        if ($columns === []) {
            return null;
        }
        $rows = $this->db->table('users')
            ->select($columns)
            ->where(['uuid' => $uuid])
            ->whereNull('deleted_at')
            ->limit(1)
            ->get();

        return $rows !== [] ? $rows[0] : null;
    }

    /**
     * Read a single non-deleted profile row selecting ONLY the given columns.
     *
     * @param list<string> $columns
     * @return array<string,mixed>|null
     */
    public function findProfileRow(string $uuid, array $columns): ?array
    {
        if ($columns === []) {
            return null;
        }
        $rows = $this->db->table('profiles')
            ->select($columns)
            ->where(['user_uuid' => $uuid])
            ->whereNull('deleted_at')
            ->limit(1)
            ->get();

        return $rows !== [] ? $rows[0] : null;
    }

    /**
     * Paginated `users LEFT JOIN profiles` reader for GET /users. Selects explicit, aliased columns
     * (account as-is; profile as `profile__<col>`) plus two control columns — `_p_present`
     * (profiles.user_uuid; null ⇒ no profile) and `_p_deleted_at` — used by the responder to PHP-null
     * absent/soft-deleted profiles. The QueryFilter (already soft-delete-guarded) is applied before
     * pagination. Soft-deleted USERS are excluded via `users.deleted_at IS NULL`.
     *
     * @param list<string> $accountCols effective `users` columns (never empty; resolver forces uuid)
     * @param list<string> $profileCols effective `profiles` columns (may be empty)
     * @return array{data: array<int,array<string,mixed>>, current_page:int, per_page:int, total:int, last_page:int, has_more:bool, from:int, to:int}
     */
    public function paginateUsersWithProfiles(
        array $accountCols,
        array $profileCols,
        \Glueful\Api\Filtering\QueryFilter $filter,
        int $page,
        int $perPage
    ): array {
        $select = [];
        foreach ($accountCols as $c) {
            $select[] = "users.$c AS $c";
        }
        foreach ($profileCols as $c) {
            $select[] = "profiles.$c AS profile__$c";
        }
        $select[] = 'profiles.user_uuid AS _p_present';
        $select[] = 'profiles.deleted_at AS _p_deleted_at';

        $qb = $this->db->table('users')
            ->selectRaw(implode(', ', $select))
            ->leftJoin('profiles', 'profiles.user_uuid', '=', 'users.uuid')
            ->whereNull('users.deleted_at');

        $filter->apply($qb);

        return $qb->paginate($page, $perPage);
    }

    /**
     * Get profiles for multiple users in a single query (bulk operation)
     *
     * @param array<string> $userUuids Array of user UUIDs
     * @return array<string, array<string, mixed>> Associative array indexed by user_uuid
     */
    public function getProfilesForUsers(array $userUuids): array
    {
        if ($userUuids === []) {
            return [];
        }

        // Remove duplicates and ensure we have valid UUIDs
        $userUuids = array_unique(array_filter($userUuids));
        if ($userUuids === []) {
            return [];
        }

        // Fetch all profiles in a single query
        $profiles = $this->db->table('profiles')
            ->select($this->userProfileFields)
            ->whereIn('user_uuid', $userUuids)
            ->get();

        // Return indexed by user_uuid for easy lookup
        return array_column($profiles, null, 'user_uuid');
    }


   /**
     * Update user password
     *
     * Sets a new password for the user identified by email or UUID.
     * The password should already be hashed before calling this method.
     *
     * Security considerations:
     * - Password should be properly hashed using PasswordHasher
     * - Previous sessions should be invalidated after password change
     * - User identity should be verified before allowing password changes
     *
     * @param string $identifier User's email or UUID
     * @param string $password New password (pre-hashed)
     * @param string|null $identifierType Type of identifier ('email' or 'uuid')
     * @return bool Success status
     */
    public function setNewPassword(string $identifier, string $password, ?string $identifierType = null): bool
    {
        // Determine identifier type if not specified
        if ($identifierType === null) {
            $identifierType = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'uuid';
        }

        // Find the user by the appropriate identifier
        $user = null;
        if ($identifierType === 'email') {
            $user = $this->findByEmail($identifier);
        } else {
            $user = $this->findByUuid($identifier);
        }
        if ($user === null) {
            return false; // User not found
        }

        // Get the currently authenticated user if available
        $currentUser = $this->getCurrentUser();
        $userId = $currentUser['uuid'] ?? null;

        // Update just the password field using the parent update method
        // The BaseRepository.auditDataAction method will automatically handle audit logging
        $success = parent::update($user['uuid'], [
            'password' => $password
        ]);
        return $success;
    }

    /**
     * Create new user
     *
     * Inserts a new user record with basic information.
     * Additional profile data should be added separately.
     *
     * @param array<string, mixed> $userData User data (username, email, password, etc.)
     * @return string New user UUID
     * @throws \InvalidArgumentException If validation fails
     */
    public function create(array $userData): string
    {
        // Validate required fields
        $required = ['username', 'email', 'password'];
        foreach ($required as $field) {
            if (($userData[$field] ?? '') === '') {
                throw new \InvalidArgumentException("Field '{$field}' is required");
            }
        }

        // Validate username and email using new Validation rules
        try {
            UsernameDTO::from(['username' => $userData['username']]);
        } catch (\Glueful\Validation\ValidationException $e) {
            throw new \InvalidArgumentException('Invalid username format');
        }

        try {
            EmailDTO::from(['email' => $userData['email']]);
        } catch (\Glueful\Validation\ValidationException $e) {
            throw new \InvalidArgumentException('Invalid email format');
        }

        // Check for duplicates
        if ($this->emailExists($userData['email'])) {
            throw new \InvalidArgumentException("Email '{$userData['email']}' already exists");
        }

        if ($this->usernameExists($userData['username'])) {
            throw new \InvalidArgumentException("Username '{$userData['username']}' already exists");
        }

        // Set default status if not provided
        if (!isset($userData['status'])) {
            $userData['status'] = 'active';
        }

        // Use parent create method which handles UUID generation and audit logging
        return parent::create($userData);
    }

    /**
     * Update user information
     *
     * Updates basic user account information.
     * Use this method for username, email, or status changes.
     * For password updates, use setNewPassword() instead.
     *
     * @param string|int $id User UUID to update
     * @param array<string, mixed> $userData Updated user data
     * @param string|null $updatedByUserId UUID of user making the update (for audit)
     * @return bool Success status
     */
    public function update($id, array $userData, ?string $updatedByUserId = null): bool
    {
        // Remove fields that shouldn't be updated directly
        unset($userData['password']); // Use setNewPassword for password changes
        unset($userData['uuid']);     // Primary key shouldn't be changed

        // Get current user ID for audit if not provided
        if ($updatedByUserId === null) {
            $currentUser = $this->getCurrentUser();
            $updatedByUserId = $currentUser['uuid'] ?? null;
        }

        // Use parent update method which handles existence check and audit logging
        return parent::update($id, $userData);
    }

    /**
     * Update user profile
     *
     * Updates or creates user profile information.
     * This includes personal details and preferences.
     *
     * @param string $uuid User UUID
     * @param array<string, mixed> $profileData Profile information to update
     * @param string|null $updatedByUserId UUID of user making the update (for audit)
     * @return bool Success status
     */
    public function updateProfile(string $uuid, array $profileData, ?string $updatedByUserId = null): bool
    {
        // Ensure user exists
        $user = $this->findByUuid($uuid);
        if ($user === null) {
            return false;
        }

        // Add user UUID to profile data
        $profileData['user_uuid'] = $uuid;

        // Check if profile exists
        $existingProfile = $this->getProfile($uuid);

        // Get current user ID for audit if not provided
        if ($updatedByUserId === null) {
            $currentUser = $this->getCurrentUser();
            $updatedByUserId = $currentUser['uuid'] ?? null;
        }

        $success = false;
        $oldTable = $this->table;
        $oldPrimaryKey = $this->primaryKey;

        try {
            // Temporarily change the table for this operation
            $this->table = 'profiles';
            $this->primaryKey = 'user_uuid';

            if ($existingProfile !== null) {
                // Update existing profile
                $success = parent::update($uuid, $profileData);
            } else {
                // Create new profile. The working primary key is user_uuid here, so
                // BaseRepository::create() won't generate the row's own uuid — set it explicitly
                // (profiles.uuid is NOT NULL + UNIQUE).
                if (empty($profileData['uuid'])) {
                    $profileData['uuid'] = Utils::generateNanoID();
                }
                $createResult = parent::create($profileData);
                $success = $createResult !== '';
            }
        } finally {
            // Restore the original table and primary key
            $this->table = $oldTable;
            $this->primaryKey = $oldPrimaryKey;
        }

        return $success;
    }

    /**
     * Find or create user from SAML authentication with transaction safety
     *
     * Locates existing users by email or creates new accounts from SAML authentication
     * data. Performs atomic operations within a database transaction to ensure data
     * consistency during user provisioning.
     *
     * **SAML Integration Process:**
     * 1. Validate required SAML attributes (email is mandatory)
     * 2. Search for existing user by email address
     * 3. Update existing user with SAML provider information
     * 4. Create new user account if not found, with pre-verified email
     * 5. Generate secure random password for SAML-only accounts
     * 6. Update login timestamps and provider metadata
     *
     * **Security Features:**
     * - Email verification automatically granted for SAML users
     * - Random password generation for security (SAML handles authentication)
     * - Transaction-based operations for data consistency
     * - Provider tracking for audit and security purposes
     *
     * **Usage Examples:**
     * ```php
     * // Process SAML login response
     * $samlData = [
     *     'email' => 'john.doe@company.com',
     *     'name' => 'John Doe',
     *     'first_name' => 'John',
     *     'last_name' => 'Doe',
     *     'saml_idp' => 'company-idp'
     * ];
     * $user = $repository->findOrCreateFromSaml($samlData);
     * ```
     *
     * @param array<string, mixed> $userData User data extracted from SAML attributes (email required)
     * @return array<string, mixed>|null User data array with updated provider info, or null on failure
     * @throws DatabaseException If user creation or update fails
     * @throws \InvalidArgumentException If required SAML attributes are missing
     * @throws \RuntimeException If transaction operations fail
     */
    public function findOrCreateFromSaml(array $userData): ?array
    {
        if (($userData['email'] ?? '') === '') {
            return null;
        }

        return $this->executeInTransaction(function () use ($userData) {
            $user = $this->findByEmail($userData['email']);

            if ($user !== null) {
                $this->update($user['uuid'], ['email_verified_at' => date('Y-m-d H:i:s')]);
                $this->upsertProfileNames((string) $user['uuid'], $userData);
                return $this->findByUuid($user['uuid']);
            }

            $uuid = $this->createExternalProviderUser($userData);
            if ($uuid === '') {
                throw new DatabaseException('Failed to create user');
            }
            $this->upsertProfileNames($uuid, $userData);

            return $this->findByUuid($uuid);
        });
    }


    /**
     * Find or create user from LDAP authentication with comprehensive attribute mapping
     *
     * Integrates with LDAP directory services to provision user accounts automatically.
     * Maps LDAP attributes to user fields and maintains synchronization with directory
     * data on each authentication.
     *
     * **LDAP Integration Process:**
     * 1. Validate required LDAP attributes (email is mandatory)
     * 2. Search for existing user by email address
     * 3. Update user with current LDAP directory information
     * 4. Create new user account if not found in database
     * 5. Map enterprise attributes (department, title, employee_id)
     * 6. Generate secure random password (LDAP handles authentication)
     * 7. Set email as pre-verified (trusted directory source)
     *
     * **Enterprise Attribute Mapping:**
     * - Basic: name, first_name, last_name, email, phone
     * - Organizational: department, title, company, employee_id
     * - Authentication: provider tracking and login timestamps
     *
     * **Security Features:**
     * - Directory-verified email addresses
     * - Secure password generation for backup authentication
     * - Provider metadata for security auditing
     * - Automatic account status management
     *
     * **Usage Examples:**
     * ```php
     * // Process LDAP authentication
     * $ldapData = [
     *     'email' => 'jane.smith@corp.com',
     *     'name' => 'Jane Smith',
     *     'department' => 'Engineering',
     *     'title' => 'Senior Developer',
     *     'employee_id' => 'EMP12345',
     *     'ldap_server' => 'corp-ldap'
     * ];
     * $user = $repository->findOrCreateFromLdap($ldapData);
     * ```
     *
     * @param array<string, mixed> $userData User data extracted from LDAP directory attributes
     * @return array<string, mixed>|null User data array with directory information, or null on failure
     * @throws \InvalidArgumentException If required LDAP attributes are missing
     * @throws \RuntimeException If user creation fails or LDAP data is corrupted
     * @throws \Exception If database operations fail during user provisioning
     */
    public function findOrCreateFromLdap(array $userData): ?array
    {
        try {
            if (($userData['email'] ?? '') === '') {
                return null;
            }

            return $this->executeInTransaction(function () use ($userData) {
                $user = $this->findByEmail($userData['email']);

                if ($user !== null) {
                    $this->update($user['uuid'], ['email_verified_at' => date('Y-m-d H:i:s')]);
                    $this->upsertProfileNames((string) $user['uuid'], $userData);
                    return $this->findByUuid($user['uuid']);
                }

                $uuid = $this->createExternalProviderUser($userData);
                $this->upsertProfileNames($uuid, $userData);

                return $this->findByUuid($uuid);
            });
        } catch (\Throwable $e) {
            // Log the error
            error_log('Error in findOrCreateFromLdap: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * @param array<string, mixed> $userData
     */
    private function createExternalProviderUser(array $userData): string
    {
        $email = (string) $userData['email'];

        return $this->create([
            'username' => $this->uniqueUsernameFromEmail($email),
            'email' => $email,
            'password' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
            'status' => 'active',
            'email_verified_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param array<string, mixed> $userData
     */
    private function upsertProfileNames(string $userUuid, array $userData): void
    {
        $firstName = (string) ($userData['first_name'] ?? '');
        $lastName = (string) ($userData['last_name'] ?? '');
        if ($firstName === '' && $lastName === '') {
            return;
        }

        $existing = $this->db->table('profiles')
            ->select(['uuid'])
            ->where(['user_uuid' => $userUuid])
            ->limit(1)
            ->get();

        $data = [];
        if ($firstName !== '') {
            $data['first_name'] = $firstName;
        }
        if ($lastName !== '') {
            $data['last_name'] = $lastName;
        }

        if ($existing !== []) {
            $this->db->table('profiles')->where(['user_uuid' => $userUuid])->update($data);
            return;
        }

        $this->db->table('profiles')->insert(array_merge([
            'uuid' => Utils::generateNanoID(),
            'user_uuid' => $userUuid,
            'status' => 'active',
        ], $data));
    }

    private function uniqueUsernameFromEmail(string $email): string
    {
        $base = strtolower((string) preg_replace('/[^a-zA-Z0-9._-]/', '_', explode('@', $email)[0] ?: 'user'));
        $base = trim($base, '._-');
        if ($base === '') {
            $base = 'user';
        }

        $candidate = $base;
        $suffix = 1;
        while ($this->usernameExists($candidate)) {
            $candidate = $base . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * Get the currently authenticated user
     *
     * Retrieves the current user directly from the session cache using the token in the request.
     * This avoids re-authenticating the request and is more efficient.
     *
     * @return array<string, mixed>|null User data or null if not authenticated
     */
    public function getCurrentUser(): ?array
    {
        try {
            // Extract token via container-resolved RequestContext
            if ($this->context === null || !$this->context->hasContainer()) {
                return null;
            }

            $requestContext = $this->context->getContainer()->get(\Glueful\Http\RequestContext::class);
            $token = $requestContext->getBearerToken();

            if ($token === null || $token === '') {
                return null;
            }

            // Get session data directly from the session cache
            $sessionCacheManager = $this->getSessionCacheManager();
            $sessionData = $sessionCacheManager->getSession($token);

            if ($sessionData !== null && isset($sessionData['user'])) {
                // Return the user data directly from session cache to avoid DB query
                return $sessionData['user'];
            }
        } catch (\Throwable $e) {
            // Silently handle auth errors - logging should continue to work
            // even if authentication fails
        }
        return null;
    }


    /**
     * Find active users
     *
     * @param array<string, string> $orderBy Sorting criteria
     * @param int|null $limit Maximum number of records
     * @return array<array<string, mixed>> Array of active users
     */
    public function findActive(array $orderBy = [], ?int $limit = null): array
    {
        return $this->findWhere(['status' => 'active'], $orderBy, $limit);
    }

    /**
     * Check if email exists
     *
     * @param string $email The email to check
     * @param string|null $excludeUuid UUID to exclude from check (for updates)
     * @return bool True if email exists
     */
    public function emailExists(string $email, ?string $excludeUuid = null): bool
    {
        $conditions = ['email' => $email];

        if ($excludeUuid !== null) {
            $conditions['uuid'] = ['!=', $excludeUuid];
        }

        return $this->count($conditions) > 0;
    }

    /**
     * Check if username exists
     *
     * @param string $username The username to check
     * @param string|null $excludeUuid UUID to exclude from check (for updates)
     * @return bool True if username exists
     */
    public function usernameExists(string $username, ?string $excludeUuid = null): bool
    {
        $conditions = ['username' => $username];

        if ($excludeUuid !== null) {
            $conditions['uuid'] = ['!=', $excludeUuid];
        }

        return $this->count($conditions) > 0;
    }

    /**
     * Create validator instance
     *
     * @return Validator Validator instance
     */
    private function createValidatorInstance(): Validator
    {
        return new Validator([]);
    }

    /**
     * Get session cache manager instance with proper fallback handling
     *
     * @return \Glueful\Auth\SessionCacheManager Session cache manager instance
     */
    private function getSessionCacheManager(): \Glueful\Auth\SessionCacheManager
    {
        try {
            if ($this->context === null) {
                throw new \RuntimeException('Container unavailable without ApplicationContext.');
            }
            return container($this->context)->get(\Glueful\Auth\SessionCacheManager::class);
        } catch (\Exception) {
            // Fallback to direct instantiation if container fails
            // SessionCacheManager requires a CacheStore, so create one
            $cacheStore = \Glueful\Helpers\CacheHelper::createCacheInstance($this->context);
            if ($cacheStore === null) {
                throw new \RuntimeException('Unable to create cache instance for SessionCacheManager');
            }
            return new \Glueful\Auth\SessionCacheManager($cacheStore, $this->context);
        }
    }

    /**
     * Update user last login timestamp
     *
     * @param string $uuid User UUID
     * @return bool True if successful
     */
    public function updateLastLogin(string $uuid): bool
    {
        return $this->update($uuid, [
            'last_login_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Soft-delete a user (set `deleted_at`). The read paths scope `users.deleted_at IS NULL`, so the
     * account disappears from `/me`/`/users` while the row (and its history) is preserved. A
     * data-access primitive — the calling app owns the policy (who may delete, self-delete guards, …).
     *
     * Overrides {@see \Glueful\Repository\BaseRepository::softDelete()} (a status-column variant) to
     * use this store's `deleted_at` convention; the inherited status params are intentionally unused.
     *
     * @param string $uuid User UUID
     * @return bool True if a row was updated
     */
    public function softDelete(string $uuid, string $statusColumn = 'status', $deletedValue = 'deleted'): bool
    {
        unset($statusColumn, $deletedValue);
        return $this->update($uuid, ['deleted_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Reverse a soft-delete (clear `deleted_at`).
     *
     * @param string $uuid User UUID
     * @return bool True if a row was updated
     */
    public function restore(string $uuid): bool
    {
        return $this->update($uuid, ['deleted_at' => null]);
    }

    /**
     * Deactivate users by UUIDs
     *
     * @param array<string> $uuids Array of user UUIDs
     * @return int Number of affected records
     */
    public function deactivateUsers(array $uuids): int
    {
        return $this->bulkUpdate($uuids, ['status' => 'inactive']);
    }
}
