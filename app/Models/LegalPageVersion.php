<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegalPageVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'legal_page_id',
        'version_number',
        'title',
        'content',
        'changes',
        'changelog',
        'created_by',
    ];

    protected $casts = [
        'changes' => 'json',
        'created_at' => 'datetime',
    ];

    const UPDATED_AT = null;

    public function legalPage(): BelongsTo
    {
        return $this->belongsTo(LegalPage::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
