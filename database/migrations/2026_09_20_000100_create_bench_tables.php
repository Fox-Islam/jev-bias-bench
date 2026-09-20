<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runs', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('preset');
            $table->string('design')->default('counterfactual');
            $table->unsignedBigInteger('seed');
            $table->string('provider');
            $table->string('model');
            $table->json('scenario_keys');
            $table->json('conditions');
            $table->unsignedInteger('persona_count');
            $table->unsignedInteger('bases')->default(1);
            $table->unsignedInteger('replicates');
            $table->string('status')->default('pending');
            $table->text('notes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('personas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('idx');
            $table->string('full_name');
            $table->unsignedInteger('base_index');
            $table->boolean('is_anchor')->default(false);
            $table->string('swapped_attribute')->nullable();
            $table->string('swapped_level')->nullable();
            $table->json('profile');
            $table->timestamps();
            $table->unique(['run_id', 'idx']);
        });

        Schema::create('probes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('persona_id')->constrained()->cascadeOnDelete();
            $table->string('scenario_key');
            $table->string('condition');
            $table->unsignedSmallInteger('replicate');
            $table->string('case_variant');
            $table->string('status')->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->string('request_id')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->double('cost')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['run_id', 'persona_id', 'scenario_key', 'condition', 'replicate'], 'probes_cell_unique');
            $table->index(['run_id', 'status']);
        });

        Schema::create('outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('probe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('persona_id')->constrained()->cascadeOnDelete();
            $table->string('scenario_key');
            $table->string('condition');
            $table->unsignedSmallInteger('replicate');
            $table->string('case_variant');
            $table->string('question_key');
            $table->string('kind');
            $table->double('value');
            $table->double('confidence')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
            $table->index(['run_id', 'scenario_key', 'question_key', 'condition'], 'outcomes_cell_index');
            $table->index(['run_id', 'persona_id']);
            $table->index(['run_id', 'condition', 'question_key'], 'outcomes_condition_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outcomes');
        Schema::dropIfExists('probes');
        Schema::dropIfExists('personas');
        Schema::dropIfExists('runs');
    }
};
