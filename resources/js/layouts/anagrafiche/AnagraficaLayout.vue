<script setup lang="ts">
/**
 * La barra delle schede dell'anagrafica.
 *
 * Gemella di `layouts/fornitori/FornitoreLayout.vue`, e per la stessa ragione: la scheda di una
 * persona cresce per **sezioni** — dettagli, documenti, e domani unità, movimenti, comunicazioni —
 * e ognuna è una pagina sua con la sua paginazione. Una pagina sola con tutto dentro diventa
 * illeggibile al terzo elenco.
 *
 * ⚠️ **Le voci si aggiungono qui e da nessun'altra parte.** Il layout legge l'anagrafica da
 * `usePage()`, quindi ogni pagina che lo monta deve passare la prop `anagrafica`: senza, la barra
 * sparisce e non c'è nessun errore che lo dica.
 */
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { usePermission } from '@/composables/permissions';
import { Folders, TextSearch } from 'lucide-vue-next';
import NavSezione from '@/components/NavSezione.vue';
import type { LinkItem } from '@/types';

const page = usePage<{ anagrafica: { id: number } }>();

const anagrafica = computed(() => page.props.anagrafica);
const { generatePath } = usePermission();

/**
 * ⚠️ La prima voce è **esatta**, le altre no.
 *
 * `/anagrafiche/5` è il prefisso di `/anagrafiche/5/documenti`: senza `exact` la linguetta
 * «Dettagli» resterebbe accesa anche stando sui documenti, e due linguette accese insieme non
 * dicono più dove sei. È la stessa regola della scheda del fornitore e di quella dell'unità.
 */
const vociNav = computed<LinkItem[]>(() => [
  {
    type: 'link',
    icon: TextSearch,
    title: 'Dettagli',
    exact: true,
    href: generatePath('anagrafiche/:anagrafica', { anagrafica: anagrafica.value.id }),
  },
  {
    type: 'link',
    icon: Folders,
    title: 'Documenti',
    href: generatePath('anagrafiche/:anagrafica/documenti', { anagrafica: anagrafica.value.id }),
  },
]);
</script>

<template>
  <div>
    <NavSezione :items="vociNav" class="mb-4" />

    <div class="w-full">
      <section class="w-full">
        <slot />
      </section>
    </div>
  </div>
</template>
