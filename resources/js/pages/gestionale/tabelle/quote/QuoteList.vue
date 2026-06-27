<script setup lang="ts">
import { ref, computed } from "vue";
import { Head, useForm, Link } from "@inertiajs/vue3";
import GestionaleLayout from "@/layouts/GestionaleLayout.vue";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import InputError from '@/components/InputError.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { Plus, LoaderCircle, Trash2, Table as TableIcon, Info, Hash } from 'lucide-vue-next';
import { usePermission } from "@/composables/permissions";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { AlertDialog, AlertDialogContent, AlertDialogHeader, AlertDialogTitle, AlertDialogDescription, AlertDialogFooter, AlertDialogCancel } from "@/components/ui/alert-dialog";
import vSelect from "vue-select";
import "vue-select/dist/vue-select.css";
import type { BreadcrumbItem } from "@/types";
import type { Tabella } from "@/types/gestionale/tabelle";
import type { Building } from "@/types/buildings";
import type { Millesimo } from "@/types/gestionale/millesimi";
import type { Immobile } from "@/types/gestionale/immobili";

const props = defineProps<{
  condominio: Building;
  tabella: Tabella;
  millesimi: Millesimo[];
  immobili: Immobile[];
}>()

const showNoImmobiliDialog = ref(false);
const alertMessage = ref("");

// Extract raw data from Proxy objects
const rawMillesimi = JSON.parse(JSON.stringify(props.millesimi));
const rawImmobili = JSON.parse(JSON.stringify(props.immobili));

// Form separato a seconda del tipo tabella
const form = useForm({
  quote: rawMillesimi.map((q: Millesimo) => {
    if (props.tabella.tipo === "acqua") {
      return {
        id: q.id as number | null,
        immobile: q.immobile as Immobile | null, 
        valore: q.valore as string,
        has_contatore: q.coefficienti?.has_contatore ?? false,
        ultima_lettura: q.coefficienti?.ultima_lettura ?? ""
      }
    }

    if (props.tabella.tipo === "riscaldamento") {
      return {
        id: q.id as number | null,
        immobile: q.immobile as Immobile | null, 
        valore: q.valore as string,
        coeff_dispersione: q.coefficienti?.coeff_dispersione ?? "",
        quota_fissa: q.coefficienti?.quota_fissa ?? "",
        quota_variabile: q.coefficienti?.quota_variabile ?? ""
      }
    }

    return {
      id: q.id as number | null,
      immobile: q.immobile as Immobile | null, 
      valore: q.valore as string
    }
  }),
});

const { generatePath, generateRoute } = usePermission();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, href: '#' },
  { title: 'Tabelle', href: generatePath('gestionale/:condominio/tabelle', { condominio: props.condominio.id }) },
  { title: props.tabella.nome, href: '#' },
  { title: 'Quote', href: '#' },
]);

const pageGuides = computed(() => [
  {
    title: 'Assegnazione Quote',
    description: "Associa ogni unità immobiliare al corrispondente valore millesimale.",
    icon: TableIcon,
    colorVariant: 'blue' as const
  },
  {
    title: 'Parametri Specifici',
    description: "Configura campi aggiuntivi per tabelle di tipo acqua o riscaldamento.",
    icon: Info,
    colorVariant: 'emerald' as const
  },
  {
    title: 'Controllo Totali',
    description: "Verifica che la somma totale corrisponda al limite previsto.",
    icon: Hash,
    colorVariant: 'amber' as const
  }
]);

// Calcola immobili disponibili
const immobiliDisponibili = computed(() => {
  const usedIds = form.quote.map((q: any) => q.immobile?.id).filter(Boolean);
  return rawImmobili.filter((i: Immobile) => !usedIds.includes(i.id));
});

const addImmobile = () => {
  const maxRows = rawImmobili.length;

  if (form.quote.length >= maxRows) {
    alertMessage.value = "Hai già raggiunto il numero massimo di righe consentite.";
    showNoImmobiliDialog.value = true;
    return;
  }

  let nuovoImmobile: any = {};

  if (props.tabella.tipo === "acqua") {
    nuovoImmobile = {
      id: null,
      valore: "",
      immobile: null,
      has_contatore: false,
      ultima_lettura: ""
    };
  } else if (props.tabella.tipo === "riscaldamento") {
    nuovoImmobile = {
      id: null,
      valore: "",
      immobile: null,
      coeff_dispersione: "",
      quota_fissa: "",
      quota_variabile: ""
    };
  } else {
    nuovoImmobile = {
      id: null,
      valore: "",
      immobile: null
    };
  }

  form.quote = [...form.quote, nuovoImmobile];
};

const removeImmobile = (index: number | string) => {
  form.quote.splice(Number(index), 1);
};

// Funzione per generare placeholder dinamico in base al numero di decimali
const valorePlaceholder = (decimali: number) => {
  if (!decimali || decimali < 0) return "0";
  return "0." + "0".repeat(decimali);
};

const submit = () => {
  form.put(
    route("admin.gestionale.tabelle.quote.update", {
      condominio: props.condominio.id,
      tabella: props.tabella.id,
    }),
    { preserveScroll: true }
  );
};

</script>

<template>
  <Head title="Millesimi tabella" />

  <GestionaleLayout>
    <div class="px-6 py-8 space-y-6">

      <PageHeaderGuide
        page-title="Associazione millesimi"
        :page-subtitle="`Gestione delle quote per la tabella: ${props.tabella.nome}`"
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
        :back-url="route(generateRoute('gestionale.tabelle.index'), { condominio: props.condominio.id })"
        back-text="Torna alle tabelle"
      >
      </PageHeaderGuide>

      <form @submit.prevent="submit" class="space-y-6">
        
        <Card class="border-dashed shadow-sm bg-slate-50/50 dark:bg-slate-900/20">
          <CardHeader class="pb-3 border-b border-dashed mb-0 flex flex-row items-center justify-between">
            <div class="space-y-1">
              <CardTitle class="text-base font-semibold">Elenco Unità Associate</CardTitle>
              <CardDescription>Specifica i valori millesimali per ogni unità immobiliare.</CardDescription>
            </div>
            <Button 
              type="button" 
              @click="addImmobile" 
              class="h-8 px-4 text-[10px] font-bold uppercase tracking-widest gap-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-50 shadow-sm"
            >
              <Plus class="w-3.5 h-3.5" />
              Aggiungi Immobile
            </Button>
          </CardHeader>
          <CardContent class="p-0">
            <div class="overflow-x-auto">
              <Table>
                
                <TableHeader class="bg-slate-50/50 dark:bg-slate-900/50">
                  <TableRow>
                    <TableHead class="w-[500px]">Immobile</TableHead>
                    <TableHead>{{ props.tabella.quota.charAt(0).toUpperCase() + props.tabella.quota.slice(1) }}</TableHead>

                    <!-- Acqua -->
                    <TableHead v-if="props.tabella.tipo === 'acqua'" class="text-center">Contatore?</TableHead>
                    <TableHead v-if="props.tabella.tipo === 'acqua'">Ultima lettura (m³)</TableHead>

                    <!-- Riscaldamento -->
                    <TableHead v-if="props.tabella.tipo === 'riscaldamento'">Quota fissa (%)</TableHead>
                    <TableHead v-if="props.tabella.tipo === 'riscaldamento'">Quota variabile (%)</TableHead>
                    <TableHead v-if="props.tabella.tipo === 'riscaldamento'">Coeff. dispersione</TableHead>

                    <TableHead class="text-center w-[80px]">Azioni</TableHead>
                  </TableRow>
                </TableHeader>

                <TableBody>
                  <TableRow v-for="(q, idx) in form.quote" :key="q.id ?? idx" class="border-b border-dashed last:border-0">

                    <!-- Immobile -->
                    <TableCell>
                      <!-- Mostra come testo se l'immobile è già associato (ha un ID) -->
                      <div v-if="q.id && q.immobile">
                        <div class="font-medium">{{ q.immobile?.nome ?? '—' }}</div>
                        <div class="text-[11px] text-slate-500 dark:text-slate-400">
                          Palazzina: {{ q.immobile?.palazzina?.name ?? "—" }} |
                          Scala: {{ q.immobile?.scala?.name ?? "—" }} |
                          Interno: {{ q.immobile?.interno ?? "—" }} |
                          Piano: {{ q.immobile?.piano ?? "—" }} |
                          Sup: {{ q.immobile?.superficie ?? "—" }} m²
                        </div>
                      </div>
                      
                      <!-- Mostra dropdown per selezionare un nuovo immobile -->
                      <div v-else>
                        <v-select
                          class="w-full vs--wide-dropdown bg-white dark:bg-slate-950 text-sm"
                          :options="immobiliDisponibili"
                          v-model="q.immobile"
                          append-to-body
                          placeholder="Seleziona immobile"
                          :reduce="(i: Immobile) => i"
                          :value="q.immobile"
                          @input="(value: Immobile) => { q.immobile = value }"
                          label="nome"
                          :getOptionLabel="(option: Immobile) => option.nome"
                        >
                          <!-- Template per le opzioni nel dropdown -->
                          <template #option="option">
                            <div class="flex flex-col py-2">
                              <span class="font-medium">{{ option.nome }}</span>
                              <span class="text-xs text-gray-500 mt-1">
                                Palazzina: {{ option.palazzina?.name ?? "—" }} |
                                Scala: {{ option.scala?.name ?? "—" }} |
                                Interno: {{ option.interno ?? "—" }} |
                                Piano: {{ option.piano ?? "—" }} |
                                Sup: {{ option.superficie ?? "—" }} m²
                              </span>
                            </div>
                          </template>

                          <!-- Template per l'opzione selezionata -->
                          <template #selected-option="option">
                            <div v-if="option" class="flex flex-col">
                              <span class="font-medium">{{ option.nome }}</span>
                            </div>
                            <div v-else class="text-gray-400">Seleziona immobile</div>
                          </template>
                        </v-select>
                        <InputError :message="(form.errors as Record<string, string>)[`quote.${idx}.immobile.id`]" />
                      </div>
                    </TableCell>

                    <!-- Millesimi -->
                    <TableCell>
                      <Input
                        v-model="q.valore"
                        class="w-28 bg-white dark:bg-slate-950"
                        :placeholder="valorePlaceholder(props.tabella.numero_decimali)"
                      />
                      <InputError :message="(form.errors as Record<string, string>)[`quote.${idx}.valore`]" />
                    </TableCell>

                    <!-- Solo acqua -->
                    <TableCell v-if="props.tabella.tipo === 'acqua'" class="text-center">
                      <input type="checkbox" v-model="q.has_contatore" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary" />
                    </TableCell>
                    <TableCell v-if="props.tabella.tipo === 'acqua'">
                      <Input v-if="q.has_contatore" v-model="q.ultima_lettura" class="w-28 bg-white dark:bg-slate-950" placeholder="m³" />
                      <Input v-else class="w-28 bg-slate-100 text-gray-400" value="—" disabled />
                    </TableCell>

                    <!-- Solo riscaldamento -->
                    <TableCell v-if="props.tabella.tipo === 'riscaldamento'">
                      <Input v-model="q.quota_fissa" class="w-28 bg-white dark:bg-slate-950" placeholder="%" />
                    </TableCell>
                    <TableCell v-if="props.tabella.tipo === 'riscaldamento'">
                      <Input v-model="q.quota_variabile" class="w-28 bg-white dark:bg-slate-950" placeholder="%" />
                    </TableCell>
                    <TableCell v-if="props.tabella.tipo === 'riscaldamento'">
                      <Input v-model="q.coeff_dispersione" class="w-28 bg-white dark:bg-slate-950" placeholder="Coeff." />
                    </TableCell>

                    <!-- Azioni -->
                    <TableCell class="text-center">
                      <Button size="icon" variant="ghost" @click="removeImmobile(idx)" class="text-red-500 hover:text-red-700 hover:bg-red-50 dark:hover:bg-red-950/50">
                        <Trash2 class="h-4 w-4" />
                      </Button>
                    </TableCell>
                  </TableRow>
                </TableBody>

              </Table>
            </div>
          </CardContent>
        </Card>

        <div class="flex items-center justify-end gap-3">
          <Link
            :href="route(generateRoute('gestionale.tabelle.index'), { condominio: props.condominio.id })"
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
            Salva Quote
          </Button>
        </div>

      </form>
    </div>
  </GestionaleLayout>

  <!-- Dialog se non ci sono immobili disponibili -->
  <AlertDialog v-model:open="showNoImmobiliDialog">
    <AlertDialogContent>
      <AlertDialogHeader>
        <AlertDialogTitle>Attenzione</AlertDialogTitle>
        <AlertDialogDescription>
          {{ alertMessage }}
        </AlertDialogDescription>
      </AlertDialogHeader>
      <AlertDialogFooter>
        <AlertDialogCancel>Chiudi</AlertDialogCancel>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>

</template>

<style src="vue-select/dist/vue-select.css"></style>
