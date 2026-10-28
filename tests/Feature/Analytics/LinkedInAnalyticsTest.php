<?php

declare(strict_types=1);

use App\Actions\Analytics\BuildWorkspaceAnalyticsReport;
use App\Actions\Analytics\DispatchAccountAnalytics;
use App\Actions\Analytics\ListAvailableChannelMetrics;
use App\Dto\Analytics\DateRange;
use App\Enums\Analytics\MetricKey;
use App\Enums\Analytics\PublicationAvailability;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Jobs\Analytics\CollectPublicationMetrics;
use App\Jobs\Analytics\FinalizeAccountDailySnapshot;
use App\Jobs\Analytics\FinalizeAccountDailySnapshots;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\AnalyticsSyncState;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Analytics\Collectors\Followers\FollowerCollectorFactory;
use App\Services\Analytics\Collectors\Metrics\LinkedInPublicationMetricsCollector;
use App\Services\Analytics\Collectors\Metrics\PublicationMetricsCollectorFactory;
use App\Services\Analytics\Collectors\Publications\PublicationHistoryCollectorFactory;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const LINKEDIN_SHARE_URN = 'urn:li:share:7325786486870552578';

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $this->api = config('trypost.platforms.linkedin.api');
});

function linkedInPublication(array $scopes = [], string $remoteId = LINKEDIN_SHARE_URN): AnalyticsPublication
{
    $account = SocialAccount::factory()->linkedin()->create([
        'scopes' => [...Platform::LinkedIn->requiredPublishScopes(), ...$scopes],
        'token_expires_at' => now()->addDay(),
    ]);

    return AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'platform' => Platform::LinkedIn,
        'network' => Platform::LinkedIn->network(),
        'platform_user_id' => $account->platform_user_id,
        'remote_id' => $remoteId,
        'provider_published_at' => CarbonImmutable::now('UTC')->subDay(),
    ]);
}

/**
 * The shape memberCreatorPostAnalytics answers with up to LinkedIn-Version 202604,
 * which TryPost sends (202601): `metricType` wrapped in its Rest.li type.
 */
function memberPostAnalyticsResponse(string $queryType, int $count, string $entityKey = 'share', string $urn = LINKEDIN_SHARE_URN): array
{
    return [
        'elements' => [[
            'count' => $count,
            'metricType' => ['com.linkedin.adsexternalapi.memberanalytics.v1.CreatorPostAnalyticsMetricTypeV1' => $queryType],
            'targetEntity' => [$entityKey => $urn],
        ]],
        'paging' => ['count' => 10, 'start' => 0, 'links' => []],
    ];
}

/** @return array<string, int|float|null> */
function linkedInMetricValues(AnalyticsPublication $publication): array
{
    $observation = app(LinkedInPublicationMetricsCollector::class)->collect($publication, CarbonImmutable::now('UTC'));

    return collect($observation->metrics)->mapWithKeys(fn ($metric) => [$metric->key->value => $metric->value])->all();
}

test('personal linkedin is measured per post but never for followers or post history', function () {
    expect(Platform::LinkedIn->isIncludedInAnalytics())->toBeTrue()
        ->and(Platform::LinkedIn->hasFollowerAnalytics())->toBeFalse()
        ->and(Platform::LinkedIn->hasPublicationHistory())->toBeFalse()
        ->and(Platform::LinkedInPage->isIncludedInAnalytics())->toBeFalse()
        ->and(Platform::analyticsValues())->toContain(Platform::LinkedIn->value)
        ->and(Platform::followerAnalyticsValues())->not->toContain(Platform::LinkedIn->value)
        ->and(Platform::publicationHistoryValues())->not->toContain(Platform::LinkedIn->value)
        ->and(Platform::followerAnalyticsValues())->toContain(Platform::Mastodon->value)
        ->and(Platform::publicationHistoryValues())->toContain(Platform::Mastodon->value)
        ->and(app(PublicationMetricsCollectorFactory::class)->for(Platform::LinkedIn))->toBeInstanceOf(LinkedInPublicationMetricsCollector::class)
        ->and(app(FollowerCollectorFactory::class)->supports(Platform::LinkedIn))->toBeFalse()
        ->and(app(PublicationHistoryCollectorFactory::class)->supports(Platform::LinkedIn))->toBeFalse()
        ->and(fn () => app(PublicationMetricsCollectorFactory::class)->for(Platform::LinkedInPage))->toThrow(AnalyticsCollectionException::class);
});

test('an account without the post analytics scope reads reactions and comments from socialActions', function () {
    Http::preventStrayRequests();
    Http::fake(["{$this->api}/v2/socialActions/*" => Http::response([
        'likesSummary' => ['totalLikes' => 12, 'likedByCurrentUser' => false],
        'commentsSummary' => ['aggregatedTotalComments' => 3, 'totalFirstLevelComments' => 2],
        'target' => LINKEDIN_SHARE_URN,
    ])]);

    expect(linkedInMetricValues(linkedInPublication()))->toEqual([
        MetricKey::Reactions->value => 12,
        MetricKey::Comments->value => 3,
        MetricKey::Engagements->value => 15,
    ]);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === "{$this->api}/v2/socialActions/".rawurlencode(LINKEDIN_SHARE_URN)
        && ! $request->hasHeader('LinkedIn-Version'));
});

test('an account granted r_member_postAnalytics reads lifetime totals from memberCreatorPostAnalytics', function (string $urn, string $entityKey) {
    $counts = ['IMPRESSION' => 1500, 'MEMBERS_REACHED' => 900, 'REACTION' => 40, 'COMMENT' => 6, 'RESHARE' => 4];
    Http::preventStrayRequests();
    Http::fake(["{$this->api}/rest/memberCreatorPostAnalytics*" => function (Request $request) use ($counts, $entityKey, $urn) {
        $queryType = $request->data()['queryType'];

        return Http::response(memberPostAnalyticsResponse($queryType, $counts[$queryType], $entityKey, $urn));
    }]);

    $values = linkedInMetricValues(linkedInPublication([LinkedInPublicationMetricsCollector::POST_ANALYTICS_SCOPE], $urn));

    expect($values)->toEqual([
        MetricKey::Impressions->value => 1500,
        MetricKey::Reach->value => 900,
        MetricKey::Reactions->value => 40,
        MetricKey::Comments->value => 6,
        MetricKey::Shares->value => 4,
        MetricKey::Engagements->value => 50,
    ]);

    Http::assertSentCount(5);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), "entity=({$entityKey}:".rawurlencode($urn).')')
        && str_contains($request->url(), 'q=entity')
        && str_contains($request->url(), 'aggregation=TOTAL')
        && $request->header('LinkedIn-Version') === ['202601']
        && $request->header('X-Restli-Protocol-Version') === ['2.0.0']);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/v2/socialActions/'));
})->with([
    'share' => [LINKEDIN_SHARE_URN, 'share'],
    'ugcPost' => ['urn:li:ugcPost:6443156446455693312', 'ugc'],
]);

test('a plain string metricType from newer LinkedIn versions is read too', function () {
    Http::fake(["{$this->api}/rest/memberCreatorPostAnalytics*" => fn (Request $request) => Http::response(['elements' => [[
        'count' => 7,
        'metricType' => $request->data()['queryType'],
        'targetEntity' => ['share' => LINKEDIN_SHARE_URN],
    ]]])]);

    expect(linkedInMetricValues(linkedInPublication([LinkedInPublicationMetricsCollector::POST_ANALYTICS_SCOPE]))[MetricKey::Impressions->value])->toBe(7);
});

test('an account stored before grants were recorded never asks for post analytics', function () {
    Http::fake(["{$this->api}/v2/socialActions/*" => Http::response([
        'likesSummary' => ['totalLikes' => 1],
        'commentsSummary' => ['aggregatedTotalComments' => 0],
    ])]);
    $publication = linkedInPublication();
    $publication->socialAccount->update(['scopes' => null]);

    expect(linkedInMetricValues($publication->refresh()))->toHaveKeys([MetricKey::Reactions->value, MetricKey::Comments->value]);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'memberCreatorPostAnalytics'));
});

test('a refused post analytics read falls back to socialActions', function () {
    Http::fake([
        "{$this->api}/rest/memberCreatorPostAnalytics*" => Http::response([
            'status' => 403,
            'serviceErrorCode' => 100,
            'code' => 'ACCESS_DENIED',
            'message' => 'Not enough permissions to access: memberCreatorPostAnalytics.FINDER-entity.20260101',
        ], 403),
        "{$this->api}/v2/socialActions/*" => Http::response([
            'likesSummary' => ['totalLikes' => 9],
            'commentsSummary' => ['aggregatedTotalComments' => 2],
        ]),
    ]);

    expect(linkedInMetricValues(linkedInPublication([LinkedInPublicationMetricsCollector::POST_ANALYTICS_SCOPE])))->toEqual([
        MetricKey::Reactions->value => 9,
        MetricKey::Comments->value => 2,
        MetricKey::Engagements->value => 11,
    ]);
    Http::assertSentCount(2);
});

test('a post analytics refusal after a successful read keeps what was measured and fills reactions and comments from socialActions', function () {
    Http::fake([
        "{$this->api}/rest/memberCreatorPostAnalytics*" => fn (Request $request) => $request->data()['queryType'] === 'IMPRESSION'
            ? Http::response(memberPostAnalyticsResponse('IMPRESSION', 420))
            : Http::response(['status' => 403, 'code' => 'ACCESS_DENIED'], 403),
        "{$this->api}/v2/socialActions/*" => Http::response(['likesSummary' => ['totalLikes' => 4]]),
    ]);

    expect(linkedInMetricValues(linkedInPublication([LinkedInPublicationMetricsCollector::POST_ANALYTICS_SCOPE])))->toEqual([
        MetricKey::Impressions->value => 420,
        MetricKey::Reactions->value => 4,
        MetricKey::Comments->value => 0,
        MetricKey::Engagements->value => 4,
    ]);
    Http::assertSentCount(3);
});

test('a skipped reaction metric is filled from socialActions without replacing what post analytics measured', function () {
    $counts = ['IMPRESSION' => 300, 'MEMBERS_REACHED' => 200, 'COMMENT' => 5, 'RESHARE' => 1];
    Http::fake([
        "{$this->api}/rest/memberCreatorPostAnalytics*" => fn (Request $request) => array_key_exists($request->data()['queryType'], $counts)
            ? Http::response(memberPostAnalyticsResponse($request->data()['queryType'], $counts[$request->data()['queryType']]))
            : Http::response(['status' => 400, 'code' => 'ILLEGAL_ARGUMENT'], 400),
        "{$this->api}/v2/socialActions/*" => Http::response([
            'likesSummary' => ['totalLikes' => 11],
            'commentsSummary' => ['aggregatedTotalComments' => 99],
        ]),
    ]);

    expect(linkedInMetricValues(linkedInPublication([LinkedInPublicationMetricsCollector::POST_ANALYTICS_SCOPE])))->toEqual([
        MetricKey::Impressions->value => 300,
        MetricKey::Reach->value => 200,
        MetricKey::Comments->value => 5,
        MetricKey::Shares->value => 1,
        MetricKey::Reactions->value => 11,
        MetricKey::Engagements->value => 17,
    ]);
    Http::assertSentCount(6);
});

test('a refused socialActions fill keeps what post analytics measured', function () {
    Http::fake([
        "{$this->api}/rest/memberCreatorPostAnalytics*" => fn (Request $request) => $request->data()['queryType'] === 'IMPRESSION'
            ? Http::response(memberPostAnalyticsResponse('IMPRESSION', 420))
            : Http::response(['status' => 403, 'code' => 'ACCESS_DENIED'], 403),
        "{$this->api}/v2/socialActions/*" => Http::response(['status' => 403, 'code' => 'ACCESS_DENIED'], 403),
    ]);

    expect(linkedInMetricValues(linkedInPublication([LinkedInPublicationMetricsCollector::POST_ANALYTICS_SCOPE])))
        ->toEqual([MetricKey::Impressions->value => 420]);
});

test('a post without any social activity reads as zero reactions and zero comments', function () {
    Http::preventStrayRequests();
    Http::fake(["{$this->api}/v2/socialActions/*" => Http::response([])]);

    expect(linkedInMetricValues(linkedInPublication()))->toEqual([
        MetricKey::Reactions->value => 0,
        MetricKey::Comments->value => 0,
        MetricKey::Engagements->value => 0,
    ]);
});

test('empty post analytics and an empty socialActions read as zero reactions and zero comments', function () {
    Http::preventStrayRequests();
    Http::fake([
        "{$this->api}/rest/memberCreatorPostAnalytics*" => Http::response(['elements' => [], 'paging' => ['count' => 10, 'start' => 0, 'links' => []]]),
        "{$this->api}/v2/socialActions/*" => Http::response([]),
    ]);

    expect(linkedInMetricValues(linkedInPublication([LinkedInPublicationMetricsCollector::POST_ANALYTICS_SCOPE])))->toEqual([
        MetricKey::Reactions->value => 0,
        MetricKey::Comments->value => 0,
        MetricKey::Engagements->value => 0,
    ]);
    Http::assertSentCount(6);
});

test('the daily metrics job stores a snapshot of zeros for a post without any social activity', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));
    Http::fake(["{$this->api}/v2/socialActions/*" => Http::response([])]);
    $publication = linkedInPublication();

    app()->call([new CollectPublicationMetrics($publication->id, '2026-10-20'), 'handle']);

    $snapshot = AnalyticsPublicationDailySnapshot::query()->where('publication_id', $publication->id)->sole();
    expect($snapshot->reactions_count)->toBe(0)
        ->and($snapshot->comments_count)->toBe(0)
        ->and($snapshot->engagement_count)->toBe(0)
        ->and($publication->refresh()->metric_failures)->toBe(0);
});

test('a publication id that is not a post urn makes no request', function () {
    Http::preventStrayRequests();

    expect(fn () => linkedInMetricValues(linkedInPublication(remoteId: 'unknown')))
        ->toThrow(fn (AnalyticsCollectionException $exception) => expect($exception->category)->toBe('unsupported'));
});

test('the daily metrics job stores impressions and reach so the engagement rate has an exposure', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));
    $counts = ['IMPRESSION' => 1000, 'MEMBERS_REACHED' => 800, 'REACTION' => 30, 'COMMENT' => 8, 'RESHARE' => 2];
    Http::fake(["{$this->api}/rest/memberCreatorPostAnalytics*" => fn (Request $request) => Http::response(
        memberPostAnalyticsResponse($request->data()['queryType'], $counts[$request->data()['queryType']]),
    )]);
    $publication = linkedInPublication([LinkedInPublicationMetricsCollector::POST_ANALYTICS_SCOPE]);

    app()->call([new CollectPublicationMetrics($publication->id, '2026-10-20'), 'handle']);

    $snapshot = AnalyticsPublicationDailySnapshot::query()->where('publication_id', $publication->id)->sole();
    expect($snapshot->impressions_count)->toBe(1000)
        ->and($snapshot->reach_count)->toBe(800)
        ->and($snapshot->reactions_count)->toBe(30)
        ->and($snapshot->comments_count)->toBe(8)
        ->and($snapshot->shares_count)->toBe(2)
        ->and($snapshot->engagement_count)->toBe(40)
        ->and($snapshot->exposure_count)->toBe(800);
});

test('a socialActions-only snapshot has no exposure, so no engagement rate is derived', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));
    Http::fake(["{$this->api}/v2/socialActions/*" => Http::response([
        'likesSummary' => ['totalLikes' => 5],
        'commentsSummary' => ['aggregatedTotalComments' => 1],
    ])]);
    $publication = linkedInPublication();

    app()->call([new CollectPublicationMetrics($publication->id, '2026-10-20'), 'handle']);

    $snapshot = AnalyticsPublicationDailySnapshot::query()->where('publication_id', $publication->id)->sole();
    expect($snapshot->reactions_count)->toBe(5)
        ->and($snapshot->engagement_count)->toBe(6)
        ->and($snapshot->impressions_count)->toBeNull()
        ->and($snapshot->exposure_count)->toBeNull();
});

// Locks in the existing permission handling of CollectPublicationMetrics for the new collector; it passes on main too.
test('a permission refusal keeps the account connected and is not retried', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));
    Http::fake(["{$this->api}/v2/socialActions/*" => Http::response([
        'status' => 403,
        'serviceErrorCode' => 100,
        'code' => 'ACCESS_DENIED',
        'message' => 'Not enough permissions to access: socialActions.GET.NO_VERSION',
    ], 403)]);
    $publication = linkedInPublication();
    Bus::fake([CollectPublicationMetrics::class]);

    app()->call([new CollectPublicationMetrics($publication->id, '2026-10-20', baseline: true), 'handle']);

    Bus::assertNotDispatched(CollectPublicationMetrics::class);
    expect(AnalyticsPublicationDailySnapshot::query()->where('publication_id', $publication->id)->exists())->toBeFalse()
        ->and($publication->refresh()->metric_failures)->toBe(0)
        ->and($publication->availability)->toBe(PublicationAvailability::Available)
        ->and($publication->socialAccount->refresh()->status)->toBe(Status::Connected);
});

test('daily discovery skips personal linkedin and still bootstraps other platforms', function () {
    $linkedIn = SocialAccount::factory()->linkedin()->create();
    $mastodon = SocialAccount::factory()->mastodon()->create();

    $this->artisan('analytics:dispatch-publication-discovery')->assertExitCode(0);

    Queue::assertPushed(BootstrapAccountAnalytics::class, fn (BootstrapAccountAnalytics $job): bool => $job->socialAccountId === $mastodon->id);
    Queue::assertNotPushed(BootstrapAccountAnalytics::class, fn (BootstrapAccountAnalytics $job): bool => $job->socialAccountId === $linkedIn->id);
});

test('connecting a personal linkedin starts neither follower collection nor a backfill', function () {
    $linkedIn = SocialAccount::factory()->linkedin()->create();

    app(DispatchAccountAnalytics::class)->handle($linkedIn);
    (new BootstrapAccountAnalytics($linkedIn->id))->handle();

    Queue::assertNotPushed(CollectAccountDailySnapshot::class);
    Queue::assertNotPushed(BootstrapAccountAnalytics::class);
    expect(AnalyticsSyncState::query()->where('social_account_id', $linkedIn->id)->exists())->toBeFalse();
});

test('follower finalization skips personal linkedin', function () {
    $linkedIn = SocialAccount::factory()->linkedin()->create();
    $mastodon = SocialAccount::factory()->mastodon()->create();
    Bus::fake([FinalizeAccountDailySnapshot::class]);

    (new FinalizeAccountDailySnapshots('2026-10-20'))->handle();

    Bus::assertDispatched(FinalizeAccountDailySnapshot::class, fn (FinalizeAccountDailySnapshot $job): bool => $job->socialAccountId === $mastodon->id);
    Bus::assertNotDispatched(FinalizeAccountDailySnapshot::class, fn (FinalizeAccountDailySnapshot $job): bool => $job->socialAccountId === $linkedIn->id);
});

test('the daily metrics run reads personal linkedin publications', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));
    $publication = linkedInPublication();
    Bus::fake([CollectPublicationMetrics::class]);

    $this->artisan('analytics:dispatch-publication-metrics')->assertExitCode(0);

    Bus::assertDispatched(CollectPublicationMetrics::class, fn (CollectPublicationMetrics $job): bool => $job->publicationId === $publication->id);
});

test('a personal linkedin channel offers post metrics but no follower series', function () {
    $publication = linkedInPublication([LinkedInPublicationMetricsCollector::POST_ANALYTICS_SCOPE]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $publication->id,
        'date' => CarbonImmutable::now('UTC')->toDateString(),
        'reactions_count' => 3,
        'impressions_count' => 100,
        'engagement_count' => 3,
        'exposure_count' => 100,
    ]);
    $metrics = app(ListAvailableChannelMetrics::class);
    $channel = $publication->socialAccount;

    expect($metrics->handle($channel))->not->toContain('followers')
        ->and($metrics->handle($channel))->toContain('reactions', 'impressions', 'engagement_rate')
        ->and($metrics->series($channel))->not->toContain('followers', 'net_followers');
});

test('a connected personal linkedin does not blank the workspace follower total', function () {
    $workspace = Workspace::factory()->create();
    $mastodon = SocialAccount::factory()->mastodon()->create(['workspace_id' => $workspace->id, 'created_at' => '2026-08-01']);
    SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id, 'created_at' => '2026-08-01']);
    AnalyticsAccountDailySnapshot::factory()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $mastodon->id,
        'social_account_key' => $mastodon->id,
        'network' => $mastodon->platform->network(),
        'platform_user_id' => $mastodon->platform_user_id,
        'platform' => $mastodon->platform,
        'date' => '2026-09-10',
        'followers_count' => 70,
    ]);

    $report = app(BuildWorkspaceAnalyticsReport::class)->execute(
        $workspace,
        new DateRange(CarbonImmutable::parse('2026-09-01', 'UTC'), CarbonImmutable::parse('2026-09-10', 'UTC')),
    );

    expect($report['summary']['followers']['value'])->toBe(70);
});
