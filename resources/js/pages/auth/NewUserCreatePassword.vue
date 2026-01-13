<script setup lang="ts">
    
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';
import { trans } from 'laravel-vue-i18n';

interface Props {
    email: string;
}

const props = defineProps<Props>();

const form = useForm({
    email: props.email,
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('password.create'), {
        onFinish: () => {
            form.reset('password', 'password_confirmation');
        },
    });
}; 

</script>

<template>
    <AuthLayout 
        :title="trans('auth.header.set_password.title')" 
        :description="trans('auth.header.set_password.description')"
    >
        <Head :title="trans('auth.header.set_password.head')" />

        <form @submit.prevent="submit">
            <div class="grid gap-6">
                <div class="grid gap-2">
                    <Label for="email">{{ trans('auth.label.set_password.email') }}</Label>
                    <Input 
                        id="email" 
                        type="email" 
                        name="email" 
                        autocomplete="email" 
                        v-model="form.email" 
                        class="mt-1 block w-full" 
                        readonly 
                    />
                    <InputError :message="form.errors.email" class="mt-2" />
                </div>

                <div class="grid gap-2">
                    <Label for="password">{{ trans('auth.label.set_password.password') }}</Label>
                    <Input
                        id="password"
                        type="password"
                        name="password"
                        autocomplete="new-password"
                        v-model="form.password"
                        class="mt-1 block w-full"
                        autofocus
                        :placeholder="trans('auth.placeholder.set_password.password')"
                    />
                    <InputError :message="form.errors.password" />
                </div>

                <div class="grid gap-2">
                    <Label for="password_confirmation">{{ trans('auth.label.set_password.password_confirmation') }}</Label>
                    <Input
                        id="password_confirmation"
                        type="password"
                        name="password_confirmation"
                        autocomplete="new-password"
                        v-model="form.password_confirmation"
                        class="mt-1 block w-full"
                        :placeholder="trans('auth.placeholder.set_password.password_confirmation')"
                    />
                    <InputError :message="form.errors.password_confirmation" />
                </div>

                <Button type="submit" class="mt-4 w-full" :disabled="form.processing">
                    <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                    {{ trans('auth.button.set_password') }}
                </Button>
            </div>
        </form>
    </AuthLayout>
</template>
