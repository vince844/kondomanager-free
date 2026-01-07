<script setup lang="ts">
import { computed, ref } from 'vue'; // Aggiungi ref
import { Link, Head, useForm } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import StrutturaLayout from '@/layouts/gestionale/StrutturaLayout.vue';
import { usePermission } from "@/composables/permissions";
import CondominioDropdown from '@/components/CondominioDropdown.vue';
import { Button } from '@/components/ui/button';
import { List, Save, LoaderCircle, Info } from 'lucide-vue-next'; // Aggiungi Info
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Textarea } from '@/components/ui/textarea';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card'; // Aggiunto
import MoneyInput from '@/components/MoneyInput.vue'; // Aggiunto
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
  allowNegative: true,           
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
  
  // 🔥 SALDO INIZIALE (Riceve float dal Resource, MoneyInput lo gestisce)
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

      <StrutturaLayout>
        <form class="space-y-2" @submit.prevent="submit">

          <div class="flex flex-col lg:flex-row lg:justify-between gap-2 w-full">
            <h2 class="text-2xl font-bold tracking-tight hidden lg:block">Modifica {{ form.nome }}</h2>
            
            <div class="flex gap-2 w-full lg:w-auto">
                 <Link
                    as="button"
                    :href="generatePath('gestionale/:condominio/casse', { condominio: props.condominio.id })"
                    class="w-full lg:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-secondary text-secondary-foreground px-3 py-1.5 text-sm font-medium hover:bg-secondary/80 border shadow-sm"
                    >
                    <List class="w-4 h-4" />
                    <span>Annulla</span>
                </Link>

                <Button :disabled="form.processing" class="h-9 w-full lg:w-auto">
                    <Save class="w-4 h-4 mr-2" v-if="!form.processing" />
                    <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin mr-2" />
                    Aggiorna
                </Button>
            </div>
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
                        :reduce="(option: CassaOption) => option.value"
                        :clearable="false"
                        :disabled="cassaData.has_movements"  />
                    
                    <p v-if="cassaData.has_movements" class="text-xs text-amber-600 mt-1">
                        Impossibile cambiare il tipo: risorsa già utilizzata in contabilità.
                    </p>
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
                        <h4 class="text-sm font-semibold">Modifica Saldo</h4>
                        <p class="text-sm">
                          Puoi correggere il saldo di apertura se necessario.<br>
                          Nota: Questo NON influenza i movimenti già registrati, ma solo il punto di partenza.
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
              </div>

              <div class="sm:col-span-3">
                <Label for="descrizione">Descrizione</Label>
                <Input 
                  id="descrizione" 
                  class="mt-1 block w-full"
                  v-model="form.descrizione" 
                />
              </div>
            </div>

            <div class="grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6 pt-2">
              <div class="sm:col-span-6">
                <Label for="note">Note interne</Label>
                <Textarea 
                    id="note" 
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
                            </div>
                        </div>
                        <div class="flex items-center space-x-2">
                            <Switch id="predefinito" v-model="form.predefinito" />
                            <Label for="predefinito">Conto principale</Label>
                        </div>
                    </div>
                </CardHeader>
                
                <CardContent class="space-y-6">
                    <div class="grid grid-cols-1 gap-y-4 gap-x-4 sm:grid-cols-6">
                        <div class="sm:col-span-4">
                            <Label for="intestatario">Intestatario conto</Label>
                            <Input id="intestatario" v-model="form.intestatario" class="mt-1" />
                        </div>
                        <div class="sm:col-span-2">
                            <Label for="tipo_conto">Tipologia conto</Label>
                            <v-select id="tipo_conto" :options="tipiContoCorrente" label="label" class="mt-1 block w-full bg-white" v-model="form.tipo_conto" :reduce="(option: ContoOption) => option.value" :clearable="false" />
                        </div>
                        <div class="sm:col-span-4">
                            <Label for="istituto">Nome banca / filiale</Label>
                            <Input id="istituto" v-model="form.istituto" class="mt-1" />
                             <InputError :message="form.errors.istituto" />
                        </div>
                        <div class="sm:col-span-2">
                            <Label for="bic">BIC / SWIFT</Label>
                            <Input id="bic" v-model="form.bic" class="mt-1 font-mono uppercase" />
                        </div>
                        <div class="sm:col-span-6">
                            <Label for="iban">IBAN</Label>
                            <Input id="iban" v-model="form.iban" class="mt-1 font-mono text-lg uppercase tracking-wide" maxlength="27" />
                            <InputError :message="form.errors.iban" />
                        </div>
                    </div>
                     <div class="pt-4 border-t border-dashed">
                        <h4 class="text-sm font-medium mb-3 text-muted-foreground">Indirizzo filiale</h4>
                        <div class="grid grid-cols-1 gap-y-4 gap-x-4 sm:grid-cols-6">
                            <div class="sm:col-span-6">
                                <Label for="indirizzo">Indirizzo e civico</Label>
                                <Input id="indirizzo" v-model="form.indirizzo" class="mt-1" />
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