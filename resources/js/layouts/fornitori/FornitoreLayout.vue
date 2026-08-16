<script setup lang="ts">

import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { usePermission } from "@/composables/permissions";
import { UsersRound, Folders, TextSearch, Wallet } from 'lucide-vue-next';
import type { LinkItem } from '@/types';
import type { Fornitore } from '@/types/fornitori';
import NavSezione from '@/components/NavSezione.vue';

const page = usePage<{
  fornitore: Fornitore;
}>();

const fornitore = computed(() => page.props.fornitore);
const { generatePath } = usePermission();

const topbarNavItems: LinkItem[] = [
  {
    type: 'link',
    icon: TextSearch,
    title: 'Dettagli',
    href:  generatePath('fornitori/:fornitore', { fornitore: fornitore.value.id }),
  },
  {
    type: 'link',
    icon: UsersRound,
    title: 'Referenti',
    href:  generatePath('fornitori/:fornitore/anagrafiche', { fornitore: fornitore.value.id }),
  },
  {
    type: 'link',
    icon: Wallet,
    title: 'Situazione debitoria',
    href:  generatePath('fornitori/:fornitore/situazione-debitoria', { fornitore: fornitore.value.id }),
  },
  {   
    type: 'link',
    icon: Folders,
    title: 'Documenti',
    href:  generatePath('fornitori/:fornitore/documenti', { fornitore: fornitore.value.id }),
  }, 
];

const currentPath = window.location.pathname;


/**
 * La voce di base della scheda si accende **solo** sul suo percorso: «Fornitore» sta a
 * `/fornitori/5`, le sorelle a `/fornitori/5/documenti`. Prima la regola era un ternario scritto a
 * mano dentro `:class` nel template; ora è un dato della voce, che il componente sa leggere.
 */
const vociNav = computed(() =>
  topbarNavItems.map((item, indice) => ({ ...item, exact: indice === 0 }))
);

</script>

<template>
  <div >
    <NavSezione :items="vociNav" class="mb-4" />

    <!-- Main content -->
    <div class="w-full">
      <section class="w-full">
        <slot />
      </section>
    </div>
  </div>
</template>

