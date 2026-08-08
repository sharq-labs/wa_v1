<?php

namespace App\Enums;

enum NodeType: string
{
    // Triggers
    case TriggerIncomingMessage = 'trigger_incoming_message';
    case TriggerKeyword = 'trigger_keyword';
    case TriggerNewContact = 'trigger_new_contact';
    case TriggerButtonClick = 'trigger_button_click';
    case TriggerListSelection = 'trigger_list_selection';
    case TriggerTagAdded = 'trigger_tag_added';
    case TriggerTagRemoved = 'trigger_tag_removed';
    case TriggerFieldChanged = 'trigger_field_changed';
    case TriggerWebhook = 'trigger_webhook';
    case TriggerScheduled = 'trigger_scheduled';

    // Actions
    case SendText = 'send_text';
    case SendImage = 'send_image';
    case SendVideo = 'send_video';
    case SendAudio = 'send_audio';
    case SendDocument = 'send_document';
    case SendTemplate = 'send_template';
    case SendButtons = 'send_buttons';
    case SendList = 'send_list';
    case AskQuestion = 'ask_question';
    case SetCustomField = 'set_custom_field';
    case ClearCustomField = 'clear_custom_field';
    case AddTag = 'add_tag';
    case RemoveTag = 'remove_tag';
    case AssignAgent = 'assign_agent';
    case UnassignAgent = 'unassign_agent';
    case PauseBot = 'pause_bot';
    case ResumeBot = 'resume_bot';
    case CloseConversation = 'close_conversation';
    case ReopenConversation = 'reopen_conversation';
    case StartAutomation = 'start_automation';
    case StopAutomation = 'stop_automation';
    case HttpRequest = 'http_request';
    case SendWebhook = 'send_webhook';
    case AddNote = 'add_note';
    case Goal = 'goal';

    // Control
    case Condition = 'condition';
    case Delay = 'delay';
    case WaitUntil = 'wait_until';
    case RandomSplit = 'random_split';
    case GoToNode = 'go_to_node';
    case Stop = 'stop';

    public function isTrigger(): bool
    {
        return str_starts_with($this->value, 'trigger_');
    }
}
