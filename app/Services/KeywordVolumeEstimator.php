<?php

namespace App\Services\Seo;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * KeywordVolumeEstimator
 *
 * Estimates Google keyword search volume without relying on Google Ads API or
 * any dedicated keyword-research API. It uses ValueSERP to fetch organic Google
 * SERP data and derives a volume estimate from multiple correlated signals.
 *
 * ---------------------------------------------------------------------------
 * HOW IT WORKS
 * ---------------------------------------------------------------------------
 * True search volume is proprietary to Google. However, several observable SERP
 * signals correlate strongly with volume:
 *
 *  1. Total result count  – Google's "About X results" figure. High-volume
 *     keywords have an enormous index. Log-scaled to prevent outliers from
 *     dominating the score.
 *
 *  2. Ad density          – Advertisers only bid on keywords that have traffic.
 *     The presence of shopping ads, top text ads, and bottom text ads each add
 *     weight. More ad slots = higher commercial interest = higher volume.
 *
 *  3. Featured snippet    – Google serves featured snippets for queries with
 *     high, consistent search demand. Presence boosts the estimate.
 *
 *  4. People Also Ask     – The number of PAA questions correlates with query
 *     depth and breadth of interest. Capped contribution to prevent noise.
 *
 *  5. Autocomplete count  – Fetched from a secondary autocomplete call.
 *     More suggestions = more search diversity = higher aggregate volume.
 *
 *  6. Related searches    – A richer related-search cluster signals an
 *     active, high-volume topic.
 *
 *  7. Result freshness    – A high proportion of recent results (< 1 year)
 *     indicates an actively searched, trending keyword.
 *
 * Each signal is normalised to [0, 1] and then multiplied by a weight.
 * The weighted sum produces a raw score that is mapped to one of five volume
 * tiers, each with a representative monthly-search midpoint estimate.
 *
 * ---------------------------------------------------------------------------
 * ACCURACY NOTE
 * ---------------------------------------------------------------------------
 * This is an *estimate*, not ground truth. It is best used for:
 *   - Relative ranking of keywords within the same niche
 *   - Quick triage / prioritisation when no paid tool is available
 *   - Sanity-checking keyword lists
 *
 * For absolute volume figures, pair this with Google Search Console data or a
 * dedicated keyword research tool.
 *
 * ---------------------------------------------------------------------------
 * USAGE
 * ---------------------------------------------------------------------------
 *   $estimator = new KeywordVolumeEstimator('YOUR_VALUESERP_API_KEY');
 *   $result = $estimator->estimate('best running shoes');
 *   // Or estimate multiple keywords at once:
 *   $results = $estimator->estimateBatch(['best running shoes', 'nike air max', 'trail running']);
 *
 * ---------------------------------------------------------------------------
 * CONFIGURATION
 * ---------------------------------------------------------------------------
 * Add to your .env:
 *   VALUESERP_API_KEY=your_key_here
 *   KEYWORD_VOLUME_CACHE_TTL=86400   # seconds (default: 24 hours)
 *   KEYWORD_VOLUME_CACHE_PREFIX=kve_
 *
 * Then resolve from the container:
 *   app(\App\Services\Seo\KeywordVolumeEstimator::class)
 *
 * Or bind it in a ServiceProvider:
 *   $this->app->singleton(KeywordVolumeEstimator::class, function () {
 *       return new KeywordVolumeEstimator(config('services.valueserp.key'));
 *   });
 */
class KeywordVolumeEstimator
{
    // -----------------------------------------------------------------------
    // Constants
    // -----------------------------------------------------------------------

    private const SERP_API_URL = 'https://api.valueserp.com/search';

    /** Signal weights – must sum to 1.0 */
    private const WEIGHTS = [
        'result_count' => 0.30,
        'ad_density' => 0.25,
        'featured_snippet' => 0.10,
        'paa_count' => 0.10,
        'autocomplete' => 0.12,
        'related_searches' => 0.08,
        'freshness' => 0.05,
    ];

    /**
     * Volume tier definitions.
     * Each tier has a human-readable label, a score range [min, max),
     * and a representative monthly search estimate midpoint.
     */
    private const VOLUME_TIERS = [
        [
            'tier' => 'very_high',
            'label' => 'Very High',
            'min_score' => 0.75,
            'max_score' => 1.01,
            'estimate' => 100000,
            'range' => '100,000+',
        ],
        [
            'tier' => 'high',
            'label' => 'High',
            'min_score' => 0.55,
            'max_score' => 0.75,
            'estimate' => 30000,
            'range' => '10,000–100,000',
        ],
        [
            'tier' => 'medium',
            'label' => 'Medium',
            'min_score' => 0.35,
            'max_score' => 0.55,
            'estimate' => 3000,
            'range' => '1,000–10,000',
        ],
        [
            'tier' => 'low',
            'label' => 'Low',
            'min_score' => 0.15,
            'max_score' => 0.35,
            'estimate' => 300,
            'range' => '100–1,000',
        ],
        [
            'tier' => 'very_low',
            'label' => 'Very Low',
            'min_score' => 0.0,
            'max_score' => 0.15,
            'estimate' => 50,
            'range' => '<100',
        ],
    ];

    // -----------------------------------------------------------------------
    // Properties
    // -----------------------------------------------------------------------

    private string $apiKey;
    private int $cacheTtl;
    private string $cachePrefix;
    private bool $debug;

    // -----------------------------------------------------------------------
    // Constructor
    // -----------------------------------------------------------------------

    public function __construct(
        string $apiKey = '',
        int $cacheTtl = 86400,
        string $cachePrefix = 'kve_',
        bool $debug = false
    ) {
        $this->apiKey = $apiKey ?: config('services.valueserp.key', env('VALUESERP_API_KEY', ''));
        $this->cacheTtl = $cacheTtl ?: (int) env('KEYWORD_VOLUME_CACHE_TTL', 86400);
        $this->cachePrefix = $cachePrefix ?: env('KEYWORD_VOLUME_CACHE_PREFIX', 'kve_');
        $this->debug = $debug;
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Estimate the monthly search volume for a single keyword.
     *
     * @param string $keyword The keyword to analyse.
     * @param string $gl Google country code (default: 'us').
     * @param string $hl Language code (default: 'en').
     * @return array
     */
    public function estimate(string $keyword, string $gl = 'us', string $hl = 'en'): array
    {
        $cacheKey = $this->cachePrefix . md5(strtolower(trim($keyword)) . $gl . $hl);

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($keyword, $gl, $hl) {
            return $this->computeEstimate($keyword, $gl, $hl);
        });
    }

    /**
     * Estimate search volumes for multiple keywords.
     * Adds a small delay between requests to avoid rate-limiting.
     *
     * @param string[] $keywords
     * @param string $gl
     * @param string $hl
     * @param int $delayMs Milliseconds to sleep between requests (default 200ms).
     * @return array[]
     */
    public function estimateBatch(
        array $keywords,
        string $gl = 'us',
        string $hl = 'en',
        int $delayMs = 200
    ): array {
        $results = [];

        foreach ($keywords as $keyword) {
            $results[] = $this->estimate($keyword, $gl, $hl);

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);

        return $results;
    }

    // -----------------------------------------------------------------------
    // Core Estimation Logic
    // -----------------------------------------------------------------------

    private function computeEstimate(string $keyword, string $gl, string $hl): array
    {
        $serpData = $this->fetchSerpData($keyword, $gl, $hl);
        $autocompleteCount = $this->fetchAutocompleteCount($keyword, $gl, $hl);
        $signals = $this->extractSignals($serpData, $autocompleteCount);
        $score = $this->computeWeightedScore($signals);
        $tier = $this->scoreToTier($score);

        return [
            'keyword' => $keyword,
            'score' => round($score, 4),
            'tier' => $tier['tier'],
            'tier_label' => $tier['label'],
            'volume_range' => $tier['range'],
            'volume_estimate' => $tier['estimate'],
            'signals' => $signals,
            'cached' => false,
        ];
    }

    // -----------------------------------------------------------------------
    // Signal Extraction
    // -----------------------------------------------------------------------

    private function extractSignals(array $serp, int $autocompleteCount): array
    {
        return [
            'result_count' => $this->signalResultCount($serp),
            'ad_density' => $this->signalAdDensity($serp),
            'featured_snippet' => $this->signalFeaturedSnippet($serp),
            'paa_count' => $this->signalPaaCount($serp),
            'autocomplete' => $this->signalAutocomplete($autocompleteCount),
            'related_searches' => $this->signalRelatedSearches($serp),
            'freshness' => $this->signalFreshness($serp),
        ];
    }

    /**
     * Signal 1: Total result count (log-scaled).
     * Scale: 0 results → 0.0, 10B+ results → 1.0
     */
    private function signalResultCount(array $serp): array
    {
        $raw = $serp['search_information']['total_results'] ?? 0;
        $normalised = $raw > 0 ? min(1.0, log10($raw) / 10.0) : 0.0;

        return [
            'raw' => $raw,
            'normalised' => round($normalised, 4),
            'weight' => self::WEIGHTS['result_count'],
        ];
    }

    /**
     * Signal 2: Ad density.
     * Counts unique ad slots across top ads, bottom ads, and shopping results.
     */
    private function signalAdDensity(array $serp): array
    {
        $raw = count($serp['ads'] ?? [])
            + count($serp['bottom_ads'] ?? [])
            + count($serp['shopping_results'] ?? []);

        $normalised = min(1.0, $raw / 10.0);

        return [
            'raw' => $raw,
            'normalised' => round($normalised, 4),
            'weight' => self::WEIGHTS['ad_density'],
        ];
    }

    /**
     * Signal 3: Featured snippet presence (binary).
     */
    private function signalFeaturedSnippet(array $serp): array
    {
        $has = isset($serp['answer_box']) || isset($serp['featured_snippet']) ? 1 : 0;

        return [
            'raw' => $has,
            'normalised' => (float) $has,
            'weight' => self::WEIGHTS['featured_snippet'],
        ];
    }

    /**
     * Signal 4: People Also Ask count.
     * Max expected: 4 boxes shown; score caps at 4.
     */
    private function signalPaaCount(array $serp): array
    {
        $raw = count($serp['related_questions'] ?? []);
        $normalised = min(1.0, $raw / 4.0);

        return [
            'raw' => $raw,
            'normalised' => round($normalised, 4),
            'weight' => self::WEIGHTS['paa_count'],
        ];
    }

    /**
     * Signal 5: Autocomplete suggestion count.
     * Max expected: 10 suggestions.
     */
    private function signalAutocomplete(int $count): array
    {
        $normalised = min(1.0, $count / 10.0);

        return [
            'raw' => $count,
            'normalised' => round($normalised, 4),
            'weight' => self::WEIGHTS['autocomplete'],
        ];
    }

    /**
     * Signal 6: Related searches count.
     * Google shows up to 8 related searches for popular queries.
     */
    private function signalRelatedSearches(array $serp): array
    {
        $raw = count($serp['related_searches'] ?? []);
        $normalised = min(1.0, $raw / 8.0);

        return [
            'raw' => $raw,
            'normalised' => round($normalised, 4),
            'weight' => self::WEIGHTS['related_searches'],
        ];
    }

    /**
     * Signal 7: Result freshness.
     * The proportion of top-10 organic results dated within the last 12 months.
     */
    private function signalFreshness(array $serp): array
    {
        $organic = $serp['organic_results'] ?? [];

        if (empty($organic)) {
            return ['raw' => 0, 'normalised' => 0.0, 'weight' => self::WEIGHTS['freshness']];
        }

        $cutoff = strtotime('-12 months');
        $fresh = 0;

        foreach (array_slice($organic, 0, 10) as $result) {
            $dateStr = $result['date'] ?? ($result['date_raw'] ?? null);
            if (!$dateStr) continue;

            $ts = strtotime($dateStr);
            if ($ts !== false && $ts >= $cutoff) {
                $fresh++;
            }
        }

        $total = min(count($organic), 10);
        $normalised = $total > 0 ? $fresh / $total : 0.0;

        return [
            'raw' => $fresh . '/' . $total,
            'normalised' => round($normalised, 4),
            'weight' => self::WEIGHTS['freshness'],
        ];
    }

    // -----------------------------------------------------------------------
    // Score Computation
    // -----------------------------------------------------------------------

    private function computeWeightedScore(array $signals): float
    {
        $score = 0.0;

        foreach ($signals as $signal) {
            $score += $signal['normalised'] * $signal['weight'];
        }

        return min(1.0, max(0.0, $score));
    }

    private function scoreToTier(float $score): array
    {
        foreach (self::VOLUME_TIERS as $tier) {
            if ($score >= $tier['min_score'] && $score < $tier['max_score']) {
                return $tier;
            }
        }

        return end(self::VOLUME_TIERS);
    }

    // -----------------------------------------------------------------------
    // ValueSERP API Calls
    // -----------------------------------------------------------------------

    private function fetchSerpData(string $keyword, string $gl, string $hl): array
    {
        $params = [
            'api_key' => $this->apiKey,
            'q' => $keyword,
            'gl' => $gl,
            'hl' => $hl,
            'num' => 10,
            'include_fields' => implode(',', [
                'search_information',
                'ads',
                'bottom_ads',
                'shopping_results',
                'answer_box',
                'featured_snippet',
                'related_questions',
                'related_searches',
                'organic_results',
            ]),
        ];

        $response = Http::timeout(15)
            ->retry(2, 500)
            ->get(self::SERP_API_URL, $params);

        if ($response->failed()) {
            Log::warning('KeywordVolumeEstimator: SERP request failed', [
                'keyword' => $keyword,
                'status' => $response->status(),
            ]);
            return [];
        }

        $data = $response->json();

        if ($this->debug) {
            Log::debug('KeywordVolumeEstimator: SERP data', ['keyword' => $keyword, 'data' => $data]);
        }

        return $data ?? [];
    }

    private function fetchAutocompleteCount(string $keyword, string $gl, string $hl): int
    {
        $response = Http::timeout(10)
            ->retry(2, 500)
            ->get('https://suggestqueries.google.com/complete/search', [
                'client' => 'firefox',
                'q' => $keyword,
                'gl' => $gl,
                'hl' => $hl,
            ]);

        if ($response->failed()) {
            Log::warning('KeywordVolumeEstimator: Autocomplete request failed', [
                'keyword' => $keyword,
            ]);
            return 0;
        }

        // Response format: ["query", ["suggestion1", "suggestion2", ...]]
        $data = $response->json();

        return isset($data[1]) ? count($data[1]) : 0;
    }

    // -----------------------------------------------------------------------
    // Utility
    // -----------------------------------------------------------------------

    public static function getVolumeTiers(): array
    {
        return self::VOLUME_TIERS;
    }

    public static function getWeights(): array
    {
        return self::WEIGHTS;
    }

    public static function test($keywords, $gl = 'us'): void
    {
        $estimator = new static();
        $results = is_array($keywords)
            ? $estimator->estimateBatch($keywords, $gl)
            : [$estimator->estimate($keywords, $gl)];
        foreach ($results as $r) {
            echo str_pad($r['keyword'], 40)
                . str_pad($r['tier_label'], 12)
                . str_pad($r['volume_range'], 20)
                . 'score: ' . $r['score'] . PHP_EOL;
        }
    }
}