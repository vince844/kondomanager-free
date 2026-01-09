<script setup lang="ts">

import { Link,  Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { List, Plus, LoaderCircle, Info } from 'lucide-vue-next';
import Heading from '@/components/Heading.vue';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Separator } from '@/components/ui/separator';
import { Textarea } from '@/components/ui/textarea';
import VueDatePicker from '@vuepic/vue-datepicker';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import '@vuepic/vue-datepicker/dist/main.css';
import vSelect from "vue-select";
import { usePermission } from '@/composables/permissions';
import { trans } from 'laravel-vue-i18n';
import type { BreadcrumbItem } from '@/types';
import type { Building } from '@/types/buildings';
import type { DocumentType } from '@/types/anagrafiche';

const props = defineProps<{
  condomini: Building[];
}>();  

const breadcrumbs: BreadcrumbItem[] = [
  {
    title: 'Nuova anagrafica',
    href: '/anagrafiche/create',
  },
];

const documents = computed(() => [
  {
    label: trans('anagrafiche.label.passport'),
    id: 'passport',
  },
  {
    label: trans('anagrafiche.label.id_card'),
    id: 'id_card',
  }
]);

const { generateRoute } = usePermission();

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
  condomini: [],
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
  <Head :title="trans('anagrafiche.header.new_resident_head')" />

  <AppLayout :breadcrumbs="breadcrumbs">
  
    <div class="px-4 py-6">

      <Heading
        :title="trans('anagrafiche.header.new_resident_title')"
        :description="trans('anagrafiche.header.new_resident_description')"
      />

      <form @submit.prevent="submit" class="space-y-6">

        <!-- Action buttons -->
        <div class="flex flex-col lg:flex-row lg:justify-end gap-2 w-full">
          <Button :disabled="form.processing" class="h-8 w-full lg:w-auto">
            <Plus class="w-4 h-4" v-if="!form.processing" />
            <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
            {{trans('anagrafiche.actions.save_resident')}}
          </Button>

          <Link
            as="button"
            :href="route(generateRoute('anagrafiche.index'))"
            class="w-full lg:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90"
          >
            <List class="w-4 h-4" />
            <span>{{trans('anagrafiche.actions.list_residents')}}</span>
          </Link>
        </div>

        <!-- Form card -->
        <div class="bg-white dark:bg-muted rounded shadow-sm p-3 space-y-4 border mt-3">

          <!-- Section: Dati anagrafici -->
          <div class="pt-3">
            <h3 class="text-lg font-medium leading-6 text-gray-900">{{trans('anagrafiche.header.resident_info_heading')}}</h3>
            <p class="mt-1 text-sm text-gray-500">{{trans('anagrafiche.header.resident_info_description')}}</p>
          </div>
          <Separator class="my-4"/>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <Label for="nome">{{trans('anagrafiche.label.name')}}</Label>
              <Input 
                id="nome" 
                v-model="form.nome" 
                class="w-full" 
                :placeholder="trans('anagrafiche.placeholder.name')" 
                @focus="form.clearErrors('nome')" 
              />
              <InputError :message="form.errors.nome" />
            </div>

            <div>
              <Label for="indirizzo">{{trans('anagrafiche.label.address')}}</Label>
              <Input 
                id="indirizzo" 
                v-model="form.indirizzo" 
                class="w-full" 
                :placeholder="trans('anagrafiche.placeholder.address')" 
                @focus="form.clearErrors('indirizzo')" 
              />
              <InputError :message="form.errors.indirizzo" />
            </div>

            <div>
              <Label for="telefono">{{trans('anagrafiche.label.telephone')}}</Label>
              <Input 
                id="telefono" 
                v-model="form.telefono" 
                class="w-full" 
                :placeholder="trans('anagrafiche.placeholder.telephone')" 
                @focus="form.clearErrors('telefono')" 
              />
              <InputError :message="form.errors.telefono" />
            </div>

            <div>
              <Label for="cellulare">{{trans('anagrafiche.label.mobile')}}</Label>
              <Input 
                id="cellulare" 
                v-model="form.cellulare" 
                class="w-full" 
                :placeholder="trans('anagrafiche.placeholder.mobile')" 
                @focus="form.clearErrors('cellulare')" 
              />
              <InputError :message="form.errors.cellulare" />
            </div>

            <div>
              <Label for="email">{{trans('anagrafiche.label.primary_email')}}</Label>
              <Input 
                id="email" 
                v-model="form.email"
                class="w-full" 
                :placeholder="trans('anagrafiche.placeholder.primary_email')" 
                @focus="form.clearErrors('email')" 
              />
              <InputError :message="form.errors.email" />
            </div>

            <div>
              <Label for="email_secondaria">{{trans('anagrafiche.label.secondary_email')}}</Label>
              <Input 
                id="email_secondaria" 
                v-model="form.email_secondaria" 
                class="w-full" 
                :placeholder="trans('anagrafiche.placeholder.secondary_email')" 
                @focus="form.clearErrors('email_secondaria')" 
              />
              <InputError :message="form.errors.email_secondaria" />
            </div>

            <div>
              <Label for="pec">{{trans('anagrafiche.label.pec')}}</Label>
              <Input 
                id="pec" 
                v-model="form.pec" 
                class="w-full" 
                :placeholder="trans('anagrafiche.placeholder.pec')" 
                @focus="form.clearErrors('pec')" 
              />
              <InputError :message="form.errors.pec" />
            </div>
          </div>

          <!-- Section: Dati fiscali -->
          <div class="pt-3">
            <h3 class="text-lg font-medium leading-6 text-gray-900">{{trans('anagrafiche.header.resident_fiscal_heading')}}</h3>
            <p class="mt-1 text-sm text-gray-500">{{trans('anagrafiche.header.resident_fiscal_description')}}</p>
          </div>
          
          <Separator class="my-4" />

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <Label for="tipologia_documento">{{trans('anagrafiche.label.document_type')}}</Label>
              <v-select
                class="w-full"
                :options="documents"
                label="label"
                v-model="form.tipologia_documento"
                :reduce="(d: DocumentType) => d.id"
                :placeholder="trans('anagrafiche.placeholder.document_type')" 
              />
            </div>

            <div>
              <Label for="numero_documento">{{trans('anagrafiche.label.document_number')}}</Label>
              <Input 
                id="numero_documento" 
                v-model="form.numero_documento" 
                class="w-full" 
                :placeholder="trans('anagrafiche.placeholder.document_number')" 
                @focus="form.clearErrors('numero_documento')" 
              />
              <InputError :message="form.errors.numero_documento" />
            </div>

            <div>
              <Label for="scadenza_documento">{{trans('anagrafiche.label.document_expiry')}}</Label>
              <VueDatePicker
                v-model="form.scadenza_documento"
                class="w-full"
                format="dd/MM/yyyy"
                position="left" 
                locale="it"
                :enable-time-picker="false"
                auto-apply
                :placeholder="trans('anagrafiche.placeholder.document_expiry')" 
              />
              <InputError :message="form.errors.scadenza_documento" />
            </div>

            <div>
              <Label for="codice_fiscale">{{trans('anagrafiche.label.fiscal_code')}}</Label>
              <Input
                id="codice_fiscale" 
                v-model="form.codice_fiscale" 
                class="w-full" 
                :placeholder="trans('anagrafiche.placeholder.fiscal_code')" 
                @focus="form.clearErrors('codice_fiscale')" 
              />
              <InputError :message="form.errors.codice_fiscale" />
            </div>

            <div>
              <Label for="luogo_nascita">{{trans('anagrafiche.label.birth_place')}}</Label>
              <Input 
                id="luogo_nascita" 
                v-model="form.luogo_nascita" 
                class="w-full" 
                :placeholder="trans('anagrafiche.placeholder.birth_place')" 
                @focus="form.clearErrors('luogo_nascita')" 
              />
              <InputError :message="form.errors.luogo_nascita" />
            </div>

            <div>
              <Label for="data_nascita">{{trans('anagrafiche.label.birthday')}}</Label>
              <VueDatePicker
                v-model="form.data_nascita"
                class="w-full"
                format="dd/MM/yyyy"
                position="left" 
                locale="it"
                :enable-time-picker="false"
                auto-apply
                :placeholder="trans('anagrafiche.placeholder.birthday')" 
              />
              <InputError :message="form.errors.data_nascita" />
            </div>

            <div>
              <!-- Label text and icon in a flex row -->
              <div class="flex items-center text-sm font-medium mb-1 gap-x-2">
                <Label for="stato">{{trans('anagrafiche.label.buildings')}}</Label>

                <HoverCard>
                  <HoverCardTrigger as-child>
                  <button type="button" class="cursor-pointer">
                      <Info class="w-4 h-4 text-muted-foreground" />
                  </button>
                  </HoverCardTrigger>
                  <HoverCardContent class="w-80">
                  <div class="flex justify-between space-x-4">
                      <div class="space-y-1">
                      <h4 class="text-sm font-semibold">
                          {{ trans('anagrafiche.tooltip.buildings_header') }}
                      </h4>
                      <p class="text-sm">
                          {{ trans('anagrafiche.tooltip.buildings_description') }}
                      </p>
                      </div>
                  </div>
                  </HoverCardContent>
                </HoverCard>
              </div>

              <v-select
                multiple
                class="w-full"
                :options="condomini"
                label="nome"
                v-model="form.condomini"
                :reduce="(option: Building) => option.id"
                :placeholder="trans('anagrafiche.placeholder.buildings')" 
              />

            </div>

            <div class="sm:col-span-2">
              <Label for="note">{{trans('anagrafiche.label.notes')}}</Label>
              <Textarea 
                id="note" 
                class="w-full" 
                :placeholder="trans('anagrafiche.placeholder.notes')" 
                v-model="form.note" 
                @focus="form.clearErrors('note')" 
              />
              <InputError :message="form.errors.note" />
            </div>
          </div>

        </div>
      </form>
    </div>
  </AppLayout>
</template>

<style src="vue-select/dist/vue-select.css"></style>