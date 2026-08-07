<?php

namespace App\Enums;

enum AgentStatus: string
{
    case Online = 'online';
    case Offline = 'offline';
    case Away = 'away';
    case Busy = 'busy';
}
