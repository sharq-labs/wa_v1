<?php

namespace App\Enums;

enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Manager = 'manager';
    case Agent = 'agent';
    case Viewer = 'viewer';

    public function level(): int
    {
        return match ($this) {
            self::Owner => 50,
            self::Admin => 40,
            self::Manager => 30,
            self::Agent => 20,
            self::Viewer => 10,
        };
    }

    public function atLeast(self $role): bool
    {
        return $this->level() >= $role->level();
    }
}
