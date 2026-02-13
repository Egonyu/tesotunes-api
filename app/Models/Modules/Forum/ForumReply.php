<?php

namespace App\Models\Modules\Forum;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ForumReply extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'forum_replies';

    protected $fillable = [
        'topic_id',
        'user_id',
        'parent_id',
        'content',
        'likes_count',
        'is_solution',
        'is_highlighted',
    ];

    protected $casts = [
        'likes_count' => 'integer',
        'is_solution' => 'boolean',
        'is_highlighted' => 'boolean',
    ];

    // Relationships
    public function topic(): BelongsTo
    {
        return $this->belongsTo(ForumTopic::class, 'topic_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
