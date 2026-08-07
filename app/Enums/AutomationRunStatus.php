<?php

namespace App\Enums;

enum AutomationRunStatus: string
{
    case Running = 'running';
    case Waiting = 'waiting';
    case Paused = 'paused';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
