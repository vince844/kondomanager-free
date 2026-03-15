<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, Head, useForm } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import StrutturaLayout from '@/layouts/gestionale/StrutturaLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { usePermission } from "@/composables/permissions";
import CondominioDropdown from '@/components/CondominioDropdown.vue';
import { Button } from '@/components/ui/button';
import { Save, LoaderCircle, Banknote, Building2, Wallet, Info } from 'lucide-vue-next';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Textarea } from '@/components/ui/textarea';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import MoneyInput from '@/components/MoneyInput.vue';
import vSelect from "vue-select";
import type { Building } from '@/types/buildings';
import type { BreadcrumbItem } from '@/types';
import type { CassaOption, TipoCassa } from '@/types/gestionale/casse';

interface ContoOption { label: string; value: string; }

const props = defineProps<{
  condominio: Building;
  condomini: Building[];
  cassa: any; 
}>();

const { generatePath, generateRoute } = usePermission();

// --- MONEY OPTIONS ---
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
  { title: 'Modifica risorsa', href: '#' },
]);

const pageGuides = computed(() => [
  {
    title: 'Tipo di risorsa',
    description: 'Il tipo non può essere modificato se la risorsa è già stata utilizzata in contabilità. In questo caso il campo risulterà disabilitato.',
    icon: Banknote,
    colorVariant: 'blue' as const
  },
  {
    title: 'Saldo di apertura',
    description: 'Puoi correggere il saldo di apertura se necessario. Questa modifica non influenza i movimenti già registrati, solo il punto di partenza.',
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

const cassaData = props.cassa.data || props.cassa; 
const bancaData = cassaData.conto_corrente || {};

const form = useForm({
  _method: 'PUT',
  nome: cassaData.nome,
  descrizione: cassaData.descrizione || '',
  tipo: cassaData.tipo as TipoCassa,
  saldo_iniziale: cassaData.saldo_iniziale, 
  note: cassaData.note || '',
  intestatario: bancaData.intestatario || props.condominio.nome,
  tipo_conto: bancaData.tipo || 'ordinario', 
  istituto: bancaData.istituto || '',
  iban: bancaData.iban || '',
  bic: bancaData.swift || '', 
  predefinito: Boolean(bancaData.predefinito), 
  indirizzo: bancaData.indirizzo || '',
  comune: bancaData.comune || '',
  cap: bancaData.cap || '',
  provincia: bancaData.provincia || '',
  nazione: bancaData.nazione || 'Italia',
});

const submit = () => {
    const routeName = 'gestionale.casse.update';
    const params = { condominio: props.condominio.id, cassa: cassaData.id };
    
    form.post(route(...generateRoute(routeName, params)), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Modifica risorsa" />

    <GestionaleLayout :breadcrumbs="breadcrumbs">
      <template #breadcrumb-condominio>
        <CondominioDropdown :condominio="props.condominio" :condomini="props.condomini" />
      </template>

      <div class="px-6 py-8 space-y-4">

        <PageHeaderGuide
          :page-title="`Modifica: ${form.nome}`"
          :page-subtitle="`Aggiorna i dati della risorsa finanziaria per il condominio: ${props.condominio.nome}`"
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
                <CardDescription>Modifica il nome, il tipo e il saldo di apertura della risorsa.</CardDescription>
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
                      :reduce="(option: CassaOption) => option.value"
                      :clearable="false"
                      :disabled="cassaData.has_movements"
                    />
                    <p v-if="cassaData.has_movements" class="text-xs text-amber-600 mt-1">
                      Impossibile cambiare il tipo: risorsa già utilizzata in contabilità.
                    </p>
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
                          <h4 class="text-sm font-bold mb-2">Modifica saldo</h4>
                          <p class="text-xs text-slate-500 leading-relaxed">
                            Puoi correggere il saldo di apertura se necessario. Questa modifica non influenza i movimenti già registrati, solo il punto di partenza.
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
                  </div>

                  <div class="sm:col-span-3">
                    <Label for="descrizione" class="mb-1.5 block font-bold text-xs uppercase tracking-widest text-slate-500">Descrizione</Label>
                    <Input 
                      id="descrizione" 
                      class="mt-1 block w-full bg-white dark:bg-slate-950"
                      v-model="form.descrizione" 
                    />
                  </div>
                </div>

                <div class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-6">
                  <div class="sm:col-span-6">
                    <Label for="note" class="mb-1.5 block font-bold text-xs uppercase tracking-widest text-slate-500">Note interne</Label>
                    <Textarea 
                      id="note" 
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
                  </div>
                  <div class="sm:col-span-4">
                    <Label for="istituto" class="mb-1.5 block font-bold text-xs uppercase tracking-widest text-slate-500">Nome banca / filiale</Label>
                    <Input id="istituto" v-model="form.istituto" class="mt-1 bg-white dark:bg-slate-950" />
                    <InputError :message="form.errors.istituto" />
                  </div>
                  <div class="sm:col-span-2">
                    <Label for="bic" class="mb-1.5 block font-bold text-xs uppercase tracking-widest text-slate-500">BIC / SWIFT</Label>
                    <Input id="bic" v-model="form.bic" class="mt-1 uppercase bg-white dark:bg-slate-950" />
                  </div>
                  <div class="sm:col-span-6">
                    <Label for="iban" class="mb-1.5 block font-bold text-xs uppercase tracking-widest text-slate-500">IBAN</Label>
                    <Input id="iban" v-model="form.iban" class="mt-1 text-lg uppercase tracking-wide bg-white dark:bg-slate-950" maxlength="27" />
                    <InputError :message="form.errors.iban" />
                  </div>
                </div>

                <div class="pt-4 border-t border-dashed">
                  <h4 class="text-xs font-bold uppercase tracking-widest text-slate-500 mb-4">Indirizzo filiale</h4>
                  <div class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-6">
                    <div class="sm:col-span-6">
                      <Label for="indirizzo" class="mb-1.5 block font-bold text-xs uppercase tracking-widest text-slate-500">Indirizzo e civico</Label>
                      <Input id="indirizzo" v-model="form.indirizzo" class="mt-1 bg-white dark:bg-slate-950" />
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
                <Save v-else class="h-4 w-4" />
                Aggiorna risorsa
              </Button>
            </div>

          </form>
        </StrutturaLayout>
      </div>
    </GestionaleLayout>
</template>

<style src="vue-select/dist/vue-select.css"></style>