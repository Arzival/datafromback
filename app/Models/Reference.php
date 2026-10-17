<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * Borrar una referencia la manda a la papelera (soft delete): sale del
 * catálogo y de la exportación de gusto, pero conserva archivos y etiquetas
 * hasta que se borra para siempre.
 */
class Reference extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rejected_tags' => 'array',
            'bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration_ms' => 'integer',
            'cost_usd' => 'float',
        ];
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->withPivot('confidence');
    }

    /** Ruta absoluta del original. Es lo que se inyecta en los prompts. */
    public function absolutePath(): string
    {
        return Storage::disk(config('vault.disk'))->path($this->path);
    }

    public function absoluteThumbPath(): ?string
    {
        return $this->thumb_path
            ? Storage::disk(config('vault.disk'))->path($this->thumb_path)
            : null;
    }
}
