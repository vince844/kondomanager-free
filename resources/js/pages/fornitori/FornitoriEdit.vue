<script setup lang="ts">

import { ref } from 'vue';
import { Link, Head, useForm, usePage , router} from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { List, Plus, LoaderCircle, ArrowLeft} from 'lucide-vue-next';
import Heading from '@/components/Heading.vue';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Separator } from '@/components/ui/separator';
import { Checkbox } from '@/components/ui/checkbox';
import { Textarea } from '@/components/ui/textarea';
import MoneyInput from '@/components/MoneyInput.vue'
import { usePermission } from '@/composables/permissions';
import vSelect from "vue-select";
import VueDatePicker from '@vuepic/vue-datepicker';
import '@vuepic/vue-datepicker/dist/main.css';
import type { BreadcrumbItem } from '@/types';
import type { Categoria } from '@/types/categorie';
import type {Fornitore } from '@/types/fornitori';

const props = defineProps<{
  fornitore: Fornitore;
  categorie: Categoria[];
}>()

const breadcrumbs: BreadcrumbItem[] = [
  {
      title: 'Modifica fornitore',
      href: '/fornitori',
  }
];

const page = usePage();

const backUrl = page.props.back_url as string || route('fornitori.index');

const { generateRoute } = usePermission();

const form = useForm({
    ragione_sociale: props.fornitore?.ragione_sociale,
    codice_fiscale: props.fornitore?.codice_fiscale,
    partita_iva: props.fornitore?.partita_iva,
    nazione: props.fornitore?.nazione,
    indirizzo: props.fornitore?.indirizzo,
    comune: props.fornitore?.comune,
    provincia: props.fornitore?.provincia,
    cap: props.fornitore?.cap,
    iscrizione_cciaa: props.fornitore?.iscrizione_cciaa,
    data_iscrizione_cciaa: props.fornitore?.data_iscrizione_cciaa,
    capitale_sociale: props.fornitore?.capitale_sociale,
    categoria_id: props.fornitore?.categoria_id,
    codice_ateco: props.fornitore?.codice_ateco,
    certificazione_iso: props.fornitore?.certificazione_iso,
    numero_iscrizione_ordine: props.fornitore?.numero_ordine,
    note: props.fornitore?.note,
    telefono: props.fornitore?.telefono,
    cellulare: props.fornitore?.cellulare,
    fax: props.fornitore?.fax,
    email: props.fornitore.email,
    pec: props.fornitore?.pec,
    sito_web: props.fornitore?.sito_web,
});


const moneyOptions = ref({
  prefix: '',              
  suffix: '',              
  thousands: '.',          
  decimal: ',',          
  precision: 2, 
  allowNegative: true,           
  allowBlank: false,
  masked: true 
})

const submit = () => {
    form.put(route(generateRoute('fornitori.update'), {id: props.fornitore.id}), {
        preserveScroll: true
    });
};

</script>

<template>

  <Head title="Modifica fornitore" />

  <AppLayout :breadcrumbs="breadcrumbs">

    <div class="px-4 py-6">
      
      <Heading 
        title="Modifica fornitore" 
        description="Compila il seguente modulo per modificare i dati del fornitore" 
      />

      <form class="space-y-2" @submit.prevent="submit">

        <!-- Action buttons -->
        <div class="flex flex-col lg:flex-row lg:justify-end gap-2 w-full">
          <Button :disabled="form.processing" class="h-8 w-full lg:w-auto">
            <Plus class="w-4 h-4" v-if="!form.processing" />
            <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
            Salva
          </Button>

          <Link
            as="button"
            @click="router.visit(backUrl)"
            class="w-full lg:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90"
          >
            <ArrowLeft class="w-4 h-4" />
            <span>Indietro</span>
          </Link>
        </div>

        <div class="bg-white dark:bg-muted rounded shadow-sm p-3 space-y-4 border mt-3" >

          <div class="pt-3">
            <h3 class="text-lg font-medium leading-6 text-gray-900">Informazioni principali</h3>
            <p class="mt-1 text-sm text-gray-500">Di seguito è possibile specificare le informazioni principali del fornitore</p>
          </div>

          <Separator class="my-4" />

          <!--  Ragione sociale field -->
          <div class="mt-2 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
            <div class="sm:col-span-3">
              <Label for="ragione_sociale">Ragione sociale</Label>
              <Input 
                id="ragione_sociale" 
                class="mt-1 block w-full"
                v-model="form.ragione_sociale" 
                v-on:focus="form.clearErrors('ragione_sociale')"
                placeholder="Ragione sociale del fornitore" 
              />
              
              <InputError :message="form.errors.ragione_sociale" />
    
            </div>

          </div> 

          <div class="grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
            <!-- Codice fiscale -->
            <div class="sm:col-span-3">
              <Label for="codice_fiscale">Codice fiscale</Label>
              <Input 
                id="codice_fiscale" 
                class="mt-1 block w-full"
                  v-model="form.codice_fiscale" 
                  v-on:focus="form.clearErrors('codice_fiscale')"
                  placeholder="Codice fiscale" 
              />
              
              <InputError :message="form.errors.codice_fiscale" />
    
            </div>
            <!-- Partita IVA -->
            <div class="sm:col-span-3">
              <Label for="partita_iva">Partita IVA</Label>
              <Input 
                id="partita_iva" 
                class="mt-1 block w-full"
                  v-model="form.partita_iva" 
                  v-on:focus="form.clearErrors('partita_iva')"
                  placeholder="Partita IVA" 
              />
              
              <InputError :message="form.errors.partita_iva" />
            </div>
          </div>

          <div class="grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
            <div class="sm:col-span-6">
              <Label for="note">Note aggiuntive</Label>
              <Textarea 
                id="note" 
                class="w-full" 
                placeholder="Inserisci una nota qui" 
                v-model="form.note" 
                @focus="form.clearErrors('note')" 
              />
              <InputError :message="form.errors.note" />
            </div>
 
          </div>

          <div class="pt-5">
            <h3 class="text-lg font-medium leading-6 text-gray-900">Indirizzo e contatti</h3>
            <p class="mt-1 text-sm text-gray-500">Di seguito è possibile specificare l'indirizzo e le informazioni di contatto del fornitore</p>
          </div>

          <Separator class="my-4" />

          <!-- Nazione -->
          <div class="mt-2 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
            <div class="sm:col-span-2">
              <Label for="nazione">Nazione</Label>
              <Input 
                id="nazione" 
                class="mt-1 block w-full"
                v-model="form.nazione" 
                v-on:focus="form.clearErrors('nazione')"
                placeholder="Nazione" 
              />
              
              <InputError :message="form.errors.nazione" />
    
            </div>
          </div> 

          <!-- Indirizzo -->
          <div class="grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
            <div class="sm:col-span-4">
              <Label for="indirizzo">Indirizzo</Label>
              <Input 
                id="indirizzo" 
                class="mt-1 block w-full"
                v-model="form.indirizzo" 
                v-on:focus="form.clearErrors('indirizzo')"
                placeholder="Indirizzo" 
              />
              
              <InputError :message="form.errors.indirizzo" />
    
            </div>
          </div> 

          <div class="grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
            <!-- Comune -->
            <div class="sm:col-span-2">
              <Label for="comune">Comune</Label>
              <Input 
                id="comune" 
                class="mt-1 block w-full"
                  v-model="form.comune" 
                  v-on:focus="form.clearErrors('comune')"
                  placeholder="Comune" 
              />
              
              <InputError :message="form.errors.comune" />
    
            </div>
            <!-- Provincia -->
           <div class="sm:col-span-2">
              <Label for="provincia">Provincia</Label>
              <Input 
                id="provincia" 
                class="mt-1 block w-full"
                  v-model="form.provincia" 
                  v-on:focus="form.clearErrors('provincia')"
                  placeholder="Provincia" 
              />
              
              <InputError :message="form.errors.provincia" />
            </div>
            <!-- CAP -->
            <div class="sm:col-span-2">
              <Label for="codice_postale">Codice postale</Label>
              <Input 
                id="codice_postale" 
                class="mt-1 block w-full"
                  v-model="form.cap" 
                  v-on:focus="form.clearErrors('cap')"
                  placeholder="Codice postale" 
              />
              
              <InputError :message="form.errors.cap" />
            </div>
          </div>

          <div class="grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
            <!-- Telefono -->
            <div class="sm:col-span-2">
              <Label for="telefono">Telefono</Label>
              <Input 
                id="telefono" 
                class="mt-1 block w-full"
                  v-model="form.telefono" 
                  v-on:focus="form.clearErrors('telefono')"
                  placeholder="Telefono" 
              />
              
              <InputError :message="form.errors.telefono" />
    
            </div>
            <!-- Cellulare -->
           <div class="sm:col-span-2">
              <Label for="cellulare">Cellulare</Label>
              <Input 
                id="cellulare" 
                class="mt-1 block w-full"
                  v-model="form.cellulare" 
                  v-on:focus="form.clearErrors('cellulare')"
                  placeholder="Cellulare" 
              />
              
              <InputError :message="form.errors.cellulare" />
            </div>
            <!-- CAP -->
            <div class="sm:col-span-2">
              <Label for="fax">Fax</Label>
              <Input 
                id="fax" 
                class="mt-1 block w-full"
                  v-model="form.fax" 
                  v-on:focus="form.clearErrors('fax')"
                  placeholder="Fax" 
              />
              
              <InputError :message="form.errors.fax" />
            </div>
          </div>

          <div class="grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
            <!-- Email -->
            <div class="sm:col-span-2">
              <Label for="email">Indirizzo email</Label>
              <Input 
                id="email" 
                class="mt-1 block w-full"
                  v-model="form.email" 
                  v-on:focus="form.clearErrors('email')"
                  placeholder="Indizio email" 
              />
              
              <InputError :message="form.errors.email" />
    
            </div>
            <!-- Pec -->
           <div class="sm:col-span-2">
              <Label for="pec">Indirizzo PEC</Label>
              <Input 
                id="pec" 
                class="mt-1 block w-full"
                  v-model="form.pec" 
                  v-on:focus="form.clearErrors('pec')"
                  placeholder="Indirizzo PEC" 
              />
              
              <InputError :message="form.errors.pec" />
            </div>
            <!-- CAP -->
            <div class="sm:col-span-2">
              <Label for="sito_web">Sito internet</Label>
              <Input 
                id="sito_web" 
                class="mt-1 block w-full"
                  v-model="form.sito_web" 
                  v-on:focus="form.clearErrors('sito_web')"
                  placeholder="Sito internet" 
              />
              
              <InputError :message="form.errors.fax" />
            </div>
          </div>

          <div class="pt-5">
            <h3 class="text-lg font-medium leading-6 text-gray-900">Dati societari</h3>
            <p class="mt-1 text-sm text-gray-500">Di seguito è possibile specificare i dati societari del fornitore</p>
          </div>

          <Separator class="my-4" />

          <div class="mt-2 grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
            <!-- Iscrizione CCIAA -->
            <div class="sm:col-span-3">
              <Label for="iscrizione_cciaa">Iscrizione CCIAA</Label>
              <Input 
                id="iscrizione_cciaa" 
                class="mt-1 block w-full"
                v-model="form.iscrizione_cciaa" 
                v-on:focus="form.clearErrors('iscrizione_cciaa')"
                placeholder="Iscrizione CCIAA" 
              />
              
              <InputError :message="form.errors.iscrizione_cciaa" />
    
            </div>
            <!-- Data iscrizione CCIAA -->
            <div class="sm:col-span-3">
              <Label for="data_iscrizione_cciaa">Data iscrizione CCIAA</Label>
              <VueDatePicker
                v-model="form.data_iscrizione_cciaa"
                class="w-full py-1"
                format="dd/MM/yyyy"
                position="left" 
                locale="it"
                :enable-time-picker="false"
                auto-apply
                placeholder="Data iscrizione CCIAA"
              />
              <InputError :message="form.errors.data_iscrizione_cciaa" />
            </div>
          </div>

          <!-- Capitale sociale -->
          <div class="grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
            <div class="sm:col-span-3">
              <Label for="capitale_sociale">Capitale sociale</Label>

              <MoneyInput
                id="capitale_sociale"
                v-model="form.capitale_sociale"
                :money-options="moneyOptions"
                :lazy="true" 
                placeholder="0,00"
                @focus="form.clearErrors('capitale_sociale')"
              />

              <InputError :message="form.errors.capitale_sociale" />
              <p class="text-xs text-gray-500 mt-1">
                Inserisci l'importo nel formato italiano (es. 1.234,56)
                <strong>Per saldo negativo, usa il segno -</strong>
              </p>
    
            </div>
          </div> 

          <!-- Codice ATECO -->
          <div class="grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
            <div class="sm:col-span-3">
              <Label for="codice_ateco">Codice ATECO</Label>
              <Input 
                id="codice_ateco" 
                class="mt-1 block w-full"
                v-model="form.codice_ateco" 
                v-on:focus="form.clearErrors('codice_ateco')"
                placeholder="Codice ATECO" 
              />
              
              <InputError :message="form.errors.codice_ateco" />
    
            </div>
            <div class="sm:col-span-3">
              <Label for="codice_ateco">Certificazione ISO</Label>
              <div class="mt-1 flex items-center space-x-2 h-9 px-3 py-1 border-input rounded-md border bg-transparent shadow-xs">
                
                <!-- Certificazione ISO -->
                <Checkbox 
                  class="size-4" 
                  v-model="form.certificazione_iso"
                  id="is_featured"
                  @update:checked="(val: boolean ) => form.certificazione_iso = val"
                />

                <Label
                  for="is_featured"
                  class="text-sm text-slate-600 "
                >
                  Certificazione del sistema di qualità conforme alle norme Europee
                </Label>
                  
              </div>

              <InputError :message="form.errors.certificazione_iso" class="sm:col-span-6" />
            </div>
          </div> 

          <!-- Categoria fornitore -->
          <div class="grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
            <div class="sm:col-span-3">
              <Label for="tipologia_ordine">Categoria fornitore</Label>
               <v-select
                class="w-full"
                :options="categorie"
                v-model="form.categoria_id"
                :reduce="(d: Categoria) => d.id"
                label="name"
                placeholder="Seleziona categoria"
              />
              
              <InputError :message="form.errors.categoria_id" />
    
            </div>

            <div class="sm:col-span-3">
              <Label for="numero_iscrizione_ordine">Numero iscrizione ordine</Label>
              <Input 
                id="numero_iscrizione_ordine" 
                class="mt-1 block w-full"
                v-model="form.numero_iscrizione_ordine" 
                v-on:focus="form.clearErrors('numero_iscrizione_ordine')"
                placeholder="Numero iscrizione ordine" 
              />
              
              <InputError :message="form.errors.numero_iscrizione_ordine" />
    
            </div>
          </div> 

        </div>

      </form>

      </div>

  </AppLayout> 
  
 </template>

 <style src="vue-select/dist/vue-select.css"></style>