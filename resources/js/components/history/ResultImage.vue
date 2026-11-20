<script setup lang="ts">
import { computed } from 'vue';

/**
 * A succeeded generation's image on History: a thumbnail linking to the full-size
 * image in a new tab, or, once expired, a placeholder that never requests the URL.
 * Its box is sized before the image loads, 3:2 across a phone and an 8.5rem square
 * from `sm`, so a card never jumps.
 */
const props = defineProps<{
    /** The image URL, `https://` or root-relative, as the server sent it. */
    src: string | null;
    /** The prompt, which describes the image. */
    alt: string;
    expired: boolean;
}>();

const state = computed(() => (props.expired ? 'expired' : 'image'));
</script>

<template>
    <div
        :data-state="state"
        :class="[
            'relative aspect-3/2 w-full shrink-0 overflow-hidden rounded-lg bg-zinc-50 sm:aspect-square sm:size-34',
            state !== 'image' && 'flex items-center justify-center p-3 text-center ring-1 ring-zinc-900/10 ring-inset',
        ]"
    >
        <template v-if="state === 'image' && src !== null">
            <a
                :href="src"
                target="_blank"
                rel="noopener noreferrer"
                class="block size-full rounded-lg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-900"
            >
                <img :src="src" :alt="alt" loading="lazy" decoding="async" class="size-full object-cover" />
                <span class="sr-only">(opens in a new tab)</span>
            </a>
            <!-- Above the image, so its edge stays visible on a light picture. -->
            <span aria-hidden="true" class="pointer-events-none absolute inset-0 rounded-lg ring-1 ring-zinc-900/10 ring-inset"></span>
        </template>
        <p v-else class="text-xs font-medium text-zinc-500">Image expired</p>
    </div>
</template>
