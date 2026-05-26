<?php

namespace App\Services\Observability;

use App\Enums\Observability\EventOutcome;
use App\Enums\Observability\EventSeverity;
use App\Enums\Observability\SecurityEventType;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Immutable-ish payload describing a single security event.
 *
 * Built fluently at the touchpoint, then either recorded synchronously by
 * {@see SecurityEventRecorder::record()} or shipped to the queue via
 * {@see SecurityEventRecorder::emit()}. Everything is captured at emit time so
 * the queued job never needs request context.
 */
class SecurityEvent
{
    public Carbon $occurredAt;

    public ?EventOutcome $outcome = null;

    public ?EventSeverity $severity = null;

    public ?string $title = null;

    public ?string $summary = null;

    public ?string $eventKey = null;

    public ?string $sourceIp = null;

    public ?string $sourceCountry = null;

    public ?string $sourceAsn = null;

    public ?string $sourceUserAgent = null;

    public ?string $actorType = null;

    public ?string $actorId = null;

    public ?string $actorLabel = null;

    public ?string $targetRoute = null;

    public ?string $targetMethod = null;

    public ?string $targetResourceType = null;

    public ?string $targetResourceId = null;

    public ?string $attackTechnique = null;

    public ?string $attackPattern = null;

    public ?string $host = null;

    public ?string $environment = null;

    public ?string $requestId = null;

    public ?string $traceId = null;

    public ?string $sessionId = null;

    public ?string $incidentKey = null;

    /** @var array<string, mixed> */
    public array $details = [];

    /** @var array<string, mixed> */
    public array $rawRef = [];

    private function __construct(public readonly SecurityEventType $type)
    {
        $this->occurredAt = Carbon::now();
    }

    public static function of(SecurityEventType $type): self
    {
        return new self($type);
    }

    public function occurredAt(Carbon $when): self
    {
        $this->occurredAt = $when;

        return $this;
    }

    public function outcome(EventOutcome $outcome): self
    {
        $this->outcome = $outcome;

        return $this;
    }

    public function severity(EventSeverity $severity): self
    {
        $this->severity = $severity;

        return $this;
    }

    public function title(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function summary(string $summary): self
    {
        $this->summary = $summary;

        return $this;
    }

    public function eventKey(string $eventKey): self
    {
        $this->eventKey = $eventKey;

        return $this;
    }

    public function source(?string $ip, ?string $country = null, ?string $asn = null, ?string $userAgent = null): self
    {
        $this->sourceIp = $ip;
        $this->sourceCountry = $country;
        $this->sourceAsn = $asn;
        $this->sourceUserAgent = $userAgent;

        return $this;
    }

    public function actor(?string $type, int|string|null $id = null, ?string $label = null): self
    {
        $this->actorType = $type;
        $this->actorId = $id === null ? null : (string) $id;
        $this->actorLabel = $label;

        return $this;
    }

    public function target(?string $route, ?string $method = null, ?string $resourceType = null, int|string|null $resourceId = null): self
    {
        $this->targetRoute = $route;
        $this->targetMethod = $method;
        $this->targetResourceType = $resourceType;
        $this->targetResourceId = $resourceId === null ? null : (string) $resourceId;

        return $this;
    }

    public function attack(?string $technique, ?string $pattern = null): self
    {
        $this->attackTechnique = $technique;
        $this->attackPattern = $pattern;

        return $this;
    }

    public function correlation(?string $requestId, ?string $traceId = null, ?string $sessionId = null): self
    {
        $this->requestId = $requestId;
        $this->traceId = $traceId;
        $this->sessionId = $sessionId;

        return $this;
    }

    public function incident(?string $incidentKey): self
    {
        $this->incidentKey = $incidentKey;

        return $this;
    }

    public function detail(string $key, mixed $value): self
    {
        $this->details[$key] = $value;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public function details(array $details): self
    {
        $this->details = [...$this->details, ...$details];

        return $this;
    }

    /**
     * @param  array<string, mixed>  $rawRef
     */
    public function rawRef(array $rawRef): self
    {
        $this->rawRef = $rawRef;

        return $this;
    }

    /**
     * Capture source, target, and correlation context from the current request.
     */
    public function fromRequest(Request $request): self
    {
        $this->sourceIp ??= $request->ip();
        $this->sourceUserAgent ??= $request->userAgent();
        $this->targetRoute ??= '/'.ltrim($request->path(), '/');
        $this->targetMethod ??= $request->method();
        $this->host ??= $request->getHost();
        $this->requestId ??= self::stringAttribute($request, 'observability_request_id');
        $this->traceId ??= self::stringAttribute($request, 'observability_trace_id');
        $this->sessionId ??= self::stringAttribute($request, 'observability_session_id');

        return $this;
    }

    private static function stringAttribute(Request $request, string $key): ?string
    {
        $value = $request->attributes->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'occurred_at' => $this->occurredAt->toIso8601String(),
            'outcome' => $this->outcome?->value,
            'severity' => $this->severity?->value,
            'title' => $this->title,
            'summary' => $this->summary,
            'event_key' => $this->eventKey,
            'source_ip' => $this->sourceIp,
            'source_country' => $this->sourceCountry,
            'source_asn' => $this->sourceAsn,
            'source_user_agent' => $this->sourceUserAgent,
            'actor_type' => $this->actorType,
            'actor_id' => $this->actorId,
            'actor_label' => $this->actorLabel,
            'target_route' => $this->targetRoute,
            'target_method' => $this->targetMethod,
            'target_resource_type' => $this->targetResourceType,
            'target_resource_id' => $this->targetResourceId,
            'attack_technique' => $this->attackTechnique,
            'attack_pattern' => $this->attackPattern,
            'host' => $this->host,
            'environment' => $this->environment,
            'request_id' => $this->requestId,
            'trace_id' => $this->traceId,
            'session_id' => $this->sessionId,
            'incident_key' => $this->incidentKey,
            'details' => $this->details,
            'raw_ref' => $this->rawRef,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $event = new self(SecurityEventType::from((string) $data['type']));
        $event->occurredAt = isset($data['occurred_at'])
            ? Carbon::parse((string) $data['occurred_at'])
            : Carbon::now();
        $event->outcome = isset($data['outcome']) ? EventOutcome::tryFrom((string) $data['outcome']) : null;
        $event->severity = isset($data['severity']) ? EventSeverity::tryFrom((string) $data['severity']) : null;
        $event->title = $data['title'] ?? null;
        $event->summary = $data['summary'] ?? null;
        $event->eventKey = $data['event_key'] ?? null;
        $event->sourceIp = $data['source_ip'] ?? null;
        $event->sourceCountry = $data['source_country'] ?? null;
        $event->sourceAsn = $data['source_asn'] ?? null;
        $event->sourceUserAgent = $data['source_user_agent'] ?? null;
        $event->actorType = $data['actor_type'] ?? null;
        $event->actorId = isset($data['actor_id']) ? (string) $data['actor_id'] : null;
        $event->actorLabel = $data['actor_label'] ?? null;
        $event->targetRoute = $data['target_route'] ?? null;
        $event->targetMethod = $data['target_method'] ?? null;
        $event->targetResourceType = $data['target_resource_type'] ?? null;
        $event->targetResourceId = isset($data['target_resource_id']) ? (string) $data['target_resource_id'] : null;
        $event->attackTechnique = $data['attack_technique'] ?? null;
        $event->attackPattern = $data['attack_pattern'] ?? null;
        $event->host = $data['host'] ?? null;
        $event->environment = $data['environment'] ?? null;
        $event->requestId = $data['request_id'] ?? null;
        $event->traceId = $data['trace_id'] ?? null;
        $event->sessionId = $data['session_id'] ?? null;
        $event->incidentKey = $data['incident_key'] ?? null;
        $event->details = (array) ($data['details'] ?? []);
        $event->rawRef = (array) ($data['raw_ref'] ?? []);

        return $event;
    }

    public function resolvedSeverity(): EventSeverity
    {
        return $this->severity ?? $this->type->defaultSeverity();
    }

    public function resolvedOutcome(): EventOutcome
    {
        return $this->outcome ?? $this->type->defaultOutcome();
    }

    public function resolvedTitle(): string
    {
        return $this->title ?? $this->type->title();
    }
}
