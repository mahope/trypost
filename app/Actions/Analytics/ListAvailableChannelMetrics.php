<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Enums\Analytics\MetricAvailability;
use App\Enums\Analytics\MetricKey;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\SocialAccount;
use App\Support\Analytics\ChannelMetrics;

class ListAvailableChannelMetrics
{
    private const array COLUMNS = [
        'reactions' => 'reactions_count',
        'comments' => 'comments_count',
        'views' => 'views_count',
        'impressions' => 'impressions_count',
        'shares' => 'shares_count',
        'saves' => 'saves_count',
        'reach' => 'reach_count',
        'watch_time_minutes' => 'watch_time_milliseconds',
        'average_watch_time_seconds' => 'average_watch_time_milliseconds',
    ];

    /** Metrics only found in a snapshot's measured metrics, with the keys that report them. */
    private const array MEASURED = [
        'clicks' => [MetricKey::Clicks, MetricKey::LinkClicks],
        'reposts' => [MetricKey::Reposts],
        'quotes' => [MetricKey::Quotes],
        'follows_gained' => [MetricKey::Follows],
        'profile_visits' => [MetricKey::ProfileVisits],
    ];

    /** Publications sampled, newest first, to tell which measured metrics a channel reports. */
    private const int MEASURED_SAMPLE = 200;

    /** Metrics the channel metrics chart can draw, in menu order. */
    public const array SERIES = ['posts', 'followers', 'net_followers', 'reach', 'views', 'impressions', 'profile_visits'];

    public function __construct(
        private readonly ResolveAnalyticsAccountKey $accountKey,
        private readonly QueryLatestPublicationSnapshots $latestSnapshots,
    ) {}

    /**
     * @param  array<string, bool>|null  $availability  Already resolved through availability() for this request.
     * @return list<string>
     */
    public function handle(SocialAccount $channel, ?string $accountKey = null, ?array $availability = null): array
    {
        $availability ??= $this->availability($channel, $accountKey);

        return array_values(array_filter(ChannelMetrics::ORDER, fn (string $metric): bool => (bool) data_get($availability, $metric, false)));
    }

    /**
     * Metrics the channel metrics chart can draw: posts and the follower series always, post metrics only
     * where the network reports them.
     *
     * @param  array<string, bool>|null  $availability  Already resolved through availability() for this request.
     * @return list<string>
     */
    public function series(SocialAccount $channel, ?string $accountKey = null, ?array $availability = null): array
    {
        $availability = [...($availability ?? $this->availability($channel, $accountKey)), 'net_followers' => $channel->platform->hasFollowerAnalytics()];

        return array_values(array_filter(self::SERIES, fn (string $metric): bool => (bool) data_get($availability, $metric, false)));
    }

    /** @return array<string, bool> */
    public function availability(SocialAccount $channel, ?string $accountKey = null): array
    {
        $accountKey ??= $this->accountKey->for($channel);
        $query = $this->latestSnapshots->execute($channel->workspace_id, [$accountKey]);

        foreach (self::COLUMNS as $metric => $column) {
            $query->selectRaw("COUNT(metric.{$column}) as {$metric}");
        }

        $counts = $query
            ->selectRaw('SUM(CASE WHEN metric.engagement_count IS NOT NULL AND metric.exposure_count > 0 THEN 1 ELSE 0 END) as engagement_rate')
            ->first();
        $available = ['followers' => $channel->platform->hasFollowerAnalytics(), 'posts' => true];

        foreach ([...array_keys(self::COLUMNS), 'engagement_rate'] as $metric) {
            $available[$metric] = (int) data_get($counts, $metric, 0) > 0;
        }

        $measured = $this->measured($channel, $accountKey);

        foreach (array_keys(self::MEASURED) as $metric) {
            $available[$metric] = in_array($metric, $measured, true);
        }

        $available['follows_gained'] = data_get($available, 'follows_gained') && $channel->platform->reportsPublicationFollows();
        $available['net_followers'] = $this->hasFollowerHistory($channel, $accountKey);

        return $available;
    }

    /**
     * Measured metrics reported by the channel's most recent publications.
     *
     * @return list<string>
     */
    private function measured(SocialAccount $channel, string $accountKey): array
    {
        $found = [];
        $rows = $this->latestSnapshots->execute($channel->workspace_id, [$accountKey])
            ->whereNotNull('metric.metrics')
            ->select(['metric.metrics'])
            ->orderByDesc('publication.provider_published_at')
            ->limit(self::MEASURED_SAMPLE)
            ->get();

        foreach ($rows as $row) {
            $metrics = json_decode((string) $row->metrics, true);

            foreach (self::MEASURED as $metric => $keys) {
                foreach ($keys as $key) {
                    $value = data_get($metrics, $key->value);

                    if (data_get($value, 'availability') === MetricAvailability::Available->value && is_numeric(data_get($value, 'value'))) {
                        $found[$metric] = true;
                    }
                }
            }
        }

        return array_keys($found);
    }

    private function hasFollowerHistory(SocialAccount $channel, string $accountKey): bool
    {
        return AnalyticsAccountDailySnapshot::query()
            ->where('workspace_id', $channel->workspace_id)
            ->where('social_account_key', $accountKey)
            ->whereNotNull('followers_count')
            ->limit(2)
            ->pluck('id')
            ->count() > 1;
    }
}
