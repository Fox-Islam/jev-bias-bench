<script setup>
import { onMounted, ref, watch } from 'vue';
import { fetchProbes } from '../api.js';

const props = defineProps({ run: { type: String, required: true } });

const probes = ref([]);
const open = ref(null);
const filters = ref({ scenario: '', condition: '', attribute: '', level: '', limit: 12 });
const loading = ref(false);

const load = async () => {
    loading.value = true;
    try {
        probes.value = await fetchProbes(props.run, Object.fromEntries(
            Object.entries(filters.value).filter(([, v]) => v !== '')
        ));
    } finally {
        loading.value = false;
    }
};

onMounted(load);
watch(() => props.run, load);
</script>

<template>
    <section>
        <p class="max-w-3xl text-sm text-zinc-600 dark:text-zinc-400">
            The calls themselves. Every number in this dashboard traces back to one of these payloads, which is the only
            way a claim about bias can be checked rather than believed.
        </p>

        <div class="mt-4 flex flex-wrap items-end gap-3 text-sm">
            <label class="flex flex-col gap-1">
                <span class="text-xs text-zinc-500">Scenario</span>
                <input v-model="filters.scenario" placeholder="hiring_screen" class="rounded-md border border-zinc-300 bg-white px-2 py-1 dark:border-zinc-700 dark:bg-zinc-900">
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-xs text-zinc-500">Condition</span>
                <input v-model="filters.condition" placeholder="full" class="rounded-md border border-zinc-300 bg-white px-2 py-1 dark:border-zinc-700 dark:bg-zinc-900">
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-xs text-zinc-500">Swapped attribute</span>
                <input v-model="filters.attribute" placeholder="religion" class="rounded-md border border-zinc-300 bg-white px-2 py-1 dark:border-zinc-700 dark:bg-zinc-900">
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-xs text-zinc-500">Level</span>
                <input v-model="filters.level" placeholder="muslim" class="rounded-md border border-zinc-300 bg-white px-2 py-1 dark:border-zinc-700 dark:bg-zinc-900">
            </label>
            <button class="rounded-md bg-zinc-900 px-3 py-1.5 text-white dark:bg-zinc-100 dark:text-zinc-900" @click="load">Show</button>
        </div>

        <p v-if="loading" class="mt-6 text-sm text-zinc-500">Loading…</p>

        <ul v-else class="mt-6 space-y-2">
            <li v-for="probe in probes" :key="probe.id" class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                <button class="flex w-full items-center justify-between px-4 py-3 text-left text-sm" @click="open = open === probe.id ? null : probe.id">
                    <span>
                        <span class="font-medium">{{ probe.person }}</span>
                        <span class="ml-2 text-zinc-500">
                            {{ probe.scenario }} · {{ probe.condition }} · {{ probe.variant }}
                            <template v-if="probe.anchor"> · anchor</template>
                            <template v-else-if="probe.swapped"> · {{ probe.swapped }} = {{ probe.level }}</template>
                        </span>
                    </span>
                    <span class="text-xs text-zinc-400">{{ probe.latency_ms }}ms</span>
                </button>
                <div v-if="open === probe.id" class="grid gap-4 border-t border-zinc-200 p-4 lg:grid-cols-2 dark:border-zinc-800">
                    <div>
                        <h3 class="text-xs uppercase tracking-wide text-zinc-500">Sent</h3>
                        <pre class="mt-2 max-h-96 overflow-auto rounded bg-zinc-50 p-3 text-xs dark:bg-zinc-950">{{ JSON.stringify(probe.request, null, 2) }}</pre>
                    </div>
                    <div>
                        <h3 class="text-xs uppercase tracking-wide text-zinc-500">Answered</h3>
                        <pre class="mt-2 max-h-96 overflow-auto rounded bg-zinc-50 p-3 text-xs dark:bg-zinc-950">{{ JSON.stringify(probe.response, null, 2) }}</pre>
                    </div>
                </div>
            </li>
        </ul>
    </section>
</template>
