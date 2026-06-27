<script setup lang="ts">
import { computed } from 'vue';
import { Link, Head, useForm } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import { usePermission } from "@/composables/permissions";
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Button } from '@/components/ui/button';
import { Plus, LoaderCircle, Table, Layers, Settings } from 'lucide-vue-next';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Textarea } from '@/components/ui/textarea';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import vSelect from "vue-select";
import type { Building } from '@/types/buildings';
import type { BreadcrumbItem } from '@/types';
import type { Palazzina } from '@/types/gestionale/palazzine';
import type { Scala } from '@/types/gestionale/scale';
import type { Tabella } from '@/types/gestionale/tabelle';

const props = defineProps<{
  condominio: Building;
  tabella: Tabella;
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

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, href: '#' },
  { title: 'Tabelle', href: generatePath('gestionale/:condominio/tabelle', { condominio: props.condominio.id }) },
  { title: props.tabella.nome, href: '#' },
  { title: 'Modifica', href: '#' },
]);

const pageGuides = computed(() => [
  {
    title: 'Configurazione Tabella',
    description: "Modifica il nome, la tipologia e l'unità di misura della tabella.",
    icon: Table,
    colorVariant: 'blue' as const
  },
  {
    title: 'Assegnazione Strutturale',
    description: "Gestisci l'associazione a specifiche palazzine o scale del condominio.",
    icon: Layers,
    colorVariant: 'emerald' as const
  },
  {
    title: 'Impostazioni Avanzate',
    description: "Aggiorna le note di gestione della tabella.",
    icon: Settings,
    colorVariant: 'amber' as const
  }
]);

const form = useForm({
  nome: props.tabella.nome,
  tipo: props.tabella.tipo,
  quota: props.tabella.quota,
  numero_decimali: props.tabella.numero_decimali,
  descrizione: props.tabella.descrizione,
  note: props.tabella.note,
  palazzina_id: props.tabella.palazzina ? props.tabella.palazzina.id : '',
  scala_id: props.tabella.scala ? props.tabella.scala.id : '',
});

const submit = () => {
    form.put(route(...generateRoute('gestionale.tabelle.update', { condominio: props.condominio.id, tabella: props.tabella.id })), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
        }
    });
};
</script>

<template>
  <Head title="Modifica tabella" />

  <GestionaleLayout>
    <div class="px-6 py-8 space-y-6">

      <PageHeaderGuide
        page-title="Modifica tabella millesimale"
        :page-subtitle="`Aggiornamento della tabella: ${props.tabella.nome}`"
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
        :back-url="generatePath('gestionale/:condominio/tabelle', { condominio: props.condominio.id })"
        back-text="Torna all'elenco"
      >
      </PageHeaderGuide>

      <form id="tabellaForm" @submit.prevent="submit" class="space-y-6">
        
        <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
          <CardHeader class="pb-3 border-b border-dashed mb-4">
            <CardTitle class="text-base font-semibold">Configurazione principale</CardTitle>
            <CardDescription>Modifica i parametri fondamentali della tabella.</CardDescription>
          </CardHeader>
          <CardContent class="space-y-6">
            <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-6">
              <div class="sm:col-span-3">
                <Label for="nome">Nome tabella</Label>
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
                <Label for="tipologia">Tipologia</Label>
                <v-select
                  class="mt-1 bg-white dark:bg-slate-950 text-sm w-full"
                  :options="tipologieTabelle"
                  label="label"
                  v-model="form.tipo"
                  :reduce="(d: TabellaType) => d.id"
                  placeholder="Seleziona tipologia tabella"
                />
                <InputError :message="form.errors.tipo" />
              </div>

              <div class="sm:col-span-3">
                <Label for="quota">Unità di misura</Label>
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
                <Label for="numero_decimali">Numero decimali</Label>
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
                <Label for="descrizione">Descrizione</Label>
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
            <CardDescription>Limita l'uso di questa tabella a palazzine o scale specifiche.</CardDescription>
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
                  placeholder="Nessuna palazzina associata"
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
                  placeholder="Nessuna scala associata"
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
            Salva Modifiche
          </Button>
        </div>

      </form>
    </div>
  </GestionaleLayout>
</template>

<style src="vue-select/dist/vue-select.css"></style>
