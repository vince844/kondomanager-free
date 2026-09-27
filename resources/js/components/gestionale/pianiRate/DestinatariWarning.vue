<script setup lang="ts">
/**
 * Il cancello (2) della decisione 14 (`docs/subentro_e_competenza_temporale.md`): la risoluzione dei
 * titolari **per periodo** ha cambiato dei destinatari o dei pesi rispetto a quella atemporale — o il
 * piano contiene una pregressa registrata senza periodo (decisione 26) — e la generazione si è fermata
 * per dirlo prima di scrivere.
 *
 * Stesso schema di `ScopertoWarning.vue` — riquadro ambra, tabella di chi e perché, nota di almeno
 * dieci caratteri, «Procedi comunque» — perché l'amministratore ha già imparato a leggerlo lì. Le tre
 * forme che il motore registra:
 *
 * - `pro_rata_giorni`: sulla coppia (unità, ruolo) qualcuno entra o esce nel periodo di competenza, e la
 *   spesa si divide in proporzione ai giorni (D8). La riga mostra i titolari con i giorni di ciascuno.
 * - `fuori_periodo`: un titolare che il calcolo atemporale avrebbe fatto pagare è escluso dal periodo
 *   (chiuso prima, o decorrente dopo con un predecessore chiuso: D7).
 * - `pregressa_senza_periodo` (decisione 26, 1.11.0-beta.35): una fattura pregressa registrata senza il periodo in cui
 *   il costo è maturato. Non è una coppia che cambia — nessuna unità, nessun ruolo — ma il piano non sa se in quell'anno
 *   l'unità era di qualcun altro, quindi lo dice e chiede la nota anche quando nessun titolare cambia quest'anno.
 *
 * Tema **chiaro** come `ScopertoWarning` (nessuna classe `dark:`), e per la stessa ragione: il badge
 * dei ruoli viaggia con `tema="chiaro"`.
 */
import { computed, ref } from 'vue';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import BadgeRuolo from '@/components/gestionale/immobili/BadgeRuolo.vue';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { CalendarClock, ExternalLink } from 'lucide-vue-next';

export interface DestinatarioCambiato {
  immobile_id: number | null;
  immobile_nome: string | null;
  tipologia: string;
  conto_id: number | null;
  conto_nome: string | null;
  motivo: 'pro_rata_giorni' | 'fuori_periodo' | 'pregressa_senza_periodo';
  /** Solo per `pregressa_senza_periodo`. */
  fattura_id?: number;
  fattura_numero?: string;
  /** Solo per `pregressa_senza_periodo`: quanto serve a riconoscerla senza ricordare il numero (`GeneratePianoRateAction`). */
  fattura?: {
    id: number; numero: string; fornitore: string | null; data_documento: string | null;
    totale_formattato: string; nel_piano_formattato: string | null; voce: string | null; url: string;
  };
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
  /**
   * Il cancello si apre su un piano che esiste già (`PianiRateShow`: generazione di un piano in bozza, «Ricalcola»), non
   * alla creazione. Cambia la via per la pregressa senza periodo: alla creazione la generazione si annulla e la fattura
   * non resta agganciata; su un piano esistente la fattura è nel piano, e prima dello storno il piano va eliminato
   * (R1 della Fase 1-bis, 1.11.0-beta.35).
   */
  pianoEsistente?: boolean;
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

// Le coppie che cambiano e le pregresse senza periodo sono due cose diverse: si contano e si mostrano a parte.
const coppie = computed(() => props.destinatari.filter(d => d.motivo !== 'pregressa_senza_periodo'));
const pregresse = computed(() => props.destinatari.filter(d => d.motivo === 'pregressa_senza_periodo'));
const unita = computed(() => new Set(coppie.value.map(d => d.immobile_id)).size);

// La fattura di una pregressa, in una finestra: il numero da solo non basta a riconoscerla (Vincenzo a video, 27/09/2026).
type FatturaDelCancello = NonNullable<DestinatarioCambiato['fattura']>;
const fatturaAperta = ref<FatturaDelCancello | null>(null);

// La nota dice che cosa si è letto: con le sole pregresse nessun titolare cambia (R12 della Fase 1-bis).
const lettoCosa = computed(() => {
  const pregressa = pregresse.value.length === 1 ? 'come si ripartisce la pregressa' : 'come si ripartiscono le pregresse';
  if (!coppie.value.length) return pregressa;

  return pregresse.value.length ? `chi cambia e ${pregressa}` : 'chi cambia';
});
const esempioNota = computed(() => (coppie.value.length
  ? 'Es: rogito del 30/04 registrato, il conguaglio delle rate emesse è nel passaggio…'
  : 'Es: nell\'anno della spesa l\'unità era della stessa proprietaria…'));
</script>

<template>
  <div class="rounded-lg border-2 border-amber-300 bg-amber-50 shadow-sm overflow-hidden mt-6 mb-6">
    <div class="p-4 border-b border-amber-200 bg-amber-100/50 flex items-start gap-3">
      <CalendarClock class="w-6 h-6 text-amber-600 shrink-0 mt-0.5" />
      <div>
        <h3 class="font-bold text-amber-900 text-base">
          <template v-if="coppie.length">Il periodo di competenza cambia chi paga su {{ unita }} {{ unita === 1 ? 'unità' : 'unità' }}</template>
          <template v-else>{{ pregresse.length === 1 ? 'Una fattura pregressa non ha il periodo' : `${pregresse.length} fatture pregresse non hanno il periodo` }} in cui il costo è maturato</template>
        </h3>
        <p v-if="coppie.length" class="text-sm text-amber-800 mt-1">
          Su queste unità un titolare è entrato o uscito dentro il periodo della spesa: la quota si divide
          <strong>in proporzione ai giorni</strong> fra chi c'era, e chi era fuori periodo non paga. Le rate
          già emesse non si toccano. Se procedi, la registrazione lo scrive riga per riga (periodo, gradino,
          giorni), così il riparto stampato dice come è stato deciso.
        </p>
      </div>
    </div>

    <!-- Decisione 26: le pregresse registrate senza periodo, prima della tabella delle coppie. -->
    <div v-if="pregresse.length" class="p-4 space-y-2 border-b border-amber-200 bg-white/50">
      <p v-for="d in pregresse" :key="d.fattura_id" class="text-sm text-amber-900 leading-relaxed">
        <button v-if="d.fattura" type="button" :data-fattura="d.fattura.id" @click="fatturaAperta = d.fattura"
          class="font-bold underline decoration-dotted underline-offset-2 hover:text-amber-700">Fattura {{ d.fattura_numero }}</button>
        <strong v-else>Fattura {{ d.fattura_numero }}</strong><template v-if="d.fattura?.fornitore"> di {{ d.fattura.fornitore }}</template><template v-if="d.fattura">, {{ d.fattura.totale_formattato }}</template>:
        pregressa registrata senza il periodo in cui il costo è maturato.
        Il piano la ripartisce
        <template v-if="d.gradino === 'delibera'">alla data della delibera, il {{ dataBreve(d.periodo?.[0]?.dal ?? null) }}, a chi era titolare quel giorno</template>
        <template v-else>sui giorni di {{ periodoBreve(d.periodo) }}, fra chi era titolare in quei giorni</template>.
        <!-- Con la delibera la regola è a gradino: non una parte, tutto a chi è titolare quel giorno (come dice il carrello). -->
        <template v-if="d.gradino === 'delibera'">Se nell'anno in cui il costo è maturato l'unità era di qualcun altro, dopo un
          passaggio la pagherebbe per intero chi era titolare alla delibera.</template>
        <template v-else>Se nell'anno in cui il costo è maturato l'unità era di qualcun altro, dopo un passaggio una parte la
          pagherebbe chi è entrato.</template>
        Per ripartirla sul periodo giusto:
        <template v-if="pianoEsistente">elimina questo piano (se è approvato, prima riportalo in bozza), storna la fattura,
          registrala di nuovo con il periodo e crea di nuovo il piano (una fattura dentro un piano non si storna).</template>
        <template v-else>storna la fattura, registrala di nuovo con il periodo e poi crea il piano.</template>
      </p>
    </div>

    <div v-if="coppie.length" class="p-0 overflow-x-auto">
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
          <tr v-for="(d, i) in coppie" :key="i" class="align-top hover:bg-amber-50/50">
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
        <label for="nota_destinatari" class="block text-sm font-semibold text-amber-900">Ho letto {{ lettoCosa }}. Perché procedo così?</label>
        <p class="text-xs text-amber-700">La nota (almeno 10 caratteri) resta congelata nelle quote di questo piano, accanto al modo in cui i titolari sono stati risolti.</p>
        <div class="flex flex-col sm:flex-row gap-3 items-start sm:items-center">
          <Input
            id="nota_destinatari"
            v-model="nota"
            :placeholder="esempioNota"
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

    <Dialog :open="!!fatturaAperta" @update:open="(v: boolean) => { if (!v) fatturaAperta = null }">
      <DialogContent class="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Fattura {{ fatturaAperta?.numero }}</DialogTitle>
          <DialogDescription>Pregressa registrata senza il periodo in cui il costo è maturato.</DialogDescription>
        </DialogHeader>
        <dl v-if="fatturaAperta" class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
          <dt class="text-slate-500">Fornitore</dt><dd class="font-medium">{{ fatturaAperta.fornitore ?? '—' }}</dd>
          <dt class="text-slate-500">Data del documento</dt><dd class="tabular-nums">{{ fatturaAperta.data_documento ?? '—' }}</dd>
          <dt class="text-slate-500">Importo del documento</dt><dd class="tabular-nums">{{ fatturaAperta.totale_formattato }}</dd>
          <template v-if="fatturaAperta.nel_piano_formattato">
            <dt class="text-slate-500">In questo piano</dt><dd class="tabular-nums">{{ fatturaAperta.nel_piano_formattato }}</dd>
          </template>
          <template v-if="fatturaAperta.voce">
            <dt class="text-slate-500">Voce</dt><dd>{{ fatturaAperta.voce }}</dd>
          </template>
        </dl>
        <!-- In una nuova scheda: il piano (o il modulo della creazione) resta dov'è, con la nota già scritta. -->
        <a v-if="fatturaAperta" :href="fatturaAperta.url" target="_blank" rel="noopener"
          class="mt-2 inline-flex items-center gap-1.5 text-sm font-medium text-primary hover:underline">
          <ExternalLink class="h-4 w-4" /> Apri la fattura in una nuova scheda
        </a>
      </DialogContent>
    </Dialog>
  </div>
</template>
