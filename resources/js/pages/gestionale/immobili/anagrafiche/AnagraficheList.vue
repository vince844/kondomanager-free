<script setup lang="ts">
import { computed } from "vue";
import { Head, usePage, Link } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import ImmobileLayout from '@/layouts/gestionale/ImmobileLayout.vue';
import DataTable from '@/components/gestionale/immobili/anagrafiche/DataTable.vue';
import { createColumns } from '@/components/gestionale/immobili/anagrafiche/columns'
import Alert from "@/components/Alert.vue";
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { usePermission } from "@/composables/permissions";
import { UsersRound, ArrowRightLeft, PieChart, UserPlus, List } from 'lucide-vue-next';
import type { BreadcrumbItem } from '@/types';
import type { Flash } from '@/types/flash';
import type { Building } from '@/types/buildings';
import type { Immobile } from '@/types/gestionale/immobili';

const props = defineProps<{
  condominio: Building;
  immobile: Immobile;
}>();
 
const { generatePath, generateRoute } = usePermission();

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: props.condominio.nome, href: '#' },
  { title: 'Immobili', href: generatePath('gestionale/:condominio/immobili', { condominio: props.condominio.id }) },
  { title: props.immobile.nome, href: generatePath('gestionale/:condominio/immobili/:immobile', { condominio: props.condominio.id, immobile: props.immobile.id }) },
  { title: 'Anagrafiche', href: '#' },
]);

// Aggiunta la TERZA card per completare il tris
const pageGuides = computed(() => [
  {
    title: 'Soggetti Associati',
    description: "Visualizza proprietari, inquilini o usufruttuari legati a questa unità immobiliare.",
    icon: UsersRound,
    colorVariant: 'blue' as const
  },
  {
    title: 'Quote di Competenza',
    description: "Verifica la corretta ripartizione della quota di competenza percentuale tra i vari soggetti.",
    icon: PieChart,
    colorVariant: 'emerald' as const
  },
  {
    title: 'Storico Subentri',
    description: "Registra un cambio di inquilino inserendo la data di fine per calcolare i riparti.",
    icon: ArrowRightLeft,
    colorVariant: 'amber' as const
  }
]);

</script>

<template>
  <Head title="Elenco anagrafiche immobile" />

  <GestionaleLayout>
    <div class="px-6 py-8 space-y-4">
      
      <PageHeaderGuide
        page-title="Anagrafiche collegate"
        :page-subtitle="`Gestisci i soggetti associati all'unità immobiliare: ${props.immobile.nome} (Int. ${props.immobile.interno})`"
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
      >
        <template #actions>
          <div class="flex items-center gap-2">
            <Link
              as="button"
              :href="generatePath('gestionale/:condominio/immobili', { condominio: props.condominio.id })"
              class="inline-flex h-8 items-center justify-center gap-2 rounded-md bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 px-4 text-sm font-medium text-slate-700 dark:text-slate-300 shadow-sm hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors"
            >
              <List class="w-3.5 h-3.5" />
              <span>Immobili</span>
            </Link>

            <Link
              :href="route(generateRoute('gestionale.immobili.anagrafiche.create'), { condominio: props.condominio.id, immobile: props.immobile.id })"
              class="inline-flex h-8 items-center justify-center gap-2 rounded-md shadow px-4 bg-primary text-[10px] font-bold uppercase tracking-widest text-primary-foreground hover:bg-primary/90 transition-colors"
            >
              <UserPlus class="w-3.5 h-3.5" />
              <span>Associa soggetto</span>
            </Link> 
          </div>
        </template>
      </PageHeaderGuide>

      <ImmobileLayout>

        <div v-if="flashMessage" class="py-3">
            <Alert :message="flashMessage.message" :type="flashMessage.type" />
        </div>

        <div class="container mx-auto p-0 space-y-4 mt-2">
          <DataTable 
            :columns="createColumns(props.condominio, props.immobile)" 
            :data="props.immobile.anagrafiche"
          />
        </div>

      </ImmobileLayout>

    </div>
  </GestionaleLayout>
</template>