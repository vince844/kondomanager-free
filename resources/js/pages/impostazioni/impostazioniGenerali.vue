<script setup lang="ts">

import { computed } from 'vue';
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
import type { Flash } from '@/types/flash';

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

const breadcrumbs: BreadcrumbItem[] = [
  { title: 'Impostazioni', href: '/impostazioni' },
  { title: 'generali', href: '/impostazioni/generali' },
];

const { can_register, language } = usePage().props;

const form = useForm({
  user_frontend_registration: Boolean(can_register),
  language: language || 'it',
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
        description="Im questa pagina puoi gestire le impostazioni generali dell'applicazione"
      />

      <div v-if="flashMessage" class="py-2">
        <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>

      <form @submit.prevent="submit">

        <Card class="border shadow-none p-4">

          <div class="flex flex-col w-full sm:flex-row sm:justify-end">
            <Link
              as="button"
              :href="'/impostazioni'"
              class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-white hover:bg-primary/90"
            >
              <Settings class="w-4 h-4" />
              <span>Impostazioni</span>
            </Link>
          </div>

          <CardContent class="p-0 mb-3">

            <div class="flex items-center gap-4 border rounded p-4 mt-3">
              <div class="flex-1 flex flex-col justify-center">
                <label for="language" class="text-sm font-medium leading-none">
                  Lingua applicazione
                </label>
                <p class="text-sm text-muted-foreground">
                  Seleziona la lingua principale per l'applicazione.
                </p>
              </div>

              <Select v-model="form.language">
                <SelectTrigger>
                  <SelectValue placeholder="Seleziona lingua applicazione" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="it">
                    Italiano
                  </SelectItem>
                  <SelectItem value="en">
                    Inglese
                  </SelectItem>
                </SelectContent>
              </Select>

            </div>
            
            <div class="flex items-center gap-4 border rounded p-4 mt-3">
              <!-- Text next to switch -->
              <div class="flex-1 flex flex-col justify-center">
                <label class="text-sm font-medium leading-none">
                  Abilita registrazione utenti
                </label>
                <p class="text-sm text-muted-foreground">
                  Se attivato, gli utenti possono registrarsi dalla pagina frontend.
                </p>
              </div>

              <!-- Switch -->
              <Switch
                v-model="form.user_frontend_registration"
                class="shrink-0"
              />
            </div>
          </CardContent>

          <CardFooter class="pl-0 flex items-center gap-4">
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
