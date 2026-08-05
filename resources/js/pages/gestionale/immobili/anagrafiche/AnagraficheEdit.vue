<script setup lang="ts">
import { computed } from 'vue';
import { Link, Head, useForm } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import ImmobileLayout from '@/layouts/gestionale/ImmobileLayout.vue';
import { usePermission } from "@/composables/permissions";
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Button } from '@/components/ui/button';
import { Save, LoaderCircle, Info, UserCheck, CalendarDays, Coins, List } from 'lucide-vue-next';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Textarea } from '@/components/ui/textarea';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { useDateConverter } from '@/composables/useDateConverter';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import VueDatePicker from '@vuepic/vue-datepicker';
import '@vuepic/vue-datepicker/dist/main.css';
import vSelect from "vue-select";
import type { Building } from '@/types/buildings';
import type { BreadcrumbItem } from '@/types';
import type { Immobile } from '@/types/gestionale/immobili';
import type { Anagrafica, AnagraficaWithPivot } from '@/types/anagrafiche';
import type { DropdownType } from '@/types/dropdown';

const props = defineProps<{
  condominio: Building;
  immobile: Immobile;
  anagrafiche: Anagrafica[];
  anagrafica: AnagraficaWithPivot;
}>();

const { generatePath, generateRoute } = usePermission();
const { toBackend } = useDateConverter();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, href: '#' },
  { title: 'Immobili', href: generatePath('gestionale/:condominio/immobili', { condominio: props.condominio.id }) },
  { title: props.immobile.nome, href: generatePath('gestionale/:condominio/immobili/:immobile', { condominio: props.condominio.id, immobile: props.immobile.id }) },
  { title: 'Modifica Associazione', href: '#' },
]);

const pageGuides = computed(() => [
  {
    title: 'Aggiornamento Ruolo',
    description: "Modifica la tipologia di associazione o il soggetto collegato all'unità.",
    icon: UserCheck,
    colorVariant: 'blue' as const
  },
  {
    title: 'Ricalcolo Quote',
    description: "Modifica la percentuale di competenza per aggiornare i futuri riparti di spesa.",
    icon: Coins,
    colorVariant: 'emerald' as const
  },
  {
    title: 'Gestione Periodo',
    description: "Imposta o varia le date di validità per gestire correttamente i subentri.",
    icon: CalendarDays,
    colorVariant: 'amber' as const
  }
]);

// Vedi la nota gemella in `AnagraficheNew.vue`: l'elenco è quello di
// `App\Enums\RuoloAnagraficaImmobile`, e «Nudo proprietario» esiste dalla beta.43.
const tipologiaList = [
  { label: 'Proprietario', id: 'proprietario' },
  { label: 'Nudo proprietario', id: 'nuda_proprietario' },
  { label: 'Usufruttuario', id: 'usufruttuario' },
  { label: 'Inquilino', id: 'inquilino' }
];

const form = useForm({
  tipologia: props.anagrafica.pivot?.tipologia ?? '',
  data_inizio: props.anagrafica.pivot?.data_inizio ?? '',
  data_fine: props.anagrafica.pivot?.data_fine ?? '',
  quota: props.anagrafica.pivot?.quota ?? '',
  note: props.anagrafica.pivot?.note ?? '',
  anagrafica_id: props.anagrafica.id ?? '',
});

const submit = () => {
    form.data_inizio = toBackend(form.data_inizio);
    form.data_fine   = toBackend(form.data_fine);
    
    form.put(route(...generateRoute('gestionale.immobili.anagrafiche.update', { 
        condominio: props.condominio.id, 
        immobile: props.immobile.id,
        anagrafica: props.anagrafica.id
    })), {
        preserveScroll: true,
    });
};
</script>

<template>
  <Head title="Modifica associazione immobile" />

  <GestionaleLayout>
    <div class="px-6 py-8 space-y-4">

      <PageHeaderGuide
        page-title="Modifica associazione"
        :page-subtitle="`Gestione associazione per l'unità: ${immobile.nome}`"
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
        :back-url="generatePath('gestionale/:condominio/immobili/:immobile/anagrafiche', { condominio: props.condominio.id, immobile: props.immobile.id })"
        back-text="Annulla e torna all'elenco"
      />

      <ImmobileLayout>
        <div class="space-y-6">

          <form @submit.prevent="submit" class="space-y-6">

            <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
              <CardHeader class="pb-3 border-b border-dashed mb-4">
                <CardTitle class="text-base font-semibold text-slate-800 dark:text-slate-200">Soggetto e ruolo</CardTitle>
                <CardDescription>Specifica il titolo di possesso o detenzione.</CardDescription>
              </CardHeader>
              
              <CardContent class="space-y-6">
                <div class="grid grid-cols-1 gap-x-6 gap-y-6 sm:grid-cols-12">
                  
                  <div class="sm:col-span-6">
                    <Label for="anagrafica_id" class="mb-1.5 block font-bold text-xs uppercase tracking-widest text-slate-500">Anagrafica Associata</Label>
                    <v-select
                      id="anagrafica_id"
                      class="w-full bg-white dark:bg-slate-950 text-sm"
                      :options="anagrafiche"
                      v-model="form.anagrafica_id"
                      :reduce="(d: Anagrafica) => d.id"
                      label="nome"
                      placeholder="Seleziona..."
                      disabled
                    >
                      <template #selected-option="{ nome, cognome }">
                         <span class="font-bold">{{ nome }} {{ cognome }}</span>
                      </template>
                    </v-select>
                    <p class="text-[10px] text-slate-400 mt-1 italic">L'anagrafica non è modificabile. Per cambiare soggetto, crea una nuova associazione.</p>
                  </div>

                  <div class="sm:col-span-3">
                    <Label for="tipologia" class="mb-1.5 block font-bold text-xs uppercase tracking-widest text-slate-500">Tipologia</Label>
                    <v-select
                      class="w-full bg-white dark:bg-slate-950 text-sm"
                      :options="tipologiaList"
                      label="label"
                      v-model="form.tipologia"
                      :reduce="(d: DropdownType) => d.id"
                      placeholder="Scegli..."
                    />
                    <InputError :message="form.errors.tipologia" />
                  </div>

                  <div class="sm:col-span-3">
                    <div class="flex items-center gap-1 mb-1.5">
                      <Label for="quota" class="font-bold text-xs uppercase tracking-widest text-slate-500">Quota competenza (%)</Label>
                      <HoverCard>
                        <HoverCardTrigger as-child>
                          <button type="button" class="cursor-pointer flex items-center">
                            <Info class="w-3.5 h-3.5 text-slate-400 hover:text-indigo-500 transition-colors" />
                          </button>
                        </HoverCardTrigger>
                        <HoverCardContent class="w-80 z-50 font-sans tracking-normal lowercase first-letter:uppercase">
                           <div class="space-y-2">
                             <p class="text-sm">Indica il peso di questo soggetto nel riparto spese interno all'unità.</p>
                             <Separator class="my-2" />
                             <p class="text-[11px] text-slate-500">Esempio: 2 comproprietari al 50%.</p>
                           </div>
                        </HoverCardContent>
                      </HoverCard>
                    </div>

                    <Input
                      id="quota" 
                      placeholder="es. 100.00" 
                      v-model="form.quota" 
                      class="w-full bg-white dark:bg-slate-950 font-mono"
                    />
                    <InputError :message="form.errors.quota" />
                  </div>

                </div>
              </CardContent>
            </Card>

            <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
              <CardHeader class="pb-3 border-b border-dashed mb-4">
                <CardTitle class="text-base font-semibold text-slate-800 dark:text-slate-200">Validità e note</CardTitle>
                <CardDescription>Date di competenza per la gestione contabile.</CardDescription>
              </CardHeader>
              
              <CardContent class="space-y-6">
                <div class="grid grid-cols-1 gap-x-6 gap-y-6 sm:grid-cols-2">
                  
                  <div>
                    <Label for="data_inizio" class="mb-1.5 block font-bold text-xs uppercase tracking-widest text-slate-500">Data inizio competenza</Label>
                    <VueDatePicker
                      v-model="form.data_inizio"
                      class="w-full"
                      format="dd/MM/yyyy"
                      locale="it"
                      :enable-time-picker="false"
                      auto-apply
                    />
                    <InputError :message="form.errors.data_inizio" />
                  </div>

                  <div>
                    <div class="flex items-center gap-1 mb-1.5">
                      <Label for="data_fine" class="font-bold text-xs uppercase tracking-widest text-slate-500">Data fine competenza</Label>
                      <HoverCard>
                        <HoverCardTrigger as-child>
                          <button type="button" class="cursor-pointer flex items-center">
                            <Info class="w-3.5 h-3.5 text-slate-400 hover:text-amber-500 transition-colors" />
                          </button>
                        </HoverCardTrigger>
                        <HoverCardContent class="w-80 z-50 font-sans tracking-normal lowercase first-letter:uppercase">
                          <p class="text-sm">Fondamentale per i <strong>subentri</strong>. Se inserita, il sistema interromperà gli addebiti a questa data.</p>
                        </HoverCardContent>
                      </HoverCard>
                    </div>

                    <VueDatePicker
                      v-model="form.data_fine"
                      class="w-full"
                      format="dd/MM/yyyy"
                      locale="it"
                      :enable-time-picker="false"
                      auto-apply
                    />
                    <InputError :message="form.errors.data_fine" />
                  </div>

                  <div class="sm:col-span-2 mt-2 mb-2 border-t border-dashed"></div>

                  <div class="sm:col-span-2">
                    <Label for="note" class="mb-1.5 block font-bold text-xs uppercase tracking-widest text-slate-500">Note interne</Label>
                    <Textarea 
                        id="note" 
                        class="w-full mt-1 bg-white dark:bg-slate-950 resize-none" 
                        rows="3"
                        placeholder="Note visibili solo agli amministratori..." 
                        v-model="form.note" 
                    />
                    <InputError :message="form.errors.note" />
                  </div>

                </div>
              </CardContent>
            </Card>

            <div class="flex items-center justify-end gap-3 pt-2">
              <Link
                  :href="generatePath('gestionale/:condominio/immobili/:immobile/anagrafiche', { condominio: props.condominio.id, immobile: props.immobile.id })"
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
                  Salva Modifiche
              </Button>
            </div>

          </form>

        </div>
      </ImmobileLayout>
   </div>
  </GestionaleLayout>
</template>

<style src="vue-select/dist/vue-select.css"></style>