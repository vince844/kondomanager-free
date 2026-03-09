<script setup lang="ts">
import { computed, ref } from 'vue'; // Aggiungi ref
import { Link, Head, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
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
  disableNegative: false,       
  allowBlank: false,
  masked: true 
})

// --- BREADCRUMBS ---
const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: trans('gestionale.list_pages.casse.breadcrumbs.management'), href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, component: "condominio-dropdown" } as any,
  { title: trans('gestionale.list_pages.casse.breadcrumbs.list'), href: generatePath('gestionale/:condominio/casse', { condominio: props.condominio.id }) },
  { title: trans('gestionale.list_pages.casse.edit.breadcrumb'), href: '#' },
]);

const tipiCassa = computed<CassaOption[]>(() => [
    { label: trans('gestionale.list_pages.casse.table.types.cashbox'), value: 'contanti' },
    { label: trans('gestionale.list_pages.casse.table.types.bank_account'), value: 'banca' },
    { label: trans('gestionale.list_pages.casse.table.types.reserve_fund'), value: 'fondo' },
    { label: trans('gestionale.list_pages.casse.table.types.other'), value: 'virtuale' },
]);

const tipiContoCorrente = computed<ContoOption[]>(() => [
    { label: trans('gestionale.list_pages.casse.table.bank_account_types.ordinary'), value: 'ordinario' },
    { label: trans('gestionale.list_pages.casse.table.bank_account_types.dedicated'), value: 'dedicato' },
    { label: trans('gestionale.list_pages.casse.table.bank_account_types.postal'), value: 'postale' },
    { label: trans('gestionale.list_pages.casse.table.bank_account_types.special_accounting'), value: 'contabilita_speciale' },
    { label: trans('gestionale.list_pages.casse.table.bank_account_types.foreign'), value: 'estero' },
    { label: trans('gestionale.list_pages.casse.table.bank_account_types.other'), value: 'altro' },
]);

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
    <Head :title="trans('gestionale.form_common.actions.edit')" />

    <GestionaleLayout :breadcrumbs="breadcrumbs">
      <template #breadcrumb-condominio>
        <CondominioDropdown :condominio="props.condominio" :condomini="props.condomini" />
      </template>

      <StrutturaLayout>
        <form class="space-y-2" @submit.prevent="submit">

          <div class="flex flex-col lg:flex-row lg:justify-between gap-2 w-full">
            <h2 class="text-2xl font-bold tracking-tight hidden lg:block">{{ trans('gestionale.list_pages.casse.edit.page_title_named', { name: form.nome }) }}</h2>
            
            <div class="flex gap-2 w-full lg:w-auto">
                 <Link
                    as="button"
                    :href="generatePath('gestionale/:condominio/casse', { condominio: props.condominio.id })"
                    class="w-full lg:w-auto inline-flex items-center justify-center gap-2 rounded-md bg-secondary text-secondary-foreground px-3 py-1.5 text-sm font-medium hover:bg-secondary/80 border shadow-sm"
                    >
                    <List class="w-4 h-4" />
                    <span>{{ trans('gestionale.form_common.actions.cancel') }}</span>
                </Link>

                <Button :disabled="form.processing" class="h-9 w-full lg:w-auto">
                    <Save class="w-4 h-4 mr-2" v-if="!form.processing" />
                    <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin mr-2" />
                    {{ trans('gestionale.list_pages.casse.edit.actions.update_resource') }}
                </Button>
            </div>
          </div>

          <div class="bg-white dark:bg-muted rounded shadow-sm p-4 space-y-6 border mt-3">

            <div class="grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                <div class="sm:col-span-3">
                  <Label for="nome">{{ trans('gestionale.form_common.labels.name') }}</Label>
                  <Input 
                    id="nome" 
                    class="mt-1 block w-full"
                    v-model="form.nome" 
                    v-on:focus="form.clearErrors('nome')"
                  />
                  <InputError :message="form.errors.nome" />
                </div>

                <div class="sm:col-span-3">
                    <Label for="tipo">{{ trans('gestionale.form_common.labels.type') }}</Label>
                    <v-select 
                        :options="tipiCassa" 
                        label="label" 
                        class="mt-1 block w-full"
                        v-model="form.tipo"
                        :reduce="(option: CassaOption) => option.value"
                        :clearable="false"
                        :disabled="cassaData.has_movements"  />
                    
                    <p v-if="cassaData.has_movements" class="text-xs text-amber-600 mt-1">
                        {{ trans('gestionale.list_pages.casse.edit.messages.type_change_blocked') }}
                    </p>
                    <InputError :message="form.errors.tipo" />
                </div>
            </div> 

            <div class="grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
              
              <div class="sm:col-span-3">
                  <Label for="saldo_iniziale">{{ trans('gestionale.form_common.labels.initial_balance') }}</Label>

                  <HoverCard>
                    <HoverCardTrigger as-child>
                      <button type="button" class="cursor-pointer inline-block align-middle">
                        <Info class="ml-1 w-4 h-4 text-muted-foreground" />
                      </button>
                    </HoverCardTrigger>
                    <HoverCardContent class="w-80 z-50">
                      <div class="space-y-1">
                        <h4 class="text-sm font-semibold">{{ trans('gestionale.list_pages.casse.edit.balance.title') }}</h4>
                        <p class="text-sm">
                          {{ trans('gestionale.list_pages.casse.edit.balance.help_line_1') }}<br>
                          {{ trans('gestionale.list_pages.casse.edit.balance.help_line_2') }}
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
                <Label for="descrizione">{{ trans('gestionale.form_common.labels.description') }}</Label>
                <Input 
                  id="descrizione" 
                  class="mt-1 block w-full"
                  v-model="form.descrizione" 
                />
              </div>
            </div>

            <div class="grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6 pt-2">
              <div class="sm:col-span-6">
                <Label for="note">{{ trans('gestionale.form_common.labels.notes') }}</Label>
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
                                <CardTitle class="text-base font-semibold">{{ trans('gestionale.list_pages.casse.edit.bank_details.title') }}</CardTitle>
                            </div>
                        </div>
                        <div class="flex items-center space-x-2">
                            <Switch id="predefinito" v-model="form.predefinito" />
                            <Label for="predefinito">{{ trans('gestionale.list_pages.casse.edit.bank_details.main_account') }}</Label>
                        </div>
                    </div>
                </CardHeader>
                
                <CardContent class="space-y-6">
                    <div class="grid grid-cols-1 gap-y-4 gap-x-4 sm:grid-cols-6">
                        <div class="sm:col-span-4">
                            <Label for="intestatario">{{ trans('gestionale.form_common.labels.account_holder') }}</Label>
                            <Input id="intestatario" v-model="form.intestatario" class="mt-1" />
                        </div>
                        <div class="sm:col-span-2">
                            <Label for="tipo_conto">{{ trans('gestionale.list_pages.casse.edit.bank_details.account_type') }}</Label>
                            <v-select id="tipo_conto" :options="tipiContoCorrente" label="label" class="mt-1 block w-full bg-white" v-model="form.tipo_conto" :reduce="(option: ContoOption) => option.value" :clearable="false" />
                        </div>
                        <div class="sm:col-span-4">
                            <Label for="istituto">{{ trans('gestionale.form_common.labels.bank_branch') }}</Label>
                            <Input id="istituto" v-model="form.istituto" class="mt-1" />
                             <InputError :message="form.errors.istituto" />
                        </div>
                        <div class="sm:col-span-2">
                            <Label for="bic">{{ trans('gestionale.form_common.labels.bic_swift') }}</Label>
                            <Input id="bic" v-model="form.bic" class="mt-1 font-mono uppercase" />
                        </div>
                        <div class="sm:col-span-6">
                            <Label for="iban">{{ trans('gestionale.form_common.labels.iban') }}</Label>
                            <Input id="iban" v-model="form.iban" class="mt-1 font-mono text-lg uppercase tracking-wide" maxlength="27" />
                            <InputError :message="form.errors.iban" />
                        </div>
                    </div>
                     <div class="pt-4 border-t border-dashed">
                        <h4 class="text-sm font-medium mb-3 text-muted-foreground">{{ trans('gestionale.list_pages.casse.edit.bank_details.branch_address') }}</h4>
                        <div class="grid grid-cols-1 gap-y-4 gap-x-4 sm:grid-cols-6">
                            <div class="sm:col-span-6">
                                <Label for="indirizzo">{{ trans('gestionale.form_common.labels.address') }}</Label>
                                <Input id="indirizzo" v-model="form.indirizzo" class="mt-1" />
                            </div>
                            <div class="sm:col-span-2">
                                <Label for="cap">{{ trans('gestionale.form_common.labels.postal_code') }}</Label>
                                <Input id="cap" v-model="form.cap" class="mt-1" maxlength="5" />
                            </div>
                            <div class="sm:col-span-3">
                                <Label for="comune">{{ trans('gestionale.form_common.labels.city') }}</Label>
                                <Input id="comune" v-model="form.comune" class="mt-1" />
                            </div>
                            <div class="sm:col-span-1">
                                <Label for="provincia">{{ trans('gestionale.form_common.labels.province') }}</Label>
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
