<script setup lang="ts">

import { Link,  Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { List, Plus, LoaderCircle } from 'lucide-vue-next';
import Heading from '@/components/Heading.vue';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Separator } from '@/components/ui/separator';
import { Textarea } from '@/components/ui/textarea';
import VueDatePicker from '@vuepic/vue-datepicker';
import '@vuepic/vue-datepicker/dist/main.css';
import vSelect from "vue-select";
import { usePermission } from '@/composables/permissions';
import type { BreadcrumbItem } from '@/types';
import type { Building } from '@/types/buildings';

const props = defineProps<{
  buildings: Building[];
}>();  

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Nuova anagrafica',
        href: '/anagrafiche/create',
    },
];

type DocumentType = {
  label: string;
  id: string;
};

const documents = [
  {
      label: 'Passaporto',
      id: 'passport',
  },
  {
      label: "Carta d'identità",
      id: 'id_card',
  }
];

const { hasPermission, generateRoute } = usePermission();

const form = useForm({
    nome: '',
    indirizzo: '',
    email: '',
    email_secondaria: '',
    pec: '',
    codice_fiscale: '',
    tipologia_documento: '',
    numero_documento: '',
    scadenza_documento: '',
    luogo_nascita: '',
    data_nascita: '',
    telefono: '',
    cellulare: '',
    note: '',
    buildings: [],
});

const submit = () => {
    form.post(route(generateRoute('anagrafiche.store')), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
        }
    });
};

</script>

<template>
  <Head title="Crea nuova anagrafica" />

  <AppLayout :breadcrumbs="breadcrumbs">
  
    <div class="px-4 py-6">

      <Heading
        title="Crea anagrafica"
        description="Compila il seguente modulo per la creazione di una nuova anagrafica"
      />

      <form @submit.prevent="submit" class="space-y-6">

        <!-- Action buttons -->
        <div class="flex flex-col lg:flex-row lg:justify-end gap-2 w-full">
          <Button :disabled="form.processing" class="h-8 w-full lg:w-auto">
            <Plus class="w-4 h-4" v-if="!form.processing" />
            <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
            Salva
          </Button>

          <Link
            as="button"
            :href="route(generateRoute('anagrafiche.index'))"
            class="w-full lg:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90"
          >
            <List class="w-4 h-4" />
            <span>Elenco</span>
          </Link>
        </div>

        <!-- Form card -->
        <div class="bg-white dark:bg-muted rounded shadow-sm p-4 sm:p-6 border space-y-6">

          <!-- Section: Dati anagrafici -->
          <div class="space-y-1">
            <h3 class="text-lg font-medium text-gray-900">Dati anagrafici</h3>
            <p class="text-sm text-gray-500">Specifica i dati generici dell'anagrafica</p>
          </div>
          <Separator />

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <Label for="nome">Nome e cognome</Label>
              <Input id="nome" v-model="form.nome" class="w-full" placeholder="Nome e cognome" @focus="form.clearErrors('nome')" />
              <InputError :message="form.errors.nome" />
            </div>

            <div>
              <Label for="indirizzo">Indirizzo di residenza</Label>
              <Input id="indirizzo" v-model="form.indirizzo" class="w-full" placeholder="Indirizzo di residenza" @focus="form.clearErrors('indirizzo')" />
              <InputError :message="form.errors.indirizzo" />
            </div>

            <div>
              <Label for="telefono">Telefono fisso</Label>
              <Input id="telefono" v-model="form.telefono" class="w-full" placeholder="Telefono fisso" @focus="form.clearErrors('telefono')" />
              <InputError :message="form.errors.telefono" />
            </div>

            <div>
              <Label for="cellulare">Telefono cellulare</Label>
              <Input id="cellulare" v-model="form.cellulare" class="w-full" placeholder="Telefono cellulare" @focus="form.clearErrors('cellulare')" />
              <InputError :message="form.errors.cellulare" />
            </div>

            <div>
              <Label for="email">Email primario</Label>
              <Input id="email" v-model="form.email" class="w-full" placeholder="Email primario" @focus="form.clearErrors('email')" />
              <InputError :message="form.errors.email" />
            </div>

            <div>
              <Label for="email_secondaria">Email secondario</Label>
              <Input id="email_secondaria" v-model="form.email_secondaria" class="w-full" placeholder="Email secondario" @focus="form.clearErrors('email_secondaria')" />
              <InputError :message="form.errors.email_secondaria" />
            </div>

            <div>
              <Label for="pec">Email PEC</Label>
              <Input id="pec" v-model="form.pec" class="w-full" placeholder="Email PEC" @focus="form.clearErrors('pec')" />
              <InputError :message="form.errors.pec" />
            </div>
          </div>

          <!-- Section: Dati fiscali -->
          <div class="space-y-1">
            <h3 class="text-lg font-medium text-gray-900">Dati fiscali</h3>
            <p class="text-sm text-gray-500">Specifica i dati fiscali dell'anagrafica</p>
          </div>
          <Separator />

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <Label for="tipologia_documento">Tipologia documento</Label>
              <v-select
                class="w-full"
                :options="documents"
                label="label"
                v-model="form.tipologia_documento"
                :reduce="(d: DocumentType) => d.id"
                placeholder="Seleziona tipologia"
              />
            </div>

            <div>
              <Label for="numero_documento">Numero documento</Label>
              <Input id="numero_documento" v-model="form.numero_documento" class="w-full" placeholder="Numero documento" @focus="form.clearErrors('numero_documento')" />
              <InputError :message="form.errors.numero_documento" />
            </div>

            <div>
              <Label for="scadenza_documento">Scadenza documento</Label>
              <VueDatePicker
                v-model="form.scadenza_documento"
                class="w-full"
                format="dd/MM/yyyy"
                locale="it"
                :enable-time-picker="false"
                auto-apply
                placeholder="Data scadenza"
              />
              <InputError :message="form.errors.scadenza_documento" />
            </div>

            <div>
              <Label for="codice_fiscale">Codice fiscale</Label>
              <Input id="codice_fiscale" v-model="form.codice_fiscale" class="w-full" placeholder="Codice fiscale" @focus="form.clearErrors('codice_fiscale')" />
              <InputError :message="form.errors.codice_fiscale" />
            </div>

            <div>
              <Label for="luogo_nascita">Luogo di nascita</Label>
              <Input id="luogo_nascita" v-model="form.luogo_nascita" class="w-full" placeholder="Luogo di nascita" @focus="form.clearErrors('luogo_nascita')" />
              <InputError :message="form.errors.luogo_nascita" />
            </div>

            <div>
              <Label for="data_nascita">Data nascita</Label>
              <VueDatePicker
                v-model="form.data_nascita"
                class="w-full"
                format="dd/MM/yyyy"
                locale="it"
                :enable-time-picker="false"
                auto-apply
                placeholder="Data nascita"
              />
              <InputError :message="form.errors.data_nascita" />
            </div>

            <div>
              <Label for="buildings">Condomini</Label>
              <v-select
                multiple
                class="w-full"
                :options="buildings"
                label="nome"
                v-model="form.buildings"
                :reduce="(option: Building) => option.id"
                placeholder="Seleziona condomini"
              />
            </div>

            <div class="sm:col-span-2">
              <Label for="note">Note aggiuntive</Label>
              <Textarea id="note" class="w-full" placeholder="Inserisci una nota qui" v-model="form.note" @focus="form.clearErrors('note')" />
              <InputError :message="form.errors.note" />
            </div>
          </div>

        </div>
      </form>
    </div>
  </AppLayout>
</template>


<style src="vue-select/dist/vue-select.css"></style>