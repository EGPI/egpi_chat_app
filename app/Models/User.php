<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar_url',
        'phone',
        'is_active',
        'last_seen_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function conversationParticipants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    public function createdConversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'created_by');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    public function messageReceipts(): HasMany
    {
        return $this->hasMany(MessageReceipt::class);
    }

    public function syncEvents(): HasMany
    {
        return $this->hasMany(SyncEvent::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }
}