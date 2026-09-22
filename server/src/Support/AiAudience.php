<?php

namespace Fleetbase\Ai\Support;

use Fleetbase\Support\Auth;

/**
 * Describes who Fleetbase AI is answering, so knowledge, commands, and prompt rules can be scoped.
 *
 * Only a user whose `type` is `admin` is a system admin. Organization roles (including an
 * "Administrator" role) never grant system-admin audience.
 */
class AiAudience
{
    public const END_USER     = 'end_user';
    public const DEVELOPER    = 'developer';
    public const SYSTEM_ADMIN = 'system_admin';

    public function __construct(public readonly bool $isSystemAdmin = false, public readonly ?string $userUuid = null)
    {
    }

    public static function forUser($user): static
    {
        return new static(data_get($user, 'type') === 'admin', data_get($user, 'uuid'));
    }

    public static function endUser(): static
    {
        return new static(false);
    }

    /**
     * Whether content tagged for the given audience may be shown.
     */
    public function allows(?string $audience): bool
    {
        return match ($audience) {
            self::SYSTEM_ADMIN => $this->isSystemAdmin,
            self::DEVELOPER    => $this->isSystemAdmin || $this->can('developers see api-key'),
            default            => true,
        };
    }

    /**
     * Whether the user holds all of the given IAM permissions. System admins hold every permission.
     */
    public function canAll(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (!$this->can($permission)) {
                return false;
            }
        }

        return true;
    }

    public function can(string $permission): bool
    {
        return $this->isSystemAdmin || $this->checkPermission($permission);
    }

    public function toArray(): array
    {
        return [
            'is_system_admin' => $this->isSystemAdmin,
        ];
    }

    /**
     * @codeCoverageIgnore
     */
    protected function checkPermission(string $permission): bool
    {
        return Auth::can($permission);
    }
}
