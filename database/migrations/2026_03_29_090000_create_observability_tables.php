<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('audit_logs')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                if (! Schema::hasColumn('audit_logs', 'request_id')) {
                    $table->string('request_id', 64)->nullable()->after('url')->index();
                }

                if (! Schema::hasColumn('audit_logs', 'trace_id')) {
                    $table->string('trace_id', 64)->nullable()->after('request_id')->index();
                }

                if (! Schema::hasColumn('audit_logs', 'session_id')) {
                    $table->string('session_id', 128)->nullable()->after('trace_id')->index();
                }
            });
        }

        if (Schema::hasTable('api_usage_logs')) {
            Schema::table('api_usage_logs', function (Blueprint $table) {
                if (! Schema::hasColumn('api_usage_logs', 'request_id')) {
                    $table->string('request_id', 64)->nullable()->after('requested_at')->index();
                }

                if (! Schema::hasColumn('api_usage_logs', 'trace_id')) {
                    $table->string('trace_id', 64)->nullable()->after('request_id')->index();
                }

                if (! Schema::hasColumn('api_usage_logs', 'session_id')) {
                    $table->string('session_id', 128)->nullable()->after('trace_id')->index();
                }
            });
        }

        if (! Schema::hasTable('observability_events')) {
            Schema::create('observability_events', function (Blueprint $table) {
                $table->id();
                $table->string('event_key', 120)->unique();
                $table->string('source_type', 50)->nullable()->index();
                $table->string('source_id', 120)->nullable()->index();
                $table->timestamp('occurred_at')->index();
                $table->string('domain', 40)->index();
                $table->string('category', 40)->index();
                $table->string('outcome', 40)->index();
                $table->string('severity', 20)->index();
                $table->string('title');
                $table->text('summary')->nullable();
                $table->string('source_ip', 45)->nullable()->index();
                $table->string('source_country', 120)->nullable()->index();
                $table->string('source_asn', 120)->nullable()->index();
                $table->string('source_user_agent', 500)->nullable();
                $table->string('actor_type', 40)->nullable()->index();
                $table->string('actor_id', 120)->nullable()->index();
                $table->string('actor_label')->nullable();
                $table->string('target_route', 255)->nullable()->index();
                $table->string('target_method', 10)->nullable();
                $table->string('target_resource_type', 80)->nullable()->index();
                $table->string('target_resource_id', 120)->nullable()->index();
                $table->string('attack_technique', 120)->nullable()->index();
                $table->string('attack_pattern', 120)->nullable()->index();
                $table->string('host', 120)->nullable()->index();
                $table->string('environment', 40)->nullable()->index();
                $table->string('request_id', 64)->nullable()->index();
                $table->string('trace_id', 64)->nullable()->index();
                $table->string('session_id', 128)->nullable()->index();
                $table->string('incident_key', 120)->nullable()->index();
                $table->unsignedTinyInteger('risk_score')->default(0)->index();
                $table->json('risk_reasons')->nullable();
                $table->json('details')->nullable();
                $table->json('raw_ref')->nullable();
                $table->json('linked_entity_keys')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('observability_entities')) {
            Schema::create('observability_entities', function (Blueprint $table) {
                $table->id();
                $table->string('entity_key', 160)->unique();
                $table->string('entity_type', 40)->index();
                $table->string('label');
                $table->unsignedTinyInteger('risk_score')->default(0)->index();
                $table->timestamp('first_seen_at')->nullable()->index();
                $table->timestamp('last_seen_at')->nullable()->index();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('observability_event_entities')) {
            Schema::create('observability_event_entities', function (Blueprint $table) {
                $table->id();
                $table->foreignId('event_id')->constrained('observability_events')->cascadeOnDelete();
                $table->foreignId('entity_id')->constrained('observability_entities')->cascadeOnDelete();
                $table->string('relation', 40)->index();
                $table->timestamps();
                $table->unique(['event_id', 'entity_id', 'relation'], 'observability_event_entity_relation_unique');
            });
        }

        if (! Schema::hasTable('observability_entry_points')) {
            Schema::create('observability_entry_points', function (Blueprint $table) {
                $table->id();
                $table->string('entry_key', 160)->unique();
                $table->string('label');
                $table->string('subsystem', 40)->index();
                $table->string('route_pattern', 255)->nullable()->index();
                $table->json('methods')->nullable();
                $table->string('exposure_type', 40)->index();
                $table->string('criticality', 20)->default('medium')->index();
                $table->unsignedInteger('total_hits')->default(0);
                $table->unsignedInteger('unique_sources')->default(0);
                $table->unsignedInteger('blocked_hits')->default(0);
                $table->unsignedInteger('failed_hits')->default(0);
                $table->unsignedInteger('successful_hits')->default(0);
                $table->unsignedInteger('suspicious_hits')->default(0);
                $table->unsignedTinyInteger('risk_score')->default(0)->index();
                $table->timestamp('last_seen_at')->nullable()->index();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('observability_incidents')) {
            Schema::create('observability_incidents', function (Blueprint $table) {
                $table->id();
                $table->string('incident_key', 120)->unique();
                $table->string('title');
                $table->string('status', 30)->default('open')->index();
                $table->string('severity', 20)->default('medium')->index();
                $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
                $table->text('summary')->nullable();
                $table->text('notes')->nullable();
                $table->timestamp('detected_at')->nullable()->index();
                $table->timestamp('started_at')->nullable()->index();
                $table->timestamp('resolved_at')->nullable()->index();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('observability_incident_events')) {
            Schema::create('observability_incident_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('incident_id')->constrained('observability_incidents')->cascadeOnDelete();
                $table->foreignId('event_id')->constrained('observability_events')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['incident_id', 'event_id'], 'observability_incident_event_unique');
            });
        }

        if (! Schema::hasTable('observability_rollups_hourly')) {
            Schema::create('observability_rollups_hourly', function (Blueprint $table) {
                $table->id();
                $table->timestamp('bucket_start')->index();
                $table->string('dimension_type', 40)->index();
                $table->string('dimension_key', 160)->index();
                $table->unsignedInteger('total_events')->default(0);
                $table->unsignedInteger('blocked_events')->default(0);
                $table->unsignedInteger('failed_events')->default(0);
                $table->unsignedInteger('suspicious_events')->default(0);
                $table->unsignedInteger('successful_suspicious_events')->default(0);
                $table->unsignedInteger('distinct_sources')->default(0);
                $table->unsignedTinyInteger('avg_risk_score')->default(0);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['bucket_start', 'dimension_type', 'dimension_key'], 'observability_rollups_hourly_unique');
            });
        }

        if (! Schema::hasTable('observability_integrity_snapshots')) {
            Schema::create('observability_integrity_snapshots', function (Blueprint $table) {
                $table->id();
                $table->string('snapshot_key', 160)->unique();
                $table->string('path')->index();
                $table->string('category', 40)->index();
                $table->string('hash', 128)->nullable();
                $table->string('status', 20)->default('unknown')->index();
                $table->string('host', 120)->nullable()->index();
                $table->json('metadata')->nullable();
                $table->timestamp('observed_at')->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('observability_integrity_snapshots');
        Schema::dropIfExists('observability_rollups_hourly');
        Schema::dropIfExists('observability_incident_events');
        Schema::dropIfExists('observability_incidents');
        Schema::dropIfExists('observability_entry_points');
        Schema::dropIfExists('observability_event_entities');
        Schema::dropIfExists('observability_entities');
        Schema::dropIfExists('observability_events');

        if (Schema::hasTable('api_usage_logs')) {
            Schema::table('api_usage_logs', function (Blueprint $table) {
                foreach (['request_id', 'trace_id', 'session_id'] as $column) {
                    if (Schema::hasColumn('api_usage_logs', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('audit_logs')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                foreach (['request_id', 'trace_id', 'session_id'] as $column) {
                    if (Schema::hasColumn('audit_logs', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
