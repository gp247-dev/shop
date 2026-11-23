<?php

namespace GP247\Shop\Tests\Feature\Compat;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * Whatever a client sends, a GP247 API route answers in the error contract — never a
 * 500 or a redirect. Two sweeps over every registered `api/*` route: a call without a
 * token and without `Accept: application/json` (curl, Postman and many SDKs send a
 * wildcard Accept) gets 401 `unauthenticated`; a write call with the right kind of token and an
 * empty JSON body gets a 4xx JSON answer.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-error-contract
 * @aidlc-adr compat-foundation_rest-api-hardening
 */
#[Group('compat-foundation')]
class ApiAlwaysJsonTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;

    /**
     * Registered GP247 API routes, one entry per route (first HTTP verb).
     *
     * @return array<int, array{method: string, uri: string, route: Route}>
     */
    private function apiRoutes(): array
    {
        $routes = [];
        foreach (Router::getRoutes()->getRoutes() as $route) {
            if (!str_starts_with($route->uri(), 'api/')) {
                continue;
            }
            $routes[] = [
                'method' => $route->methods()[0],
                // WHY a fixed value: the sweep checks the answer's shape, not a record.
                'uri'    => '/' . preg_replace('/\{[^}]+\}/', 'zz1', $route->uri()),
                'route'  => $route,
            ];
        }
        $this->assertNotEmpty($routes, 'the API routes must be registered in tests');

        return $routes;
    }

    /**
     * Whether the route signs the caller in (auth:<guard> in its middleware).
     */
    private function needsSignIn(Route $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && ($middleware === 'auth' || str_starts_with($middleware, 'auth:'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * One call as a plain HTTP client would make it: no JSON Accept header.
     */
    private function plainCall(string $method, string $uri, string $accept): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->call($method, $uri, [], [], [], ['HTTP_ACCEPT' => $accept]);
    }

    public function test_a_signed_in_endpoint_called_without_a_token_or_json_accept_answers_401_json(): void
    {
        $offenders = [];
        $checked = 0;
        foreach ($this->apiRoutes() as $r) {
            if (!$this->needsSignIn($r['route'])) {
                continue;
            }
            $checked++;
            foreach (['*/*', 'text/html'] as $accept) {
                $response = $this->plainCall($r['method'], $r['uri'], $accept);
                if ($response->getStatusCode() !== 401 || $response->json('code') !== 'unauthenticated') {
                    $offenders[] = "{$r['method']} {$r['uri']} (Accept {$accept}) => {$response->getStatusCode()} " . substr((string) $response->getContent(), 0, 80);
                }
            }
        }

        $this->assertGreaterThan(10, $checked, 'the sweep must reach the signed-in routes');
        $this->assertSame([], $offenders);
    }

    public function test_a_write_endpoint_given_an_empty_body_answers_a_json_error_not_a_server_error(): void
    {
        $this->app['config']->set('mail.default', 'array');
        $this->pinAdminStoreContext(null, null);
        $admin = $this->makeAdmin([], ['administrator']);
        $customer = $this->makeCustomer();

        $offenders = [];
        $checked = 0;
        foreach ($this->apiRoutes() as $r) {
            if (!in_array($r['method'], ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                continue;
            }
            $checked++;
            // WHY a fresh token per call: a logout or session route revokes the token it is given.
            $token = str_starts_with($r['uri'], '/api/core') || str_starts_with($r['uri'], '/api/v2/core')
                ? $this->tokenFor($admin, ['admin-supper'])
                : $this->tokenFor($customer, ['user']);
            $response = $this->apiCall($r['method'], $r['uri'], $token, []);
            $status = $response->getStatusCode();
            $json = json_decode((string) $response->getContent(), true);
            if ($status >= 500 || !is_array($json) || ($status >= 400 && !isset($json['code']))) {
                $offenders[] = "{$r['method']} {$r['uri']} => {$status} " . substr((string) $response->getContent(), 0, 80);
            }
        }

        $this->assertGreaterThan(10, $checked, 'the sweep must reach the write routes');
        $this->assertSame([], $offenders);
    }
}
