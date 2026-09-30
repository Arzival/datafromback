<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Reference;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Forma exacta que consume el frontend (src/types/vault.ts). Las claves van
 * en camelCase a propósito: el contrato lo define el cliente TypeScript.
 *
 * @mixin Reference
 */
class ReferenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'title' => $this->title,
            'filename' => $this->filename,
            'imageUrl' => route('references.image', $this->id),
            'thumbUrl' => $this->thumb_path
                ? route('references.thumb', $this->id)
                : route('references.image', $this->id),
            // Ruta en disco: sin esto los prompts no pueden decirle a un
            // agente dónde está la captura.
            'filePath' => $this->absolutePath(),
            'width' => $this->width,
            'height' => $this->height,
            'bytes' => $this->bytes,
            'sourceUrl' => $this->source_url,
            'status' => $this->status,
            'error' => $this->error,
            'providerId' => $this->provider_id,
            'model' => $this->model,
            'summary' => $this->summary,
            'notes' => $this->notes,
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->map(fn ($tag) => [
                'category' => $tag->category,
                'value' => $tag->value,
                'confidence' => $tag->pivot->confidence !== null
                    ? (float) $tag->pivot->confidence
                    : null,
            ])->values(), []),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
