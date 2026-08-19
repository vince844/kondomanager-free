<script setup lang="ts">

import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { usePermission } from "@/composables/permissions";
import { UsersRound, Folders, TextSearch, Link2 } from 'lucide-vue-next';
import type { LinkItem } from '@/types';
import type { Building } from '@/types/buildings';
import type { Immobile } from '@/types/gestionale/immobili';
import NavSezione from '@/components/NavSezione.vue';

const page = usePage<{
  condominio: Building;
  immobile: Immobile
}>();

const condominio = computed(() => page.props.condominio);
const immobile = computed(() => page.props.immobile);

const { generatePath } = usePermission();

const topbarNavItems: LinkItem[] = [
  {
    type: 'link',
    icon: TextSearch,
    title: 'Dettagli',
    // `exact`: questo indirizzo è il **prefisso** di tutte le voci sorelle
    // (`/anagrafiche`, `/documenti`, …), quindi senza confronto esatto resterebbe acceso
    // anche stando altrove — e a video si vedrebbero due voci attive insieme.
    exact: true,
    href:  generatePath('gestionale/:condominio/immobili/:immobile', { condominio: condominio.value.id, immobile: immobile.value.id }),
  },
  {
    type: 'link',
    icon: UsersRound,
    title: 'Anagrafiche',
    href: generatePath('gestionale/:condominio/immobili/:immobile/anagrafiche', { condominio: condominio.value.id, immobile: immobile.value.id }),
  },
  {
    type: 'link',
    icon: Folders,
    title: 'Documenti',
    href: generatePath('gestionale/:condominio/immobili/:immobile/documenti', { condominio: condominio.value.id, immobile: immobile.value.id }),
  },
];

/**
 * La tab «Pertinenze» compare **solo se c'è qualcosa da mostrare**: l'unità ha pertinenze
 * collegate, oppure è essa stessa una pertinenza.
 *
 * ⚠️ Una tab sempre presente e quasi sempre vuota è lo stesso difetto della colonna quasi vuota
 * che abbiamo evitato nell'elenco, spostato altrove: costa attenzione a ogni unità per servirne
 * poche. In un condominio tipico le pertinenze sono una minoranza, e sugli appartamenti senza box
 * questa tab non ha niente da dire.
 *
 * Chi vuole *creare* un legame non passa da qui comunque: il campo sta nella scheda della
 * pertinenza, ed è la pertinenza che punta al principale.
 */
const haPertinenze = computed(() =>
  (immobile.value?.pertinenze_count ?? 0) > 0
  || !!immobile.value?.pertinenza_di_immobile_id
  || !!immobile.value?.pertinenza_di_esterna
);

const vociVisibili = computed<LinkItem[]>(() => haPertinenze.value
  ? [
      ...topbarNavItems,
      {
        type: 'link',
        icon: Link2,
        title: 'Pertinenze',
        href: generatePath('gestionale/:condominio/immobili/:immobile/pertinenze', { condominio: condominio.value.id, immobile: immobile.value.id }),
      } as LinkItem,
    ]
  : topbarNavItems
);

const currentPath = window.location.pathname;

</script>

<template>
  <div>
    <NavSezione :items="vociVisibili" class="mb-4" />

    <!-- Main content -->
    <div class="w-full">
      <section class="w-full">
        <slot />
      </section>
    </div>
  </div>
</template>

