<script setup lang="ts">
/**
 * A succeeded generation's image on History: a thumbnail linking to the full-size
 * image in a new tab. Its box is sized before the image loads, 3:2 across a phone and
 * an 8.5rem square from `sm`, so a card never jumps.
 */
defineProps<{
    /** The image URL, `https://` or root-relative, as the server sent it. */
    src: string;
    /** The prompt, which describes the image. */
    alt: string;
}>();
</script>

<template>
    <div data-state="image" class="relative aspect-3/2 w-full shrink-0 overflow-hidden rounded-lg bg-zinc-50 sm:aspect-square sm:size-34">
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
    </div>
</template>
