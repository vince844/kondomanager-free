<script setup lang="ts">

import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';
import { trans } from 'laravel-vue-i18n';

defineProps<{
    status?: string;
}>();

const form = useForm({});

const submit = () => {
    form.post(route('verification.send'));
};
</script>

<template>
    <AuthLayout 
        :title="trans('auth.header.verification_notice.title')" 
        :description="trans('auth.header.verification_notice.description')"
    >
        <Head :title="trans('auth.header.verification_notice.head')" />

        <div v-if="status === 'verification-link-sent'" class="mb-4 text-center text-sm font-medium text-green-600">
            {{ trans('auth.status.verification_link_sent') }}
        </div>

        <form @submit.prevent="submit" class="space-y-6 text-center">
            <Button :disabled="form.processing" variant="secondary">
                <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                {{ trans('auth.button.verification_notice') }}
            </Button>

            <TextLink :href="route('logout')" method="post" as="button" class="mx-auto block text-sm"> {{ trans('auth.button.logout') }} </TextLink>
        </form>

        <hr class="my-6 border-border" />

        <div class="text-center text-xs text-muted-foreground">
            Powered by 
            <a 
                href="https://www.kondomanager.com" 
                target="_blank" 
                rel="noopener noreferrer" 
                class="font-semibold transition-colors hover:text-foreground hover:underline"
            >
                Kondomanager
            </a> 
            — 
            <a 
                href="https://github.com/vince844/kondomanager-free" 
                target="_blank" 
                rel="noopener noreferrer" 
                class="transition-colors hover:text-foreground hover:underline"
            >
                Software Open Source (AGPL-3.0)
            </a>
        </div>
        
    </AuthLayout>
</template>
