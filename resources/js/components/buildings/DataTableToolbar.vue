<script setup lang="ts">

import { ref } from 'vue';
import { watchDebounced } from '@vueuse/core';
import { Link, router, usePage } from '@inertiajs/vue3';
import { Input } from '@/components/ui/input';
import { Plus, Sparkles, ArrowRight, Trash2, LoaderCircle } from 'lucide-vue-next';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { usePermission } from "@/composables/permissions";
import { useTabellaServer } from '@/composables/useTabellaServer';
import { Permission } from "@/enums/Permission";
import { trans } from 'laravel-vue-i18n';
import type { Table } from '@tanstack/vue-table';
import type { Building } from '@/types/buildings';

interface DataTableToolbarProps {
  table: Table<Building>
}

defineProps<DataTableToolbarProps>();

/**
 * Il condominio dimostrativo.
 *
 * ⚠️ **Un pulsante accanto a «Crea condominio», non un riquadro dentro il modulo.** La prima
 * stesura metteva un pannello tratteggiato in cima alla pagina di creazione: spiegava tutto, ma
 * occupava mezza schermata e si presentava a chi stava già compilando — invadente, e Vincenzo lo ha
 * detto prima ancora che lo vedessi. Qui è discreto, e il modale spiega **prima** di fare invece di
 * far scoprire dopo.
 *
 * La prop arriva dalla pagina (`usePage`) e non a cascata: questa barra è annidata due livelli sotto
 * l'elenco, e passarla a mano vorrebbe dire toccare tre file per un dato che Inertia già espone.
 */
/*
 * ⚠️ **`usePage()` va chiamato dentro il `computed`, non fuori.** Catturando `usePage().props` una
 * volta sola si ottiene una **fotografia**: Inertia, a ogni navigazione, sostituisce l'oggetto, e
 * la copia catturata resta quella vecchia. Effetto misurato: rimosso il condominio dimostrativo, il
 * pulsante continuava a dire «Condominio di esempio» invece di tornare «Crea un condominio di
 * esempio», e tornava giusto solo ricaricando la pagina a mano.
 */
const demo = computed(() => {
  const props = usePage().props as unknown as { condominioDemo?: { id: number; nome: string } | null };

  return props.condominioDemo ?? null;
});

const modaleAperto = ref(false);
const inCorso = ref(false);
const confermaRimozione = ref(false);

const creaDemo = () => {
  inCorso.value = true;
  router.post(route('condomini.dimostrativo.crea'), {}, {
    onFinish: () => { inCorso.value = false; modaleAperto.value = false; },
  });
};

/**
 * ⚠️ **La conferma a due passi non è un vezzo.** Questo è l'unico punto del programma in cui si
 * cancella una contabilità — su un condominio vero i vincoli del database lo impediscono, e qui no
 * perché quei movimenti li ha scritti il programma. Un pulsante che cancella tutto al primo clic,
 * accanto a uno che apre, si preme per sbaglio.
 */
const rimuoviDemo = () => {
  if (!confermaRimozione.value) { confermaRimozione.value = true; return; }
  if (!demo.value) return;

  inCorso.value = true;
  router.delete(route('condomini.dimostrativo.elimina', { condominio: demo.value.id }), {
    onFinish: () => { inCorso.value = false; confermaRimozione.value = false; modaleAperto.value = false; },
  });
};

const nameFilter = ref('')

const { hasPermission } = usePermission();

// Una sola richiesta che porta tutto: filtri, pagina, righe per pagina, ordinamento
const { filtra } = useTabellaServer(() => route('condomini.index'))

// Debounce search input (300ms delay)
watchDebounced(
  nameFilter,
  (newValue) => {
    // Reset filters if empty, otherwise filter
    filtra({
      nome: newValue || null,
    })
  },
  { debounce: 300 }
)

</script>

<template>
  <div class="flex items-center justify-between w-full mb-3">
    <!-- Left Section: Input -->
    <div class="flex items-center space-x-2">
      <div class="flex items-center space-x-2">
        <Input
          :placeholder="trans('condomini.table.filter_by_name')"
          v-model="nameFilter"
          class="h-8 w-[150px] lg:w-[250px]"
        />
      </div>
    </div>

    <!--
      I due pulsanti stanno **insieme a destra**, non uno al centro e uno al margine: sono due
      strade per la stessa cosa — mettere dentro un condominio — e separarle le fa leggere come
      funzioni diverse.
    -->
    <div class="flex items-center gap-2">

    <Link
      as="button"
      v-if="hasPermission([Permission.CREATE_CONDOMINI])"
      :href="route('condomini.create')"
      class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-slate-900 dark:bg-slate-700 border border-slate-800 shadow-sm text-xs font-medium text-white hover:bg-slate-800 dark:hover:bg-slate-600 transition-colors"
    >
      <Plus class="w-3.5 h-3.5 text-green-500" />
      <span>{{ trans('condomini.actions.new_building') }}</span>
    </Link>

    <!--
      Il condominio dimostrativo: stessa fila, peso visivo minore. Chi sa già cosa vuole fare non
      lo nota; chi è appena arrivato lo trova esattamente dove sta guardando.
    -->
    <button
      v-if="hasPermission([Permission.CREATE_CONDOMINI])"
      type="button"
      class="inline-flex items-center gap-2 px-3 py-2 rounded-lg border shadow-sm text-xs font-medium transition-colors"
      :class="demo
        ? 'border-rose-200 bg-rose-50 text-rose-700 hover:bg-rose-100 dark:border-rose-800/60 dark:bg-rose-950/30 dark:text-rose-300'
        : 'border-indigo-200 bg-indigo-50 text-indigo-700 hover:bg-indigo-100 dark:border-indigo-800/60 dark:bg-indigo-950/30 dark:text-indigo-300'"
      @click="modaleAperto = true"
    >
      <!--
        ⚠️ **Colore e verbo cambiano insieme.** Un pulsante che dice «condominio di esempio» quando
        ne esiste già uno non dice cosa succede premendolo: chi arriva lì una seconda volta ha in
        testa una cosa sola — toglierlo. Il rosso lo annuncia prima del testo.
      -->
      <Trash2 v-if="demo" class="w-3.5 h-3.5" />
      <Sparkles v-else class="w-3.5 h-3.5" />
      <span>{{ demo ? 'Rimuovi il condominio di esempio' : 'Crea un condominio di esempio' }}</span>
    </button>

    </div>

  </div>

  <Dialog v-model:open="modaleAperto">
    <DialogContent class="sm:max-w-lg">
      <DialogHeader>
        <div class="mb-1 flex items-center gap-3">
          <div class="rounded-lg p-2" :class="demo ? 'bg-rose-100 text-rose-700 dark:bg-rose-900 dark:text-rose-300' : 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900 dark:text-indigo-300'">
            <Trash2 v-if="demo" class="h-5 w-5" />
            <Sparkles v-else class="h-5 w-5" />
          </div>
          <DialogTitle class="text-xl font-extrabold tracking-tight">
            {{ demo ? 'Rimuovere il condominio di esempio' : 'Un condominio già pronto' }}
          </DialogTitle>
        </div>
        <DialogDescription class="text-[13px] leading-relaxed text-slate-600 dark:text-slate-400">
          {{ demo
            ? 'Sparirà con tutti i dati che contiene. Puoi ricrearlo quando vuoi: sarà identico.'
            : 'Serve a vedere cosa fa KondoManager senza dover inserire tutto a mano.' }}
        </DialogDescription>
      </DialogHeader>

      <div class="space-y-3 text-[13px] leading-relaxed text-slate-700 dark:text-slate-300">
        <p>{{ demo ? 'Contiene:' : 'Il programma ne costruisce uno completo, con dati realistici:' }}</p>
        <ul class="ml-5 list-disc space-y-1">
          <li>quattro unità con proprietari, un inquilino e due comproprietari;</li>
          <li>le tabelle millesimali, compreso il <strong>lastrico diviso secondo l'art. 1126 c.c.</strong> — un terzo a chi ne ha l'uso esclusivo, due terzi agli altri;</li>
          <li>il preventivo e il piano rate;</li>
          <li>due incassi, di cui uno parziale: così si vede anche una morosità;</li>
          <li>una fattura con ritenuta d'acconto e una nota di credito compensata;</li>
          <li>un giroconto che alimenta il fondo lavori.</li>
        </ul>
        <p v-if="!demo" class="text-slate-500 dark:text-slate-400">
          Non tocca gli altri condomini. Puoi modificarlo o rimuoverlo quando vuoi, e finché
          esiste il programma non ne crea un secondo.
        </p>
        <p v-else class="text-slate-500 dark:text-slate-400">
          Nessun altro condominio viene toccato.
        </p>

        <p v-if="confermaRimozione" class="rounded-md bg-rose-50 px-3 py-2 text-[12px] text-rose-800 dark:bg-rose-900/20 dark:text-rose-300">
          Verranno cancellati anche i suoi movimenti contabili. Su un condominio vero non sarebbe
          possibile: qui si può perché quei dati li ha scritti il programma.
        </p>
      </div>

      <DialogFooter class="gap-2 sm:justify-between">
        <Button
          v-if="demo"
          type="button"
          class="gap-2 order-2 sm:order-1"
          :class="confermaRimozione
            ? 'text-white bg-rose-600 hover:bg-rose-700'
            : 'text-rose-700 bg-rose-50 hover:bg-rose-100 dark:text-rose-300 dark:bg-rose-900/20'"
          :disabled="inCorso"
          @click="rimuoviDemo"
        >
          <LoaderCircle v-if="inCorso" class="h-4 w-4 animate-spin" />
          <Trash2 v-else class="h-4 w-4" />
          {{ confermaRimozione ? 'Confermi? Sparisce tutto' : 'Rimuovilo' }}
        </Button>
        <span v-else></span>

        <Link v-if="demo" :href="route('admin.gestionale.index', { condominio: demo.id })">
          <Button type="button" variant="outline" class="gap-2">Aprilo invece <ArrowRight class="h-4 w-4" /></Button>
        </Link>
        <Button v-else type="button" class="gap-2" :disabled="inCorso" @click="creaDemo">
          <LoaderCircle v-if="inCorso" class="h-4 w-4 animate-spin" />
          <Sparkles v-else class="h-4 w-4" />
          {{ inCorso ? 'Lo sto costruendo…' : 'Crea il condominio di esempio' }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
