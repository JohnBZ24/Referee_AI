<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AI\AIService;
use Illuminate\Http\JsonResponse;

class ModelController extends Controller
{
    public function __construct(private readonly AIService $aiService) {}

    public function index(): JsonResponse
    {
        return response()->json($this->aiService->listModels());
    }
}
