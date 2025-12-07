<script setup lang="ts">

import { computed, watch } from 'vue';
import { Head, useForm, usePage, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { Switch } from '@/components/ui/switch';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardFooter } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Settings } from 'lucide-vue-next';
import Heading from '@/components/Heading.vue';
import Alert from "@/components/Alert.vue";
import type { BreadcrumbItem } from '@/types';
import type { GeneralSettings } from '@/types/GeneralSettings';

const page = usePage<GeneralSettings>();
const flashMessage = computed(() => page.props.flash?.message);

const breadcrumbs: BreadcrumbItem[] = [
  { title: 'Impostazioni', href: '/impostazioni' },
  { title: 'Generali', href: '/impostazioni/generali' },
];

// Props da Inertia
const { can_register, language, open_condominio_on_login, default_condominio_id, condomini } = page.props;

const form = useForm({
  user_frontend_registration: Boolean(can_register),
  language: language || 'it',
  open_condominio_on_login: Boolean(open_condominio_on_login),
  default_condominio_id: default_condominio_id ? String(default_condominio_id) : null,
});

watch(() => form.default_condominio_id, (newValue) => {
  if (newValue) {
    form.clearErrors('default_condominio_id');
  }
});

const submit = () => {
  form.post(route('impostazioni.generali.store'), {
    preserveScroll: true,
  });
}; 

</script>

<template>
  <AppLayout :breadcrumbs="breadcrumbs">
    <Head title="Impostazioni generali" />

    <div class="px-4 py-6">
      <Heading
        title="Impostazioni generali"
        description="In questa pagina puoi gestire le impostazioni generali dell'applicazione"
      />

      <div v-if="flashMessage" class="py-2">
        <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>

      <form @submit.prevent="submit">

        <Card class="border shadow-none p-4">

          <div class="flex flex-col w-full sm:flex-row sm:justify-end mb-4">
            <Link
              as="button"
              :href="'/impostazioni'"
              class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-white hover:bg-primary/90"
            >
              <Settings class="w-4 h-4" />
              <span>Impostazioni</span>
            </Link>
          </div>

          <CardContent class="space-y-4 p-0">

            <!-- Lingua applicazione -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border rounded-lg p-4">
              <div class="flex-1">
                <label for="language" class="block text-sm font-medium leading-none mb-1">
                  Lingua applicazione
                </label>
                <p class="text-sm text-muted-foreground">
                  Seleziona la lingua principale per l'applicazione.
                </p>
              </div>

              <div class="w-full sm:w-[240px] shrink-0">
                <Select v-model="form.language">
                  <SelectTrigger class="w-full">
                    <SelectValue placeholder="Seleziona lingua" />
                  </SelectTrigger>
                  <SelectContent position="popper" :side-offset="4" align="start" class="min-w-[var(--reka-select-trigger-width)]">
                    <SelectItem value="it">
                      Italiano
                    </SelectItem>
                    <SelectItem value="en">
                      Inglese
                    </SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>

            <!-- Apri condominio al login -->
            <div class="flex flex-row items-center justify-between gap-4 border rounded-lg p-4">
              <div class="flex-1">
                <label class="block text-sm font-medium leading-none mb-1">
                  Apri condominio al login
                </label>
                <p class="text-sm text-muted-foreground">
                  Se attivato, l'utente verrà reindirizzato direttamente al condominio selezionato.
                </p>
              </div>

              <div class="shrink-0">
                <Switch v-model="form.open_condominio_on_login" />
              </div>
            </div>

            <!-- Condominio predefinito (mostrato solo se open_condominio_on_login è true) -->
            <div 
              v-if="form.open_condominio_on_login" 
              class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border rounded-lg p-4"
            >
              <div class="flex-1">
                <label class="block text-sm font-medium leading-none mb-1">
                  Condominio predefinito
                </label>
                <p class="text-sm text-muted-foreground">
                  Seleziona il condominio da aprire automaticamente il gestionale dopo il login.
                </p>
              </div>

              <div class="w-full sm:w-[240px] shrink-0">
                <Select v-model="form.default_condominio_id">
                  <SelectTrigger class="w-full" :class="{ 'border-red-500': form.errors.default_condominio_id }">
                    <SelectValue placeholder="Seleziona condominio" />
                  </SelectTrigger>
                  <SelectContent position="popper" :side-offset="4" align="start" class="min-w-[var(--reka-select-trigger-width)]">
                    <SelectItem 
                      v-for="c in condomini" 
                      :key="c.id" 
                      :value="c.id"
                    >
                      {{ c.nome }}
                    </SelectItem>
                  </SelectContent>
                </Select>
                <p v-if="form.errors.default_condominio_id" class="text-sm text-red-500 mt-1">
                  {{ form.errors.default_condominio_id }}
                </p>
              </div>
            </div>

            <!-- Abilita registrazione utenti -->
            <div class="flex flex-row items-center justify-between gap-4 border rounded-lg p-4">
              <div class="flex-1">
                <label class="block text-sm font-medium leading-none mb-1">
                  Abilita registrazione utenti
                </label>
                <p class="text-sm text-muted-foreground">
                  Se attivato, gli utenti possono registrarsi dalla home page.
                </p>
              </div>

              <div class="shrink-0">
                <Switch v-model="form.user_frontend_registration" />
              </div>
            </div>

          </CardContent>

          <CardFooter class="px-0 pt-6 flex items-center gap-4">
            <Button :disabled="form.processing">
              <span
                v-if="form.processing"
                class="animate-spin inline-block h-4 w-4 border-2 border-current rounded-full border-t-transparent mr-2"
              ></span>
              Salva impostazioni
            </Button>
          </CardFooter>
        </Card>
      </form>
    </div>
  </AppLayout>
</template>