<script setup lang="ts">

import { Head, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import vSelect from "vue-select";
import { LoaderCircle, Info } from 'lucide-vue-next';
import UtentiLayout from '@/layouts/utenti/Layout.vue';
import { TagsInput, TagsInputInput, TagsInputItem, TagsInputItemDelete, TagsInputItemText } from '@/components/ui/tags-input';
import { Separator } from '@/components/ui/separator';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import { trans } from 'laravel-vue-i18n';
import type { BreadcrumbItem } from '@/types';
import type { Building } from '@/types/buildings';
import { erroriDegliIndirizzi } from '@/lib/inviti/erroriDegliIndirizzi';

defineProps<{
  buildings: Building[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
  { title: 'Impostazioni', href: '/impostazioni' },
  { title: 'utenti', href: '/utenti' },
  { title: 'inviti', href: '/inviti' },
  { title: 'invita utenti', href: '#' },
];

const form = useForm({
    emails: [] as string[],
    buildings: [],
});

// Un errore resta finché il campo non cambia: tolto o aggiunto un indirizzo, gli errori degli
// indirizzi (`emails`, `emails.0`…) non descrivono più l'elenco che si vede.
watch(() => form.emails, () => {
    const chiavi = Object.keys(form.errors).filter((chiave) => chiave === 'emails' || chiave.startsWith('emails.'));
    if (chiavi.length) {
        form.clearErrors(...(chiavi as Array<keyof typeof form.errors>));
    }
});

watch(() => form.buildings, () => form.clearErrors('buildings'));

const etichettaPulsante = computed(() => form.emails.length > 1
    ? trans('users.actions.send_invites', { count: String(form.emails.length) })
    : trans('users.actions.send_invite'));

const submit = () => {
    form.post(route("inviti.store"), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
        }
    });
};

const addCurrentInput = (event: Event) => {
  const input = event.target as HTMLInputElement;
  const value = input.value.trim();

  if (value && !form.emails.includes(value)) {
    form.emails = [...form.emails, value];
    input.value = '';
  }
};

</script>

<template>

    <Head :title="trans('users.header.new_invite_head')" />

    <AppLayout :breadcrumbs="breadcrumbs">

        <UtentiLayout>

            <form @submit.prevent="submit">

                <div>
                    <h3 class="text-lg font-medium leading-6 text-gray-900 dark:text-gray-100">{{ trans('users.header.new_invite_title') }}</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {{ trans('users.header.new_invite_description') }}
                    </p>
                </div>

                <Separator class="my-4" />

                <div class="py-4">
                    <div class="mt-2 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">

                        <!--  Indirizzi email -->
                        <div class="sm:col-span-3">
                            <div class="flex items-center text-sm font-medium pb-2 gap-x-2">
                                <Label for="emails">{{ trans('users.label.invite_emails') }}</Label>

                                <HoverCard>
                                    <HoverCardTrigger as-child>
                                        <button type="button" class="cursor-pointer">
                                            <Info class="w-4 h-4 text-muted-foreground" />
                                        </button>
                                    </HoverCardTrigger>
                                    <HoverCardContent class="w-80">
                                        <div class="space-y-1">
                                            <h4 class="text-sm font-semibold">{{ trans('users.label.invite_emails') }}</h4>
                                            <p class="text-sm">{{ trans('users.tooltip.invite_emails') }}</p>
                                        </div>
                                    </HoverCardContent>
                                </HoverCard>
                            </div>

                            <TagsInput v-model="form.emails" class="w-full">
                                <TagsInputItem v-for="item in form.emails" :key="item" :value="item">
                                    <TagsInputItemText />
                                    <TagsInputItemDelete @click="form.emails = form.emails.filter(email => email !== item)" />
                                </TagsInputItem>
                                <!-- `.prevent`: Invio aggiunge l'indirizzo e basta. Senza, mandava anche il
                                     modulo, con un indirizzo solo e i condomini ancora da scegliere. -->
                                <TagsInputInput
                                    id="emails"
                                    :placeholder="trans('users.placeholder.invite_emails')"
                                    @blur="addCurrentInput"
                                    @keydown.enter.prevent="addCurrentInput"
                                />
                            </TagsInput>

                            <InputError class="mt-2" :message="form.errors.emails" />
                            <!-- Gli errori dei singoli indirizzi arrivano su `emails.0`, `emails.1`…: senza
                                 queste righe «indirizzo già invitato» non compariva da nessuna parte. -->
                            <InputError
                                v-for="(errore, indice) in erroriDegliIndirizzi(form.errors)"
                                :key="indice"
                                class="mt-2"
                                :message="errore"
                            />
                        </div>

                        <!--  Condomini -->
                        <div class="sm:col-span-3">
                            <div class="flex items-center text-sm font-medium pb-2 gap-x-2">
                                <Label for="buildings">{{ trans('users.label.invite_buildings') }}</Label>

                                <HoverCard>
                                    <HoverCardTrigger as-child>
                                        <button type="button" class="cursor-pointer">
                                            <Info class="w-4 h-4 text-muted-foreground" />
                                        </button>
                                    </HoverCardTrigger>
                                    <HoverCardContent class="w-80">
                                        <div class="space-y-1">
                                            <h4 class="text-sm font-semibold">{{ trans('users.label.invite_buildings') }}</h4>
                                            <p class="text-sm">{{ trans('users.tooltip.invite_buildings') }}</p>
                                        </div>
                                    </HoverCardContent>
                                </HoverCard>
                            </div>

                            <v-select
                                multiple
                                input-id="buildings"
                                :options="buildings"
                                label="nome"
                                v-model="form.buildings"
                                :reduce="(option: Building) => option.codice_identificativo"
                                :placeholder="trans('users.placeholder.invite_buildings')"
                            />

                            <InputError class="mt-2" :message="form.errors.buildings" />
                        </div>

                    </div>
                </div>

                <div class="pt-5">
                    <div class="flex">

                        <Button :disabled="form.processing">
                            <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                            {{ etichettaPulsante }}
                        </Button>

                    </div>
                </div>
            </form>

        </UtentiLayout>

    </AppLayout>

  </template>

<style src="vue-select/dist/vue-select.css"></style>
