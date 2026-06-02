<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\UserSearchController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/users/search', [UserSearchController::class, 'index']);

    Route::get('/conversations', [ConversationController::class, 'index']);
    Route::post('/conversations/direct', [ConversationController::class, 'createDirect']);
    Route::post('/conversations/group', [ConversationController::class, 'createGroup']);
    Route::post('/conversations/announcement', [ConversationController::class, 'createAnnouncement']);

    Route::post('/conversations/{conversation}/members', [ConversationController::class, 'addMember']);
    Route::delete('/conversations/{conversation}/members/{user}', [ConversationController::class, 'removeMember']);
    Route::post('/conversations/{conversation}/admins/{user}', [ConversationController::class, 'promoteMember']);

    Route::post('/conversations/{conversation}/messages', [MessageController::class, 'store']);
    Route::get('/conversations/{conversation}/messages', [MessageController::class, 'index']);

    Route::post('/messages/{message}/delivered', [MessageController::class, 'markDelivered']);
    Route::post('/conversations/{conversation}/read', [MessageController::class, 'markConversationRead']);

    Route::get('/sync', [SyncController::class, 'index']);
});