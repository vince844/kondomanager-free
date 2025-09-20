<script setup lang="ts">
import { ref, computed, onMounted } from "vue";
import { Head, useForm, Link } from "@inertiajs/vue3";
import GestionaleLayout from "@/layouts/GestionaleLayout.vue";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import InputError from '@/components/InputError.vue';
import Heading from '@/components/Heading.vue';
import { List, Plus, LoaderCircle, Trash2 } from 'lucide-vue-next';
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
    console.log('Processing millesimo:', q);
    
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
  { title: 'tabelle', href: generatePath('gestionale/:condominio/tabelle', { condominio: props.condominio.id }) },
  { title: 'millesimi', href: '#' },
  { title: props.tabella.nome, href: '#' },
]);

// Calcola immobili disponibili - FIXED: Use reactive form data instead of raw data
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

const removeImmobile = (index: number) => {
  form.quote.splice(index, 1);
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

  <GestionaleLayout :breadcrumbs="breadcrumbs">
    <div class="px-4 py-6">
      <div class="w-full shadow ring-1 ring-black/5 md:rounded-lg p-4">
        <section class="w-full">

          <Heading 
            :title="`Associa immobli alla tabella - ${props.tabella.nome}`" 
            description="Di seguito puoi specificare i millesimi per ogni immobile associato alla tabella"
          />

          <div class="flex flex-wrap flex-col lg:flex-row lg:justify-end gap-2 items-start lg:items-center mb-4">
            <Button :disabled="form.processing" class="h-8 w-full lg:w-auto" @click="submit">
              <Plus class="w-4 h-4" v-if="!form.processing" />
              <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
              Salva
            </Button>

            <Button class="h-8 w-full lg:w-auto" @click="addImmobile">
              <Plus class="w-4 h-4" />
              Aggiungi immobile
            </Button>

            <Link 
              :href="route(generateRoute('gestionale.tabelle.index'), { condominio: props.condominio.id })" 
              class="inline-flex items-center justify-center gap-2 rounded-md bg-primary text-sm font-medium text-white px-3 py-1.5 h-8 w-full lg:w-auto hover:bg-primary/90"
            >
              <List class="w-4 h-4" />
              <span>Tabelle</span>
            </Link>
          </div>

          <form @submit.prevent="submit" class="space-y-6">
            <!-- Table -->
            <div class="overflow-x-auto">
              <div class="border rounded-lg">
                <Table>
                  
                  <TableHeader>
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
                    <TableRow v-for="(q, idx) in form.quote" :key="q.id ?? idx">

                      <!-- Immobile -->
                      <TableCell>
                        <!-- Mostra come testo se l'immobile è già associato (ha un ID) -->
                        <div v-if="q.id && q.immobile">
                          <div class="font-medium">{{ q.immobile?.nome ?? '—' }}</div>
                          <div class="text-xs text-gray-400">
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
                            class="w-full vs--wide-dropdown"
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
                          <InputError :message="form.errors[`quote.${idx}.immobile.id`]" />
                        </div>
                      </TableCell>

                      <!-- Millesimi -->
                      <TableCell>
                        <Input
                          v-model="q.valore"
                          class="w-28"
                          :placeholder="valorePlaceholder(props.tabella.numero_decimali)"
                        />
                        <InputError :message="form.errors[`quote.${idx}.valore`]" />
                      </TableCell>

                      <!-- Solo acqua -->
                      <TableCell v-if="props.tabella.tipo === 'acqua'" class="text-center">
                        <input type="checkbox" v-model="q.has_contatore" class="h-4 w-4" />
                      </TableCell>
                      <TableCell v-if="props.tabella.tipo === 'acqua'">
                        <Input v-if="q.has_contatore" v-model="q.ultima_lettura" class="w-28" placeholder="m³" />
                        <Input v-else class="w-28 text-gray-400" value="—" disabled />
                      </TableCell>

                      <!-- Solo riscaldamento -->
                      <TableCell v-if="props.tabella.tipo === 'riscaldamento'">
                        <Input v-model="q.quota_fissa" class="w-28" placeholder="%" />
                      </TableCell>
                      <TableCell v-if="props.tabella.tipo === 'riscaldamento'">
                        <Input v-model="q.quota_variabile" class="w-28" placeholder="%" />
                      </TableCell>
                      <TableCell v-if="props.tabella.tipo === 'riscaldamento'">
                        <Input v-model="q.coeff_dispersione" class="w-28" placeholder="Coeff." />
                      </TableCell>

                      <!-- Azioni -->
                      <TableCell class="text-center">
                        <Button size="icon" variant="ghost" @click="removeImmobile(idx)" class="text-red-500 hover:text-red-700">
                          <Trash2 class="h-4 w-4" />
                        </Button>
                      </TableCell>
                    </TableRow>
                  </TableBody>

                </Table>
              </div>
            </div>
          </form>
        </section>
      </div>
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
