<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'service_category',
        'subject',
        'body_html',
        'body_text',
        'available_variables',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'available_variables' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    public function renderSubject(array $data): string
    {
        return $this->replaceVariables($this->subject, $data);
    }

    public function renderBody(array $data): string
    {
        return $this->replaceVariables($this->body_text, $data);
    }

    protected function replaceVariables(string $template, array $data): string
    {
        foreach ($data as $key => $val) {
            $template = str_replace('{{' . $key . '}}', (string) $val, $template);
            $template = str_replace('{{ ' . $key . ' }}', (string) $val, $template);
        }
        return $template;
    }
}
