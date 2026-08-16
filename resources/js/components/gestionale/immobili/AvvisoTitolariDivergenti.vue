<script setup lang="ts">
/**
 * «Questa pertinenza ha un proprietario diverso dall'unità a cui è collegata.»
 *
 * ## Perché si segnala, e perché non si corregge
 *
 * L'art. 817 c.c. chiede il **requisito soggettivo**: i due beni devono appartenere allo stesso
 * proprietario, e la giurisprudenza ne ricava la cessazione del vincolo quando il proprietario
 * dispone separatamente della pertinenza (Cass. civ. sez. II 21 luglio 2021 n. 20911; Cass. civ.
 * n. 13742/2019). Titolari diversi sono quindi un **indizio** che il legame non c'è più.
 *
 * Un indizio, non una prova, ed è il motivo per cui il programma **non rompe il legame da solo**.
 * In almeno quattro casi frequenti la conclusione automatica sarebbe sbagliata:
 *
 * 1. **comunione ereditaria pendente**, dove la divisione ha effetto retroattivo (art. 757 c.c.);
 * 2. **usufrutto costituito sul solo box**, che cambia i titolari senza toccare la proprietà;
 * 3. **anagrafe non ancora aggiornata**, entro i sessanta giorni dell'art. 1130 n. 6 c.c.;
 * 4. **comunione legale non registrata**, dove il coniuge non compare fra i titolari.
 *
 * E ce n'è un quinto che pesa più di tutti: il **vincolo Tognoli** sopravvive alla divergenza,
 * perché è imposto dalla legge e non dalla volontà del proprietario.
 *
 * L'amministratore prende atto della realtà; non la corregge il software.
 *
 * ## Perché ambra e mai rosso, mai bloccante
 *
 * Non è un errore: è una situazione che **capita**, tipicamente quando un box viene venduto da
 * solo. Un rosso qui insegnerebbe a ignorare i rossi.
 */
import { computed } from 'vue';
import { Info } from 'lucide-vue-next';
import BadgeRuolo from '@/components/gestionale/immobili/BadgeRuolo.vue';

type Titolare = { id: number | string; nome: string; pivot?: { tipologia?: string | null } | null };

const props = defineProps<{
  /** Il nome dell'unità che è pertinenza. */
  nomePertinenza: string;
  /** Il nome dell'unità principale. */
  nomePrincipale: string;
  titolariPertinenza: Titolare[];
  titolariPrincipale: Titolare[];
  /**
   * `dialogo` quando si sta **scegliendo** il collegamento: il tono è «puoi farlo lo stesso».
   * `scheda` quando la divergenza è già in archivio: il tono è «guarda che è così».
   */
  contesto?: 'dialogo' | 'scheda';
}>();

/**
 * Si confrontano i soli **titolari di diritto reale**, non gli inquilini.
 *
 * Un box locato a una persona diversa da chi abita l'appartamento non ha niente di anomalo, e
 * segnalarlo sarebbe rumore su un fatto normalissimo. Il requisito soggettivo dell'art. 817
 * riguarda la **proprietà**, non il godimento — è la stessa distinzione per cui
 * `RuoloAnagraficaImmobile::titolariDiDirittoReale()` esclude l'inquilino.
 */
const RUOLI_REALI = ['proprietario', 'nuda_proprietario', 'usufruttuario'];

const nomiReali = (t: Titolare[]) => t
  .filter((x) => RUOLI_REALI.includes(x.pivot?.tipologia ?? ''))
  .map((x) => x.nome)
  .sort();

const divergono = computed(() => {
  const a = nomiReali(props.titolariPertinenza);
  const b = nomiReali(props.titolariPrincipale);

  // Senza titolari da una delle due parti non c'è divergenza da dichiarare: c'è un dato che
  // manca, ed è un'altra segnalazione — la fa `kondomanager:verifica-titolarita`.
  if (a.length === 0 || b.length === 0) return false;

  return a.join('|') !== b.join('|');
});

const soliDiDirittoReale = (t: Titolare[]) =>
  t.filter((x) => RUOLI_REALI.includes(x.pivot?.tipologia ?? ''));
</script>

<template>
  <div
    v-if="divergono"
    class="rounded-lg border border-amber-200 bg-amber-50/60 p-4 dark:border-amber-900/50 dark:bg-amber-900/10"
  >
    <div class="flex items-start gap-2.5">
      <!-- `Info` e non `AlertTriangle`: è un'informazione, non un allarme. -->
      <Info class="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-500" />

      <div class="min-w-0 space-y-3">
        <p class="text-sm text-amber-900 dark:text-amber-200">
          <template v-if="contesto === 'dialogo'">
            <!--
              «Puoi collegarli lo stesso» è la parte che conta, e sta all'inizio: dice
              all'amministratore che non sta per essere fermato, prima di spiegargli perché la
              cosa è insolita.
            -->
            Puoi collegarli lo stesso — il programma non lo impedisce — ma di solito una pertinenza
            appartiene allo stesso proprietario dell'unità principale (art. 817 c.c.).
          </template>
          <template v-else>
            Questa pertinenza ha un titolare diverso dall'unità a cui è collegata.
            <strong>Nessun calcolo ne risente:</strong> il collegamento è descrittivo.
          </template>
        </p>

        <!-- I fatti che hanno prodotto la segnalazione, non solo la segnalazione. -->
        <div class="grid gap-3 sm:grid-cols-2 text-xs">
          <div>
            <p class="font-semibold text-amber-900 dark:text-amber-200">{{ nomePertinenza }}</p>
            <ul class="mt-1 space-y-1">
              <li v-for="t in soliDiDirittoReale(titolariPertinenza)" :key="t.id" class="flex items-center gap-1.5">
                <BadgeRuolo :ruolo="t.pivot?.tipologia" />
                <span class="text-slate-700 dark:text-slate-300 truncate">{{ t.nome }}</span>
              </li>
            </ul>
          </div>

          <div>
            <p class="font-semibold text-amber-900 dark:text-amber-200">{{ nomePrincipale }}</p>
            <ul class="mt-1 space-y-1">
              <li v-for="t in soliDiDirittoReale(titolariPrincipale)" :key="t.id" class="flex items-center gap-1.5">
                <BadgeRuolo :ruolo="t.pivot?.tipologia" />
                <span class="text-slate-700 dark:text-slate-300 truncate">{{ t.nome }}</span>
              </li>
            </ul>
          </div>
        </div>

        <p class="text-xs text-amber-800/80 dark:text-amber-300/70">
          Il programma non scioglie il collegamento da solo: capita con una successione ancora da
          dividere, con un usufrutto sulla sola pertinenza, o con un'anagrafe non ancora aggiornata.
          E un parcheggio vincolato resta pertinenza per legge anche quando i titolari divergono.
        </p>
      </div>
    </div>
  </div>
</template>
