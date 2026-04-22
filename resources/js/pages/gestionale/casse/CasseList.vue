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
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: 'Struttura', href: '#' },
  { title: 'Risorse e Fondi' }
]);

// Configurazione della guida per Risorse e Fondi
const pageGuides = [
  {
    title: 'Conti Correnti',
    description: 'Censire correttamente l\'IBAN e i dati della banca è fondamentale per automatizzare la riconciliazione e i pagamenti',
    icon: Landmark,
    colorVariant: 'blue' as const
  },
  {
    title: 'Fondi e Riserve',
    description: 'Oltre al conto principale, puoi gestire casse contanti o fondi accantonati (es. Fondo TFR Portiere o Fondo Morosità).',
    icon: Wallet,
    colorVariant: 'emerald' as const
  },
  {
    title: 'Saldi Iniziali',
    description: 'Assicurati di inserire il saldo di partenza corretto per ogni risorsa per garantire la quadratura perfetta tra software e banca.',
    icon: ShieldCheck,
    colorVariant: 'amber' as const
  }
];
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