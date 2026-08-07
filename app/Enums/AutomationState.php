<?php

namespace App\Enums;

/**
 * Lifecycle state of an automation definition.
 */
enum AutomationState: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Paused = 'paused';
    case Archived = 'archived';
}
