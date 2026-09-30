<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Vault\ProviderRegistry;
use Illuminate\Http\JsonResponse;

class ProviderController extends Controller
{
    public function index(ProviderRegistry $registry): JsonResponse
    {
        return response()->json($registry->toArray());
    }
}
