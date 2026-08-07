<?php

namespace App\Enums;

enum MessageStatus: string
{
    case Queued = 'queued';
    case Received = 'received';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';
}
