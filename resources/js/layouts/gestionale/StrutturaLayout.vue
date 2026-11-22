<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">

import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { usePermission } from "@/composables/permissions";
import { Building2, ArrowUpNarrowWide, TextSearch, Wallet, Coins, HandCoins, Percent, Settings2, History } from 'lucide-vue-next';
import { Permission } from '@/enums/Permission';
import type { LinkItem } from '@/types';
import type { Building } from '@/types/buildings';
import NavSezione from '@/components/NavSezione.vue';

const page = usePage<{
  condominio: Building;
}>();

const condominio = computed(() => page.props.condominio);

const { generatePath, hasPermission, hasRole } = usePermission();

const topbarNavItems: (LinkItem & { breve?: string })[] = [
  { 
    type: 'link',
    icon: TextSearch,
    title: 'Dettagli',
    href:  generatePath('gestionale/:condominio/struttura', { condominio: condominio.value.id }),
  },
  {
    type: 'link',
    icon: Building2,
    title: 'Palazzine',
    href: generatePath('gestionale/:condominio/palazzine', { condominio: condominio.value.id }),
  },
  {
    type: 'link',
    icon: ArrowUpNarrowWide,
    title: 'Scale',
    href: generatePath('gestionale/:condominio/scale', { condominio: condominio.value.id }),
  },
  {
    type: 'link',
    icon: Wallet,
    title: 'Risorse e fondi',
    href: generatePath('gestionale/:condominio/casse', { condominio: condominio.value.id }),
  },
  {
    type: 'link',
    icon: Coins,
    title: 'Saldi Iniziali',
    href: generatePath('gestionale/:condominio/saldi', { condominio: condominio.value.id }),
  },
  {
    type: 'link',
    icon: HandCoins,
    title: 'Già versato',
    href: generatePath('gestionale/:condominio/contributi', { condominio: condominio.value.id }),
  },
  {
    type: 'link',
    icon: Percent,
    title: 'Recupero dei crediti',
    href: generatePath('gestionale/:condominio/recupero-crediti', { condominio: condominio.value.id }),
  },
  {
    type: 'link',
    icon: Settings2,
    title: 'Impostazioni',
    href: generatePath('gestionale/:condominio/impostazioni', { condominio: condominio.value.id }),
  },
  // Il registro delle modifiche del condominio (1.12.0-beta.13, §14): in fondo, solo per chi ha «Vede il registro delle modifiche» (mai i ruoli del portale).
  // Nella riga si chiama «Registro», perché le nove voci stiano su una riga (decisione 570); il nome intero resta al passaggio del mouse e nella tendina.
  ...(hasPermission([Permission.VIEW_REGISTRO_MODIFICHE]) && !hasRole(['utente', 'fornitore']) ? [{
    type: 'link' as const,
    icon: History,
    title: 'Registro delle modifiche',
    breve: 'Registro',
    href: generatePath('gestionale/:condominio/registro-modifiche', { condominio: condominio.value.id }),
  }] : []),
];

const currentPath = window.location.pathname;

</script>

<template>
  <div>
    <!-- Su una riga, come i Movimenti: con la nona voce la barra andava a capo (decisione 570). -->
    <NavSezione :items="topbarNavItems" una-riga class="mb-4" />

    <!-- Main content -->
    <div class="w-full">
      <section class="w-full">
        <slot />
      </section>
    </div>
  </div>
</template>

