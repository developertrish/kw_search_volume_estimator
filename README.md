# ⟁ VOLSCAN — Keyword Volume Estimator

A self-contained keyword search volume estimation engine for Laravel. No Google Ads API. No third-party keyword tools. VOLSCAN analyses live Google SERP signals via [ValueSERP](https://www.valueserp.com/) and triangulates a monthly volume estimate from multiple correlated data points.

![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?style=flat-square&logo=laravel&logoColor=white)
![Vue](https://img.shields.io/badge/Vue-3-42B883?style=flat-square&logo=vue.js&logoColor=white)
![Inertia](https://img.shields.io/badge/Inertia.js-1.x-9553E9?style=flat-square)
![Tailwind](https://img.shields.io/badge/Tailwind-3-38BDF8?style=flat-square&logo=tailwindcss&logoColor=white)
![License](https://img.shields.io/badge/license-MIT-green?style=flat-square)

---

## How It Works

True search volume is proprietary to Google. VOLSCAN approximates it by scoring 7 observable SERP signals that correlate strongly with volume — combining them into a weighted composite score that maps to one of five volume tiers.

| # | Signal | Weight | Rationale |
|---|--------|--------|-----------|
| 1 | **Total result count** (log-scaled) | 30% | Google's index size correlates with topic breadth |
| 2 | **Ad density** (top + bottom + shopping) | 25% | Advertisers only bid on keywords with real traffic |
| 3 | **Featured snippet** presence | 10% | Only appears for consistently high-demand queries |
| 4 | **People Also Ask** count | 10% | Richer PAA = larger query ecosystem |
| 5 | **Autocomplete suggestions** count | 12% | Google only surfaces queries with measurable volume |
| 6 | **Related searches** count | 8% | Active high-volume topics have rich related clusters |
| 7 | **Result freshness** (% < 12 months) | 5% | Trending/evergreen keywords produce fresh results |

Each signal is normalised to `[0, 1]` and multiplied by its weight. The weighted sum maps to a volume tier:

| Tier | Score Range | Est. Monthly Searches |
|------|-------------|----------------------|
| Very High | ≥ 0.75 | 100,000+ |
| High | 0.55 – 0.75 | 10,000 – 100,000 |
| Medium | 0.35 – 0.55 | 1,000 – 10,000 |
| Low | 0.15 – 0.35 | 100 – 1,000 |
| Very Low | < 0.15 | < 100 |

> **Accuracy note:** VOLSCAN produces estimates, not ground truth. It's best used for relative keyword ranking within a niche, quick triage when no paid tool is available, and sanity-checking keyword lists.

---

## Features

- **Zero dependency on Google Ads API** — no account, no billing, no approval
- **Single + batch mode** — analyse one keyword or up to 50 at a time
- **Result caching** — configurable TTL (default 24h) via Laravel Cache
- **Autocomplete signal** — uses Google's public suggest endpoint, no API key needed
- **Country + language targeting** — pass `gl` and `hl` parameters
- **Sortable results table** — sort by keyword, tier, volume range, or score
- **Signal breakdown** — click any row to see per-signal scores and weights
- **CSV export** — one-click download of all results
- **Inertia + Vue 3 frontend** — dark industrial UI with Tailwind CSS

---

## Requirements

- PHP 8.2+
- Laravel 13
- Node.js 18+
- [ValueSERP API key](https://www.valueserp.com/) (free tier available)
- Inertia.js with Vue 3 adapter
- Tailwind CSS v3

---

## Installation

**1. Copy the service class into your application:**

```
app/Services/Seo/KeywordVolumeEstimator.php
```

**2. Copy the controller:**

```
app/Http/Controllers/Seo/KeywordVolumeController.php
```

**3. Copy the Vue page:**

```
resources/js/pages/KeywordVolume/Index.vue
```

**4. Add your ValueSERP API key to `.env`:**

```env
VALUESERP_API_KEY=your_key_here
KEYWORD_VOLUME_CACHE_TTL=86400
KEYWORD_VOLUME_CACHE_PREFIX=kve_
```

**5. Bind the service in a provider** (e.g. `AppServiceProvider`):

```php
use App\Services\Seo\KeywordVolumeEstimator;

$this->app->singleton(KeywordVolumeEstimator::class, fn() =>
    new KeywordVolumeEstimator(config('services.valueserp.key'))
);
```

Add to `config/services.php`:

```php
'valueserp' => [
    'key' => env('VALUESERP_API_KEY'),
],
```

**6. Register routes:**

`routes/web.php`:
```php
use App\Http\Controllers\Seo\KeywordVolumeController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/seo/keyword-volume', [KeywordVolumeController::class, 'index'])
        ->name('seo.keyword-volume');
});
```

`routes/api.php`:
```php
Route::middleware(['auth'])->prefix('seo/keyword-volume')->group(function () {
    Route::post('single', [KeywordVolumeController::class, 'single']);
    Route::post('batch',  [KeywordVolumeController::class, 'batch']);
    Route::get('tiers',   [KeywordVolumeController::class, 'tiers']);
});
```

**7. Install frontend dependencies and build:**

```bash
npm install axios
npm run dev
```

---

## Usage

### In the browser

Navigate to `/seo/keyword-volume`, enter keywords (one per line), select a country and language, and click **SCAN**.

### Programmatically

```php
$estimator = app(\App\Services\Seo\KeywordVolumeEstimator::class);

// Single keyword
$result = $estimator->estimate('best running shoes');
// [
//   'keyword'         => 'best running shoes',
//   'score'           => 0.6341,
//   'tier'            => 'high',
//   'tier_label'      => 'High',
//   'volume_range'    => '10,000–100,000',
//   'volume_estimate' => 30000,
//   'cached'          => false,
//   'signals'         => [ ... ],
// ]

// Batch (sorted by score descending)
$results = $estimator->estimateBatch([
    'best running shoes',
    'nike air max',
    'trail running',
]);

// Target a specific country
$result = $estimator->estimate('braai recipes', gl: 'za', hl: 'en');
```

### In Tinker

```php
\App\Services\Seo\KeywordVolumeEstimator::test('best running shoes');

// Batch
\App\Services\Seo\KeywordVolumeEstimator::test([
    'best running shoes',
    'nike air max',
    'trail running',
]);
```

### API endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/api/seo/keyword-volume/single` | Estimate a single keyword |
| `POST` | `/api/seo/keyword-volume/batch` | Estimate up to 50 keywords |
| `GET` | `/api/seo/keyword-volume/tiers` | Return tier definitions and weights |

**Single request body:**
```json
{
    "keyword": "best running shoes",
    "gl": "us",
    "hl": "en"
}
```

**Batch request body:**
```json
{
    "keywords": ["best running shoes", "nike air max", "trail running"],
    "gl": "us",
    "hl": "en",
    "delay_ms": 200
}
```

---

## Configuration

| `.env` key | Default | Description |
|------------|---------|-------------|
| `VALUESERP_API_KEY` | — | Your ValueSERP API key (required) |
| `KEYWORD_VOLUME_CACHE_TTL` | `86400` | Cache duration in seconds (24h) |
| `KEYWORD_VOLUME_CACHE_PREFIX` | `kve_` | Cache key prefix |

---

## Running Tests

```bash
php artisan test --filter KeywordVolumeEstimatorTest
```

Tests use Laravel's `Http::fake()` to mock ValueSERP responses — no API credits consumed.

---

## File Structure

```
app/
└── Http/
│   └── Controllers/
│       └── Seo/
│           └── KeywordVolumeController.php
└── Services/
    └── Seo/
        └── KeywordVolumeEstimator.php

resources/
└── js/
    └── pages/
        └── KeywordVolume/
            └── Index.vue

tests/
└── Unit/
    └── Services/
        └── Seo/
            └── KeywordVolumeEstimatorTest.php
```

---

## License

MIT
