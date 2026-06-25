<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CallController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\UserDeviceController;
use App\Http\Controllers\Api\UserSearchController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::post('/devices', [UserDeviceController::class, 'store']);
    Route::delete('/devices/{deviceUuid}', [UserDeviceController::class, 'revoke']);

    Route::get('/users/search', [UserSearchController::class, 'index']);

    Route::get('/conversations', [ConversationController::class, 'index']);
    Route::post('/conversations/direct', [ConversationController::class, 'createDirect']);
    Route::post('/conversations/group', [ConversationController::class, 'createGroup']);
    Route::post('/conversations/announcement', [ConversationController::class, 'createAnnouncement']);

    Route::get('/conversations/{conversation}/details', [ConversationController::class, 'details']);
    Route::post('/conversations/{conversation}/members', [ConversationController::class, 'addMember']);
    Route::delete('/conversations/{conversation}/members/{user}', [ConversationController::class, 'removeMember']);
    Route::post('/conversations/{conversation}/admins/{user}', [ConversationController::class, 'promoteMember']);

    Route::post('/conversations/{conversation}/messages', [MessageController::class, 'store']);
    Route::get('/conversations/{conversation}/messages', [MessageController::class, 'index']);

    Route::post('/conversations/{conversation}/calls/audio/start', [CallController::class, 'startAudio']);
    Route::post('/calls/{call}/accept', [CallController::class, 'accept']);
    Route::post('/calls/{call}/reject', [CallController::class, 'reject']);
    Route::post('/calls/{call}/end', [CallController::class, 'end']);
    Route::post('/calls/{call}/signal', [CallController::class, 'signal']);
    Route::get('/calls/{call}', [CallController::class, 'show']);

    Route::post('/messages/{message}/delivered', [MessageController::class, 'markDelivered']);
    Route::post('/conversations/{conversation}/read', [MessageController::class, 'markConversationRead']);

    Route::get('/sync', [SyncController::class, 'index']);
});
