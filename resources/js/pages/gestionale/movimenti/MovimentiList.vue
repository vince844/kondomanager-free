<script setup lang="ts">
import { computed } from "vue";
import { Head, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import MovimentiLayout from '@/layouts/gestionale/MovimentiLayout.vue';
import Alert from "@/components/Alert.vue";
import { usePermission } from "@/composables/permissions";
import { trans } from 'laravel-vue-i18n';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { ArrowRightLeft, Landmark, Bot } from 'lucide-vue-next';
import type { Flash } from '@/types/flash';
import type { Building } from '@/types/buildings';

const props = defineProps<{
  condominio: Building;
  condomini: Building[];
}>()

const { generatePath } = usePermission();
const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

// Breadcrumbs testuali per il componente Header
const headerBreadcrumbs = computed(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: 'Movimenti' }
]);

// Configurazione della guida per la sezione Movimenti
const pageGuides = [
  {
    title: 'Flussi Finanziari',
    description: 'Registra gli incassi delle rate, il pagamento delle fatture e i giroconti. Ogni movimento alimenta direttamente il bilancio del condominio.',
    icon: ArrowRightLeft,
    colorVariant: 'blue' as const
  },
  {
    title: 'Conti e Tesoreria',
    description: 'Associa sempre i movimenti ai conti bancari o postali corretti per mantenere la riconciliazione allineata con l\'estratto conto reale.',
    icon: Landmark,
    colorVariant: 'emerald' as const
  },
  {
    title: 'Assistente Intelligente',
    description: 'Il sistema controlla in automatico le date di competenza per prevenire errori negli esercizi e suggerisce descrizioni coerenti.',
    icon: Bot,
    colorVariant: 'amber' as const
  }
];
</script>

<template>
  <Head :title="trans('gestionale.list_pages.movimenti.head_title')" />

  <GestionaleLayout>

    <div class="px-6 py-8 space-y-3">
      
      <PageHeaderGuide
        :page-title="trans('gestionale.list_pages.movimenti.page_title')"
        :page-subtitle="trans('gestionale.list_pages.movimenti.page_subtitle')"
        :guides="pageGuides"
        :breadcrumbs="headerBreadcrumbs"
        :video-url="null /* 'https://youtube.com/...' */"
        :condominio="props.condominio"
        :condomini="props.condomini"
      >
      </PageHeaderGuide>

      <div class="w-full">
        <section class="w-full space-y-4">
          
          <div v-if="flashMessage">
              <Alert :message="flashMessage.message" :type="flashMessage.type" />
          </div>

          <MovimentiLayout>
            <div class="border border-slate-200 dark:border-slate-800 rounded-2xl bg-white dark:bg-slate-950 overflow-hidden shadow-sm p-4 min-h-[400px]">
               </div>
          </MovimentiLayout>

        </section>
      </div>

    </div>

  </GestionaleLayout>
</template>
