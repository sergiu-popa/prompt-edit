<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import CreditPacks from '@/components/credits/CreditPacks.vue';
import Badge from '@/components/ui/Badge.vue';
import MoneyAmount from '@/components/ui/MoneyAmount.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatUsdCents } from '@/lib/money';
import type { BuyCreditsPageProps } from '@/types/credits';

/** The fake Buy credits page (D-71): fixed packs, no payment fields, and no payment taken. */
// eslint-disable-next-line vue/prop-name-casing -- named as the server sends it; Vue camelizes kebab-case only
defineProps<BuyCreditsPageProps>();

const page = usePage();
const purchased = computed(() => page.flash.purchased_nanodollars);
const credits = computed(() => page.props.credits);
</script>

<template>
    <Head title="Buy credits" />

    <AppLayout>
        <div class="flex flex-col gap-8">
            <PageHeader title="Buy credits" description="Add a pack to your balance to keep generating.">
                <template #actions>
                    <Badge tone="warning">Demo — no payment is taken</Badge>
                </template>
            </PageHeader>

            <p
                v-if="purchased !== undefined"
                role="status"
                data-purchased
                class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 ring-1 ring-emerald-600/20 ring-inset"
            >
                ✓ {{ formatUsdCents(purchased) }} added.<template v-if="credits">
                    Balance <MoneyAmount :nanodollars="credits.available_nanodollars" balance class="font-medium"
                /></template>
            </p>

            <CreditPacks :packs="packs_nanodollars" />
        </div>
    </AppLayout>
</template>
