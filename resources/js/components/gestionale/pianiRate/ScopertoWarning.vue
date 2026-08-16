<script setup lang="ts">
import { ref, computed } from 'vue';
import BadgeRuolo from '@/components/gestionale/immobili/BadgeRuolo.vue';
import { AlertTriangle, ExternalLink } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { usePermission } from "@/composables/permissions";

/**
 * Uno scoperto ha tre forme, e prima della beta.48 questa schermata ne conosceva una sola.
 *
 * - `motivo` assente → **quota orfana**: l'unità c'è, ma nessun soggetto attivo a cui
 *   addebitarla. È il caso storico della v1.9.1, l'unico che questa tabella sapeva mostrare.
 * - `conto_senza_tabella` → il capitolo non ha nessuna tabella millesimale collegata.
 * - `tabella_senza_millesimi` → la tabella è collegata ma non ha immobili, o li ha tutti a zero.
 *
 * Le ultime due **non riguardano un immobile**: `immobile_id` arriva `null`. Mostrandole con il
 * tracciato della prima si otteneva «Immobile #» seguito dal vuoto, un badge di ruolo vuoto e un
 * collegamento a `/immobili/null` — cioè un pulsante che non porta da nessuna parte, proprio
 * accanto a un messaggio che chiede di fare qualcosa.
 */
export interface ScopertoCents {
    immobile_id: number | null;
    immobile_nome: string | null;
    conto_id: number;
    conto_nome: string;
    tabella_id?: number | null;
    tabella_nome?: string | null;
    importo: number; // in cents
    ruolo_richiesto: string | null;
    motivo?: string | null;
}

const props = defineProps<{
    scoperti: ScopertoCents[];
    processing?: boolean;
}>();

const emit = defineEmits<{
    (e: 'procedi', nota: string): void
}>();

const nota = ref('');
const { euro } = useCurrencyFormatter();
const { generatePath } = usePermission();

const totaleScoperto = computed(() => props.scoperti.reduce((acc, curr) => acc + curr.importo, 0));

/**
 * Cosa manca, in una riga, e cosa si deve fare per toglierlo di mezzo.
 *
 * La frase dell'azione è la parte che conta: la roadmap lo dice esplicitamente — *«il messaggio
 * deve nominare i capitoli e dire cosa fare, altrimenti la telefonata si sposta, non si toglie»*.
 */
const descrizione = (s: ScopertoCents): { cosa: string; azione: string } => {
    if (s.motivo === 'conto_senza_tabella') {
        return {
            cosa: 'Nessuna tabella millesimale collegata al capitolo',
            azione: 'Collega una tabella millesimale a questa voce di spesa',
        };
    }

    if (s.motivo === 'tabella_senza_millesimi') {
        return {
            cosa: s.tabella_nome
                ? `La tabella «${s.tabella_nome}» non ha millesimi utilizzabili`
                : 'La tabella collegata non ha millesimi utilizzabili',
            azione: 'Assegna gli immobili alla tabella e inserisci i millesimi',
        };
    }

    return {
        cosa: s.immobile_nome ?? 'Unità senza soggetto',
        azione: 'Censisci le anagrafiche mancanti su questa unità',
    };
};

/**
 * Dove si va a sistemare. `null` quando non c'è una destinazione costruibile.
 *
 * ⚠️ **`conto_senza_tabella` non ha collegamento, ed è una scelta.** La pagina in cui si collega
 * una tabella a un capitolo è `.../esercizi/{esercizio}/piani-conti/{pianoConto}/conti`, e il
 * payload dello scoperto non porta né l'esercizio né il piano dei conti. Indovinarli
 * produrrebbe un pulsante che porta altrove — peggio di nessun pulsante, perché sembra una
 * strada. La riga dice comunque quale capitolo e cosa fare.
 *
 * Si chiude quando arriva l'avviso preventivo sui capitoli del piano (§7.4 di
 * `docs/validatore_coerenza_millesimi.md`): quella schermata quegli id ce li ha già.
 */
const destinazione = (s: ScopertoCents): string | null => {
    if (s.motivo === 'tabella_senza_millesimi') {
        return s.tabella_id
            ? generatePath('gestionale/:condominio/tabelle/' + s.tabella_id + '/quote')
            : null;
    }

    if (s.motivo === 'conto_senza_tabella') {
        return null;
    }

    return s.immobile_id
        ? generatePath('gestionale/:condominio/immobili/' + s.immobile_id)
        : null;
};

const etichettaDestinazione = (s: ScopertoCents): string =>
    s.motivo === 'tabella_senza_millesimi' ? 'Millesimi' : 'Anagrafiche';

const canProceed = computed(() => nota.value.trim().length >= 10);

const handleProcedi = () => {
    if (canProceed.value) {
        emit('procedi', nota.value.trim());
    }
};
</script>

<template>
  <div class="rounded-lg border-2 border-amber-300 bg-amber-50 shadow-sm overflow-hidden mt-6 mb-6">
    <div class="p-4 border-b border-amber-200 bg-amber-100/50 flex items-start gap-3">
      <AlertTriangle class="w-6 h-6 text-amber-600 shrink-0 mt-0.5" />
      <div>
        <h3 class="font-bold text-amber-900 text-base">
          Attenzione: {{ scoperti.length }} {{ scoperti.length === 1 ? 'importo non ripartibile' : 'importi non ripartibili' }} (totale {{ euro(totaleScoperto) }})
        </h3>
        <p class="text-sm text-amber-800 mt-1">
          Questi importi non sono addebitabili a nessuno: manca il soggetto, la tabella millesimale o i millesimi.
          Se procedi, il piano rate <strong>non li conterrà</strong> — le quote degli altri condòmini restano corrette.
        </p>
      </div>
    </div>

    <div class="p-0 overflow-x-auto">
      <table class="w-full text-sm text-left">
        <thead class="bg-amber-100/30 text-amber-900 text-xs uppercase font-semibold">
          <tr>
            <th class="px-4 py-2">Cosa manca</th>
            <th class="px-4 py-2">Voce di spesa</th>
            <th class="px-4 py-2">Ruolo atteso</th>
            <th class="px-4 py-2 text-right">Importo</th>
            <th class="px-4 py-2 text-center">Azione</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-amber-100 bg-white/50">
          <tr v-for="(scoperto, index) in scoperti" :key="index" class="hover:bg-amber-50/50 align-top">
            <td class="px-4 py-2">
              <div class="font-medium text-slate-900">{{ descrizione(scoperto).cosa }}</div>
              <div class="text-[11px] text-slate-600 mt-0.5">{{ descrizione(scoperto).azione }}</div>
            </td>
            <td class="px-4 py-2 text-slate-700">{{ scoperto.conto_nome }}</td>
            <td class="px-4 py-2">
              <!-- Il ruolo esiste solo per la quota orfana: sulle altre due forme il badge
                   sarebbe una cornice vuota, che si legge come un dato mancante. -->
              <!-- ⚠️ Qui i colori erano una terza copia della stessa mappa, e **in disaccordo con
                   le altre due**: l'inquilino era blu qui e verde nella scheda dell'unità, e
                   `nuda_proprietario` non c'era affatto. Un amministratore che legge lo stesso
                   ruolo in due colori diversi deve reimparare il vocabolario a ogni schermata.
                   Ora è lo stesso componente del resto del gestionale. -->
              <BadgeRuolo v-if="scoperto.ruolo_richiesto" :ruolo="scoperto.ruolo_richiesto" taglia="md" tema="chiaro" />
              <span v-else class="text-slate-400 text-xs">—</span>
            </td>
            <!-- `euro()` ha `fromCents: true` come default e la conversione la fa da sé: qui
                 c'era un `/ 100` che divideva una seconda volta, e € 24.741,60 di scoperto
                 comparivano come € 247,42. Preesistente, trovato dal primo test di questo
                 componente. Gli altri due chiamanti che pre-dividono — PianiRateNew ed
                 EstrattoContoAnagrafica — istanziano `fromCents: false` e sono corretti. -->
            <td class="px-4 py-2 text-right font-medium text-amber-700">{{ euro(scoperto.importo) }}</td>
            <td class="px-4 py-2 text-center">
              <a v-if="destinazione(scoperto)" :href="destinazione(scoperto)!" target="_blank"
                 class="inline-flex items-center gap-1 text-[11px] font-semibold text-indigo-600 hover:text-indigo-800 transition-colors">
                {{ etichettaDestinazione(scoperto) }} <ExternalLink class="w-3 h-3" />
              </a>
              <span v-else class="text-slate-400 text-xs">—</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="p-4 bg-amber-50 border-t border-amber-200 space-y-4">
      <div class="space-y-2">
        <label for="nota_scoperti" class="block text-sm font-semibold text-amber-900">
          Cosa vuoi fare?
        </label>
        <p class="text-xs text-amber-700">Se vuoi procedere comunque addossando la differenza, specifica una motivazione (min. 10 caratteri) che verrà salvata nello storico.</p>
        <!-- Il testo diceva «correggi l'anagrafica»: vero per la quota orfana, falso per le
             altre due forme, dove il rimedio è la tabella millesimale. Ogni riga dice già il
             suo rimedio nella colonna «Cosa manca»: qui resta solo ciò che vale per tutte. -->
        <p class="text-xs font-semibold text-amber-800">
          Attenzione: una volta emesse le rate, Ricalcola non potrà più recuperare questi importi. Se vuoi includerli, correggi prima di emettere quanto indicato qui sopra.
        </p>
        <div class="flex flex-col sm:flex-row gap-3 items-start sm:items-center">
          <Input 
            id="nota_scoperti"
            v-model="nota"
            placeholder="Es: tabella millesimale in approvazione, la collego prima del consuntivo..."
            class="flex-1 bg-white border-amber-300 focus-visible:ring-amber-500"
            :disabled="processing"
            @keyup.enter="handleProcedi"
          />
          <Button 
            type="button" 
            @click="handleProcedi" 
            :disabled="!canProceed || processing"
            class="shrink-0 bg-amber-600 hover:bg-amber-700 text-white font-bold"
          >
            {{ processing ? 'Generazione in corso...' : 'Procedi comunque' }}
          </Button>
        </div>
        <p v-if="nota.length > 0 && !canProceed" class="text-xs text-amber-600 font-medium">
          La motivazione è troppo breve ({{ nota.length }}/10 caratteri).
        </p>
      </div>
    </div>
  </div>
</template>
