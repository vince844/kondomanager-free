<script setup lang="ts">

import { computed, watch, ref } from 'vue';
import { Link, Head, useForm } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import { usePermission } from "@/composables/permissions";
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import TabelleGuide from '@/components/guides/TabelleGuide.vue';
import { Button } from '@/components/ui/button';
import { Plus, LoaderCircle, Table, Layers, Settings, Info } from 'lucide-vue-next';
import { Checkbox } from '@/components/ui/checkbox';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Textarea } from '@/components/ui/textarea';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import vSelect from "vue-select";
import type { Building } from '@/types/buildings';
import type { BreadcrumbItem } from '@/types';
import type { Palazzina } from '@/types/gestionale/palazzine';
import type { Scala } from '@/types/gestionale/scale';

const props = defineProps<{
  condominio: Building;
  condomini: Building[];
  palazzine: Palazzina[];
  scale: Scala[];
}>()

type TabellaType = {
  label: string;
  id: string;
};

const tipologieTabelle = [
  { label: 'Standard', id: 'standard' },
  { label: "Ascensore", id: 'ascensore' },
  { label: "Riscaldamento", id: 'riscaldamento' },
  { label: "Acqua", id: 'acqua' },
  { label: "Lastrico", id: 'lastrico' },
  { label: "Speciale", id: 'speciale' },
  { label: "Altro", id: 'altro' }
];

const unitaMisura = [
  { label: 'Millesimi', id: 'millesimi' },
  { label: "Persone", id: 'persone' },
  { label: "Quote", id: 'quote' },
  { label: "Kilowatt", id: 'kwatt' },
  { label: "Metri cubi", id: 'mtcubi' },
];

const { generatePath, generateRoute } = usePermission();

const showGuide = ref(false);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, href: '#' },
  { title: 'Tabelle', href: generatePath('gestionale/:condominio/tabelle', { condominio: props.condominio.id }) },
  { title: 'Nuova Tabella', href: '#' },
]);

const pageGuides = computed(() => [
  {
    title: 'Configurazione Tabella',
    description: "Definisci il nome, la tipologia e l'unità di misura (es. millesimi, quote).",
    icon: Table,
    colorVariant: 'blue' as const
  },
  {
    title: 'Assegnazione Strutturale',
    description: "Associa la tabella a specifiche palazzine o scale del condominio.",
    icon: Layers,
    colorVariant: 'emerald' as const
  },
  {
    title: 'Impostazioni Avanzate',
    description: "Configura note e l'associazione automatica a tutti gli immobili.",
    icon: Settings,
    colorVariant: 'amber' as const
  }
]);

const form = useForm({
  nome: '',
  tipologia: 'standard',
  quota: 'millesimi',
  numero_decimali: 2,
  descrizione: '',
  note: '',
  all_flats: false as boolean,
  palazzina_id: '',
  scala_id: '',
});

const submit = () => {
  form.post(route(...generateRoute('gestionale.tabelle.store', { condominio: props.condominio.id })), {
    preserveScroll: true,
  });
};

watch(() => form.tipologia, (newVal) => {
  if (newVal === 'ascensore' && form.quota === 'millesimi') {
    form.quota = 'quote';
  }
});
</script>

<template>
  <Head title="Crea nuova tabella" />

  <GestionaleLayout>
    <div class="px-6 py-8 space-y-6">

      <PageHeaderGuide
        page-title="Nuova tabella millesimale"
        :page-subtitle="`Creazione tabella di riparto per: ${props.condominio.nome}`"
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
        :back-url="generatePath('gestionale/:condominio/tabelle', { condominio: props.condominio.id })"
        back-text="Torna all'elenco"
        has-text-guide
        text-guide-title="Guida"
        @open-text-guide="showGuide = true"
      >
      </PageHeaderGuide>

      <form id="tabellaForm" @submit.prevent="submit" class="space-y-6">
        
        <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
          <CardHeader class="pb-3 border-b border-dashed mb-4">
            <CardTitle class="text-base font-semibold">Configurazione principale</CardTitle>
            <CardDescription>Definisci i parametri fondamentali della tabella.</CardDescription>
          </CardHeader>
          <CardContent class="space-y-6">
            <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-6">
              <div class="sm:col-span-3">
                <div class="flex items-center h-5">
                  <Label for="nome">Nome tabella</Label>
                </div>
                <Input
                  id="nome"
                  class="mt-1 bg-white dark:bg-slate-950 w-full"
                  v-model="form.nome"
                  @focus="form.clearErrors('nome')"
                  placeholder="es. Tabella A Millesimi di Proprietà"
                />
                <InputError :message="form.errors.nome" />
              </div>

              <div class="sm:col-span-3">
                <div class="flex items-center gap-1 h-5">
                  <Label for="tipologia">Tipologia</Label>
                  <HoverCard>
                    <HoverCardTrigger as-child>
                      <button type="button" class="cursor-pointer flex items-center">
                        <Info class="w-3.5 h-3.5 text-slate-400 hover:text-indigo-500 transition-colors" />
                      </button>
                    </HoverCardTrigger>
                    <HoverCardContent class="w-80 z-50">
                      <div class="space-y-3">
                        <h4 class="text-sm font-semibold flex items-center gap-2">
                          <Info class="w-4 h-4" /> Tipo tabella
                        </h4>
                        <div class="text-sm space-y-2 text-slate-500">
                          <ul class="list-disc pl-4 space-y-1 text-xs">
                            <li><strong>Standard:</strong> La tipica tabella generale, di proprietà o scale.</li>
                            <li><strong>Ascensore:</strong> Il 50/50 (Art. 1124) non è calcolato in automatico. Inserisci i valori finali. (Consigliato: Quote).</li>
                            <li><strong>Riscaldamento / Acqua:</strong> Per le spese termiche e idriche.</li>
                            <li><strong>Lastrico / Speciale:</strong> Per organizzare ripartizioni particolari (es. 1/3 e 2/3, oppure tetti, corselli box).</li>
                          </ul>
                        </div>
                      </div>
                    </HoverCardContent>
                  </HoverCard>
                </div>
                <v-select
                  class="mt-1 bg-white dark:bg-slate-950 text-sm w-full"
                  :options="tipologieTabelle"
                  label="label"
                  v-model="form.tipologia"
                  :reduce="(d: TabellaType) => d.id"
                  placeholder="Seleziona tipologia tabella"
                />
                <InputError :message="form.errors.tipologia" />
              </div>

              <div class="sm:col-span-3">
                <div class="flex items-center gap-1 h-5">
                  <Label for="quota">Unità di misura</Label>
                  <HoverCard>
                    <HoverCardTrigger as-child>
                      <button type="button" class="cursor-pointer flex items-center">
                        <Info class="w-3.5 h-3.5 text-slate-400 hover:text-indigo-500 transition-colors" />
                      </button>
                    </HoverCardTrigger>
                    <HoverCardContent class="w-80 z-50">
                      <div class="space-y-3">
                        <h4 class="text-sm font-semibold flex items-center gap-2">
                          <Info class="w-4 h-4" /> Quale scegliere?
                        </h4>
                        <div class="text-sm space-y-2 text-slate-500">
                          <ul class="list-disc pl-4 space-y-1 text-xs">
                            <li><strong>Millesimi:</strong> Il classico calcolo su base 1.000.</li>
                            <li><strong>Quote:</strong> Utile se ripartisci in parti proporzionali (es. 1 quota al PT, 2 quote al piano 1). Il totale può essere qualsiasi numero.</li>
                            <li><strong>Persone / Metri cubi:</strong> Ripartizione a consumo o teste.</li>
                          </ul>
                        </div>
                      </div>
                    </HoverCardContent>
                  </HoverCard>
                </div>
                <v-select
                  class="mt-1 bg-white dark:bg-slate-950 text-sm w-full"
                  :options="unitaMisura"
                  label="label"
                  v-model="form.quota"
                  :reduce="(d: TabellaType) => d.id"
                  placeholder="Seleziona unità di misura"
                />
                <InputError :message="form.errors.quota" />
              </div>

              <div class="sm:col-span-3">
                <div class="flex items-center h-5">
                  <Label for="numero_decimali">Numero decimali</Label>
                </div>
                <v-select
                  id="numero_decimali"
                  class="mt-1 bg-white dark:bg-slate-950 text-sm w-full"
                  :options="[0,1,2,3,4,5]"
                  v-model="form.numero_decimali"
                  placeholder="Seleziona numero di decimali"
                />
                <InputError :message="form.errors.numero_decimali" />
              </div>

              <div class="sm:col-span-6">
                <div class="flex items-center h-5">
                  <Label for="descrizione">Descrizione</Label>
                </div>
                <Textarea
                  id="descrizione"
                  class="mt-1 bg-white dark:bg-slate-950 block w-full"
                  v-model="form.descrizione"
                  placeholder="Descrizione opzionale della tabella"
                />
                <InputError :message="form.errors.descrizione" />
              </div>
            </div>
          </CardContent>
        </Card>

        <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
          <CardHeader class="pb-3 border-b border-dashed mb-4">
            <CardTitle class="text-base font-semibold">Assegnazione strutturale</CardTitle>
            <CardDescription>Opzionalmente, puoi limitare l'uso di questa tabella a palazzine o scale specifiche.</CardDescription>
          </CardHeader>
          <CardContent class="space-y-6">
            <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-6">
              <div class="sm:col-span-3">
                <Label for="palazzina">Palazzina</Label>
                <v-select 
                  :options="palazzine" 
                  label="name" 
                  class="mt-1 bg-white dark:bg-slate-950 text-sm w-full"
                  v-model="form.palazzina_id"
                  placeholder="Associa ad una palazzina..."
                  @update:modelValue="form.clearErrors('palazzina_id')" 
                  :reduce="(palazzina: Palazzina) => palazzina.id"
                />
                <InputError :message="form.errors.palazzina_id" />
              </div>

              <div class="sm:col-span-3">
                <Label for="scala">Scala</Label>
                <v-select 
                  :options="scale" 
                  label="name" 
                  class="mt-1 bg-white dark:bg-slate-950 text-sm w-full"
                  v-model="form.scala_id"
                  placeholder="Associa ad una scala..."
                  @update:modelValue="form.clearErrors('scala_id')" 
                  :reduce="(scala: Scala) => scala.id"
                />
                <InputError :message="form.errors.scala_id" />
              </div>
            </div>
          </CardContent>
        </Card>

        <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
          <CardHeader class="pb-3 border-b border-dashed mb-4">
            <CardTitle class="text-base font-semibold">Impostazioni avanzate</CardTitle>
            <CardDescription>Altre configurazioni e note di gestione.</CardDescription>
          </CardHeader>
          <CardContent class="space-y-6">
            <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-6">
              <div class="sm:col-span-6">
                <Label for="note">Note</Label>
                <Textarea
                  id="note"
                  class="mt-1 bg-white dark:bg-slate-950 block w-full"
                  v-model="form.note"
                  placeholder="Note riservate agli amministratori..."
                />
                <InputError :message="form.errors.note" />
              </div>

              <div class="sm:col-span-6">
                <div class="flex items-center space-x-2">
                  <Checkbox
                    class="size-4"
                    v-model="form.all_flats"
                    id="all_flats"
                    @update:checked="(val:boolean) => form.all_flats = val"
                  />
                  <label
                    for="all_flats"
                    class="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70"
                  >
                    Associa tutti gli immobili esistenti
                  </label>

                  <HoverCard>
                    <HoverCardTrigger as-child>
                      <button type="button" class="cursor-pointer">
                        <Info class="w-4 h-4 text-muted-foreground" />
                      </button>
                    </HoverCardTrigger>
                    <HoverCardContent class="w-80 z-50">
                      <div class="flex justify-between space-x-4">
                        <div class="space-y-1">
                          <h4 class="text-sm font-semibold">Inserimento massivo</h4>
                          <p class="text-sm">
                            Selezionando questa opzione, dopo aver salvato la tabella si aprirà una nuova schermata che mostra tutti gli immobili del condominio, permettendoti di inserire massivamente tutte le quote e i parametri in un solo passaggio.
                          </p>
                        </div>
                      </div>
                    </HoverCardContent>
                  </HoverCard>
                </div>
              </div>
            </div>
          </CardContent>
        </Card>

        <div class="flex items-center justify-end gap-3">
          <Link
            :href="generatePath('gestionale/:condominio/tabelle', { condominio: props.condominio.id })"
            class="inline-flex h-9 items-center justify-center gap-2 rounded-md bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 px-6 text-[10px] font-bold uppercase tracking-widest text-slate-700 dark:text-slate-300 shadow-sm hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors"
          >
            Annulla
          </Link>

          <Button 
            type="submit"
            :disabled="form.processing" 
            class="h-9 px-8 text-[10px] font-bold uppercase tracking-widest shadow-md gap-2"
          >
            <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
            <Plus v-else class="h-3.5 w-3.5" />
            Salva Tabella
          </Button>
        </div>

      </form>
    </div>
  </GestionaleLayout>

  <TabelleGuide v-model:open="showGuide" />

</template>

<style src="vue-select/dist/vue-select.css"></style>
