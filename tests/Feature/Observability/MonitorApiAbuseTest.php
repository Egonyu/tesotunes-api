<?php

use App\Http\Middleware\MonitorApiAbuse;
use App\Models\ObservabilityEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Other tests in the suite generate observability_events via the
    // AuditLogObserver / api-group MonitorApiAbuse middleware. Start each
    // test with a known-empty table so the assertions about emission stay
    // self-contained regardless of test-suite ordering.
    ObservabilityEvent::query()->delete();
});

it('emits a forbidden-probe event on a 403 response', function () {
    $request = Request::create('/api/admin/users', 'GET');

    (new MonitorApiAbuse)->handle($request, fn () => response()->json(['message' => 'Forbidden.'], 403));

    $event = ObservabilityEvent::query()->where('domain', 'api')->where('category', 'access')->first();

    expect($event)->not->toBeNull()
        ->and($event->outcome)->toBe('blocked')
        ->and($event->target_route)->toBe('/api/admin/users');
});

it('emits a rate-limit event on a 429 response', function () {
    $request = Request::create('/api/login', 'POST');

    (new MonitorApiAbuse)->handle($request, fn () => response()->json(['message' => 'Too many attempts.'], 429));

    $event = ObservabilityEvent::query()->where('category', 'rate_limit')->first();

    expect($event)->not->toBeNull()
        ->and($event->domain)->toBe('api')
        ->and($event->outcome)->toBe('blocked');
});

it('does not emit for successful responses', function () {
    $request = Request::create('/api/songs', 'GET');

    (new MonitorApiAbuse)->handle($request, fn () => response()->json(['data' => []], 200));

    expect(ObservabilityEvent::query()->count())->toBe(0);
});

it('ignores the security console own traffic', function () {
    $request = Request::create('/api/admin/observability/overview', 'GET');

    (new MonitorApiAbuse)->handle($request, fn () => response()->json(['message' => 'Forbidden.'], 403));

    expect(ObservabilityEvent::query()->count())->toBe(0);
});
