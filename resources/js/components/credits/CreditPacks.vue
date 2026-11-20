<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

import Button from '@/components/ui/Button.vue';
import { formatUsdCents } from '@/lib/money';
import { paths } from '@/lib/paths';

/**
 * The fake packs, one card each, with an Add button (D-71). One purchase token names
 * this visit's purchase, so a double click or a resent request records one; a new one
 * is made after each success, so the next Add is a new purchase. While a request runs
 * every Add is disabled and the clicked one reads "Adding…".
 */
const props = defineProps<{
    packs: number[];
}>();

const form = useForm<{ pack_nanodollars: number | null; purchase_token: string }>({
    pack_nanodollars: null,
    purchase_token: crypto.randomUUID(),
});

const cards = computed(() => props.packs.map((pack) => ({ nanodollars: pack, label: formatUsdCents(pack) })));

const error = computed(() => (form.processing ? undefined : (form.errors.pack_nanodollars ?? form.errors.purchase_token)));

function add(pack: number): void {
    if (form.processing) {
        return;
    }

    form.pack_nanodollars = pack;
    form.post(paths.creditPurchases, {
        preserveScroll: true,
        onSuccess: () => {
            form.purchase_token = crypto.randomUUID();
        },
    });
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <ul class="grid gap-4 sm:grid-cols-3">
            <li
                v-for="card in cards"
                :key="card.nanodollars"
                data-pack
                class="flex items-center justify-between gap-4 rounded-xl border border-zinc-200 bg-white p-4 shadow-xs sm:flex-col sm:items-stretch sm:p-6"
            >
                <p class="text-2xl font-semibold tracking-tight text-zinc-900 tabular-nums sm:text-center">{{ card.label }}</p>
                <Button
                    :disabled="form.processing"
                    :busy="form.processing && form.pack_nanodollars === card.nanodollars"
                    busy-label="Adding…"
                    @click="add(card.nanodollars)"
                    >Add <span class="sr-only">{{ card.label }}</span></Button
                >
            </li>
        </ul>
        <p v-if="error" role="alert" class="text-sm text-red-700">{{ error }}</p>
    </div>
</template>
