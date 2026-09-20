<script setup lang="ts">
/**
 * Il cancello (2) della decisione 14 (`docs/subentro_e_competenza_temporale.md`): la risoluzione dei
 * titolari **per periodo** ha cambiato dei destinatari o dei pesi rispetto a quella atemporale, e la
 * generazione si è fermata per dirlo prima di scrivere.
 *
 * Stesso schema di `ScopertoWarning.vue` — riquadro ambra, tabella di chi e perché, nota di almeno
 * dieci caratteri, «Procedi comunque» — perché l'amministratore ha già imparato a leggerlo lì. Le due
 * forme che il motore registra:
 *
 * - `pro_rata_giorni`: sulla coppia (unità, ruolo) qualcuno entra o esce nel periodo di competenza, e la
 *   spesa si divide in proporzione ai giorni (D8). La riga mostra i titolari con i giorni di ciascuno.
 * - `fuori_periodo`: un titolare che il calcolo atemporale avrebbe fatto pagare è escluso dal periodo
 *   (chiuso prima, o decorrente dopo con un predecessore chiuso: D7).
 *
 * Tema **chiaro** come `ScopertoWarning` (nessuna classe `dark:`), e per la stessa ragione: il badge
 * dei ruoli viaggia con `tema="chiaro"`.
 */
import { computed, ref } from 'vue';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import BadgeRuolo from '@/components/gestionale/immobili/BadgeRuolo.vue';
import { CalendarClock } from 'lucide-vue-next';

export interface DestinatarioCambiato {
  immobile_id: number | null;
  immobile_nome: string | null;
  tipologia: string;
  conto_id: number | null;
  conto_nome: string | null;
  motivo: 'pro_rata_giorni' | 'fuori_periodo';
  gradino: string | null;
  periodo: { dal: string; al: string }[];
  righe?: { riga_id: number; giorni: number; anagrafica_id: number | null; anagrafica_nome: string | null; data_inizio: string | null; data_fine: string | null; quota: number | string | null }[];
  anagrafiche_escluse?: { anagrafica_id: number; anagrafica_nome: string }[];
  /** Decisione 22: i giorni del periodo, quelli in cui nessuno del ruolo era in vigore, e dove sono andati. */
  giorni_periodo?: number;
  giorni_scoperti?: number;
  ripiego?: { ruolo: string | null; giorni_residui: number; righe: { riga_id: number; giorni: number; anagrafica_nome?: string | null }[] } | null;
}

const RUOLI: Record<string, string> = { proprietario: 'proprietario', nuda_proprietario: 'nudo proprietario', usufruttuario: 'usufruttuario', inquilino: 'inquilino' };

const props = defineProps<{
  destinatari: DestinatarioCambiato[];
  processing?: boolean;
}>();

const emit = defineEmits<{
  (e: 'procedi', nota: string): void
}>();

const nota = ref('');
const canProceed = computed(() => nota.value.trim().length >= 10);

function handleProcedi() {
  if (canProceed.value) emit('procedi', nota.value.trim());
}

const GRADINI: Record<string, string> = {
  dichiarata: 'competenza dichiarata',
  delibera: 'data della delibera',
  capitolo: 'competenza del capitolo',
  gestione: 'periodo della gestione',
  esercizio: 'periodo dell\'esercizio',
};

const dataBreve = (iso: string | null) => (iso ? iso.split('-').reverse().join('/') : '—');
const periodoBreve = (p: { dal: string; al: string }[]) => p.map(t => `${dataBreve(t.dal)}–${dataBreve(t.al)}`).join(' + ');

const unita = computed(() => new Set(props.destinatari.map(d => d.immobile_id)).size);
</script>

<template>
  <div class="rounded-lg border-2 border-amber-300 bg-amber-50 shadow-sm overflow-hidden mt-6 mb-6">
    <div class="p-4 border-b border-amber-200 bg-amber-100/50 flex items-start gap-3">
      <CalendarClock class="w-6 h-6 text-amber-600 shrink-0 mt-0.5" />
      <div>
        <h3 class="font-bold text-amber-900 text-base">
          Il periodo di competenza cambia chi paga su {{ unita }} {{ unita === 1 ? 'unità' : 'unità' }}
        </h3>
        <p class="text-sm text-amber-800 mt-1">
          Su queste unità un titolare è entrato o uscito dentro il periodo della spesa: la quota si divide
          <strong>in proporzione ai giorni</strong> fra chi c'era, e chi era fuori periodo non paga. Le rate
          già emesse non si toccano. Se procedi, la registrazione lo scrive riga per riga (periodo, gradino,
          giorni), così il riparto stampato dice come è stato deciso.
        </p>
      </div>
    </div>

    <div class="p-0 overflow-x-auto">
      <table class="w-full text-sm text-left">
        <thead class="bg-amber-100/30 text-amber-900 text-xs uppercase font-semibold">
          <tr>
            <th class="px-4 py-2">Unità · ruolo</th>
            <th class="px-4 py-2">Voce di spesa</th>
            <th class="px-4 py-2">Periodo (gradino)</th>
            <th class="px-4 py-2">Chi paga, e per quanti giorni</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-amber-100 bg-white/50">
          <tr v-for="(d, i) in destinatari" :key="i" class="align-top hover:bg-amber-50/50">
            <td class="px-4 py-2">
              <div class="font-medium text-slate-900">{{ d.immobile_nome ?? '—' }}</div>
              <BadgeRuolo :ruolo="d.tipologia" taglia="sm" tema="chiaro" class="mt-1" />
            </td>
            <td class="px-4 py-2 text-slate-700">{{ d.conto_nome ?? '—' }}</td>
            <td class="px-4 py-2 text-slate-700 whitespace-nowrap">
              <div class="tabular-nums">{{ periodoBreve(d.periodo) }}</div>
              <div class="text-[11px] text-slate-500">{{ GRADINI[d.gradino ?? ''] ?? d.gradino ?? '' }}</div>
            </td>
            <td class="px-4 py-2 text-slate-700">
              <template v-if="d.motivo === 'pro_rata_giorni'">
                <div v-for="r in d.righe" :key="r.riga_id" class="flex flex-wrap items-baseline gap-x-2">
                  <span class="font-medium text-slate-900">{{ r.anagrafica_nome ?? '—' }}</span>
                  <span class="text-[11px] text-slate-500">{{ dataBreve(r.data_inizio) }} → {{ r.data_fine ? dataBreve(r.data_fine) : 'in corso' }}</span>
                  <span class="tabular-nums font-semibold" :class="r.giorni === 0 ? 'text-slate-400' : 'text-amber-800'">{{ r.giorni }}<template v-if="d.giorni_periodo"> su {{ d.giorni_periodo }}</template> {{ r.giorni === 1 && !d.giorni_periodo ? 'giorno' : 'giorni' }}</span>
                </div>
                <!-- Decisione 22: i giorni in cui nessuno del ruolo era in vigore, e a chi sono andati. -->
                <div v-if="d.giorni_scoperti" class="mt-1 flex flex-wrap items-baseline gap-x-2 text-[11px]">
                  <span class="font-medium text-amber-900">nessun {{ RUOLI[d.tipologia] ?? d.tipologia }} per {{ d.giorni_scoperti }} {{ d.giorni_scoperti === 1 ? 'giorno' : 'giorni' }}</span>
                  <template v-if="d.ripiego?.ruolo">
                    <span class="text-slate-600">→ a carico del {{ RUOLI[d.ripiego.ruolo] ?? d.ripiego.ruolo }}<template v-if="d.ripiego.righe?.length">: {{ d.ripiego.righe.map(r => `${r.anagrafica_nome ?? '—'} (${r.giorni} gg)`).join(', ') }}</template></span>
                  </template>
                  <span v-if="d.ripiego && d.ripiego.giorni_residui > 0" class="font-semibold text-red-700">{{ d.ripiego.ruolo ? `restano ${d.ripiego.giorni_residui} giorni senza nessuno` : 'nessuno a cui addebitarli' }}: la parte è scoperta e la generazione chiede una motivazione</span>
                </div>
              </template>
              <template v-else>
                <div v-for="a in d.anagrafiche_escluse" :key="a.anagrafica_id" class="flex flex-wrap items-baseline gap-x-2">
                  <span class="font-medium text-slate-900">{{ a.anagrafica_nome }}</span>
                  <span class="text-[11px] text-slate-500">fuori dal periodo: non paga questa voce</span>
                </div>
              </template>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="p-4 bg-amber-50 border-t border-amber-200 space-y-4">
      <div class="space-y-2">
        <label for="nota_destinatari" class="block text-sm font-semibold text-amber-900">Ho letto chi cambia. Perché procedo così?</label>
        <p class="text-xs text-amber-700">La nota (almeno 10 caratteri) resta congelata nelle quote di questo piano, accanto al modo in cui i titolari sono stati risolti.</p>
        <div class="flex flex-col sm:flex-row gap-3 items-start sm:items-center">
          <Input
            id="nota_destinatari"
            v-model="nota"
            placeholder="Es: rogito del 30/04 registrato, il conguaglio delle rate emesse è nel passaggio…"
            class="flex-1 bg-white border-amber-300 focus-visible:ring-amber-500"
            :disabled="processing"
            @keyup.enter="handleProcedi"
          />
          <Button type="button" @click="handleProcedi" :disabled="!canProceed || processing" class="shrink-0 bg-amber-600 hover:bg-amber-700 text-white font-bold">
            {{ processing ? 'Generazione in corso...' : 'Procedi comunque' }}
          </Button>
        </div>
        <p v-if="nota.length > 0 && !canProceed" class="text-xs text-amber-600 font-medium">La motivazione è troppo breve ({{ nota.length }}/10 caratteri).</p>
      </div>
    </div>
  </div>
</template>
