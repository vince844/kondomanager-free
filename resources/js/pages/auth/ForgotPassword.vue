<script setup lang="ts">
    
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { trans } from 'laravel-vue-i18n';
import { Head, useForm } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';

defineProps<{
    status?: string;
}>();

const form = useForm({
    email: '',
});

const submit = () => {
    form.post(route('password.email'));
};
</script>

<template>
     <Head :title="trans('auth.header.forgot_password.head')" />

    <AuthLayout 
        :title="trans('auth.header.forgot_password.title')" 
        :description="trans('auth.header.forgot_password.description')"
    >
       
        <div v-if="status" class="mb-4 text-center text-sm font-medium text-green-600">
            {{ status }}
        </div>

        <div class="space-y-6">
            <form @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="email">{{ trans('auth.label.login.email') }}</Label>
                    <Input 
                        id="email" 
                        type="email" 
                        name="email" 
                        autocomplete="off" 
                        v-model="form.email" 
                        autofocus 
                        :placeholder="trans('auth.placeholder.login.email')"
                    />
                    <InputError :message="form.errors.email" />
                </div>

                <div class="my-6 flex items-center justify-start">
                    <Button class="w-full" :disabled="form.processing">
                        <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                        {{ trans('auth.button.forgot_password') }}
                    </Button>
                </div>
            </form>

            <div class="space-x-1 text-center text-sm text-muted-foreground">
                <span>{{ trans('auth.link.or_back_to_login') }}</span>
                <TextLink :href="route('login')">{{ trans('auth.link.back_to_login') }}</TextLink>
            </div>
        </div>
    </AuthLayout>
</template>
