<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Vault\ProviderRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreReferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'image' => ['required', 'image', 'mimes:png,jpg,jpeg,webp,avif,gif', 'max:20480'],
            'provider_id' => ['required', 'string', 'in:'.implode(',', array_keys(config('vault.providers')))],
            'model' => ['nullable', 'string', 'max:64'],
            'source_url' => ['nullable', 'url', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'image.required' => 'No llegó ninguna imagen.',
            'image.image' => 'El archivo no es una imagen.',
            'image.max' => 'La imagen supera los 20 MB.',
            'provider_id.in' => 'Ese motor de IA no existe.',
        ];
    }

    /** El motor puede existir en la config pero no estar instalado ahora. */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $providerId = $this->input('provider_id');

                if (! is_string($providerId) || $validator->errors()->has('provider_id')) {
                    return;
                }

                if (! app(ProviderRegistry::class)->isAvailable($providerId)) {
                    $validator->errors()->add(
                        'provider_id',
                        app(ProviderRegistry::class)->make($providerId)->unavailableReason()
                            ?? 'Ese motor no está disponible.',
                    );
                }
            },
        ];
    }
}
