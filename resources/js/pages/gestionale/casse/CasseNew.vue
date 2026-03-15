<script setup lang="ts">

import { computed, onMounted, ref } from 'vue';
import { Link, Head, useForm } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import StrutturaLayout from '@/layouts/gestionale/StrutturaLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { usePermission } from "@/composables/permissions";
import CondominioDropdown from '@/components/CondominioDropdown.vue';
import { Button } from '@/components/ui/button';
import { Plus, LoaderCircle, Banknote, Building2, Wallet } from 'lucide-vue-next'; 
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Textarea } from '@/components/ui/textarea';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card'; 
import { Info } from 'lucide-vue-next';
import MoneyInput from '@/components/MoneyInput.vue'; 
import vSelect from "vue-select";
import type { Building } from '@/types/buildings';
import type { BreadcrumbItem } from '@/types';
import type { CassaOption, TipoCassa } from '@/types/gestionale/casse';

interface ContoOption {
    label: string;
    value: string;
}

const props = defineProps<{
  condominio: Building;
  condomini: Building[];
}>()

const { generatePath, generateRoute } = usePermission();

// --- CONFIGURAZIONE MONEY INPUT ---
const moneyOptions = ref({
  prefix: '',              
  suffix: '',              
  thousands: '.',          
  decimal: ',',          
  precision: 2, 
  disableNegative: false,         
  allowBlank: false,
  masked: true 
})

// --- BREADCRUMBS ---
const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, component: "condominio-dropdown" } as any,
  { title: 'Risorse e Fondi', href: generatePath('gestionale/:condominio/casse', { condominio: props.condominio.id }) },
  { title: 'Crea risorsa', href: '#' },
]);

const pageGuides = computed(() => [
  {
    title: 'Tipo di risorsa',
    description: 'Scegli tra cassa contanti, conto corrente bancario, fondo di riserva o cassa virtuale. Per i conti bancari potrai inserire IBAN e coordinate.',
    icon: Banknote,
    colorVariant: 'blue' as const
  },
  {
    title: 'Saldo di apertura',
    description: 'Inserisci l\'importo presente sul conto al momento dell\'apertura del gestionale. Usa un valore negativo se il conto è in rosso (scoperto).',
    icon: Wallet,
    colorVariant: 'amber' as const
  },
  {
    title: 'Conto principale',
    description: 'Se il condominio ha più conti bancari, puoi contrassegnarne uno come principale. Verrà preselezionato automaticamente nelle registrazioni.',
    icon: Building2,
    colorVariant: 'emerald' as const
  }
]);

// --- OPZIONI DROPDOWN ---
const tipiCassa: CassaOption[] = [
    { label: 'Cassa contanti', value: 'contanti' },
    { label: 'Conto corrente bancario/postale', value: 'banca' },
    { label: 'Fondo di riserva', value: 'fondo' },
    { label: 'Cassa virtuale', value: 'virtuale' },
];

const tipiContoCorrente: ContoOption[] = [
    { label: 'Ordinario / Condominiale', value: 'ordinario' },
    { label: 'Dedicato (Lavori Straordinari)', value: 'dedicato' },
    { label: 'Postale', value: 'postale' },
    { label: 'Contabilità speciale', value: 'contabilita_speciale' },
    { label: 'Estero', value: 'estero' },
    { label: 'Altro', value: 'altro' },
];

const form = useForm({
  nome: '',
  descrizione: '',
  tipo: 'contanti' as TipoCassa,
  saldo_iniziale: '',
  note: '',
  intestatario: '',
  tipo_conto: 'ordinario', 
  istituto: '',
  iban: '',
  bic: '',
  predefinito: false,
  indirizzo: '',
  comune: '',
  cap: '',
  provincia: '',
  nazione: 'Italia',
});

onMounted(() => {
    form.intestatario = props.condominio.nome;
});

const submit = () => {
    form.post(route(...generateRoute('gestionale.casse.store', { condominio: props.condominio.id })), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            form.intestatario = props.condominio.nome; 
        }
    });
};
</script>

<template>
    <Head title="Crea nuova risorsa" />

    <GestionaleLayout :breadcrumbs="breadcrumbs">
      <template #breadcrumb-condominio>
        <CondominioDropdown :condominio="props.condominio" :condomini="props.condomini" />
      </template>

      <div class="px-6 py-8 space-y-4">

        <PageHeaderGuide
          page-title="Nuova risorsa finanziaria"
          :page-subtitle="`Aggiungi un conto, una cassa o un fondo per il condominio: ${props.condominio.nome}`"
          :guides="pageGuides"
          :breadcrumbs="(breadcrumbs as any)"
          :back-url="generatePath('gestionale/:condominio/casse', { condominio: props.condominio.id })"
          back-text="Annulla e torna all'elenco"
        />

        <StrutturaLayout>
          <form @submit.prevent="submit" class="space-y-6">

            <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
              <CardHeader class="pb-3 border-b border-dashed mb-4">
                <CardTitle class="text-base font-semibold text-slate-800 dark:text-slate-200">Dati principali</CardTitle>
                <CardDescription>Identifica la risorsa e specifica il saldo al momento dell'apertura.</CardDescription>
              </CardHeader>
              <CardContent class="space-y-6">

                <div class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-6">
                  <div class="sm:col-span-3">
                    <Label for="nome" class="mb-1.5 block font-bold text-xs uppercase tracking-widest text-slate-500">Nome identificativo</Label>
                    <Input 
                      id="nome" 
                      class="mt-1 block w-full bg-white dark:bg-slate-950"
                      v-model="form.nome" 
                      @focus="form.clearErrors('nome')"
                      placeholder="Es. Cassa contanti, Banca Intesa..." 
                    />
                    <InputError :message="form.errors.nome" />
                  </div>

                  <div class="sm:col-span-3">
                    <Label for="tipo" class="mb-1.5 block font-bold text-xs uppercase tracking-widest text-slate-500">Tipologia risorsa</Label>
                    <v-select 
                      :options="tipiCassa" 
                      label="label" 
                      class="mt-1 block w-full bg-white dark:bg-slate-950"
                      v-model="form.tipo"
                      placeholder="Seleziona tipo"
                      @update:modelValue="form.clearErrors('tipo')" 
                      :reduce="(option: CassaOption) => option.value"
                      :clearable="false"
                    />
                    <InputError :message="form.errors.tipo" />
                  </div>
                </div>

                <div class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-6">
                  <div class="sm:col-span-3">
                    <div class="flex items-center gap-2 mb-1.5">
                      <Label for="saldo_iniziale" class="font-bold text-xs uppercase tracking-widest text-slate-500">Saldo iniziale (apertura)</Label>
                      <HoverCard>
                        <HoverCardTrigger as-child>
                          <button type="button" class="text-slate-400 hover:text-primary outline-none">
                            <Info class="w-4 h-4" />
                          </button>
                        </HoverCardTrigger>
                        <HoverCardContent class="w-72 p-4 bg-white dark:bg-slate-900 border-slate-200 shadow-xl">
                          <h4 class="text-sm font-bold mb-2">Saldo di apertura</h4>
                          <p class="text-xs text-slate-500 leading-relaxed">
                            Inserisci l'importo presente sul conto al momento dell'apertura del gestionale.
                          </p>
                          <p class="text-xs text-slate-400 mt-2 italic">
                            <strong>Positivo:</strong> soldi presenti · <strong>Negativo:</strong> conto in rosso
                          </p>
                        </HoverCardContent>
                      </HoverCard>
                    </div>
                    <MoneyInput
                      id="saldo_iniziale"
                      v-model="form.saldo_iniziale"
                      :money-options="moneyOptions"
                      :lazy="true" 
                      placeholder="0,00"
                      class="mt-1"
                      @focus="form.clearErrors('saldo_iniziale')"
                    />
                    <InputError :message="form.errors.saldo_iniziale" />
                    <p class="text-xs text-slate-400 mt-1 italic">Es: <strong>1.000,00</strong> (attivo) · <strong>-500,00</strong> (scoperto)</p>
                  </div>

                  <div class="sm:col-span-3">
                    <Label for="descrizione" class="mb-1.5 block font-bold text-xs uppercase tracking-widest text-slate-500">Descrizione</Label>
                    <Input 
                      id="descrizione" 
                      class="mt-1 block w-full bg-white dark:bg-slate-950"
                      v-model="form.descrizione" 
                      placeholder="Descrizione opzionale" 
                    />
                  </div>
                </div>

                <div class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-6">
                  <div class="sm:col-span-6">
                    <Label for="note" class="mb-1.5 block font-bold text-xs uppercase tracking-widest text-slate-500">Note interne</Label>
                    <Textarea 
                      id="note" 
                      placeholder="Eventuali annotazioni aggiuntive..." 
                      v-model="form.note" 
                      class="mt-1 bg-white dark:bg-slate-950 resize-none"
                      rows="3"
                    />
                  </div>
                </div>

              </CardContent>
            </Card>

            <Card v-if="form.tipo === 'banca'" class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
              <CardHeader class="pb-3 border-b border-dashed mb-4">
                <div class="flex items-center justify-between">
                  <div>
                    <CardTitle class="text-base font-semibold text-slate-800 dark:text-slate-200">Dettagli istituto di credito</CardTitle>
                    <CardDescription>Coordinate bancarie e dati della filiale.</CardDescription>
                  </div>
                  <div class="flex items-center space-x-2">
                    <Switch id="predefinito" v-model="form.predefinito" />
                    <Label for="predefinito" class="cursor-pointer font-bold text-xs uppercase tracking-widest text-slate-500">Conto principale</Label>
                  </div>
                </div>
              </CardHeader>
              
              <CardContent class="space-y-6">
                <div class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-6">
                  <div class="sm:col-span-4">
                    <Label for="intestatario" class="mb-1.5 block font-bold text-xs uppercase tracking-widest text-slate-500">Intestatario conto</Label>
                    <Input id="intestatario" v-model="form.intestatario" class="mt-1 bg-white dark:bg-slate-950" />
                    <InputError :message="form.errors.intestatario" />
                  </div>

                  <div class="sm:col-span-2">
                    <Label for="tipo_conto" class="mb-1.5 block font-bold text-xs uppercase tracking-widest text-slate-500">Tipologia conto</Label>
                    <v-select 
                      id="tipo_conto"
                      :options="tipiContoCorrente" 
                      label="label" 
                      class="mt-1 block w-full bg-white dark:bg-slate-950" 
                      v-model="form.tipo_conto" 
                      :reduce="(option: ContoOption) => option.value" 
                      :clearable="false"
                    />
                    <InputError :message="form.errors.tipo_conto" />
                  </div>

                  <div class="sm:col-span-4">
                    <Label for="istituto" class="mb-1.5 block font-bold text-xs uppercase tracking-widest text-slate-500">Nome banca / filiale</Label>
                    <Input id="istituto" v-model="form.istituto" placeholder="Es. Intesa Sanpaolo" class="mt-1 bg-white dark:bg-slate-950" />
                    <InputError :message="form.errors.istituto" />
                  </div>

                  <div class="sm:col-span-2">
                    <Label for="bic" class="mb-1.5 block font-bold text-xs uppercase tracking-widest text-slate-500">BIC / SWIFT</Label>
                    <Input id="bic" v-model="form.bic" class="mt-1 uppercase bg-white dark:bg-slate-950" />
                  </div>

                  <div class="sm:col-span-6">
                    <Label for="iban" class="mb-1.5 block font-bold text-xs uppercase tracking-widest text-slate-500">IBAN</Label>
                    <Input 
                      id="iban" 
                      v-model="form.iban" 
                      class="mt-1 text-lg uppercase tracking-wide bg-white dark:bg-slate-950" 
                      maxlength="27" 
                    />
                    <InputError :message="form.errors.iban" />
                  </div>
                </div>

                <div class="pt-4 border-t border-dashed">
                  <div class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-6">
                    <div class="sm:col-span-6">
                      <Label for="indirizzo" class="mb-1.5 block font-bold text-xs uppercase tracking-widest text-slate-500">Indirizzo filiale</Label>
                      <Input id="indirizzo" v-model="form.indirizzo" class="mt-1 bg-white dark:bg-slate-950" placeholder="Via Roma, 10" />
                    </div>
                    <div class="sm:col-span-2">
                      <Label for="cap" class="mb-1.5 block font-bold text-xs uppercase tracking-widest text-slate-500">CAP</Label>
                      <Input id="cap" v-model="form.cap" class="mt-1 bg-white dark:bg-slate-950" maxlength="5" />
                    </div>
                    <div class="sm:col-span-3">
                      <Label for="comune" class="mb-1.5 block font-bold text-xs uppercase tracking-widest text-slate-500">Comune</Label>
                      <Input id="comune" v-model="form.comune" class="mt-1 bg-white dark:bg-slate-950" />
                    </div>
                    <div class="sm:col-span-1">
                      <Label for="provincia" class="mb-1.5 block font-bold text-xs uppercase tracking-widest text-slate-500">Prov.</Label>
                      <Input id="provincia" v-model="form.provincia" class="mt-1 uppercase bg-white dark:bg-slate-950" maxlength="2" />
                    </div>
                  </div>
                </div>
              </CardContent>
            </Card>

            <div class="flex items-center justify-end gap-3 pt-2">
              <Link
                :href="generatePath('gestionale/:condominio/casse', { condominio: props.condominio.id })"
                class="inline-flex items-center justify-center h-9 px-6 rounded-md border border-input bg-background text-[10px] font-bold uppercase tracking-widest hover:bg-accent hover:text-accent-foreground transition-all shadow-sm"
              >
                Annulla
              </Link>

              <Button 
                type="submit"
                :disabled="form.processing" 
                class="h-9 px-8 text-[10px] font-bold uppercase tracking-widest shadow-md gap-2"
              >
                <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                <Plus v-else class="h-4 w-4" />
                Salva risorsa
              </Button>
            </div>

          </form>
        </StrutturaLayout>
      </div>
    </GestionaleLayout>
</template>

<style src="vue-select/dist/vue-select.css"></style>