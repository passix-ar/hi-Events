<?php

declare(strict_types=1);

use HiEvents\Assistant\Http\Actions\ChatWithAssistantAction;
use Illuminate\Support\Facades\Route;

Route::post('/organizers/{organizer_id}/assistant/chat', ChatWithAssistantAction::class);
