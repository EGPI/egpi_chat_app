<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'type',
        'title',
        'avatar_url',
        'created_by',
        'metadata',
        'last_message_id',
        'last_message_sender_id',
        'last_message_preview',
        'last_message_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'last_message_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_message_id');
    }

    public function lastMessageSender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_message_sender_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function syncEvents(): HasMany
    {
        return $this->hasMany(SyncEvent::class);
    }
}