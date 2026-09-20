<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
    contrasts: { type: Array, required: true },
    paired: { type: Boolean, default: true },
});

const condition = ref('all');
const onlySignificant = ref(false);
const hideInvisible = ref(true);

const conditions = computed(() => [...new Set(props.contrasts.map((t) => t.condition))]);

const rows = computed(() =>
    props.contrasts.filter((test) => {
        if (condition.value !== 'all' && test.condition !== condition.value) return false;
        if (onlySignificant.value && !test.significant) return false;
        if (hideInvisible.value && test.visible === false) return false;
        return true;
    })
);

/* Favourability runs from 0 to 1 and is signed so that up is always better for
   the person. A negative delta is therefore a penalty however the underlying
   question was worded. */
const bar = (delta) => {
    const width = Math.min(100, Math.abs(delta) * 400);
    return { width: `${width}%`, marginLeft: delta < 0 ? `${50 - width / 2}%` : '50%' };
};
</script>

<template>
    <section>
        <div class="flex flex-wrap items-center gap-4 text-sm">
            <label class="flex items-center gap-2">
                <span class="text-zinc-500">Condition</span>
                <select v-model="condition" class="rounded-md border border-zinc-300 bg-white px-2 py-1 dark:border-zinc-700 dark:bg-zinc-900">
                    <option value="all">all</option>
                    <option v-for="c in conditions" :key="c" :value="c">{{ c }}</option>
                </select>
            </label>
            <label class="flex items-center gap-2"><input v-model="onlySignificant" type="checkbox" class="rounded"> significant only</label>
            <label v-if="paired" class="flex items-center gap-2"><input v-model="hideInvisible" type="checkbox" class="rounded"> hide control cells</label>
            <span class="text-xs text-zinc-500">{{ rows.length }} comparisons</span>
        </div>

        <p class="mt-3 max-w-3xl text-sm text-zinc-600 dark:text-zinc-400">
            <template v-if="paired">
                Each row is one person against their own anchor, identical in every way but the attribute named.
            </template>
            <template v-else>
                Each row is everyone at that attribute level against everyone at the reference level.
            </template>
            Delta is in favourability, signed so that below zero is worse treatment. <em>q</em> is the false-discovery
            rate after correcting for every comparison the run made.
        </p>

        <table class="mt-5 w-full text-sm">
            <thead class="text-left text-xs uppercase tracking-wide text-zinc-500">
                <tr class="border-b border-zinc-200 dark:border-zinc-800">
                    <th class="py-2 pr-3">Attribute</th>
                    <th class="pr-3">Swapped to</th>
                    <th class="pr-3">From</th>
                    <th class="pr-3">Condition</th>
                    <th class="pr-3 text-right">{{ paired ? 'Pairs' : 'n' }}</th>
                    <th class="pr-3 text-right">Delta</th>
                    <th class="w-40">&nbsp;</th>
                    <th class="pr-3 text-right">q</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                <tr
                    v-for="(test, i) in rows"
                    :key="i"
                    :class="test.visible === false ? 'opacity-50' : ''"
                >
                    <td class="py-2 pr-3">{{ test.factor_label || test.factor }}</td>
                    <td class="pr-3 font-medium">{{ test.level }}</td>
                    <td class="pr-3 text-zinc-500">{{ test.reference }}</td>
                    <td class="pr-3 text-zinc-500">{{ test.condition }}</td>
                    <td class="pr-3 text-right tabular-nums text-zinc-500">{{ test.pairs ?? test.n }}</td>
                    <td class="pr-3 text-right tabular-nums" :class="test.delta < 0 ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400'">
                        {{ test.delta > 0 ? '+' : '' }}{{ test.delta.toFixed(3) }}
                    </td>
                    <td>
                        <div class="relative h-2 rounded bg-zinc-100 dark:bg-zinc-800">
                            <div
                                class="absolute h-2 rounded"
                                :class="test.delta < 0 ? 'bg-red-500' : 'bg-emerald-500'"
                                :style="bar(test.delta)"
                            ></div>
                        </div>
                    </td>
                    <td class="pr-3 text-right tabular-nums" :class="test.significant ? 'font-semibold' : 'text-zinc-400'">
                        {{ test.q?.toFixed(3) }}
                    </td>
                </tr>
            </tbody>
        </table>
    </section>
</template>
