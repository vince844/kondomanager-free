<script setup lang="ts">
    
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';
import { trans } from 'laravel-vue-i18n';

interface Props {
    email: string;
}

const props = defineProps<Props>();

const form = useForm({
    name: '',
    email: props.email,
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('invito.register.store'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};

</script>

<template>
     <AuthBase 
        :title="trans('auth.header.invitation_register.title')" 
        :description="trans('auth.header.invitation_register.description')"
    >
        <Head :title="trans('auth.header.invitation_register.head')" />

        <form @submit.prevent="submit" class="flex flex-col gap-6">
            <div class="grid gap-6">
                <div class="grid gap-2">
                    <Label for="name">{{ trans('auth.label.invitation_register.name') }}</Label>
                    <Input 
                        id="name" 
                        type="text" 
                        required autofocus 
                        :tabindex="1" 
                        autocomplete="name" 
                        v-model="form.name" 
                        :placeholder="trans('auth.placeholder.invitation_register.name')" 
                    />
                    <InputError :message="form.errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="email">{{ trans('auth.label.invitation_register.email') }}</Label>
                    <Input 
                        id="email" 
                        type="email" 
                        name="email" 
                        autocomplete="email" 
                        v-model="form.email" 
                        class="mt-1 block w-full" 
                        readonly 
                    />
                    <InputError :message="form.errors.email" />
                </div>

                <div class="grid gap-2">
                    <Label for="password">{{ trans('auth.label.invitation_register.password') }}</Label>
                    <Input
                        id="password"
                        type="password"
                        required
                        :tabindex="3"
                        autocomplete="new-password"
                        v-model="form.password"
                        :placeholder="trans('auth.placeholder.invitation_register.password')"
                    />
                    <InputError :message="form.errors.password" />
                </div>

                <div class="grid gap-2">
                    <Label for="password_confirmation">{{ trans('auth.label.invitation_register.password_confirmation') }}</Label>
                    <Input
                        id="password_confirmation"
                        type="password"
                        required
                        :tabindex="4"
                        autocomplete="new-password"
                        v-model="form.password_confirmation"
                        :placeholder="trans('auth.placeholder.invitation_register.password_confirmation')"
                    />
                    <InputError :message="form.errors.password_confirmation" />
                </div>

                <Button type="submit" class="mt-2 w-full" tabindex="5" :disabled="form.processing">
                    <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                    {{ trans('auth.button.invitation_register') }}
                </Button>
            </div>

            <div class="text-center text-sm text-muted-foreground">
                {{ trans('auth.link.have_account') }}
                <TextLink :href="route('login')" class="underline underline-offset-4" :tabindex="6">{{ trans('auth.link.login') }}</TextLink>
            </div>
        </form>
    </AuthBase>
</template>
