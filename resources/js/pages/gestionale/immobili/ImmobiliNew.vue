<script setup lang="ts">

import { computed } from 'vue';
import { Link, Head, useForm } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import { usePermission } from "@/composables/permissions";
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Button } from '@/components/ui/button';
import { List, Plus, LoaderCircle, Home, Hash, MapPin, Info } from 'lucide-vue-next';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import PertinenzaField from '@/components/gestionale/immobili/PertinenzaField.vue';
import { Textarea } from '@/components/ui/textarea';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import vSelect from "vue-select";
import type { Building } from '@/types/buildings';
import CercaComune from '@/components/comuni/CercaComune.vue';
import type { BreadcrumbItem } from '@/types';
import type { Palazzina } from '@/types/gestionale/palazzine';
import type { Scala } from '@/types/gestionale/scale';
import type { TipologiaImmobile } from '@/types/gestionale/tipologie-immobili';

const props = defineProps<{
  condominio: Building;
  condomini: Building[];
  palazzine: Palazzina[];
  scale: Scala[];
  tipologie: TipologiaImmobile[];
  unitaPrincipali: { id: number; etichetta: string }[];
}>()

const { generatePath, generateRoute } = usePermission();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, href: '#' },
  { title: 'Immobili', href: generatePath('gestionale/:condominio/immobili', { condominio: props.condominio.id }) },
  { title: 'Nuovo Immobile', href: '#' },
]);

const pageGuides = computed(() => [
  {
    title: 'Dati Identificativi',
    description: "Definisci il nome, la tipologia e l'ubicazione fisica all'interno del complesso.",
    icon: Home,
    colorVariant: 'blue' as const
  },
  {
    title: 'Caratteristiche Fisiche',
    description: "Inserisci piano, interno e consistenza (vani/superficie) per i riparti millesimali.",
    icon: Info,
    colorVariant: 'emerald' as const
  },
  {
    title: 'Dati Catastali',
    description: "Informazioni legali necessarie per l'Anagrafe Condominiale e i modelli fiscali.",
    icon: Hash,
    colorVariant: 'amber' as const
  }
]);

const form = useForm({
  nome: '',
  descrizione: '',
  note: '',
  comune_catasto: '',
  codice_catasto: '',
  sezione_catasto: '',
  foglio_catasto: '',
  particella_catasto: '',
  subalterno_catasto: '',
  interno: '',
  piano: '',
  superficie: '',
  numero_vani: '',
  palazzina_id: '',
  scala_id: '',
  tipologia_id: '',
  pertinenza_di_immobile_id: null as number | null,
  pertinenza_di_esterna: null as string | null,
});

/**
 * La categoria della tipologia scelta, che decide **solo l'evidenza** del campo «Pertinenza di» —
 * mai la sua disponibilità. Vedi la nota estesa in `PertinenzaField.vue`.
 */
const categoriaTipologiaScelta = computed(
  // `form.tipologia_id` parte come stringa vuota e diventa un numero alla selezione: il
  // confronto va normalizzato, o non trova mai niente.
  () => props.tipologie.find((t) => String(t.id) === String(form.tipologia_id))?.categoria ?? null,
);

/**
 * Il Comune scelto dall'elenco riempie **due** campi, non uno: senza il codice catastale accanto al
 * nome, l'aiuto avrebbe risparmiato la parte facile e lasciato quella che nessuno ricorda.
 */
const comuneScelto = (c: { nome: string; codice_catasto: string }) => {
  form.comune_catasto = c.nome;
  form.codice_catasto = c.codice_catasto;
};

const submit = () => {
    form.post(route(...generateRoute('gestionale.immobili.store', { condominio: props.condominio.id })), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
        }
    });
};
</script>

<template>
  <Head title="Crea nuovo immobile" />

  <GestionaleLayout>
    <div class="px-6 py-8 space-y-6">

      <PageHeaderGuide
        page-title="Nuovo immobile"
        :page-subtitle="`Registrazione unità immobiliare per: ${props.condominio.nome}`"
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
        :back-url="generatePath('gestionale/:condominio/immobili', { condominio: props.condominio.id })"
        back-text="Torna all'elenco"
      >
      </PageHeaderGuide>

      <form id="immobileForm" @submit.prevent="submit" class="space-y-6">

        <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
          <CardHeader class="pb-3 border-b border-dashed mb-4">
            <CardTitle class="text-base font-semibold">Ubicazione e tipologia</CardTitle>
            <CardDescription>Definisci come l'unità è posizionata nel complesso.</CardDescription>
          </CardHeader>
          <CardContent class="space-y-6">
            <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-6">
              <div class="sm:col-span-3">
                <Label for="nome">Nome immobile</Label>
                <Input 
                  id="nome" 
                  v-model="form.nome" 
                  placeholder="es. Interno 1A" 
                  class="mt-1 bg-white dark:bg-slate-950" 
                  v-on:focus="form.clearErrors('nome')" 
                />
                <InputError :message="form.errors.nome" />
              </div>

              <div class="sm:col-span-3">
                <Label for="tipologia">Tipologia</Label>
                <v-select 
                  class="mt-1 bg-white dark:bg-slate-950 text-sm"
                  :options="tipologie" label="nome" v-model="form.tipologia_id"
                  :reduce="(t: TipologiaImmobile) => t.id" placeholder="Seleziona..."
                  @update:modelValue="form.clearErrors('tipologia_id')" 
                />
                <InputError :message="form.errors.tipologia_id" />
              </div>

              <PertinenzaField
                :unita-principali="unitaPrincipali"
                :categoria-tipologia="categoriaTipologiaScelta"
                v-model:immobile-id="form.pertinenza_di_immobile_id"
                v-model:esterna="form.pertinenza_di_esterna"
                :errore-immobile="form.errors.pertinenza_di_immobile_id"
                :errore-esterna="form.errors.pertinenza_di_esterna"
              />

              <div class="sm:col-span-6">
                <Label for="descrizione">Descrizione</Label>
                <Input 
                id="descrizione" 
                v-model="form.descrizione" 
                placeholder="es. Appartamento trilocale vista parco" 
                class="mt-1 bg-white dark:bg-slate-950" 
                v-on:focus="form.clearErrors('descrizione')"
                />
                <InputError :message="form.errors.descrizione" />
              </div>

              <div class="sm:col-span-3">
                <Label for="palazzina">Palazzina</Label>
                <v-select 
                  class="mt-1 bg-white dark:bg-slate-950 text-sm" 
                  :options="palazzine" label="name" 
                  v-model="form.palazzina_id" 
                  :reduce="(p: Palazzina) => p.id" 
                  placeholder="Associa palazzina..." 
                />
              </div>

              <div class="sm:col-span-3">
                <Label for="scala">Scala</Label>
                <v-select 
                  class="mt-1 bg-white dark:bg-slate-950 text-sm" 
                  :options="scale" 
                  label="name" 
                  v-model="form.scala_id" 
                  :reduce="(s: Scala) => s.id" placeholder="Associa scala..." 
                />
              </div>
            </div>
          </CardContent>
        </Card>

        <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
          <CardHeader class="pb-3 border-b border-dashed mb-4">
            <CardTitle class="text-base font-semibold">Dettagli strutturali</CardTitle>
            <CardDescription>Caratteristiche fisiche e note interne.</CardDescription>
          </CardHeader>
          <CardContent class="space-y-6">
            <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-8">
              <div class="sm:col-span-2">
                <Label for="interno">Interno</Label>
                <Input 
                  id="interno" 
                  v-model="form.interno" 
                  placeholder="es. 10" 
                  class="mt-1 bg-white dark:bg-slate-950" 
                  v-on:focus="form.clearErrors('interno')"
                />
                <InputError :message="form.errors.interno" />
              </div>
              <div class="sm:col-span-2">
                <Label for="piano">Piano</Label>
                <Input 
                  id="piano"
                  v-model="form.piano" 
                  placeholder="es. T, 1, 2..." 
                  class="mt-1 bg-white dark:bg-slate-950" 
                />
              </div>
              <div class="sm:col-span-2">
                <Label for="superficie">Superficie (m²)</Label>
                <Input 
                  id="superficie" 
                  v-model="form.superficie" 
                  placeholder="es. 90" 
                  class="mt-1 bg-white dark:bg-slate-950" 
                />
              </div>
              <div class="sm:col-span-2">
                <Label for="numero_vani">Numero vani</Label>
                <Input 
                  id="numero_vani" 
                  v-model="form.numero_vani"
                  placeholder="es. 5" 
                  class="mt-1 bg-white dark:bg-slate-950" 
                />
              </div>

              <div class="sm:col-span-8">
                <Label for="note">Note riservate</Label>
                <Textarea 
                  id="note" 
                  v-model="form.note" 
                  placeholder="Note visibili solo agli amministratori..." 
                  class="mt-1 bg-white dark:bg-slate-950"
                />
              </div>
            </div>
          </CardContent>
        </Card>

        <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
          <CardHeader class="pb-3 border-b border-dashed mb-4">
              <CardTitle class="text-base font-semibold">Identificativi catastali</CardTitle>
              <CardDescription>Dati catastali dell'unità immobiliare.</CardDescription>
          </CardHeader>
          <CardContent class="space-y-6">
            <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-4">
              <div class="sm:col-span-3 font-sans">
                <Label for="comune_catasto">Comune catastale</Label>
                <div class="mt-1 flex items-center gap-2">
                  <Input 
                    id="comune_catasto" 
                    v-model="form.comune_catasto" 
                    class="bg-white dark:bg-slate-950" 
                    placeholder="es. Milano, Roma..."
                  />
                  <CercaComune @scelto="comuneScelto" />
                </div>
              </div>
              <div class="sm:col-span-1 font-sans">
                <Label for="codice_catasto">Codice</Label>
                <Input 
                  id="codice_catasto" 
                  v-model="form.codice_catasto" 
                  class="mt-1 bg-white dark:bg-slate-950" 
                  placeholder="es. H501"
                />
              </div>
              
              <div class="sm:col-span-1 font-sans">
                <Label for="sezione_catasto">Sezione</Label>
                <Input 
                  id="sezione_catasto" 
                  v-model="form.sezione_catasto" 
                  class="mt-1 bg-white dark:bg-slate-950" 
                  placeholder="es. A, B..."
                />
              </div>
              <div class="sm:col-span-1 font-sans">
                <Label for="foglio_catasto">Foglio</Label>
                <Input 
                  id="foglio_catasto" 
                  v-model="form.foglio_catasto" 
                  class="mt-1 bg-white dark:bg-slate-950" 
                  placeholder="es. 123"
                />
              </div>
              <div class="sm:col-span-1 font-sans">
                <Label for="particella_catasto">Particella</Label>
                <Input 
                  id="particella_catasto" 
                  v-model="form.particella_catasto" 
                  class="mt-1 bg-white dark:bg-slate-950" 
                  placeholder="es. 1234"
                />
              </div>
              <div class="sm:col-span-1 font-sans">
                <Label for="subalterno_catasto">Subalterno</Label>
                <Input 
                  id="subalterno_catasto" 
                  v-model="form.subalterno_catasto" 
                  class="mt-1 bg-white dark:bg-slate-950" 
                  placeholder="es. 12345"
                />
              </div>
            </div>
          </CardContent>
        </Card>

        <div class="flex items-center justify-end gap-3">
            <Link
              :href="generatePath('gestionale/:condominio/immobili', { condominio: props.condominio.id })"
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
              Salva Immobile
            </Button>
        </div>

      </form>
    </div>
  </GestionaleLayout>
</template>

<style src="vue-select/dist/vue-select.css"></style>