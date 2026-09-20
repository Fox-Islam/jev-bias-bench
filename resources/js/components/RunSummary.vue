<script setup>
import { computed } from 'vue';

const props = defineProps({ report: { type: Object, required: true } });

const meta = computed(() => props.report.run);
const noise = computed(() => props.report.paired?.null ?? props.report.noise_floor);
const calibration = computed(() => props.report.calibration ?? {});

/* The false-positive rate this run produced on comparisons that cannot be real:
   swaps the model never saw, or a condition where no person was attached. */
const falsePositives = computed(() => {
    const overall = calibration.value.overall ?? {};
    if (overall.invisible) return overall.invisible;
    if (overall.blind) return { tests: overall.blind.tests, significant: overall.blind.significant, rate: overall.blind.rate };
    return null;
});

const findings = computed(() => {
    const list = props.report.paired?.overall ?? props.report.contrasts?.overall ?? [];
    return list.filter((test) => test.significant && (test.visible ?? test.condition !== 'blind')).length;
});
</script>

<template>
    <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
            <dt class="text-xs uppercase tracking-wide text-zinc-500">Run</dt>
            <dd class="mt-1 font-medium">{{ meta.name }}</dd>
            <dd class="mt-1 text-xs text-zinc-500">
                {{ meta.design }} · {{ meta.personas }} people · {{ meta.calls }} calls · {{ meta.model }}
            </dd>
        </div>

        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
            <dt class="text-xs uppercase tracking-wide text-zinc-500">Noise floor</dt>
            <dd class="mt-1 font-medium tabular-nums">{{ noise?.sd ?? noise?.pooled_sd ?? '—' }}</dd>
            <dd class="mt-1 text-xs text-zinc-500">
                How far the same request moves when nothing about it changes. Nothing smaller counts.
            </dd>
        </div>

        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
            <dt class="text-xs uppercase tracking-wide text-zinc-500">False positives</dt>
            <dd class="mt-1 font-medium tabular-nums" :class="falsePositives?.significant ? 'text-amber-600' : 'text-emerald-600'">
                <template v-if="falsePositives">{{ falsePositives.significant }} / {{ falsePositives.tests }}</template>
                <template v-else>—</template>
            </dd>
            <dd class="mt-1 text-xs text-zinc-500">
                Comparisons that cannot be real, because the model never saw the thing that changed.
            </dd>
        </div>

        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
            <dt class="text-xs uppercase tracking-wide text-zinc-500">Findings</dt>
            <dd class="mt-1 font-medium tabular-nums">{{ findings }}</dd>
            <dd class="mt-1 text-xs text-zinc-500">
                Attribute swaps that survived correction for how many comparisons were made.
            </dd>
        </div>
    </dl>
</template>
