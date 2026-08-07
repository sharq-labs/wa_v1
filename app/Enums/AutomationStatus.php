<?php

namespace App\Enums;

/**
 * Per-conversation bot status.
 */
enum AutomationStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
}
