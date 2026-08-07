<?php

namespace App\Http\Controllers\Api;

use App\Enums\MessageSenderType;
use App\Enums\MessageType;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsAppTemplate;
use App\Models\Workspace;
use App\Services\Messaging\MessageService;
use App\Services\Messaging\MessagingEligibilityService;
use App\Services\Templates\TemplateRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ConversationMessageController extends ApiController
{
    public function index(Request $request, Workspace $workspace, Conversation $conversation): JsonResponse
    {
        Gate::authorize('useInbox', $workspace);
        abort_unless($conversation->workspace_id === $workspace->id, 404);

        $messages = Message::query()
            ->where('conversation_id', $conversation->id)
            ->with('senderUser')
            ->orderByDesc('id')
            ->paginate(min((int) $request->query('per_page', 50), 100));

        return $this->success([
            'items' => MessageResource::collection(array_reverse($messages->items())),
            'meta' => [
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
                'total' => $messages->total(),
            ],
        ]);
    }

    public function sendText(Request $request, Workspace $workspace, Conversation $conversation, MessageService $messages, MessagingEligibilityService $eligibility): JsonResponse
    {
        Gate::authorize('useInbox', $workspace);
        abort_unless($conversation->workspace_id === $workspace->id, 404);

        $data = $request->validate([
            'text' => ['required', 'string', 'max:4096'],
            'reply_to' => ['nullable', 'integer'],
        ]);

        if (! $eligibility->canSendFreeForm($conversation)) {
            return $this->error(
                __('The customer service window is closed. Please select an approved WhatsApp template.'),
                ['window' => ['closed']],
                422,
            );
        }

        $message = $messages->sendText($conversation, $data['text'], [
            'sender_type' => MessageSenderType::Agent,
            'sender_user' => $request->user(),
            'reply_to' => $data['reply_to'] ?? null,
        ]);

        $this->recordFirstReply($conversation);

        return $this->success(new MessageResource($message), __('Message sent.'), 201);
    }

    public function sendMedia(Request $request, Workspace $workspace, Conversation $conversation, MessageService $messages, MessagingEligibilityService $eligibility): JsonResponse
    {
        Gate::authorize('useInbox', $workspace);
        abort_unless($conversation->workspace_id === $workspace->id, 404);

        $request->validate([
            'file' => ['required', 'file', 'max:16384',
                'mimes:jpg,jpeg,png,webp,mp4,3gp,mp3,aac,amr,ogg,opus,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv'],
            'caption' => ['nullable', 'string', 'max:1024'],
        ]);

        if (! $eligibility->canSendFreeForm($conversation)) {
            return $this->error(
                __('The customer service window is closed. Please select an approved WhatsApp template.'),
                ['window' => ['closed']],
                422,
            );
        }

        $file = $request->file('file');
        $mime = $file->getMimeType();
        $path = $file->store("media/{$workspace->id}", 'public');
        $url = url('/storage/'.$path);

        $type = match (true) {
            str_starts_with($mime, 'image/') => MessageType::Image,
            str_starts_with($mime, 'video/') => MessageType::Video,
            str_starts_with($mime, 'audio/') => MessageType::Audio,
            default => MessageType::Document,
        };

        $message = $messages->sendMedia($conversation, $type, $url, $request->input('caption'), $mime, [
            'sender_type' => MessageSenderType::Agent,
            'sender_user' => $request->user(),
        ]);

        $this->recordFirstReply($conversation);

        return $this->success(new MessageResource($message), __('Media sent.'), 201);
    }

    public function sendTemplate(Request $request, Workspace $workspace, Conversation $conversation, MessageService $messages, TemplateRenderer $renderer): JsonResponse
    {
        Gate::authorize('useInbox', $workspace);
        abort_unless($conversation->workspace_id === $workspace->id, 404);

        $data = $request->validate([
            'template_id' => ['required', 'integer'],
            'variable_mappings' => ['nullable', 'array'],
        ]);

        $template = WhatsAppTemplate::query()
            ->forWorkspace($workspace)
            ->findOrFail($data['template_id']);

        if (! $template->isApproved()) {
            return $this->error(__('Only approved templates can be sent.'));
        }

        $rendered = $renderer->render($template, $conversation, $data['variable_mappings'] ?? []);

        $message = $messages->sendTemplate($conversation, $template, $rendered['components'], $rendered['text'], [
            'sender_type' => MessageSenderType::Agent,
            'sender_user' => $request->user(),
        ]);

        $this->recordFirstReply($conversation);

        return $this->success(new MessageResource($message), __('Template sent.'), 201);
    }

    protected function recordFirstReply(Conversation $conversation): void
    {
        if (! $conversation->first_agent_reply_at) {
            $conversation->forceFill(['first_agent_reply_at' => now()])->save();
        }
    }
}
