<script setup lang="ts">

import { ref, computed } from "vue";
import { Head, useForm, Link } from "@inertiajs/vue3";
import GestionaleLayout from "@/layouts/GestionaleLayout.vue";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
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

// Form separato a seconda del tipo tabella
const form = useForm({
  quote: props.millesimi.map((q) => {
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

    return {}
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

// Calcola immobili disponibili
const immobiliDisponibili = computed(() => {
  const usedIds = form.quote.map((q: any) => q.immobile?.id).filter(Boolean);
  return props.immobili.filter((i) => !usedIds.includes(i.id));
});

const addImmobile = () => {
  if (immobiliDisponibili.value.length === 0) {
    showNoImmobiliDialog.value = true;
    return;
  }

  if (props.tabella.tipo === "acqua") {
    form.quote.push({
      id: null,
      valore: "",
      immobile: null,
      has_contatore: false,
      ultima_lettura: ""
    });
  }

  if (props.tabella.tipo === "riscaldamento") {
    form.quote.push({
      id: null,
      valore: "",
      immobile: null,
      coeff_dispersione: "",
      quota_fissa: "",
      quota_variabile: ""
    });
  }
};

const removeImmobile = (index: number) => {
  form.quote.splice(index, 1);
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
    <TableHead>Immobile</TableHead>
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
                        <div v-if="q.immobile">
                          <div class="font-medium">{{ q.immobile.nome }}</div>
                          <div class="text-xs text-gray-400">
                            Palazzina: {{ q.immobile?.palazzina?.name ?? "—" }} |
                            Scala: {{ q.immobile?.scala?.name ?? "—" }} |
                            Interno: {{ q.immobile.interno ?? "—" }} |
                            Piano: {{ q.immobile?.piano ?? "—" }} |
                            Sup: {{ q.immobile?.superficie ?? "—" }} m²
                          </div>
                        </div>
                        <div v-else>
                          <v-select class="w-full"
                            :options="immobiliDisponibili"
                            v-model="q.immobile"
                            append-to-body
                            placeholder="Seleziona immobile"
                            :reduce="(i: Immobile) => i"
                          />
                        </div>
                      </TableCell>

                      <!-- Millesimi -->
                      <TableCell>
                        <Input v-model="q.valore" class="w-28" placeholder="0.00" />
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
        <AlertDialogTitle>Nessun immobile disponibile</AlertDialogTitle>
        <AlertDialogDescription>
          Tutti gli immobili sono già stati associati a questa tabella.
        </AlertDialogDescription>
      </AlertDialogHeader>
      <AlertDialogFooter>
        <AlertDialogCancel>Chiudi</AlertDialogCancel>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>
</template>
