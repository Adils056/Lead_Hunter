<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class Integration extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider',
        'name',
        'description',
        'credentials',
        'settings',
        'is_active',
        'status',
        'last_tested_at',
        'last_error',
        'monthly_spend_limit_cents',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_active' => 'boolean',
            'last_tested_at' => 'datetime',
            'monthly_spend_limit_cents' => 'integer',
        ];
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(IntegrationSyncLog::class);
    }

    public function setEncryptedCredentialsAttribute(array $data): void
    {
        $this->attributes['credentials'] = Crypt::encryptString(json_encode($data));
    }

    public function getDecryptedCredentials(): array
    {
        if (empty($this->credentials)) {
            return [];
        }

        try {
            $decrypted = Crypt::decryptString($this->credentials);
            return json_decode($decrypted, true) ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }

    public function getMaskedKey(string $keyName = 'api_key'): string
    {
        $creds = $this->getDecryptedCredentials();
        $val = $creds[$keyName] ?? '';
        if (empty($val)) {
            return 'Not Configured';
        }

        if (strlen($val) <= 8) {
            return '••••••••';
        }

        return substr($val, 0, 4) . '••••••••' . substr($val, -4);
    }
}
