<?php

declare(strict_types=1);

use HiEvents\Assistant\Http\Actions\ChatWithAssistantAction;
use HiEvents\Assistant\Http\Actions\StreamChatWithAssistantAction;
use HiEvents\Assistant\Http\Actions\UploadAssistantAttachmentAction;
use Illuminate\Support\Facades\Route;

Route::post('/organizers/{organizer_id}/assistant/chat', ChatWithAssistantAction::class);
Route::post('/organizers/{organizer_id}/assistant/chat/stream', StreamChatWithAssistantAction::class);
Route::post('/organizers/{organizer_id}/assistant/attachments', UploadAssistantAttachmentAction::class);
