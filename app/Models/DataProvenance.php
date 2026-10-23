<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class DataProvenance extends Model
{
    protected $table = 'data_provenance';

    protected $fillable = [
        'subject_type', 'subject_id', 'field', 'value', 'source', 'source_url', 'method', 'confidence', 'collected_at',
    ];

    protected function casts(): array
    {
        return ['collected_at' => 'datetime', 'confidence' => 'integer'];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Record where one or more fields came from. Null/empty values are skipped:
     * we never record provenance for data we did not actually obtain.
     */
    public static function record(Model $subject, array $fields, string $source, ?string $sourceUrl = null, string $method = 'api', int $confidence = 80): void
    {
        foreach ($fields as $field => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            static::create([
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'field' => $field,
                'value' => is_scalar($value) ? (string) $value : json_encode($value),
                'source' => $source,
                'source_url' => $sourceUrl ? substr($sourceUrl, 0, 500) : null,
                'method' => $method,
                'confidence' => $confidence,
                'collected_at' => now(),
            ]);
        }
    }
}
