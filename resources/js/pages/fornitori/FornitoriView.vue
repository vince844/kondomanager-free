<script setup lang="ts">
import { computed } from "vue";
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import FornitoreLayout from '@/layouts/fornitori/FornitoreLayout.vue';
import Alert from "@/components/Alert.vue";
import { usePermission } from "@/composables/permissions";
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { trans } from 'laravel-vue-i18n';
import { 
  Pencil, 
  ShieldCheck, 
  ShieldOff, 
  MapPin, 
  Phone, 
  Mail, 
  Globe, 
  ReceiptEuro, 
  Percent,
  Contact,
  Landmark,
  Building2
} from 'lucide-vue-next';
import type { Flash } from '@/types/flash';
import type { Fornitore } from '@/types/fornitori';
import type { BreadcrumbItem } from '@/types'; // Assicurati che sia importato

const props = defineProps<{
  fornitore: Fornitore;
}>()

const { generatePath, generateRoute } = usePermission();

const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

const breadcrumbs: BreadcrumbItem[] = [
  {
      title: 'Fornitori',
      href: route(generateRoute('fornitori.index'))
  },
  {
      title: 'Dettaglio Anagrafica',
      href: '#',
  }
];

const pageGuides = computed(() => [
  {
    title: 'Recapiti e contatti',
    description: 'Visualizza indirizzo, telefoni, email e sito web del fornitore. Usa il pulsante Modifica per aggiornare i dati.',
    icon: Contact,
    colorVariant: 'blue' as const
  },
  {
    title: 'Tesoreria e pagamenti',
    description: 'Controlla IBAN, modalità di pagamento e la presenza di ritenuta d\'acconto prima di registrare una fattura.',
    icon: Landmark,
    colorVariant: 'amber' as const
  },
  {
    title: 'Dati societari',
    description: 'Verifica certificazioni ISO, iscrizioni a camere di commercio e albi professionali per la conformità normativa.',
    icon: Building2,
    colorVariant: 'emerald' as const
  }
]);

const indirizzoCompleto = computed(() => {
  const parts = [];
  if (props.fornitore.indirizzo) parts.push(props.fornitore.indirizzo);
  if (props.fornitore.comune) parts.push(props.fornitore.comune);
  if (props.fornitore.provincia) parts.push(`(${props.fornitore.provincia})`);
  if (props.fornitore.cap) parts.push(props.fornitore.cap);
  return parts.length > 0 ? parts.join(', ') : null;
});

const stati = [
  { value: 'attivo',  label: 'Attivo',  active: 'text-emerald-600 dark:text-emerald-400' },
  { value: 'sospeso', label: 'Sospeso', active: 'text-amber-600 dark:text-amber-400' },
  { value: 'cessato', label: 'Cessato', active: 'text-rose-600 dark:text-rose-400' },
];

const statoDot = computed(() => {
  switch(props.fornitore.stato) {
    case 'attivo':  return 'bg-emerald-500';
    case 'sospeso': return 'bg-amber-500';
    case 'cessato': return 'bg-rose-500';
    default:        return 'bg-emerald-500';
  }
});
</script>

<template>
  <AppLayout>
    <Head title="Dettagli fornitore" />

    <div class="px-6 py-8 space-y-4">

      <div v-if="flashMessage" class="mb-6">
        <Alert :message="flashMessage.message" :type="flashMessage.type" />
      </div>

      <PageHeaderGuide
        :page-title="fornitore.ragione_sociale"
        :page-subtitle="`${fornitore.categoria?.name ?? 'Fornitore'} · P.IVA ${fornitore.partita_iva ?? '—'}`"
        :guides="pageGuides"
        :breadcrumbs="breadcrumbs"
        :video-url="null"
        :back-url="generatePath('fornitori')"
        back-text="Indietro"
      >
        <template #actions>
          
          <div class="flex items-center p-0.5 bg-slate-100 dark:bg-slate-900/60 rounded-lg border border-slate-200 dark:border-slate-800">
            <span
              v-for="s in stati"
              :key="s.value"
              class="px-3.5 py-1 text-[11px] font-bold uppercase tracking-widest rounded-md transition-all duration-200 select-none cursor-default"
              :class="fornitore.stato === s.value
                ? `bg-white dark:bg-slate-800 shadow-sm ring-1 ring-slate-200/60 dark:ring-slate-700 ${s.active}`
                : 'text-slate-400 dark:text-slate-500'"
            >
              <span v-if="fornitore.stato === s.value" class="inline-flex items-center gap-1.5">
                <span :class="['w-1.5 h-1.5 rounded-full', statoDot]"></span>
                {{ s.label }}
              </span>
              <span v-else>{{ s.label }}</span>
            </span>
          </div>

          <Link
            as="button"
            :href="generatePath('fornitori/:fornitore/edit', { fornitore: props.fornitore.id })"
            class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-slate-800 dark:bg-slate-700 border border-transparent shadow-sm text-xs font-medium text-white hover:bg-slate-700 dark:hover:bg-slate-600 transition-colors cursor-pointer"
   
          >
            <Pencil class="w-3.5 h-3.5" />
            Modifica dati
          </Link>
          
        </template>
      </PageHeaderGuide>

      <div class="w-full">
        <FornitoreLayout>

          <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 pb-16">

            <div class="lg:col-span-2 space-y-6">

              <div class="rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/50">
                <div class="px-5 py-3.5 border-b border-slate-200/80 dark:border-slate-700/50">
                  <h2 class="text-[11px] font-bold uppercase tracking-widest text-slate-500 dark:text-slate-400">Recapiti e Contatti</h2>
                </div>
                <div class="divide-y divide-slate-200/60 dark:divide-slate-700/40">

                  <div v-if="indirizzoCompleto" class="flex items-start gap-3 px-5 py-3.5">
                    <MapPin class="w-4 h-4 text-slate-400 mt-0.5 shrink-0" />
                    <span class="text-sm text-slate-700 dark:text-slate-300">{{ indirizzoCompleto }}</span>
                  </div>
                  <div v-if="fornitore.telefono" class="flex items-center gap-3 px-5 py-3.5">
                    <Phone class="w-4 h-4 text-slate-400 shrink-0" />
                    <span class="text-sm text-slate-700 dark:text-slate-300">{{ fornitore.telefono }}</span>
                    <span class="text-xs text-slate-400 ml-auto">Fisso</span>
                  </div>
                  <div v-if="fornitore.cellulare" class="flex items-center gap-3 px-5 py-3.5">
                    <Phone class="w-4 h-4 text-slate-400 shrink-0" />
                    <span class="text-sm text-slate-700 dark:text-slate-300">{{ fornitore.cellulare }}</span>
                    <span class="text-xs text-slate-400 ml-auto">Cellulare</span>
                  </div>
                  <div v-if="fornitore.email" class="flex items-center gap-3 px-5 py-3.5">
                    <Mail class="w-4 h-4 text-slate-400 shrink-0" />
                    <a :href="`mailto:${fornitore.email}`" class="text-sm text-slate-700 dark:text-slate-300 hover:text-primary transition-colors">{{ fornitore.email }}</a>
                  </div>
                  <div v-if="fornitore.pec" class="flex items-center gap-3 px-5 py-3.5">
                    <Mail class="w-4 h-4 text-amber-400 shrink-0" />
                    <span class="text-sm text-slate-700 dark:text-slate-300">{{ fornitore.pec }}</span>
                    <span class="ml-auto text-[10px] font-bold uppercase tracking-wider text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20 px-2 py-0.5 rounded-md">PEC</span>
                  </div>
                  <div v-if="fornitore.sito_web" class="flex items-center gap-3 px-5 py-3.5">
                    <Globe class="w-4 h-4 text-slate-400 shrink-0" />
                    <a :href="fornitore.sito_web" target="_blank" class="text-sm text-slate-700 dark:text-slate-300 hover:text-primary transition-colors">{{ fornitore.sito_web }}</a>
                  </div>
                  <div v-if="!indirizzoCompleto && !fornitore.telefono && !fornitore.cellulare && !fornitore.email && !fornitore.pec && !fornitore.sito_web"
                    class="px-5 py-4 text-sm text-slate-400 italic">
                    Nessun recapito registrato.
                  </div>

                </div>
              </div>

              <div class="rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/50">
                <div class="px-5 py-3.5 border-b border-slate-200/80 dark:border-slate-700/50">
                  <h2 class="text-[11px] font-bold uppercase tracking-widest text-slate-500 dark:text-slate-400">Dati Societari e Iscrizioni</h2>
                </div>
                <div class="grid grid-cols-2 divide-x divide-y divide-slate-200/60 dark:divide-slate-700/40">
                  <div class="px-5 py-4">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block mb-1">Codice Fiscale</span>
                    <span class="text-sm text-slate-800 dark:text-slate-200">{{ fornitore.codice_fiscale || '—' }}</span>
                  </div>
                  <div class="px-5 py-4">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block mb-1">Codice ATECO</span>
                    <span class="text-sm text-slate-800 dark:text-slate-200">{{ fornitore.codice_ateco || '—' }}</span>
                  </div>
                  <div class="px-5 py-4">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block mb-1">Iscrizione CCIAA</span>
                    <span class="text-sm text-slate-800 dark:text-slate-200">{{ fornitore.iscrizione_cciaa || '—' }}</span>
                  </div>
                  <div class="px-5 py-4">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block mb-1">Data Iscrizione</span>
                    <span class="text-sm text-slate-800 dark:text-slate-200">{{ fornitore.data_iscrizione_cciaa || '—' }}</span>
                  </div>
                  <div class="px-5 py-4">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block mb-1">Albo / Ordine</span>
                    <span class="text-sm text-slate-800 dark:text-slate-200">{{ fornitore.numero_iscrizione_ordine || '—' }}</span>
                  </div>
                  <div class="px-5 py-4">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block mb-1">Capitale Sociale</span>
                    <span class="text-sm text-slate-800 dark:text-slate-200"> {{ fornitore.capitale_sociale ?? '0,00' }}</span>
                  </div>
                </div>
                <div class="px-5 py-3.5 border-t border-slate-200/60 dark:border-slate-700/40">
                  <div v-if="fornitore.certificazione_iso" class="inline-flex items-center gap-2 text-xs font-semibold text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800/40 rounded-lg px-3 py-1.5">
                    <ShieldCheck class="w-3.5 h-3.5" />
                    Certificazione ISO attiva
                  </div>
                  <div v-else class="inline-flex items-center gap-2 text-xs text-slate-400 dark:text-slate-500">
                    <ShieldOff class="w-3.5 h-3.5" />
                    Nessuna certificazione ISO
                  </div>
                </div>
              </div>

            </div>

            <div class="space-y-6">

              <div class="rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/50">
                <div class="px-5 py-3.5 border-b border-slate-200/80 dark:border-slate-700/50">
                  <h2 class="text-[11px] font-bold uppercase tracking-widest text-slate-500 dark:text-slate-400">Tesoreria e Pagamenti</h2>
                </div>
                <div class="px-5 py-4 border-b border-slate-200/60 dark:border-slate-700/40">
                  <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 flex items-center gap-1.5 mb-2">
                    <ReceiptEuro class="w-3 h-3" /> IBAN Principale
                  </span>
                  <span v-if="fornitore.iban_principale" class="text-sm text-slate-800 dark:text-slate-200 break-all leading-relaxed block">
                    {{ fornitore.iban_principale }}
                  </span>
                  <span v-else class="text-sm text-slate-400 italic">Non registrato</span>
                </div>
                <div class="grid grid-cols-2 divide-x divide-slate-200/60 dark:divide-slate-700/40 border-b border-slate-200/60 dark:border-slate-700/40">
                  <div class="px-5 py-4">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block mb-1">Metodo</span>
                    <span class="text-sm capitalize text-slate-800 dark:text-slate-200">{{ fornitore.modalita_pagamento_default || 'Bonifico' }}</span>
                  </div>
                  <div class="px-5 py-4">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block mb-1">Scadenza</span>
                    <span class="text-sm text-slate-800 dark:text-slate-200">{{ fornitore.giorni_scadenza || '0' }} gg</span>
                  </div>
                </div>
                <div v-if="fornitore.soggetto_ritenuta" class="px-5 py-4">
                  <div class="flex items-center gap-2 text-xs font-semibold text-amber-700 dark:text-amber-400 mb-3">
                    <Percent class="w-3.5 h-3.5" />
                    Ritenuta d'Acconto
                  </div>
                  <div class="grid grid-cols-3 gap-2">
                    <div class="bg-amber-50 dark:bg-amber-900/10 border border-amber-100 dark:border-amber-900/20 rounded-lg px-2 py-2.5 text-center">
                      <span class="text-[9px] font-bold uppercase tracking-wider text-amber-600/70 dark:text-amber-500/60 block mb-1">Aliquota</span>
                      <span class="font-bold text-sm text-amber-900 dark:text-amber-400">{{ fornitore.perc_ritenuta || '0' }}%</span>
                    </div>
                    <div class="bg-amber-50 dark:bg-amber-900/10 border border-amber-100 dark:border-amber-900/20 rounded-lg px-2 py-2.5 text-center">
                      <span class="text-[9px] font-bold uppercase tracking-wider text-amber-600/70 dark:text-amber-500/60 block mb-1">Impon.</span>
                      <span class="font-bold text-sm text-amber-900 dark:text-amber-400">{{ fornitore.perc_imponibile_ritenuta || '100' }}%</span>
                    </div>
                    <div class="bg-amber-50 dark:bg-amber-900/10 border border-amber-100 dark:border-amber-900/20 rounded-lg px-2 py-2.5 text-center">
                      <span class="text-[9px] font-bold uppercase tracking-wider text-amber-600/70 dark:text-amber-500/60 block mb-1">Tributo</span>
                      <span class="font-bold text-sm text-amber-900 dark:text-amber-400">{{ fornitore.codice_tributo || '—' }}</span>
                    </div>
                  </div>
                </div>
                <div v-else class="px-5 py-3.5 text-xs text-slate-400 italic">
                  Non soggetto a ritenuta d'acconto.
                </div>
              </div>

              <div class="rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/50">
                <div class="px-5 py-3.5 border-b border-slate-200/80 dark:border-slate-700/50">
                  <h2 class="text-[11px] font-bold uppercase tracking-widest text-slate-500 dark:text-slate-400">Note Interne</h2>
                </div>
                <div class="px-5 py-4">
                  <p v-if="fornitore.note" class="text-sm text-slate-700 dark:text-slate-300 leading-relaxed whitespace-pre-wrap">
                    {{ fornitore.note }}
                  </p>
                  <p v-else class="text-sm text-slate-400 italic">
                    Nessuna nota aggiuntiva.
                  </p>
                </div>
              </div>

            </div>
          </div>

        </FornitoreLayout>
      </div>

    </div>
  </AppLayout>
</template>