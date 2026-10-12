<script setup lang="ts">

import { computed, ref } from "vue";
import { Head, router, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import MovimentiLayout from '@/layouts/gestionale/MovimentiLayout.vue';
import DataTable from '@/components/gestionale/movimenti/incassi/DataTable.vue';
import { createColumns } from '@/components/gestionale/movimenti/incassi/columns';
import { usePermission } from "@/composables/permissions";
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { Wallet, Coins, CheckCircle, RotateCcw, AlertTriangle, ChevronDown, Loader2, ExternalLink } from 'lucide-vue-next';
import Alert from "@/components/Alert.vue";
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import type { Building } from '@/types/buildings';
import type { Esercizio } from "@/types/gestionale/esercizi";
import type { Flash } from '@/types/flash';

/** Il rilevamento di `RiallineaCreditiService` (decisioni 75.1 e 76.1, 1.11.0-beta.49). Importi in centesimi. */
interface RiallineamentoCrediti {
  compensazioni: Array<{ scrittura_id: number; protocollo: string | null; data: string | null; quota_id: number; numero_rata: number; persona: string; importo: number }>;
  rate: Array<{ rata_id: number; numero_rata: number; piano: string; azione: 'scrivi' | 'esercizio_chiuso'; persone: Array<{ nome: string; spesa: number; credito: number }> }>;
  da_emettere: Array<{ rata_id: number; numero_rata: number; piano: string; url: string }>;
  restano: Array<{ motivo: string }>;
}

const props = defineProps<{
  condominio: Building;
  condomini: Building[];
  esercizio: Esercizio;
  esercizi: Esercizio[];
  movimenti: { data: any[], meta: any };
  stats: { totale_incassi: number; incassi_mese: number; stornati: number };
  filters: any;
  riallineamento_crediti?: RiallineamentoCrediti | null;
}>();

const { generatePath, generateRoute } = usePermission();
const { euro } = useCurrencyFormatter();
const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash?.message);

// ── Crediti da riallineare ──────────────────────────────────────────────────
// La card compare se il rilevamento trova qualcosa da scrivere, una rata da emettere o voci che restano come sono (queste in sola
// lettura, senza il pulsante: rilievo LDA-8 della Fase 1-bis e 76.3 ritoccata); la conferma scrive solo scritture in aggiunta. Le
// rate «emesse» senza righe non le scrive il riallineamento: si emettono dalla pagina del piano (76.1).
const riallineamento = computed(() => props.riallineamento_crediti ?? null);
const rateDaScrivere = computed(() => (riallineamento.value?.rate ?? []).filter((r) => r.azione === 'scrivi'));
const scrittureDaFare = computed(() => (riallineamento.value?.compensazioni.length ?? 0) + rateDaScrivere.value.length);
const soloLettura = computed(() => scrittureDaFare.value === 0 && (riallineamento.value?.da_emettere.length ?? 0) === 0);

/** Le parti della quota rimasta fuori: la spesa, le entrate dell'anno (una spesa negativa, rilievo K2), il credito riportato. */
function partiDellaQuota(p: { spesa: number; credito: number }): string {
  const parti: string[] = [];
  if (p.spesa > 0) parti.push(`spesa ${euro(p.spesa)}`);
  if (p.spesa < 0) parti.push(`entrate dell'anno ${euro(-p.spesa)}`);
  if (p.credito > 0) parti.push(`credito riportato ${euro(p.credito)}`);
  return parti.join(' e ');
}

// Rilievo LDA-6: il numero con il singolare e il plurale giusti, e solo i tipi di scrittura che ci sono davvero. Le date
// (1.11.0-beta.50): la competenza è quella della scrittura corretta, la registrazione è il giorno in cui si scrive, ed è quella
// che il libro giornale mostra: chi cerca la rettifica alla data della compensazione non la trova.
const testoConferma = computed(() => {
  const compensazioni = riallineamento.value?.compensazioni.length ?? 0;
  const rate = rateDaScrivere.value.length;
  const tipi: string[] = [];
  if (compensazioni > 0) tipi.push(compensazioni === 1 ? 'la rettifica di una compensazione' : `le rettifiche di ${compensazioni} compensazioni`);
  if (rate > 0) tipi.push(rate === 1 ? 'la quota a credito rimasta fuori da una rata' : `le quote a credito rimaste fuori da ${rate} rate`);
  const una = scrittureDaFare.value === 1;
  return (una ? 'Verrà scritta nel libro giornale 1 scrittura: ' : `Verranno scritte nel libro giornale ${scrittureDaFare.value} scritture: `)
    + tipi.join(' e ')
    + `. ${una ? 'Prende' : 'Prendono'} la data di competenza e l'esercizio della scrittura che ${una ? 'corregge' : 'correggono'}; `
    + 'la registrazione è di oggi, ed è la data che il libro giornale mostra. Le scritture esistenti non si modificano.'
    + (compensazioni > 0 ? ' La rettifica di una compensazione segue la compensazione: se l\'incasso si storna, si storna con lei.' : '');
});
// In sola lettura le voci sono tutto ciò che la card ha da dire: l'elenco parte aperto.
const isRiallineamentoListOpen = ref(soloLettura.value);
const isRiallineaModalOpen = ref(false);
const isRiallineaProcessing = ref(false);

const confermaRiallineamento = () => {
  router.post(
    route(generateRoute('gestionale.riallinea-crediti'), { condominio: props.condominio.id }),
    {},
    {
      preserveScroll: true,
      onStart: () => { isRiallineaProcessing.value = true; },
      onFinish: () => {
        isRiallineaProcessing.value = false;
        isRiallineaModalOpen.value = false;
      },
    }
  );
};

const headerBreadcrumbs = computed(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: 'Movimenti' },
  { title: 'Incassi Rate' }
]);

const pageGuides = [
  {
    title: 'Registrazione Rapida',
    description: 'Registra gli incassi delle rate ordinarie e straordinarie. Il sistema calcola in automatico il residuo da saldare per ogni condòmino.',
    icon: Wallet,
    colorVariant: 'blue' as const
  },
  {
    title: 'Pagamenti Parziali',
    description: 'Puoi registrare tranquillamente incassi parziali. Il debito residuo rimarrà aperto e tracciato per i futuri solleciti.',
    icon: Coins,
    colorVariant: 'amber' as const
  },
  {
    title: 'Saldare i Pregressi',
    description: 'Utilizza l\'interfaccia per intercettare e saldare velocemente la quota relativa al "Saldo Iniziale" (il debito dell\'anno precedente).',
    icon: CheckCircle,
    colorVariant: 'emerald' as const
  }
];
</script>

<template>
  <Head title="Elenco Incassi" />

  <GestionaleLayout>
    <div class="px-6 py-8 space-y-3">

      <PageHeaderGuide
        page-title="Incassi pagamenti rate"
        page-subtitle="Registra i pagamenti dei condòmini. Tieni traccia delle rate versate, gestisci i pagamenti parziali e monitora la liquidità in entrata."
        :guides="pageGuides"
        :breadcrumbs="(headerBreadcrumbs as any)"
        :condominio="(props.condominio as any)"
        :condomini="(props.condomini as any)"
        :esercizio="props.esercizio"
        :esercizi="props.esercizi"
      />

      <div class="w-full">
        <section class="w-full space-y-4">
          <MovimentiLayout>

            <!-- ─── STATS ──────────────────────────────────────────── -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">

              <!-- Card: Totale Incassi (statica) -->
              <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm flex items-center gap-4">
                <div class="bg-blue-100 p-2.5 rounded-lg border border-blue-200">
                  <Wallet class="w-5 h-5 text-blue-600" />
                </div>
                <div>
                  <p class="text-xs text-slate-500 font-medium uppercase tracking-wider">Totale Incassi</p>
                  <p class="text-2xl font-black text-slate-900">{{ stats.totale_incassi }}</p>
                </div>
              </div>

              <!-- Card: Incassi Questo Mese (statica) -->
              <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm flex items-center gap-4">
                <div class="bg-emerald-100 p-2.5 rounded-lg border border-emerald-200">
                  <CheckCircle class="w-5 h-5 text-emerald-600" />
                </div>
                <div>
                  <p class="text-xs text-slate-500 font-medium uppercase tracking-wider">Incassi Questo Mese</p>
                  <p class="text-2xl font-black text-slate-900">{{ stats.incassi_mese }}</p>
                </div>
              </div>

              <!-- Card: Stornati (toggle filtro) -->
              <button
                @click="router.visit(route(generateRoute('gestionale.movimenti-rate.index'), { condominio: props.condominio.id, stato: filters.stato === 'annullata' ? undefined : 'annullata' }), { preserveState: true })"
                class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm flex items-center gap-4 transition-all text-left outline-none hover:opacity-100 hover:border-rose-300 hover:ring-2 hover:ring-rose-100 cursor-pointer"
                :class="filters.stato === 'annullata' ? 'ring-2 ring-rose-500 border-rose-500 opacity-100' : 'opacity-80'"
              >
                <div class="bg-slate-100 p-2.5 rounded-lg border border-slate-200" :class="stats.stornati > 0 || filters.stato === 'annullata' ? 'bg-rose-50 border-rose-100' : ''">
                  <RotateCcw class="w-5 h-5 text-slate-500" :class="stats.stornati > 0 || filters.stato === 'annullata' ? 'text-rose-600' : ''" />
                </div>
                <div class="flex-1 min-w-0">
                  <div class="flex items-center gap-2 flex-wrap">
                    <p class="text-xs text-slate-500 font-medium uppercase tracking-wider">Stornati</p>
                  </div>
                  <p class="text-2xl font-black text-slate-900" :class="stats.stornati > 0 || filters.stato === 'annullata' ? 'text-rose-700' : ''">
                    {{ stats.stornati }}
                  </p>
                </div>
              </button>

            </div>
            <!-- ─────────────────────────────────────────────────────── -->

            <div v-if="flashMessage" class="mb-3">
              <Alert :message="flashMessage.message" :type="flashMessage.type" />
            </div>

            <!-- Card «Crediti da riallineare» (1.11.0-beta.49) -->
            <div v-if="riallineamento" class="mb-6 bg-amber-50 border border-amber-200 rounded-xl p-4">
              <!-- Su mobile il pulsante va a capo sotto il testo: accanto lo schiacciava in una colonna di poche parole. -->
              <div class="flex flex-wrap sm:flex-nowrap items-start gap-3">
                <div class="bg-amber-100 p-2 rounded-lg border border-amber-200 shrink-0">
                  <AlertTriangle class="w-5 h-5 text-amber-600" />
                </div>
                <div class="flex-1 min-w-0">
                  <h3 class="text-sm font-bold text-amber-900">Crediti da riallineare</h3>
                  <p v-if="!soloLettura" class="text-xs text-amber-700 mt-1 leading-relaxed">
                    Alcuni crediti riportati da esercizi precedenti sono stati prelevati da «Crediti verso condomini» invece che da
                    «Passate gestioni», o sono rimasti fuori dall'emissione della loro rata (lo facevano le versioni fino alla 1.10).
                    Il riallineamento aggiunge le scritture che mancano: quelle già registrate non si toccano.
                  </p>
                  <p v-else class="text-xs text-amber-700 mt-1 leading-relaxed">
                    Niente da scrivere: il riallineamento lascia queste voci come sono. Qui sotto, per ognuna, il perché e,
                    dove c'è, la strada.
                  </p>

                  <Collapsible v-model:open="isRiallineamentoListOpen" class="mt-3">
                    <CollapsibleTrigger as-child>
                      <button type="button" class="inline-flex items-center gap-1 text-xs font-semibold text-amber-800 hover:text-amber-900">
                        <ChevronDown class="w-3.5 h-3.5 transition-transform" :class="{ 'rotate-180': isRiallineamentoListOpen }" />
                        {{ isRiallineamentoListOpen ? 'Nascondi il dettaglio' : 'Mostra il dettaglio' }}
                      </button>
                    </CollapsibleTrigger>
                    <CollapsibleContent>
                      <ul class="mt-2 space-y-1">
                        <li
                          v-for="c in riallineamento.compensazioni"
                          :key="`c-${c.scrittura_id}-${c.quota_id}`"
                          class="bg-white/60 border border-amber-100 rounded-md px-3 py-1.5 text-xs text-amber-800"
                        >
                          Compensazione prot. {{ c.protocollo ?? '—' }} del {{ c.data ?? '—' }}, {{ c.persona }}, rata {{ c.numero_rata }}:
                          <strong>{{ euro(c.importo) }}</strong> si spostano da «Crediti verso condomini» a «Passate gestioni».
                        </li>
                        <li
                          v-for="r in rateDaScrivere"
                          :key="`r-${r.rata_id}`"
                          class="bg-white/60 border border-amber-100 rounded-md px-3 py-1.5 text-xs text-amber-800"
                        >
                          Rata {{ r.numero_rata }} ({{ r.piano }}), quota a credito rimasta fuori dall'emissione:
                          <span v-for="(p, i) in r.persone" :key="i">
                            {{ i > 0 ? '; ' : '' }}{{ p.nome }}, {{ partiDellaQuota(p) }}
                          </span>.
                        </li>
                        <li
                          v-for="r in riallineamento.da_emettere"
                          :key="`e-${r.rata_id}`"
                          class="bg-white/60 border border-amber-100 rounded-md px-3 py-1.5 text-xs text-amber-800"
                        >
                          Rata {{ r.numero_rata }} ({{ r.piano }}): è segnata «emessa» ma non ha scritture. Si emette dalla pagina del piano,
                          con la data e l'avviso che scegli tu:
                          <a :href="r.url" class="inline-flex items-center gap-1 font-semibold underline underline-offset-2">apri il piano <ExternalLink class="w-3 h-3" /></a>
                        </li>
                        <li
                          v-for="(r, i) in riallineamento.restano"
                          :key="`x-${i}`"
                          class="bg-white/40 border border-amber-100 rounded-md px-3 py-1.5 text-xs text-amber-700"
                        >
                          Resta com'è: {{ r.motivo }}.
                        </li>
                      </ul>
                    </CollapsibleContent>
                  </Collapsible>
                </div>
                <Button
                  v-if="scrittureDaFare > 0"
                  @click="isRiallineaModalOpen = true"
                  class="shrink-0 basis-full sm:basis-auto h-8 text-xs bg-amber-600 hover:bg-amber-700 text-white"
                >
                  Scrivi le correzioni
                </Button>
              </div>
            </div>

            <div>
              <DataTable
                :columns="createColumns(props.condominio.id)"
                :data="props.movimenti.data"
                :meta="props.movimenti.meta || props.movimenti"
                :condominio="props.condominio"
              />
            </div>

          </MovimentiLayout>
        </section>
      </div>

    </div>

    <!-- Conferma del riallineamento dei crediti -->
    <Dialog v-model:open="isRiallineaModalOpen">
      <DialogContent class="sm:max-w-[460px]">
        <DialogHeader>
          <DialogTitle class="flex items-center gap-2 text-amber-600">
            <AlertTriangle class="w-5 h-5" />
            Riallinea i crediti
          </DialogTitle>
          <DialogDescription>
            {{ testoConferma }}
          </DialogDescription>
        </DialogHeader>

        <DialogFooter>
          <Button variant="outline" @click="isRiallineaModalOpen = false" :disabled="isRiallineaProcessing">
            Annulla
          </Button>
          <Button
            class="bg-amber-600 hover:bg-amber-700 text-white"
            @click="confermaRiallineamento"
            :disabled="isRiallineaProcessing"
          >
            <Loader2 v-if="isRiallineaProcessing" class="w-4 h-4 mr-2 animate-spin" />
            Conferma
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </GestionaleLayout>
</template>
