<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, Head, router, useForm } from '@inertiajs/vue3';
import { Switch } from '@/components/ui/switch';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import { usePermission } from "@/composables/permissions";
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Button } from '@/components/ui/button';
import { Plus, LoaderCircle, Table, Settings } from 'lucide-vue-next';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Textarea } from '@/components/ui/textarea';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import vSelect from "vue-select";
import type { Building } from '@/types/buildings';
import type { BreadcrumbItem } from '@/types';
import type { Tabella } from '@/types/gestionale/tabelle';

const props = defineProps<{
  condominio: Building;
  tabella: Tabella;
  /** La tabella di proprietà generale del condominio (R14): se è questa, quella di oggi, e se chi guarda la sceglie. */
  proprieta?: { questa: boolean; attuale: string | null; si_sceglie: boolean };
  /** La tabella di un gruppo (Coda 734): il segno, le unità con la quota e le unità del condominio. */
  gruppo?: { questa: boolean; unita: number; del_condominio: number };
}>()

type TabellaType = {
  label: string;
  id: string;
};

const tipologieTabelle = [
  { label: 'Standard', id: 'standard' },
  { label: "Ascensore", id: 'ascensore' },
  // `scale` c'è nell'enum del database dal primo giorno e l'importatore lo produce
  // (LivelloTabelle mappa «scala» e «scale»), ma mancava qui e nella regola `in:`:
  // una tabella importata «SCALE A» non si poteva più salvare. Coda ㊱.
  { label: "Scale", id: 'scale' },
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
  // ⚠️ Vedi la nota gemella in `TabelleNew.vue`: la promessa del filtro per palazzina o scala
  // stava in tre posti e nessun codice la manteneva. Tolta nella beta.63.
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
});

// «È la tabella di proprietà generale di Via Roma 12»: si salva quando la cambi, ed è una riga del registro sulla scheda del condominio.
const diProprieta = ref(props.proprieta?.questa ?? false);
const salvataggioProprieta = ref(false);
const cambiaProprieta = (valore: boolean) => {
    diProprieta.value = valore;
    salvataggioProprieta.value = true;
    router.post(route(generateRoute('gestionale.tabella-di-proprieta'), { condominio: props.condominio.id }),
        { perimetro_tipo: 'condominio', perimetro_id: null, tabella_id: valore ? props.tabella.id : null },
        { only: ['proprieta', 'flash'], preserveScroll: true, preserveState: true, onFinish: () => { salvataggioProprieta.value = false; },
          onError: () => { diProprieta.value = !valore; } });
};
const adessoProprieta = computed(() => {
    if (diProprieta.value) return `Adesso: «${props.tabella.nome}» è la tabella di proprietà generale di ${props.condominio.nome}: la proposta per la tabella del valore delle assemblee.`;
    return props.proprieta?.attuale
        ? `Adesso: la tabella di proprietà generale di ${props.condominio.nome} è «${props.proprieta.attuale}».`
        : `Adesso: ${props.condominio.nome} non ha una tabella di proprietà generale.`;
});

// «È la tabella di un gruppo» (Coda 734, scena 1 della bozza approvata): si salva quando la cambi, come il primo, ed è una riga del registro sulla storia
// della tabella. Su un punto votato con la tabella segnata votano solo i condòmini delle sue unità. Il segno lo mette l'amministratore, mai il programma.
// Il segno che non si toglie (la tabella è il gruppo di un'assemblea non ancora chiusa): l'interruttore torna com'era e la frase del server dice perché.
const diUnGruppo = ref(props.gruppo?.questa ?? false);
const salvataggioGruppo = ref(false);
const erroreGruppo = ref('');
const cambiaGruppo = (valore: boolean) => {
    diUnGruppo.value = valore;
    salvataggioGruppo.value = true;
    erroreGruppo.value = '';
    router.post(route(generateRoute('gestionale.tabella-di-un-gruppo'), { condominio: props.condominio.id }),
        { tabella_id: props.tabella.id, di_un_gruppo: valore },
        { only: ['gruppo', 'flash'], preserveScroll: true, preserveState: true, onFinish: () => { salvataggioGruppo.value = false; },
          onError: (e: Record<string, string>) => { diUnGruppo.value = !valore; erroreGruppo.value = e.di_un_gruppo ?? Object.values(e)[0] ?? ''; } });
};
const adessoGruppo = computed(() => {
    const unita = props.gruppo?.unita ?? 0;
    const altre = Math.max(0, (props.gruppo?.del_condominio ?? 0) - unita);
    const base = `Adesso: «${props.tabella.nome}» ha ${unita} unità su ${props.gruppo?.del_condominio ?? 0}.`;
    if (diUnGruppo.value) {
        return `${base} Su un punto votato con questa tabella votano solo i loro condòmini, e teste, millesimi e costituzione si contano fra loro (art. 1123 c. 3 c.c.).`;
    }
    if (altre === 0) return `${base} Comprende tutte le unità: il segno non cambierebbe chi vota.`;

    return `${base} Senza il segno, su un punto votato con questa tabella ${altre === 1 ? 'l\'altra conta come testa' : `le altre ${altre} contano come teste`} a zero millesimi.`;
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
        :storia="{ tipo: 'tabella', id: props.tabella.id }"
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

        <!--
          ⚠️ **La scheda «Assegnazione strutturale» è stata tolta nella beta.63, e non era una
          funzione a metà: era una funzione che non è mai esistita.**

          Le colonne `tabelle.palazzina_id` e `tabelle.scala_id` si compilavano, si salvavano, e
          **nessun calcolo e nessun filtro le leggeva**. La guida prometteva il contrario — «puoi
          delimitare l'uso di una tabella solo agli immobili che fanno fisicamente parte di quella
          scala» — quindi chi creava «Tabella scale — Scala B», sceglieva la scala e spuntava
          «associa tutti gli immobili esistenti» si ritrovava tutte le unità del condominio invece
          delle sole sei della scala.

          Misurato prima di togliere: valorizzate in **zero tabelle su sedici**. Nessuno le ha mai
          usate, e questo dice quanto la promessa fosse creduta.

          Le colonne **restano a database**: il filtro per scala e palazzina è previsto in
          Iniziativa A (v1.11) e toglierle ora vorrebbe dire due migrazioni — una per cancellarle e
          una per rimetterle — su una release che non ha ancora il backup automatico. Quello che
          sparisce è la **promessa**, non lo spazio per mantenerla.
        -->

        <Card v-if="proprieta && proprieta.si_sceglie" class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20" data-testid="tabella-di-proprieta">
          <CardHeader class="pb-3 border-b border-dashed mb-4">
            <CardTitle class="text-base font-semibold">Per le assemblee</CardTitle>
            <CardDescription>Come il programma usa questa tabella nelle assemblee di {{ condominio.nome }}.</CardDescription>
          </CardHeader>
          <CardContent class="space-y-2">
            <div class="flex items-center justify-between gap-4">
              <Label for="di-proprieta" class="text-sm">È la tabella di proprietà generale di {{ condominio.nome }}</Label>
              <Switch id="di-proprieta" :model-value="diProprieta" :disabled="salvataggioProprieta" @update:model-value="cambiaProprieta" />
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ adessoProprieta }}</p>
            <!-- La tabella di un gruppo (Coda 734): il secondo interruttore, la riga «Adesso» e il perché in un cassetto. -->
            <div v-if="gruppo" class="space-y-2 border-t border-dashed pt-3" data-testid="tabella-di-un-gruppo">
              <div class="flex items-center justify-between gap-4">
                <Label for="di-un-gruppo" class="text-sm">È la tabella di un gruppo: sui suoi punti votano solo i condòmini delle sue unità</Label>
                <Switch id="di-un-gruppo" :model-value="diUnGruppo" :disabled="salvataggioGruppo" @update:model-value="cambiaGruppo" />
              </div>
              <InputError :message="erroreGruppo" data-testid="errore-gruppo" />
              <p class="text-xs text-slate-500 dark:text-slate-400" data-testid="adesso-gruppo">{{ adessoGruppo }}</p>
              <details class="rounded-md border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950" data-testid="cassetto-gruppo">
                <summary class="cursor-pointer px-3 py-2 text-xs font-semibold text-slate-600 dark:text-slate-300">Quando è la tabella di un gruppo</summary>
                <ul class="list-disc space-y-1 px-3 pb-2 pl-7 text-xs text-slate-700 dark:text-slate-300">
                  <li>La cosa serve solo alcune unità: i box, la scala B, l'ascensore di una palazzina.</li>
                  <li>Chi non ha una quota nella tabella non ne è proprietario: sul punto non vota e non conta.</li>
                  <li>Una tabella d'uso con degli zeri (il piano terra che non usa l'ascensore ma ne è proprietario) non è di un gruppo: lascia spento.</li>
                  <li>Il programma non lo decide da solo: lo sai tu.</li>
                </ul>
              </details>
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
