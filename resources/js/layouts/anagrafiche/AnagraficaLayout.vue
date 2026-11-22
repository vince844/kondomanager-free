<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La barra delle schede dell'anagrafica.
 *
 * Gemella di `layouts/fornitori/FornitoreLayout.vue`, e per la stessa ragione: la scheda di una
 * persona cresce per **sezioni** — dettagli, documenti, assemblee (decisione 651), e domani unità, movimenti, comunicazioni —
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
import { Folders, Gavel, TextSearch } from 'lucide-vue-next';
import NavSezione, { type VoceSezione } from '@/components/NavSezione.vue';
import { Permission } from '@/enums/Permission';

const page = usePage<{ anagrafica: { id: number }; assemblee_riepilogo?: { totale: number } | null }>();

const anagrafica = computed(() => page.props.anagrafica);
const { generatePath, hasPermission } = usePermission();

/**
 * ⚠️ La prima voce è **esatta**, le altre no.
 *
 * `/anagrafiche/5` è il prefisso di `/anagrafiche/5/documenti`: senza `exact` la linguetta
 * «Dettagli» resterebbe accesa anche stando sui documenti, e due linguette accese insieme non
 * dicono più dove sei. È la stessa regola della scheda del fornitore e di quella dell'unità.
 */
const vociNav = computed<VoceSezione[]>(() => [
  {
    icon: TextSearch,
    title: 'Dettagli',
    exact: true,
    href: generatePath('anagrafiche/:anagrafica', { anagrafica: anagrafica.value.id }),
  },
  {
    icon: Folders,
    title: 'Documenti',
    href: generatePath('anagrafiche/:anagrafica/documenti', { anagrafica: anagrafica.value.id }),
  },
  // «Assemblee» (decisione 651): solo lo studio, con «Vede le assemblee»; il numero c'è dove la pagina lo manda (`assemblee_riepilogo`).
  ...(hasPermission([Permission.VIEW_ASSEMBLEE, Permission.MANAGE_ASSEMBLEE])
    ? [{
        icon: Gavel,
        title: 'Assemblee',
        href: generatePath('anagrafiche/:anagrafica/assemblee', { anagrafica: anagrafica.value.id }),
        badge: page.props.assemblee_riepilogo ? String(page.props.assemblee_riepilogo.totale) : undefined,
      }]
    : []),
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
