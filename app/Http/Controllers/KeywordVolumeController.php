<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\KeywordVolumeEstimator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class KeywordVolumeController extends Controller
{
    public function __construct(private KeywordVolumeEstimator $estimator) {}

    public function index(): Response
    {
        return Inertia::render('volume/Index', [
            'initialResults' => [],
        ]);
    }
    // ------------------------------------------------------------------
    // POST /api/seo/keyword-volume/single
    // ------------------------------------------------------------------
    /**
     * Estimate volume for a single keyword.
     *
     * Request body:
     *   { "keyword": "best running shoes", "gl": "us", "hl": "en" }
     *
     * Response:
     *   {
     *     "keyword":         "best running shoes",
     *     "score":           0.7412,
     *     "tier":            "high",
     *     "tier_label":      "High",
     *     "volume_range":    "10,000–100,000",
     *     "volume_estimate": 30000,
     *     "cached":          false,
     *     "signals": { ... }
     *   }
     */
    public function single(Request $request): JsonResponse
    {
        $data = $request->validate([
            'keyword' => ['required', 'string', 'max:255'],
            'gl'      => ['sometimes', 'string', 'size:2'],
            'hl'      => ['sometimes', 'string', 'max:5'],
        ]);

        $result = $this->estimator->estimate(
            keyword: $data['keyword'],
            gl:      $data['gl'] ?? 'us',
            hl:      $data['hl'] ?? 'en',
        );

        return response()->json($result);
    }

    // ------------------------------------------------------------------
    // POST /api/seo/keyword-volume/batch
    // ------------------------------------------------------------------
    /**
     * Estimate volumes for multiple keywords (max 50 per request).
     *
     * Request body:
     *   {
     *     "keywords": ["nike air max", "best running shoes", "trail running"],
     *     "gl": "us",
     *     "hl": "en",
     *     "delay_ms": 200
     *   }
     *
     * Response:
     *   { "results": [ { ... }, { ... } ] }
     */
    public function batch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'keywords'   => ['required', 'array', 'min:1', 'max:50'],
            'keywords.*' => ['required', 'string', 'max:255'],
            'gl'         => ['sometimes', 'string', 'size:2'],
            'hl'         => ['sometimes', 'string', 'max:5'],
            'delay_ms'   => ['sometimes', 'integer', 'min:0', 'max:2000'],
        ]);

        $results = $this->estimator->estimateBatch(
            keywords: $data['keywords'],
            gl:       $data['gl']       ?? 'us',
            hl:       $data['hl']       ?? 'en',
            delayMs:  $data['delay_ms'] ?? 200,
        );

        return response()->json(['results' => $results]);
    }

    // ------------------------------------------------------------------
    // GET /api/seo/keyword-volume/tiers
    // ------------------------------------------------------------------
    /**
     * Return the volume tier definitions (useful for UI rendering).
     *
     * Response:
     *   { "tiers": [ { "tier": "very_high", "label": "Very High", ... } ] }
     */
    public function tiers(): JsonResponse
    {
        return response()->json([
            'tiers'   => KeywordVolumeEstimator::getVolumeTiers(),
            'weights' => KeywordVolumeEstimator::getWeights(),
        ]);
    }
}