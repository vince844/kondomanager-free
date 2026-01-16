<script setup lang="ts">

import { computed, onMounted, ref } from 'vue';
import { Link, Head, useForm } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import StrutturaLayout from '@/layouts/gestionale/StrutturaLayout.vue';
import { usePermission } from "@/composables/permissions";
import CondominioDropdown from '@/components/CondominioDropdown.vue';
import { Button } from '@/components/ui/button';
import { List, Plus, LoaderCircle, Info } from 'lucide-vue-next'; // Aggiunto Info
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Textarea } from '@/components/ui/textarea';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card'; // Aggiunto HoverCard
import MoneyInput from '@/components/MoneyInput.vue'; // Aggiunto MoneyInput
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
  allowNegative: true,           
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
  saldo_iniziale: '', // Aggiunto campo saldo
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

// Imposta l'intestatario predefinito al caricamento
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

      <StrutturaLayout>
        <form class="space-y-2" @submit.prevent="submit">

          <div class="flex flex-col lg:flex-row lg:justify-end gap-2 w-full">
            <Button :disabled="form.processing" class="h-8 w-full lg:w-auto">
              <Plus class="w-4 h-4 mr-2" v-if="!form.processing" />
              <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin mr-2" />
              Salva Risorsa
            </Button>

            <Link
              as="button"
              :href="generatePath('gestionale/:condominio/casse', { condominio: props.condominio.id })"
              class="w-full lg:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-secondary text-secondary-foreground px-3 py-1.5 text-sm font-medium hover:bg-secondary/80 border shadow-sm"
            >
              <List class="w-4 h-4" />
              <span>Elenco</span>
            </Link>
          </div>

          <div class="bg-white dark:bg-muted rounded shadow-sm p-4 space-y-6 border mt-3">

            <div class="grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                <div class="sm:col-span-3">
                  <Label for="nome">Nome identificativo</Label>
                  <Input 
                    id="nome" 
                    class="mt-1 block w-full"
                    v-model="form.nome" 
                    v-on:focus="form.clearErrors('nome')"
                    placeholder="Es. cassa contanti, banca intesa..." 
                  />
                  <InputError :message="form.errors.nome" />
                </div>

                <div class="sm:col-span-3">
                    <Label for="tipo">Tipologia risorsa</Label>
                    <v-select 
                        :options="tipiCassa" 
                        label="label" 
                        class="mt-1 block w-full"
                        v-model="form.tipo"
                        placeholder="Seleziona tipo"
                        @update:modelValue="form.clearErrors('tipo')" 
                        :reduce="(option: CassaOption) => option.value"
                        :clearable="false"
                    />
                    <InputError :message="form.errors.tipo" />
                </div>
            </div> 

            <div class="grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
              
              <div class="sm:col-span-3">
                  <Label for="saldo_iniziale">Saldo Iniziale (Apertura)</Label>

                  <HoverCard>
                    <HoverCardTrigger as-child>
                      <button type="button" class="cursor-pointer inline-block align-middle">
                        <Info class="ml-1 w-4 h-4 text-muted-foreground" />
                      </button>
                    </HoverCardTrigger>
                    <HoverCardContent class="w-80 z-50">
                      <div class="space-y-1">
                        <h4 class="text-sm font-semibold">Istruzioni Saldo</h4>
                        <p class="text-sm">
                          Inserisci l'importo presente sul conto/cassa al momento dell'apertura del gestionale.<br>
                          <strong>Positivo:</strong> Soldi presenti.<br>
                          <strong>Negativo:</strong> Conto in rosso (scoperto).
                        </p>
                      </div>
                    </HoverCardContent>
                  </HoverCard>

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
                  <p class="text-xs text-gray-500 mt-1">
                    Es: <strong>1.000,00</strong> (Attivo) | <strong>-500,00</strong> (Passivo/Rosso)
                  </p>
              </div>

              <div class="sm:col-span-3">
                <Label for="descrizione">Descrizione</Label>
                <Input 
                  id="descrizione" 
                  class="mt-1 block w-full"
                  v-model="form.descrizione" 
                  placeholder="Descrizione opzionale" 
                />
              </div>
            </div>

            <div class="grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6 pt-2">
              <div class="sm:col-span-6">
                <Label for="note">Note interne</Label>
                <Textarea 
                    id="note" 
                    placeholder="Eventuali annotazioni aggiuntive..." 
                    v-model="form.note" 
                    class="mt-1"
                />
              </div>
            </div>

            <Card v-if="form.tipo === 'banca'" class="mt-4 border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
                <CardHeader class="pb-3 border-b border-dashed mb-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <div>
                                <CardTitle class="text-base font-semibold">Dettagli istituto di credito</CardTitle>
                                <CardDescription>Di seguito puoi specificare le coordinate bancarie e dati della filiale.</CardDescription>
                            </div>
                        </div>
                        
                        <div class="flex items-center space-x-2">
                            <Switch 
                                id="predefinito" 
                                v-model="form.predefinito" 
                            />
                            
                            <Label for="predefinito" class="cursor-pointer">
                                Conto principale
                            </Label>
                        </div>
                    </div>
                </CardHeader>
                
                <CardContent class="space-y-6">
                    
                    <div class="grid grid-cols-1 gap-y-4 gap-x-4 sm:grid-cols-6">
                        
                        <div class="sm:col-span-4">
                            <Label for="intestatario">Intestatario conto</Label>
                            <Input id="intestatario" v-model="form.intestatario" class="mt-1" />
                            <InputError :message="form.errors.intestatario" />
                        </div>

                        <div class="sm:col-span-2">
                            <Label for="tipo_conto">Tipologia conto</Label>
                            <v-select 
                                id="tipo_conto"
                                :options="tipiContoCorrente" 
                                label="label" 
                                class="mt-1 block w-full bg-white" 
                                v-model="form.tipo_conto" 
                                :reduce="(option: ContoOption) => option.value" 
                                :clearable="false"
                            />
                            <InputError :message="form.errors.tipo_conto" />
                        </div>

                        <div class="sm:col-span-4">
                            <Label for="istituto">Nome banca / filiale</Label>
                            <Input id="istituto" v-model="form.istituto" placeholder="Es. Intesa Sanpaolo" class="mt-1" />
                            <InputError :message="form.errors.istituto" />
                        </div>

                        <div class="sm:col-span-2">
                            <Label for="bic">BIC / SWIFT</Label>
                            <Input id="bic" v-model="form.bic" class="mt-1 font-mono uppercase" />
                        </div>

                        <div class="sm:col-span-6">
                            <Label for="iban">IBAN</Label>
                            <Input 
                                id="iban" 
                                v-model="form.iban" 
                                class="mt-1 font-mono text-lg uppercase tracking-wide" 
                                maxlength="27" 
                            />
                            <InputError :message="form.errors.iban" />
                        </div>
                    </div>

                    <div class="pt-4 border-t border-dashed">
                        <h4 class="text-sm font-medium mb-3 text-muted-foreground">Indirizzo filiale</h4>
                        <div class="grid grid-cols-1 gap-y-4 gap-x-4 sm:grid-cols-6">
                            <div class="sm:col-span-6">
                                <Label for="indirizzo">Indirizzo e civico</Label>
                                <Input id="indirizzo" v-model="form.indirizzo" class="mt-1" placeholder="Via roma, 10" />
                            </div>

                            <div class="sm:col-span-2">
                                <Label for="cap">CAP</Label>
                                <Input id="cap" v-model="form.cap" class="mt-1" maxlength="5" />
                            </div>

                            <div class="sm:col-span-3">
                                <Label for="comune">Comune</Label>
                                <Input id="comune" v-model="form.comune" class="mt-1" />
                            </div>

                            <div class="sm:col-span-1">
                                <Label for="provincia">Prov.</Label>
                                <Input id="provincia" v-model="form.provincia" class="mt-1 uppercase" maxlength="2" />
                            </div>
                        </div>
                    </div>

                </CardContent>
            </Card>

          </div>
        </form>
      </StrutturaLayout>
    </GestionaleLayout>
</template>

<style src="vue-select/dist/vue-select.css"></style>