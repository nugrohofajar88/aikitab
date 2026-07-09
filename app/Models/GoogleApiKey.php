<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class GoogleApiKey extends Model
{
    protected $fillable = [
        'api_key',
        'model',
        'date_used',
        'n_request',
    ];

    protected $casts = [
        'date_used' => 'date',
    ];

    /**
     * Log one request against this key+model for today, creating today's row
     * on first use. Called right before every actual Gemini HTTP call. Kept
     * separate per model because Google's quota is per (project/key, model)
     * — see quotaId "GenerateRequestsPerDayPerProjectPerModel-FreeTier" in
     * the 429 error body, e.g. gemini-2.5-flash and gemini-2.5-flash-lite
     * each get their own daily allowance even under the same key.
     */
    public static function recordUsage(string $apiKey, string $model): void
    {
        $row = static::firstOrCreate(
            ['api_key' => $apiKey, 'model' => $model, 'date_used' => now()->toDateString()],
            ['n_request' => 0]
        );

        $row->increment('n_request');
    }

    public static function requestsToday(string $apiKey, string $model): int
    {
        return (int) static::where('api_key', $apiKey)
            ->where('model', $model)
            ->whereDate('date_used', now()->toDateString())
            ->value('n_request');
    }

    /**
     * Total requests today across a whole pool of keys for one model, for
     * the "Gemini hari ini: X/Y" badge — sums usage regardless of which key
     * in the pool actually got picked for each request.
     */
    public static function poolRequestsToday(array $pool, string $model): int
    {
        if ($pool === []) {
            return 0;
        }

        return (int) static::whereIn('api_key', $pool)
            ->where('model', $model)
            ->whereDate('date_used', now()->toDateString())
            ->sum('n_request');
    }

    /**
     * Pick whichever key in the pool has the lowest usage today for this
     * specific model, so rotation always favors whichever account has the
     * most quota headroom left for the model actually being called. Once
     * every key is exhausted this still returns one (the least-bad option) —
     * Google's side is what actually rejects the request at that point.
     */
    public static function pickAvailableKey(array $pool, string $model): string
    {
        $sorted = static::sortKeysByUsage($pool, $model);

        if ($sorted === []) {
            throw new RuntimeException('Tidak ada GEMINI_API_KEY yang dikonfigurasi.');
        }

        return $sorted[0];
    }

    /**
     * The pool, ordered least-used-today-for-this-model first. Used to build
     * GeminiService's full (model, key) cascade — every key gets a turn, in
     * order of how much quota headroom it likely has left.
     *
     * @return array<int, string>
     */
    public static function sortKeysByUsage(array $pool, string $model): array
    {
        $pool = array_values(array_unique(array_filter($pool)));

        if ($pool === []) {
            return [];
        }

        $usage = static::whereIn('api_key', $pool)
            ->where('model', $model)
            ->whereDate('date_used', now()->toDateString())
            ->pluck('n_request', 'api_key');

        return collect($pool)->sortBy(fn (string $key) => $usage[$key] ?? 0)->values()->all();
    }

    /**
     * Requests-per-minute tracking, kept separate from the daily `n_request`
     * log above — Google enforces RPM and RPD as independent limits (seen on
     * the AI Studio rate-limits dashboard: e.g. gemini-2.5-flash peaking at
     * "8/5" RPM while its RPD was still well under cap), so a key/model pair
     * with plenty of daily quota left can still get a 429 if too many
     * requests land in the same 60-second window. Uses Cache (not the
     * `google_api_keys` table) since this is high-frequency and
     * self-expiring — no need to persist it or clutter the daily log.
     */
    public static function recentRequestCount(string $apiKey, string $model): int
    {
        return (int) Cache::get(self::rpmCacheKey($apiKey, $model), 0);
    }

    public static function recordRecentRequest(string $apiKey, string $model): void
    {
        $key = self::rpmCacheKey($apiKey, $model);
        Cache::put($key, self::recentRequestCount($apiKey, $model) + 1, now()->addSeconds(65));
    }

    /**
     * Bucketed by the current 60-second window (not a true sliding window —
     * good enough for staying clear of RPM caps without the complexity of
     * a real rate limiter).
     */
    protected static function rpmCacheKey(string $apiKey, string $model): string
    {
        return 'gemini_rpm:'.md5($apiKey).":{$model}:".intdiv(time(), 60);
    }
}
