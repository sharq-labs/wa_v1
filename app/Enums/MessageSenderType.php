<?php

namespace App\Enums;

enum MessageSenderType: string
{
    case Contact = 'contact';
    case Bot = 'bot';
    case Agent = 'agent';
    case System = 'system';
}
