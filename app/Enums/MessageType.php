<?php

namespace App\Enums;

enum MessageType: string
{
    case Text = 'text';
    case Image = 'image';
    case Video = 'video';
    case Audio = 'audio';
    case Document = 'document';
    case Template = 'template';
    case Interactive = 'interactive';
    case Button = 'button';
    case Location = 'location';
    case Contacts = 'contacts';
    case Reaction = 'reaction';
    case System = 'system';
    case Unknown = 'unknown';
}
