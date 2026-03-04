<script setup lang="ts">
import { computed } from 'vue';
import { Link, Head, useForm } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import ImmobileLayout from '@/layouts/gestionale/ImmobileLayout.vue';
import { usePermission } from "@/composables/permissions";
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Button } from '@/components/ui/button';
import { Plus, LoaderCircle, Info, UserCheck, CalendarDays, Coins } from 'lucide-vue-next';
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
import type { Anagrafica } from '@/types/anagrafiche';
import type { DropdownType } from '@/types/dropdown';

const props = defineProps<{
  condominio: Building;
  immobile: Immobile;
  anagrafiche: Anagrafica[];
}>();

const { generatePath, generateRoute } = usePermission();
const { toBackend } = useDateConverter();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, href: '#' },
  { title: 'Immobili', href: generatePath('gestionale/:condominio/immobili', { condominio: props.condominio.id }) },
  { title: props.immobile.nome, href: generatePath('gestionale/:condominio/immobili/:immobile', { condominio: props.condominio.id, immobile: props.immobile.id }) },
  { title: 'Associa Anagrafica', href: '#' },
]);

const pageGuides = computed(() => [
  {
    title: 'Associazione Soggetti',
    description: "Collega un'anagrafica all'immobile specificando il suo ruolo (Proprietario o Inquilino).",
    icon: UserCheck,
    colorVariant: 'blue' as const
  },
  {
    title: 'Quote Millesimali',
    description: "Imposta la percentuale di possesso per garantire il corretto calcolo dei riparti.",
    icon: Coins,
    colorVariant: 'emerald' as const
  },
  {
    title: 'Periodo Validità',
    description: "Definisci le date di competenza per far subentrare automaticamente i nuovi soggetti.",
    icon: CalendarDays,
    colorVariant: 'amber' as const
  }
]);

const tipologia = [
  { label: 'Proprietario', id: 'proprietario' },
  { label: "Inquilino", id: 'inquilino' },
  { label: "Usufruttuario", id: 'usufruttuario' }
];

const form = useForm({
  tipologia: '',
  data_inizio: '',
  data_fine: '',
  quota: '100.00',
  note: '',
  anagrafica_id: '',
});

const submit = () => {
    form.data_inizio = toBackend(form.data_inizio);
    form.data_fine   = toBackend(form.data_fine);
    
    form.post(route(...generateRoute('gestionale.immobili.anagrafiche.store', { condominio: props.condominio.id, immobile: props.immobile.id })), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
        }
    });
};
</script>

<template>
  <Head title="Associa anagrafica immobile" />

  <GestionaleLayout>

    <div class="px-6 py-8 space-y-4">

      <PageHeaderGuide
        page-title="Nuova associazione"
        :page-subtitle="`Collega un soggetto all'immobile: ${immobile.nome} (Int. ${immobile.interno})`"
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
                <CardDescription>Scegli chi vive in questo immobile e a che titolo.</CardDescription>
              </CardHeader>
              
              <CardContent class="space-y-6">
                <div class="grid grid-cols-1 gap-x-6 gap-y-6 sm:grid-cols-12">
                  
                  <div class="sm:col-span-6">
                    <div class="flex items-center gap-1 mb-1.5">
                      <Label for="anagrafica_id">Anagrafica</Label>
                      <HoverCard>
                        <HoverCardTrigger as-child>
                          <button type="button" class="cursor-pointer flex items-center">
                            <Info class="w-3.5 h-3.5 text-slate-400 hover:text-indigo-500 transition-colors" />
                          </button>
                        </HoverCardTrigger>
                        <HoverCardContent class="w-80 z-50">
                          <div class="space-y-3">
                            <h4 class="text-sm font-semibold flex items-center gap-2">
                              <Info class="w-4 h-4" /> Chi vedi qui?
                            </h4>
                            <div class="text-sm space-y-2 text-slate-500">
                              <p>Mostra le anagrafiche del condominio <strong>non ancora associate</strong> a questo immobile.</p>
                              <Separator class="my-2"/>
                              <div class="text-xs">
                                <span class="font-semibold text-slate-700">Manca qualcuno?</span><br>
                                Vai in <Link :href="generatePath('anagrafiche')" class="text-indigo-600 hover:underline">gestione anagrafiche</Link> per crearlo e associare il condominio.
                              </div>
                            </div>
                          </div>
                        </HoverCardContent>
                      </HoverCard>
                    </div>
                    
                    <v-select
                      id="anagrafica_id"
                      class="w-full bg-white dark:bg-slate-950 text-sm"
                      :options="anagrafiche"
                      v-model="form.anagrafica_id"
                      :reduce="(d: Anagrafica) => d.id"
                      label="nome"
                      placeholder="Cerca o seleziona..."
                    >
                      <template #option="{ nome, cognome, indirizzo }">
                        <div class="flex flex-col py-0.5">
                          <span class="font-medium text-sm">{{ nome }} {{ cognome }}</span>
                          <span class="text-[11px] text-slate-400 truncate">{{ indirizzo }}</span>
                        </div>
                      </template>
                    </v-select>
                    <InputError :message="form.errors.anagrafica_id" />
                  </div>

                  <div class="sm:col-span-3">
                    <Label for="tipologia" class="mb-1.5 block">Tipologia</Label>
                    <v-select
                      class="w-full bg-white dark:bg-slate-950 text-sm"
                      :options="tipologia"
                      label="label"
                      v-model="form.tipologia"
                      :reduce="(d: DropdownType) => d.id"
                      placeholder="Scegli tipologia..."
                    />
                    <InputError :message="form.errors.tipologia" />
                  </div>

                  <div class="sm:col-span-3">
                    <div class="flex items-center gap-1 mb-1.5">
                      <Label for="quota">Quota competenza (%)</Label>
                      
                      <HoverCard>
                        <HoverCardTrigger as-child>
                          <button type="button" class="cursor-pointer flex items-center">
                            <Info class="w-3.5 h-3.5 text-slate-400 hover:text-indigo-500 transition-colors" />
                          </button>
                        </HoverCardTrigger>
                        <HoverCardContent class="w-80 z-50">
                          <div class="space-y-3">
                            <h4 class="text-sm font-semibold flex items-center gap-2">
                              <Info class="w-4 h-4" /> Quota percentuale interna
                            </h4>
                            <div class="text-sm space-y-2 text-slate-500">
                              <p>
                                <strong class="text-red-500 dark:text-red-400">Attenzione:</strong> Non inserire qui i millesimi dell'immobile.
                              </p>
                              <p>
                                Questa è la percentuale (da 0 a 100) che indica il "peso" di questo soggetto all'interno dell'appartamento.
                              </p>
                              <ul class="list-disc pl-4 space-y-1 text-xs">
                                <li><strong>Proprietario unico:</strong> 100%</li>
                                <li><strong>Comproprietari (es. 2 coniugi):</strong> 50% ciascuno</li>
                              </ul>
                            </div>
                          </div>
                        </HoverCardContent>
                      </HoverCard>
                    </div>

                    <Input
                      id="quota" 
                      placeholder="es. 50.00" 
                      v-model="form.quota" 
                      class="w-full bg-white dark:bg-slate-950"
                      v-on:focus="form.clearErrors('quota')"
                    />
                    <InputError :message="form.errors.quota" />
                  </div>

                </div>
              </CardContent>
            </Card>

            <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
              <CardHeader class="pb-3 border-b border-dashed mb-4">
                <CardTitle class="text-base font-semibold text-slate-800 dark:text-slate-200">Validità e note</CardTitle>
                <CardDescription>Definisci il periodo di competenza contabile di questa associazione.</CardDescription>
              </CardHeader>
              
              <CardContent class="space-y-6">
                <div class="grid grid-cols-1 gap-x-6 gap-y-6 sm:grid-cols-2">
                  
                  <div>
                    <Label for="data_inizio" class="mb-1.5 block">Data inizio competenza</Label>
                    <VueDatePicker
                      v-model="form.data_inizio"
                      class="w-full mt-1"
                      format="dd/MM/yyyy"
                      locale="it"
                      :enable-time-picker="false"
                      auto-apply
                      placeholder="Seleziona data inizio competenza"
                    />
                    <InputError :message="form.errors.data_inizio" />
                  </div>

                  <div>
                    <div class="flex items-center gap-1 mb-1.5">
                      <Label for="data_fine">Data fine competenza (Opzionale)</Label>
                      
                      <HoverCard>
                        <HoverCardTrigger as-child>
                          <button type="button" class="cursor-pointer flex items-center">
                            <Info class="w-3.5 h-3.5 text-slate-400 hover:text-indigo-500 transition-colors" />
                          </button>
                        </HoverCardTrigger>
                        <HoverCardContent class="w-80 z-50">
                          <div class="space-y-3">
                            <h4 class="text-sm font-semibold flex items-center gap-2">
                              <Info class="w-4 h-4" /> Gestione Subentri
                            </h4>
                            <div class="text-sm space-y-2 text-slate-500">
                              <p>
                                Questo campo è fondamentale per i <strong>Subentri</strong> (es. compravendite o cambi inquilino).
                              </p>
                              <p>
                                Inserendo la data di uscita, il sistema saprà esattamente quando <strong>interrompere l'addebito delle rate</strong> e come calcolare i riparti per questo soggetto.
                              </p>
                              <Separator class="my-2"/>
                              <div class="text-xs text-slate-400 italic">
                                Lascia il campo vuoto se la persona vive ancora qui.
                              </div>
                            </div>
                          </div>
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
                      placeholder="Lascia vuoto se in corso"
                    />
                    <InputError :message="form.errors.data_fine" />
                  </div>

                  <div class="sm:col-span-2 mt-2 mb-2 border-t border-dashed"></div>

                  <div class="sm:col-span-2">
                    <Label for="note" class="mb-1.5 block">Note interne</Label>
                    <Textarea 
                        id="note" 
                        class="w-full mt-1 bg-white dark:bg-slate-950 resize-none" 
                        rows="3"
                        placeholder="Eventuali note sull'associazione (visibili solo agli amministratori)..." 
                        v-model="form.note" 
                        v-on:focus="form.clearErrors('note')"
                    />
                    <InputError :message="form.errors.note" />
                    <p class="text-[11px] text-muted-foreground mt-1 italic">Le note non saranno stampate in bolletta.</p>
                  </div>

                </div>
              </CardContent>
            </Card>

            <div class="flex items-center justify-end gap-3">
              <Link
                  :href="generatePath('gestionale/:condominio/immobili/:immobile/anagrafiche', { condominio: props.condominio.id, immobile: props.immobile.id })"
                  class="inline-flex items-center justify-center h-9 px-6 rounded-md border border-input bg-background text-sm font-semibold hover:bg-accent hover:text-accent-foreground transition-all shadow-sm"
              >
                Annulla
              </Link>

              <Button 
                  type="submit"
                  :disabled="form.processing" 
                  class="h-9 px-8 text-sm font-semibold shadow-md gap-2"
              >
                  <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                  <Plus v-else class="h-4 w-4" />
                  Salva associazione
              </Button>
            </div>

          </form>

        </div>
      </ImmobileLayout>

   </div>

  </GestionaleLayout>
</template>

<style src="vue-select/dist/vue-select.css"></style>