<?php

namespace App\Models;

use Illuminate\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'conversation_id',
        'sender_id',
        'client_message_id',
        'type',
        'body',
        'payload',
        'reply_to_message_id',
        'server_received_at',
        'sent_at',
        'edited_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'server_received_at' => 'datetime',
        'sent_at' => 'datetime',
        'edited_at' => 'datetime',
        'deleted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(MessageReceipt::class);
    }

    public function syncEvents(): HasMany
    {
        return $this->hasMany(SyncEvent::class);
    }

    public function replyToMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'reply_to_message_id');
    }
}