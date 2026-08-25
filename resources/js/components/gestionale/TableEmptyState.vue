<script setup lang="ts">
/**
 * Stato vuoto di un elenco del gestionale.
 *
 * Distingue due situazioni che le tabelle confondevano in un unico
 * «Nessun risultato trovato»:
 *
 *   - l'elenco è vuoto perché non è ancora stato creato niente
 *     → icona, titolo e l'indicazione di cosa fare per cominciare;
 *   - l'elenco è vuoto perché la ricerca non ha prodotto nulla
 *     → un messaggio diverso, e nessun invito a creare.
 *
 * Tenerle unite peggiora le cose invece di migliorarle: chi cerca «Rossi» e non
 * lo trova si vedrebbe proporre «crea la prima gestione», come se il condominio
 * fosse vuoto.
 *
 * La paginazione è lato server, quindi `meta.total` vale 0 in entrambi i casi e
 * non serve a distinguerli. L'unico segnale affidabile è la query string: le
 * toolbar degli elenchi ci scrivono i filtri (oggi solo `nome`).
 */

import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Empty, EmptyHeader, EmptyMedia, EmptyTitle, EmptyDescription } from '@/components/ui/empty';
import { SearchX } from 'lucide-vue-next';

const props = withDefaults(defineProps<{
  /** Icona dello stato "non c'è ancora niente". */
  icon: any;
  /** Titolo dello stato "non c'è ancora niente". */
  title: string;
  /** Cosa fare per cominciare. */
  description: string;
  /** Chiavi di filtro da cercare nella query string. */
  filterKeys?: string[];
  /** Classi del cerchio dell'icona, per restare in tinta con la sezione. */
  mediaClass?: string;
}>(), {
  filterKeys: () => ['nome'],
  mediaClass: 'bg-indigo-50/50 dark:bg-indigo-900/20 text-indigo-500',
});

const page = usePage();

const ricercaAttiva = computed(() => {
  const query = page.url.split('?')[1];
  if (!query) return false;

  const params = new URLSearchParams(query);

  return props.filterKeys.some((k) => (params.get(k) ?? '').trim() !== '');
});

const termineCercato = computed(() => {
  const query = page.url.split('?')[1];
  if (!query) return '';

  const params = new URLSearchParams(query);

  for (const k of props.filterKeys) {
    const v = (params.get(k) ?? '').trim();
    if (v !== '') return v;
  }

  return '';
});
</script>

<template>
  <Empty class="border border-dashed py-12">
    <EmptyHeader class="max-w-4xl">
      <EmptyMedia
        variant="icon"
        :class="ricercaAttiva ? 'bg-slate-100/70 dark:bg-slate-800/50 text-slate-500' : mediaClass"
      >
        <component :is="ricercaAttiva ? SearchX : icon" class="w-8 h-8" />
      </EmptyMedia>

      <EmptyTitle>
        {{ ricercaAttiva ? 'Nessun risultato per questa ricerca' : title }}
      </EmptyTitle>

      <EmptyDescription>
        <template v-if="ricercaAttiva">
          Nessuna corrispondenza per <strong>«{{ termineCercato }}»</strong>. <br>
          Prova con un termine diverso, oppure svuota il campo di ricerca per rivedere l'elenco completo.
        </template>
        <template v-else>
          {{ description }}
        </template>
      </EmptyDescription>
    </EmptyHeader>
  </Empty>
</template>
