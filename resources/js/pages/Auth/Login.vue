<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import Field from '@/components/ui/Field.vue';
import TextInput from '@/components/ui/TextInput.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { paths } from '@/lib/paths';

const form = useForm({ email: '', password: '' });

function submit(): void {
    // The disabled button stops clicks and Enter; this stops a second submit before it re-renders.
    if (form.processing) {
        return;
    }

    form.post(paths.login, { onFinish: () => form.reset('password') });
}
</script>

<template>
    <Head title="Sign in" />

    <AuthLayout title="Sign in">
        <form class="flex flex-col gap-5" novalidate @submit.prevent="submit">
            <Field v-slot="{ id, describedBy, invalid }" label="Email" :error="form.errors.email">
                <TextInput
                    :id="id"
                    v-model="form.email"
                    type="email"
                    autocomplete="username"
                    :aria-describedby="describedBy"
                    :invalid="invalid"
                />
            </Field>

            <Field v-slot="{ id, describedBy, invalid }" label="Password" :error="form.errors.password">
                <TextInput
                    :id="id"
                    v-model="form.password"
                    type="password"
                    autocomplete="current-password"
                    :aria-describedby="describedBy"
                    :invalid="invalid"
                />
            </Field>

            <Button type="submit" class="w-full" :busy="form.processing" busy-label="Signing in…">Sign in</Button>
        </form>

        <template #footer>
            No account yet?
            <Link :href="paths.register" class="font-medium text-zinc-900 underline underline-offset-4">Create one</Link>
        </template>
    </AuthLayout>
</template>
