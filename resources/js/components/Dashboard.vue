<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { listRuns, fetchReport } from '../api.js';
import RunSummary from './RunSummary.vue';
import ContrastTable from './ContrastTable.vue';
import MeasureBreakdown from './MeasureBreakdown.vue';
import ProbeInspector from './ProbeInspector.vue';

const runs = ref([]);
const selected = ref(null);
const report = ref(null);
const error = ref(null);
const loading = ref(false);
const tab = ref('findings');

const tabs = [
    { key: 'findings', label: 'Findings' },
    { key: 'questions', label: 'By question' },
    { key: 'method', label: 'Method checks' },
    { key: 'calls', label: 'Raw calls' },
];

onMounted(async () => {
    try {
        runs.value = await listRuns();
        selected.value = runs.value[0]?.name ?? null;
    } catch (e) {
        error.value = e.message;
    }
});

watch(selected, async (name) => {
    if (!name) return;
    loading.value = true;
    error.value = null;
    try {
        report.value = await fetchReport(name);
    } catch (e) {
        error.value = e.message;
        report.value = null;
    } finally {
        loading.value = false;
    }
});

const contrasts = computed(() => report.value?.paired?.overall ?? report.value?.contrasts?.overall ?? []);
const byMeasure = computed(() => report.value?.paired?.by_measure ?? report.value?.contrasts?.by_measure ?? []);
const paired = computed(() => Boolean(report.value?.paired));
</script>

<template>
    <div class="mx-auto max-w-7xl px-6 py-10">
        <header class="flex flex-wrap items-end justify-between gap-4 border-b border-zinc-200 pb-6 dark:border-zinc-800">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Jev Bias Bench</h1>
                <p class="mt-1 max-w-2xl text-sm text-zinc-600 dark:text-zinc-400">
                    What changes in Jev's answer when the only thing that changes is who the person is.
                </p>
            </div>

            <label class="flex items-center gap-2 text-sm">
                <span class="text-zinc-500 dark:text-zinc-400">Run</span>
                <select
                    v-model="selected"
                    class="rounded-md border border-zinc-300 bg-white px-3 py-1.5 text-sm shadow-sm dark:border-zinc-700 dark:bg-zinc-900"
                >
                    <option v-for="run in runs" :key="run.name" :value="run.name">
                        {{ run.name }} — {{ run.done }}/{{ run.probes }} calls
                    </option>
                </select>
            </label>
        </header>

        <p v-if="error" class="mt-8 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">
            {{ error }}
        </p>

        <p v-else-if="!runs.length" class="mt-8 text-sm text-zinc-500">
            No runs yet. <code class="rounded bg-zinc-200 px-1 dark:bg-zinc-800">sail artisan bench:run --profile=pilot</code>
        </p>

        <p v-else-if="loading" class="mt-8 text-sm text-zinc-500">Working out the report…</p>

        <template v-else-if="report">
            <RunSummary :report="report" class="mt-8" />

            <nav class="mt-10 flex gap-1 border-b border-zinc-200 dark:border-zinc-800">
                <button
                    v-for="item in tabs"
                    :key="item.key"
                    class="-mb-px border-b-2 px-4 py-2 text-sm font-medium transition"
                    :class="tab === item.key
                        ? 'border-zinc-900 text-zinc-900 dark:border-zinc-100 dark:text-zinc-100'
                        : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200'"
                    @click="tab = item.key"
                >
                    {{ item.label }}
                </button>
            </nav>

            <div class="mt-6">
                <ContrastTable v-if="tab === 'findings'" :contrasts="contrasts" :paired="paired" />
                <MeasureBreakdown v-else-if="tab === 'questions'" :contrasts="byMeasure" :validity="report.validity" :paired="paired" />
                <section v-else-if="tab === 'method'" class="space-y-8">
                    <div>
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Does the question respond to the case?</h2>
                        <p class="mt-1 max-w-3xl text-sm text-zinc-600 dark:text-zinc-400">
                            A question answered the same way whether the candidate has three years or nine is not measuring
                            anything, and a group difference found on it would be noise with a story attached.
                        </p>
                        <table class="mt-4 w-full text-sm">
                            <thead class="text-left text-xs uppercase tracking-wide text-zinc-500">
                                <tr><th class="py-2">Question</th><th>Means by case</th><th>Span</th><th>Responds</th></tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                                <tr v-for="(check, key) in report.validity" :key="key">
                                    <td class="py-2 font-mono text-xs">{{ key }}</td>
                                    <td class="text-zinc-600 dark:text-zinc-400">
                                        <span v-for="(mean, variant) in check.means_by_variant" :key="variant" class="mr-3">
                                            {{ variant }} {{ mean.toFixed(3) }}
                                        </span>
                                    </td>
                                    <td class="tabular-nums">{{ check.weak_to_strong?.toFixed(3) ?? '—' }}</td>
                                    <td>
                                        <span :class="check.responds_to_merits ? 'text-emerald-600' : 'text-amber-600 font-medium'">
                                            {{ check.responds_to_merits ? 'yes' : 'flat' }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div>
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">What attaching a person does at all</h2>
                        <table class="mt-4 w-full text-sm">
                            <thead class="text-left text-xs uppercase tracking-wide text-zinc-500">
                                <tr><th class="py-2">Question</th><th>Mean favourability by condition</th></tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                                <tr v-for="(entry, key) in report.conditions" :key="key">
                                    <td class="py-2 font-mono text-xs">{{ key }}</td>
                                    <td class="text-zinc-600 dark:text-zinc-400">
                                        <span v-for="(mean, condition) in entry.means" :key="condition" class="mr-4">
                                            {{ condition }} <span class="tabular-nums text-zinc-900 dark:text-zinc-100">{{ mean.toFixed(3) }}</span>
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
                <ProbeInspector v-else :run="selected" />
            </div>
        </template>
    </div>
</template>
