<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
    contrasts: { type: Array, required: true },
    validity: { type: Object, default: () => ({}) },
    paired: { type: Boolean, default: true },
});

const attribute = ref('all');
const condition = ref('all');

const attributes = computed(() => [...new Set(props.contrasts.map((t) => t.factor))].sort());
const conditions = computed(() => [...new Set(props.contrasts.map((t) => t.condition))]);
const measures = computed(() => [...new Set(props.contrasts.map((t) => t.measure))].sort());

const visible = computed(() =>
    props.contrasts.filter(
        (t) =>
            (attribute.value === 'all' || t.factor === attribute.value) &&
            (condition.value === 'all' || t.condition === condition.value) &&
            t.visible !== false
    )
);

const levels = computed(() => [...new Set(visible.value.map((t) => `${t.factor}:${t.level}`))].sort());

const cell = (level, measure) =>
    visible.value.find((t) => `${t.factor}:${t.level}` === level && t.measure === measure);

/* A muted scale: strong colour only where the effect is both sizeable and
   survives correction, so an eye-catching grid cannot be built out of noise. */
const tint = (test) => {
    if (!test) return '';
    const strength = Math.min(1, Math.abs(test.delta) / 0.15);
    const solid = test.significant ? strength : strength * 0.25;
    return test.delta < 0
        ? `background-color: rgb(239 68 68 / ${solid * 0.8})`
        : `background-color: rgb(16 185 129 / ${solid * 0.8})`;
};
</script>

<template>
    <section>
        <div class="flex flex-wrap items-center gap-4 text-sm">
            <label class="flex items-center gap-2">
                <span class="text-zinc-500">Attribute</span>
                <select v-model="attribute" class="rounded-md border border-zinc-300 bg-white px-2 py-1 dark:border-zinc-700 dark:bg-zinc-900">
                    <option value="all">all</option>
                    <option v-for="a in attributes" :key="a" :value="a">{{ a }}</option>
                </select>
            </label>
            <label class="flex items-center gap-2">
                <span class="text-zinc-500">Condition</span>
                <select v-model="condition" class="rounded-md border border-zinc-300 bg-white px-2 py-1 dark:border-zinc-700 dark:bg-zinc-900">
                    <option value="all">all</option>
                    <option v-for="c in conditions" :key="c" :value="c">{{ c }}</option>
                </select>
            </label>
        </div>

        <p class="mt-3 max-w-3xl text-sm text-zinc-600 dark:text-zinc-400">
            The same comparisons kept per question instead of averaged. This is where a difference that only shows up in
            one decision — pain relief, say, or whether a guarantor is demanded — stops being averaged away by the
            questions that were answered evenly.
        </p>

        <div class="mt-5 max-w-full overflow-x-auto">
            <table class="text-xs">
                <thead>
                    <tr>
                        <th class="sticky left-0 z-10 bg-zinc-50 py-2 pr-3 text-left font-medium dark:bg-zinc-950">Swap</th>
                        <th v-for="m in measures" :key="m" class="px-2 pb-2 text-left align-bottom font-normal text-zinc-500">
                            <div class="w-20 leading-tight">
                                <div class="truncate" :title="m">{{ m.split('.')[1] }}</div>
                                <div class="truncate text-[10px] text-zinc-400" :title="m">{{ m.split('.')[0] }}</div>
                            </div>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="level in levels" :key="level" class="border-t border-zinc-200 dark:border-zinc-800">
                        <td class="sticky left-0 z-10 whitespace-nowrap bg-zinc-50 py-1.5 pr-3 font-mono dark:bg-zinc-950">{{ level }}</td>
                        <td
                            v-for="m in measures"
                            :key="m"
                            class="px-1 py-1.5 text-center tabular-nums"
                            :style="tint(cell(level, m))"
                            :title="cell(level, m) ? `${level} on ${m}: ${cell(level, m).delta} (q=${cell(level, m).q})` : 'not measured'"
                        >
                            {{ cell(level, m) ? cell(level, m).delta.toFixed(2) : '' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="mt-4 text-xs text-zinc-500">
            Red is worse treatment, green better. Cells are faint unless the effect survived correction, so a grid full of
            colour would have to be earned.
        </p>
    </section>
</template>
