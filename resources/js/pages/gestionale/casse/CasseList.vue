<script setup lang="ts">
import { computed } from "vue";
import { Head, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import StrutturaLayout from '@/layouts/gestionale/StrutturaLayout.vue';
import DataTable from '@/components/gestionale/casse/DataTable.vue';
import { getColumns } from '@/components/gestionale/casse/columns';
import Alert from "@/components/Alert.vue";
import { usePermission } from "@/composables/permissions";
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';

// Icone mirate per la gestione finanziaria e banche
import { Landmark, Wallet, ShieldCheck } from 'lucide-vue-next';

import type { Flash } from '@/types/flash';
import type { Cassa } from '@/types/gestionale/casse';
import type { Building } from '@/types/buildings';
import type { PaginationMeta } from '@/types/pagination';

const props = defineProps<{
  condominio: Building;
  condomini: Building[];
  casse: Cassa[];
  meta: PaginationMeta;
}>()

const { generatePath } = usePermission();

const columns = computed(() => getColumns(props.condominio));

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

// Breadcrumbs testuali per il nuovo componente Header
const headerBreadcrumbs = computed(() => [
  {
    title: trans('gestionale.list_pages.casse.breadcrumbs.management'),
    href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }),
  },
  { title: trans('gestionale.list_pages.casse.breadcrumbs.structure'), href: '#' },
  { title: trans('gestionale.list_pages.casse.breadcrumbs.list') },
]);

// Configurazione della guida per Risorse e Fondi
const pageGuides = computed(() => [
  {
    title: trans('gestionale.list_pages.casse.guides.bank_accounts_title'),
    description: trans('gestionale.list_pages.casse.guides.bank_accounts_description'),
    icon: Landmark,
    colorVariant: 'blue' as const,
  },
  {
    title: trans('gestionale.list_pages.casse.guides.funds_title'),
    description: trans('gestionale.list_pages.casse.guides.funds_description'),
    icon: Wallet,
    colorVariant: 'emerald' as const,
  },
  {
    title: trans('gestionale.list_pages.casse.guides.opening_balances_title'),
    description: trans('gestionale.list_pages.casse.guides.opening_balances_description'),
    icon: ShieldCheck,
    colorVariant: 'amber' as const,
  },
]);
</script>

<template>
  <Head title="Elenco risorse e fondi" />

  <GestionaleLayout>

    <div class="px-6 py-8 space-y-4">
      
      <PageHeaderGuide
        page-title="Risorse e fondi"
        page-subtitle="Configura i conti bancari, le casse e i fondi del condominio. Una corretta impostazione è la base per una tesoreria sana."
        :guides="pageGuides"
        :breadcrumbs="headerBreadcrumbs"
        :video-url="null /* 'https://youtube.com/...' */"
        :condominio="props.condominio"
        :condomini="props.condomini"
      >
      </PageHeaderGuide>

      <div class="w-full">
        <StrutturaLayout>

          <div class="container mx-auto p-0 mt-4 space-y-4">
            
            <div v-if="flashMessage">
                <Alert :message="flashMessage.message" :type="flashMessage.type" />
            </div>

            <div>
              <DataTable 
                :columns="columns" 
                :data="props.casse" 
                :meta="props.meta" 
                :condominio="props.condominio"
              /> 
            </div>

          </div>

        </StrutturaLayout>
      </div>

    </div>

  </GestionaleLayout>
</template>