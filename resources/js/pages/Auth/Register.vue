<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';

import Button from '@/components/ui/Button.vue';
import Field from '@/components/ui/Field.vue';
import TextInput from '@/components/ui/TextInput.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { paths } from '@/lib/paths';

const form = useForm({ name: '', email: '', password: '', password_confirmation: '' });

function submit(): void {
    // The disabled button stops clicks and Enter; this stops a second submit before it re-renders.
    if (form.processing) {
        return;
    }

    form.post(paths.register, { onFinish: () => form.reset('password', 'password_confirmation') });
}
</script>

<template>
    <Head title="Create account" />

    <AuthLayout title="Create account">
        <form class="flex flex-col gap-5" novalidate @submit.prevent="submit">
            <Field v-slot="{ id, describedBy, invalid }" label="Name" :error="form.errors.name">
                <TextInput
                    :id="id"
                    v-model="form.name"
                    type="text"
                    autocomplete="name"
                    :aria-describedby="describedBy"
                    :invalid="invalid"
                />
            </Field>

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

            <!-- Laravel reports a mismatched confirmation on `password`, so it shows here. -->
            <Field v-slot="{ id, describedBy, invalid }" label="Password" hint="At least 8 characters." :error="form.errors.password">
                <TextInput
                    :id="id"
                    v-model="form.password"
                    type="password"
                    autocomplete="new-password"
                    :aria-describedby="describedBy"
                    :invalid="invalid"
                />
            </Field>

            <Field v-slot="{ id, describedBy, invalid }" label="Confirm password" :error="form.errors.password_confirmation">
                <TextInput
                    :id="id"
                    v-model="form.password_confirmation"
                    type="password"
                    autocomplete="new-password"
                    :aria-describedby="describedBy"
                    :invalid="invalid"
                />
            </Field>

            <Button type="submit" class="w-full" :busy="form.processing" busy-label="Creating account…">Create account</Button>
        </form>

        <template #footer>
            Already have an account?
            <Link :href="paths.login" class="font-medium text-zinc-900 underline underline-offset-4">Sign in</Link>
        </template>
    </AuthLayout>
</template>
