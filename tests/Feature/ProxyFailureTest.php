<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\XApiException;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\Proxy;
use App\Models\Team;
use App\Models\User;
use App\Models\XAccount;
use App\Services\X\XApiClientFactory;
use App\Support\ProxyFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Telling a proxy's refusal apart from X's.
 *
 * Every call an account makes goes through its proxy, so a failure can come
 * from either end -- and the message that reached the failed column said "HTTP
 * request returned status code 407" while the log line above it said "X API
 * error". Both blamed X for a request X never received.
 */
class ProxyFailureTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->team, $this->user] = $this->tenant();
    }

    private function proxy(array $attributes = []): Proxy
    {
        return Proxy::factory()->create($attributes + [
            'team_id' => $this->team->id,
            'host' => '157.15.39.126',
            'port' => 21466,
        ]);
    }

    #[Test]
    public function a_407_names_the_proxy_and_says_x_never_saw_it(): void
    {
        /*
         * RFC 9110 defines 407 as a proxy asking for credentials, so it cannot
         * have come from the origin server. That makes it the one status that
         * can be attributed with certainty -- and the one whose old wording
         * sent people to check their X app.
         */
        $explained = ProxyFailure::fromStatus(407, $this->proxy());

        $this->assertNotNull($explained);
        $this->assertStringContainsString('157.15.39.126:21466', $explained);
        $this->assertStringContainsString('407', $explained);
        $this->assertStringContainsString('chưa từng đi tới X', $explained);
    }

    #[Test]
    public function a_status_that_could_be_either_is_left_alone(): void
    {
        // A wrong explanation is worse than a generic one: it sends whoever
        // reads it somewhere else entirely. Only 407 is certain.
        foreach ([401, 403, 429, 500, 502, 503] as $status) {
            $this->assertNull(
                ProxyFailure::fromStatus($status, $this->proxy()),
                "HTTP {$status} was claimed for the proxy, and it cannot be"
            );
        }
    }

    #[Test]
    public function the_client_reports_a_407_against_the_proxy_not_against_x(): void
    {
        /*
         * The whole point, end to end. The account's proxy answers 407 before
         * the request reaches X, and what comes back has to name the proxy --
         * this is the sentence that lands in the failed column.
         */
        $proxy = $this->proxy();
        $account = XAccount::factory()->create([
            'team_id' => $this->team->id,
            'proxy_id' => $proxy->id,
        ]);

        Http::fake(['*' => Http::response('Proxy Authentication Required', 407)]);

        try {
            app(XApiClientFactory::class)->for($account->fresh())->send('GET', 'https://api.x.com/2/users/me');
            $this->fail('a 407 should not have been accepted');
        } catch (XApiException $e) {
            $this->assertSame('proxy_rejected', $e->errorCode);
            $this->assertStringContainsString('157.15.39.126:21466', $e->getMessage());
            $this->assertStringNotContainsString('HTTP request returned status code', $e->getMessage());
        }
    }

    #[Test]
    public function an_unreachable_proxy_is_named_rather_than_left_as_a_curl_number(): void
    {
        // "cURL error 7" in the failed column sends whoever reads it to look at
        // X, which is the one place that cannot be the problem.
        $explained = ProxyFailure::fromThrowable(
            new RuntimeException('cURL error 7: Failed to connect to 157.15.39.126 port 21466'),
            $this->proxy()
        );

        $this->assertNotNull($explained);
        $this->assertStringContainsString('157.15.39.126:21466', $explained);
    }

    #[Test]
    public function a_refused_tunnel_carrying_407_reads_as_authentication(): void
    {
        // The same refusal arrives two ways depending on whether curl surfaces
        // the proxy's response or fails the tunnel, and both mean one thing.
        $explained = ProxyFailure::fromThrowable(
            new RuntimeException('cURL error 7: CONNECT tunnel failed, response 407'),
            $this->proxy()
        );

        $this->assertStringContainsString('từ chối xác thực', (string) $explained);
    }

    #[Test]
    public function a_timeout_is_named_as_the_proxy_being_slow(): void
    {
        $explained = ProxyFailure::fromThrowable(
            new RuntimeException('cURL error 28: Operation timed out after 30000 milliseconds'),
            $this->proxy()
        );

        $this->assertStringContainsString('không trả lời kịp', (string) $explained);
    }

    #[Test]
    public function an_account_with_no_proxy_gets_no_proxy_explanation(): void
    {
        /*
         * The same curl error without a proxy is the server's own connection
         * failing, and blaming a proxy that is not in the path would be the
         * exact mistake this class exists to stop.
         */
        $this->assertNull(ProxyFailure::fromThrowable(
            new RuntimeException('cURL error 28: Operation timed out'),
            null
        ));
    }

    #[Test]
    public function an_ordinary_failure_keeps_its_own_words(): void
    {
        // Anything unrecognised passes through so the original message reaches
        // the column rather than being replaced by a guess.
        $this->assertNull(ProxyFailure::fromThrowable(
            new RuntimeException('Something else went wrong'),
            $this->proxy()
        ));
    }

    #[Test]
    public function the_list_carries_the_reason_and_not_only_the_count(): void
    {
        /*
         * The row said "1 lỗi" and stopped there, so reading what happened
         * meant opening the post -- and on a fan-out where one dead proxy
         * refused every target, opening it to read the same sentence again.
         *
         * This also guards the query itself: lastFailedTarget is an ofMany
         * relation, which joins a derived table, so an unqualified column list
         * on the eager load makes `id` ambiguous and MySQL refuses it. The
         * whole endpoint returned 500 and nothing in the suite noticed.
         */
        $post = Post::factory()->create([
            'team_id' => $this->team->id,
            'targets_total' => 1,
            'targets_failed' => 1,
        ]);
        $account = XAccount::factory()->create(['team_id' => $this->team->id]);

        PostTarget::factory()->create([
            'post_id' => $post->id,
            'team_id' => $this->team->id,
            'x_account_id' => $account->id,
            'status' => 'failed',
            'error_code' => 'proxy_rejected',
            'error_message' => 'Proxy 157.15.39.126:21466 từ chối xác thực (407).',
        ]);

        $response = $this->actingAs($this->user->refresh())->getJson('/api/v1/posts')->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $post->id);

        $this->assertSame('proxy_rejected', $row['last_error']['code']);
        $this->assertStringContainsString('157.15.39.126:21466', $row['last_error']['message']);
    }

    #[Test]
    public function a_failure_naming_some_other_host_is_not_blamed_on_the_proxy(): void
    {
        /*
         * Found in real rows, not in theory. Reverb being down fails a publish
         * with "Pusher error: cURL error 28: Failed to connect to 127.0.0.1
         * port 8080", and matching on the curl number alone blamed the
         * account's proxy -- which was working -- in a sentence that read
         * perfectly and pointed at the wrong machine.
         */
        $explained = ProxyFailure::fromThrowable(
            new RuntimeException('Pusher error: cURL error 28: Failed to connect to 127.0.0.1 port 8080 after 10013 ms'),
            $this->proxy()
        );

        $this->assertNull($explained, 'a failure against 127.0.0.1:8080 was blamed on the proxy');
    }

    #[Test]
    public function a_failure_naming_this_proxy_still_is(): void
    {
        // The other half: the guard must not refuse the case it was built for.
        $explained = ProxyFailure::fromThrowable(
            new RuntimeException('cURL error 28: Failed to connect to 157.15.39.126 port 21466 after 30000 ms'),
            $this->proxy()
        );

        $this->assertStringContainsString('157.15.39.126:21466', (string) $explained);
    }

    #[Test]
    public function a_refused_tunnel_is_not_confused_by_the_destination_it_names(): void
    {
        // A CONNECT failure names X after "for https://…", and that is the
        // destination, not evidence about where the fault lies.
        $explained = ProxyFailure::fromThrowable(
            new RuntimeException('cURL error 7: CONNECT tunnel failed, response 407 for https://api.x.com/2/tweets'),
            $this->proxy()
        );

        $this->assertStringContainsString('từ chối xác thực', (string) $explained);
    }
}
