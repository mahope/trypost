<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Metrics;

use App\Dto\Analytics\MetricValue;
use App\Dto\Analytics\PublicationMetricObservation;
use App\Enums\Analytics\MetricKey;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\AnalyticsPublication;
use App\Models\SocialAccount;
use App\Support\Analytics\AnalyticsAccessToken;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Metrics of a post a LinkedIn member published through TryPost.
 *
 * Reactions and comments come from the unversioned `/v2/socialActions/{urn}`
 * edge, which the member publishing token can read. Impressions, members
 * reached and reshares need `r_member_postAnalytics` (Community Management
 * API): only an account whose stored grant includes it reads
 * `memberCreatorPostAnalytics`, and a refusal there falls back to socialActions.
 *
 * @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/network-update-social-actions
 * @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/members/post-statistics
 */
class LinkedInPublicationMetricsCollector extends AbstractPublicationMetricsCollector
{
    public const string POST_ANALYTICS_SCOPE = 'r_member_postAnalytics';

    /** The same Marketing API version the LinkedIn publishers send. */
    private const string API_VERSION = '202601';

    /** @var array<string, MetricKey> */
    private const array POST_ANALYTICS = [
        'IMPRESSION' => MetricKey::Impressions,
        'MEMBERS_REACHED' => MetricKey::Reach,
        'REACTION' => MetricKey::Reactions,
        'COMMENT' => MetricKey::Comments,
        'RESHARE' => MetricKey::Shares,
    ];

    /** Rest.li entity keys of the two post URN types memberCreatorPostAnalytics accepts. */
    private const array ENTITY_KEYS = [
        'urn:li:share:' => 'share',
        'urn:li:ugcPost:' => 'ugc',
    ];

    public function collect(AnalyticsPublication $publication, CarbonImmutable $date): PublicationMetricObservation
    {
        $account = $this->account($publication);
        $entityKey = $this->entityKey($publication->remote_id);

        if ($entityKey === null) {
            throw AnalyticsCollectionException::unsupported('LinkedIn publication id is not a share or ugcPost URN.');
        }

        $metrics = $this->grantsPostAnalytics($account)
            ? $this->postAnalytics($account, $publication->remote_id, $entityKey)
            : null;

        return $this->observation($date, $this->withEngagements($metrics ?: $this->socialActions($account, $publication->remote_id)));
    }

    /**
     * An explicit grant only: an account stored before grants were recorded
     * never asks for a scope LinkedIn approves for few apps.
     */
    private function grantsPostAnalytics(SocialAccount $account): bool
    {
        return in_array(self::POST_ANALYTICS_SCOPE, (array) $account->scopes, true);
    }

    /**
     * One lifetime total per metric, skipping a metric LinkedIn answers with a
     * client error. A refusal keeps what was already read; null when LinkedIn
     * refuses the scope from the first read, so the caller reads socialActions.
     *
     * @return list<MetricValue>|null
     */
    private function postAnalytics(SocialAccount $account, string $urn, string $entityKey): ?array
    {
        $api = rtrim((string) config('trypost.platforms.linkedin.api'), '/');
        $entity = "({$entityKey}:".rawurlencode($urn).')';
        $metrics = [];

        foreach (self::POST_ANALYTICS as $queryType => $key) {
            try {
                $response = $this->restli($account, "{$api}/rest/memberCreatorPostAnalytics?q=entity&entity={$entity}&queryType={$queryType}&aggregation=TOTAL");
            } catch (AnalyticsCollectionException $exception) {
                if ($exception->category === 'permission') {
                    Log::info('analytics.linkedin_post_analytics_refused', ['reason' => $exception->getMessage()]);

                    return $metrics === [] ? null : $this->present($metrics);
                }

                if ($exception->category !== 'malformed' || $exception->gone) {
                    throw $exception;
                }

                continue;
            }

            $metrics[] = $this->total($key, (array) $response->json('elements'), $queryType);
        }

        return $this->present($metrics);
    }

    /**
     * Sums the `count` of every element returned for the metric. Versions up to
     * 202604 wrap `metricType` in an object keyed by its Rest.li type, later
     * ones send the plain string; both are read.
     *
     * @param  array<int, mixed>  $elements
     */
    private function total(MetricKey $key, array $elements, string $queryType): ?MetricValue
    {
        $counts = [];

        foreach ($elements as $element) {
            $type = data_get($element, 'metricType');
            $type = is_array($type) ? reset($type) : $type;
            $count = data_get($element, 'count');

            if ($type === $queryType && is_numeric($count)) {
                $counts[] = (int) $count;
            }
        }

        return $counts === [] ? null : $this->count($key, [$queryType => array_sum($counts)], $queryType);
    }

    /** @return list<MetricValue> */
    private function socialActions(SocialAccount $account, string $urn): array
    {
        $api = rtrim((string) config('trypost.platforms.linkedin.api'), '/');
        $actions = (array) $this->get($account, "{$api}/v2/socialActions/".rawurlencode($urn))->json();

        return $this->present([
            $this->count(MetricKey::Reactions, $actions, 'likesSummary.totalLikes'),
            $this->count(MetricKey::Comments, $actions, 'commentsSummary.aggregatedTotalComments'),
        ]);
    }

    /**
     * Sends the URL as written: a query array would encode the Rest.li
     * parentheses and colons LinkedIn expects literally.
     */
    private function restli(SocialAccount $account, string $url): Response
    {
        return $this->successfulResponse(Http::acceptJson()
            ->timeout(120)
            ->withToken(AnalyticsAccessToken::for($account))
            ->withHeaders([
                'LinkedIn-Version' => self::API_VERSION,
                'X-Restli-Protocol-Version' => '2.0.0',
            ])
            ->get($url));
    }

    private function entityKey(string $urn): ?string
    {
        foreach (self::ENTITY_KEYS as $prefix => $key) {
            if (str_starts_with($urn, $prefix)) {
                return $key;
            }
        }

        return null;
    }
}
