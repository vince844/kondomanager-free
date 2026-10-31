<script setup lang="ts">
/**
 * Il pannello «Cosa cambierà» di «Registra passaggio» (§6.4 di `docs/pertinenze_vendita_locazione.md`).
 *
 * Card sticky a destra, bordo tratteggiato, sullo schema visivo che l'amministratore ha già imparato
 * con `ScopertoWarning.vue`. Quattro blocchi, tutti al futuro, **calcolati dal server** e mai qui: il
 * componente riceve frasi e le mostra. Tre stati:
 *
 * - **in attesa**: il modulo non è ancora completo — si dice cosa manca, senza numeri;
 * - **errore**: il server non ha risposto — «Non riesco a calcolare le conseguenze: riprova», e chi
 *   ci sta sopra tiene il pulsante disabilitato. **Mai mostrare zero** al posto di un numero che non
 *   c'è: uno zero si legge come «niente da conguagliare», che è la frase opposta;
 * - **pronto**: i quattro blocchi.
 *
 * Il blocco 2 porta uno stato proprio (`nessuno` / `calcolato`): elenca le rate emesse a chi esce, la
 * morosità e — da S5 — il **conguaglio proposto** per gestione (D9), che è esattamente ciò che la
 * registrazione scrive in `saldi`. Qui vive anche la **rinuncia**: l'amministratore può non scrivere la
 * coppia, con una nota obbligatoria («regolato fra le parti nel rogito del …», Cass. 11199/2021 «salvo
 * diverso accordo»). Niente modifica libera dei numeri: per cifre diverse c'è il saldo manuale del Wallet.
 * Nella successione (1.11.0-beta.48) e alla fine di una locazione (1.11.0-beta.50, decisione 77) la casella lascia il posto alla
 * scelta «Scrivi il conguaglio» / «Non scriverlo», senza nessuna voce già scelta.
 */
import { computed, ref } from 'vue';
import { AlertTriangle, CalendarClock, Info, LoaderCircle, Receipt, Scale, ShieldCheck, Users, ArrowLeftRight } from 'lucide-vue-next';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import FraseConImporti from './FraseConImporti.vue';
import type { AnteprimaPassaggioDati } from '@/types/gestionale/passaggi';
import { competenzaDellaGestione, etichettaEsclusa } from '@/lib/gestionale/passaggi/competenzaGestione';
import { conguaglioDaScegliereNelPassaggio, conguaglioNonScrittoNelPassaggio, coppieNonScritte, fraseArretrato, sceltaConguaglioDelPassaggio, type SceltaConguaglio } from '@/lib/gestionale/passaggi/rinunciaConguaglio';
import { aNome } from '@/lib/gestionale/passaggi/preposizione';

const props = defineProps<{
  dati: AnteprimaPassaggioDati | null;
  /** Il server sta calcolando: si tiene l'ultimo pannello e si mostra l'attesa, senza svuotare. */
  inCorso: boolean;
  /** Il server non ha risposto (rete, 500). Non è una validazione: quella arriva come `bloccato`. */
  errore: boolean;
  /** Il server ha rifiutato un dato (422): l'errore è sotto il campo, qui si dice solo che si aspetta. */
  bloccato?: boolean;
  /** Cosa manca al modulo perché il server possa rispondere; vuoto se è completo. */
  mancante: string[];
  /** Il tipo di passaggio: la nota della rinuncia nomina venditore e acquirente solo nella vendita (Fase 5 della 1.11.0-beta.44). */
  tipo?: string;
  /** Il server si è fermato solo per chiedere l'erede di riferimento: è una scelta, non un dato sbagliato (P6 della 1.11.0-beta.48). */
  attesaScelta?: boolean;
  /**
   * Decisione 77 (1.11.0-beta.50): la fine di una locazione senza un nuovo inquilino. Le coppie vanno a chi paga al posto dell'inquilino,
   * non a chi entra, e le due voci lo dicono.
   */
  senzaSuccessore?: boolean;
}>();

/** La rinuncia alla coppia proposta e la sua ragione: stato del modulo, tenuto da chi ci sta sopra. */
const rinuncia = defineModel<boolean>('rinuncia', { default: false });
const notaRinuncia = defineModel<string>('notaRinuncia', { default: '' });
/** Decisioni 72 e 73 (1.11.0-beta.48): nella successione e nel legato il conguaglio si sceglie, senza valore di partenza. */
const sceltaConguaglio = defineModel<SceltaConguaglio | null>('sceltaConguaglio', { default: null });

const pronto = computed(() => props.dati !== null && !props.errore);
const conguaglio = computed(() => props.dati?.rate.conguaglio ?? null);
/** C'è almeno una coppia da scrivere: solo allora ha senso poter rinunciare. */
const haCoppie = computed(() => (conguaglio.value?.coppie.length ?? 0) > 0);
/**
 * Il conguaglio che si scrive prenderebbe un piano da ricalcolare prima (§6.3 della 1.11.0-beta.51): la frase la scrive il server, la
 * registrazione si rifiuta, e il pannello resta con il conguaglio e la sua via d'uscita — la casella della rinuncia, o «Non scriverlo».
 */
const pianoDaRicalcolare = computed(() => props.dati?.rate.piano_da_ricalcolare ?? null);
// Le coppie hanno più di un «chi entra» (S8-30): si elencano con il nome, perché la tabella per gestione non lo dice.
const piuEntranti = computed(() => new Set((conguaglio.value?.coppie ?? []).map(c => c.anagrafica_entrante_id)).size > 1);
// Coda 245 (1.11.0-beta.55): alla fine di una locazione con un coinquilino che resta la coppia è della regola del piano (`piano`, o `piano_coppia`
// quando lascia fuori una parte), o lo è solo su alcune unità (`misto`): le voci della scelta e il piede lo dicono, e le righe per persona ci sono anche
// con un ricevente solo.
const regola = computed(() => conguaglio.value?.regola ?? 'giorni');
const conLaRegolaDelPiano = computed(() => props.tipo === 'fine_locazione' && regola.value !== 'giorni');
// Nel cambio d'inquilino chi riceve è chi entra: la riga per persona serve dove nessuno entra e la coppia va a chi sta al posto dell'inquilino o a chi resta.
const righePerPersona = computed(() => piuEntranti.value || (conLaRegolaDelPiano.value && props.senzaSuccessore === true));
const notaTroppoCorta = computed(() => rinuncia.value && notaRinuncia.value.trim().length < 10);
// Decisione 25 (B3a): le bozze che passano a chi entra, e la coppia che con loro può rovesciarsi.
const riassegnazione = computed(() => conguaglio.value?.riassegnazione ?? []);
const rovesciato = computed(() => (conguaglio.value?.totale_entrante ?? 0) < 0);
/**
 * 1.11.0-beta.44, decisione 65: nella successione l'arretrato del defunto. Agli eredi, coppia e arretrato fanno un conto solo e la
 * rinuncia al conguaglio non c'è; a nome del defunto la rinuncia resta, fra gli eredi, senza la sentenza della vendita.
 */
const arretrato = computed(() => props.dati?.rate.arretrato ?? null);
const arretratoAgliEredi = computed(() => arretrato.value?.scelta === 'eredi');
// Rilievo X11 della Fase 1-bis: con l'arretrato agli eredi la spunta rimasta da «a nome del defunto» non vale, e i badge non la seguono.
// Decisione 72: con l'arretrato a nome del defunto la casella non c'è più, c'è la scelta. Decisione 77 (1.11.0-beta.50): la scelta c'è
// anche alla fine di una locazione, con un nuovo inquilino o senza, quando il pannello propone coppie, anche con un coinquilino che resta
// (Coda 245, la regola del piano). L'arretrato c'è solo nella successione: senza il tipo, dice lui.
const tipoPassaggio = computed(() => props.tipo ?? (arretrato.value ? 'successione' : undefined));
const locazione = computed(() => props.tipo === 'fine_locazione');
// Giro stretto della .50 (G5): i piani su cui il cambio d'inquilino di prima è stato regolato fra le parti, e il prospetto non va oltre.
const prospettoRegolato = computed(() => props.dati?.rate.prospetto_regolato ?? []);
// Coda 245: con la regola del piano nel cambio d'inquilino, le unità per cui il prospetto lascia fuori un piano della 1.10 (il box spuntato), una frase per unità.
const esclusiDove = computed(() => {
  const perUnita = new Map<string, string[]>();
  for (const d of props.dati?.rate.prospetto_esclude_dove ?? []) {
    perUnita.set(d.unita, [...(perUnita.get(d.unita) ?? []), d.piano]);
  }
  return [...perUnita.entries()].map(([unita, piani]) => `Per «${unita}» il prospetto degli oneri accessori lascia fuori ${piani.length === 1 ? 'il piano' : 'i piani'} «${piani.join('», «')}», generat${piani.length === 1 ? 'o' : 'i'} da una versione precedente: lì chi entra glieli rimborsa fra le parti.`).join(' ');
});
const sceltaRichiesta = computed(() => sceltaConguaglioDelPassaggio(tipoPassaggio.value, conguaglio.value?.coppie.length ?? 0, arretrato.value?.scelta));
const nonScritto = computed(() => conguaglioNonScrittoNelPassaggio(tipoPassaggio.value, conguaglio.value?.coppie.length ?? 0, arretrato.value?.scelta, sceltaConguaglio.value));
const rinunciaVale = computed(() => coppieNonScritte(tipoPassaggio.value, conguaglio.value?.coppie.length ?? 0, arretrato.value?.scelta, rinuncia.value, sceltaConguaglio.value));
const notaSceltaTroppoCorta = computed(() => nonScritto.value && notaRinuncia.value.trim().length > 0 && notaRinuncia.value.trim().length < 10);
// Rilievo X8: con la rinuncia, a nome del defunto resta tutta la sua posizione.
// Rilievo R1 della Fase 1-bis della .48: finché la scelta manca il pannello non descrive l'esito di «Scrivi» (la preselezione che la
// decisione 72 toglie): il riquadro dice «da scegliere» e la frase dell'arretrato ha le due cifre.
const daScegliere = computed(() => conguaglioDaScegliereNelPassaggio(tipoPassaggio.value, conguaglio.value?.coppie.length ?? 0, arretrato.value?.scelta, sceltaConguaglio.value));
const fraseDellArretrato = computed(() => fraseArretrato(arretrato.value, rinunciaVale.value, daScegliere.value));
// Rilievo R2: le frasi del calcolo per quota valgono solo se il conguaglio si scrive — prima della scelta servono a scegliere, con
// «Non scriverlo» sarebbero false (Bruno e Carla non hanno niente da pagare).
const frasiDelConguaglio = computed(() => (nonScritto.value ? [] : props.dati?.rate.frasi_del_conguaglio ?? []));
// «ad» davanti ai nomi con la A, in ogni passaggio, con la regola del server (`FrasiObbligati::a`).
const aChi = aNome;
// Rilievo AMM-3: chi riceve l'unità per legato non è un erede.
const legato = computed(() => arretrato.value?.legato === true);
// Verifica a video della .48: «€ 531,45 a credito» non sta nella colonna stretta del pannello; «a credito» va a capo, sotto la cifra.
const A_CREDITO = ' a credito';
const aCredito = (f?: string) => f?.endsWith(A_CREDITO) === true;
const cifra = (f?: string) => (f !== undefined && aCredito(f) ? f.slice(0, -A_CREDITO.length) : f ?? '');
const GRADINI: Record<string, string> = { dichiarata: 'competenza dichiarata', delibera: 'data della delibera', capitolo: 'competenza del capitolo', gestione: 'periodo della gestione', esercizio: 'periodo dell\'esercizio' };

/** Oltre otto righe la tabella si piega: si vede l'inizio, il totale e «mostra tutte». */
const SOGLIA = 8;
const tutteLeRighe = ref(false);
const righeVisibili = computed(() => {
  const e = props.dati?.rate.emesse ?? [];
  return tutteLeRighe.value || e.length <= SOGLIA ? e : e.slice(0, SOGLIA);
});
const righeNascoste = computed(() => (props.dati?.rate.emesse.length ?? 0) - righeVisibili.value.length);
const dataBreve = (iso: string) => iso.split('-').reverse().join('/');
</script>

<template>
  <!-- Verifica a video della .50, su richiesta di Vincenzo: da desktop il pannello non supera l'altezza dello schermo e scorre al suo
       interno, con il titolo sempre in vista; le due voci della scelta, in fondo, si raggiungono senza scorrere tutta la pagina. Da
       telefono resta nella colonna, senza scorrimento interno. -->
  <aside class="lg:sticky lg:top-6 lg:max-h-[calc(100vh-3rem)] space-y-0 rounded-xl border-2 border-dashed border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 shadow-sm overflow-hidden lg:overflow-y-auto">
    <div class="lg:sticky lg:top-0 lg:z-10 px-5 py-4 border-b border-dashed border-slate-200 dark:border-slate-700 bg-slate-50/70 dark:bg-slate-800/40 lg:bg-slate-50 dark:lg:bg-slate-800 flex items-start gap-3">
      <div class="p-2 rounded-lg bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300 shrink-0">
        <Scale class="w-4 h-4" />
      </div>
      <div class="min-w-0">
        <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100">Cosa cambierà</h3>
        <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5 leading-snug">
          Calcolato dal server su quello che hai scritto: niente viene registrato finché non confermi.
        </p>
      </div>
      <LoaderCircle v-if="inCorso" class="w-4 h-4 animate-spin text-slate-400 ml-auto shrink-0 mt-1" aria-label="Sto calcolando" />
    </div>

    <!-- Errore: il pulsante resta disabilitato a monte, qui si dice solo la verità. -->
    <div v-if="errore" class="p-5 flex items-start gap-3 text-sm text-rose-800 dark:text-rose-300 bg-rose-50/60 dark:bg-rose-900/10">
      <AlertTriangle class="w-5 h-5 shrink-0 mt-0.5" />
      <div>
        <p class="font-semibold">Non riesco a calcolare le conseguenze: riprova.</p>
        <p class="text-xs mt-1 opacity-80">Finché il pannello non risponde, il passaggio non si può registrare. Nessun importo viene mostrato al posto di quello vero.</p>
      </div>
    </div>

    <!-- Bloccato: un dato del modulo non passa la validazione del server. -->
    <div v-else-if="bloccato" class="p-5 flex items-start gap-3 text-sm text-amber-900 dark:text-amber-200 bg-amber-50/70 dark:bg-amber-900/10">
      <AlertTriangle class="w-5 h-5 shrink-0 mt-0.5 text-amber-600" />
      <div>
        <p class="font-semibold">{{ attesaScelta ? 'Manca una scelta nel modulo.' : 'Un dato del modulo non è accettabile.' }}</p>
        <p class="text-xs mt-1 opacity-80">{{ attesaScelta ? 'Scegli nel modulo a chi passano le rate in bozza: il pannello riparte da lì. Finché non riparte, il passaggio non si registra.' : 'L\'errore è segnato sotto il campo: correggilo e il pannello riparte da lì. Finché non riparte, il passaggio non si registra.' }}</p>
      </div>
    </div>

    <!-- In attesa: il modulo non basta ancora. -->
    <div v-else-if="!pronto" class="p-5 text-sm text-slate-600 dark:text-slate-400 space-y-3">
      <p class="flex items-start gap-2">
        <Info class="w-4 h-4 shrink-0 mt-0.5 text-slate-400" />
        <span v-if="mancante.length">Il pannello si compila da solo mentre scrivi. Per cominciare {{ mancante.length === 1 ? 'manca' : 'mancano' }}:</span>
        <span v-else>Il pannello sta leggendo il modulo.</span>
      </p>
      <ul v-if="mancante.length" class="list-disc pl-9 space-y-1 text-[13px]">
        <li v-for="m in mancante" :key="m">{{ m }}</li>
      </ul>
    </div>

    <!-- Pronto: i quattro blocchi. -->
    <div v-else-if="dati" class="divide-y divide-dashed divide-slate-200 dark:divide-slate-700">

      <!-- 1. Anagrafica -->
      <section class="p-5 space-y-2">
        <h4 class="flex items-center gap-2 text-[10px] font-bold uppercase tracking-widest text-slate-500 dark:text-slate-400">
          <Users class="w-3.5 h-3.5" /> 1. Anagrafica
        </h4>
        <p v-for="(f, i) in dati.anagrafica.frasi" :key="i" class="text-sm text-slate-800 dark:text-slate-200 leading-relaxed"><FraseConImporti :testo="f" /></p>
        <p v-if="dati.anagrafica.pertinenze.length" class="text-xs text-slate-500 dark:text-slate-400">
          Pertinenze incluse: {{ dati.anagrafica.pertinenze.join(', ') }}.
        </p>
      </section>

      <!-- 2. Rate già emesse -->
      <section class="p-5 space-y-3">
        <h4 class="flex items-center gap-2 text-[10px] font-bold uppercase tracking-widest text-slate-500 dark:text-slate-400">
          <Receipt class="w-3.5 h-3.5" /> 2. Rate già emesse
          <span v-if="conguaglio && haCoppie && daScegliere" class="ml-auto inline-flex items-center gap-1 rounded-md bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300 px-1.5 py-0.5 text-[9px] normal-case tracking-normal font-semibold">
            <ArrowLeftRight class="w-3 h-3" /> conguaglio da scegliere
          </span>
          <span v-else-if="conguaglio && haCoppie && pianoDaRicalcolare && !rinunciaVale" class="ml-auto inline-flex items-center gap-1 rounded-md bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300 px-1.5 py-0.5 text-[9px] normal-case tracking-normal font-semibold">
            <AlertTriangle class="w-3 h-3" /> piano da ricalcolare
          </span>
          <span v-else-if="conguaglio && haCoppie && !rinunciaVale" class="ml-auto inline-flex items-center gap-1 rounded-md bg-indigo-100 text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-300 px-1.5 py-0.5 text-[9px] normal-case tracking-normal font-semibold">
            <ArrowLeftRight class="w-3 h-3" /> conguaglio proposto
          </span>
          <span v-else-if="conguaglio && haCoppie && rinunciaVale" class="ml-auto inline-flex items-center gap-1 rounded-md bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300 px-1.5 py-0.5 text-[9px] normal-case tracking-normal font-semibold">
            {{ nonScritto ? 'conguaglio non scritto' : 'regolato fra le parti' }}
          </span>
        </h4>

        <div v-if="dati.rate.emesse.length" class="rounded-lg border border-slate-200 dark:border-slate-700 overflow-hidden">
          <!-- `table-fixed`: la colonna è stretta e il nome del piano va troncato, non deve allargare la tabella. -->
          <table class="w-full table-fixed text-[12px]">
            <colgroup><col class="w-[33%]" /><col class="w-[39%]" /><col class="w-[28%]" /></colgroup>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
              <tr v-for="(r, i) in righeVisibili" :key="i" class="align-top">
                <td class="px-3 py-1.5 text-slate-700 dark:text-slate-300 truncate">
                  Rata {{ r.rata }}
                  <span v-if="dati.rate.piani_distinti > 1" class="block text-[10px] text-slate-400 font-normal truncate" :title="r.piano">{{ r.piano }}</span>
                </td>
                <td class="px-3 py-1.5 text-slate-500 dark:text-slate-400 truncate">scad. {{ dataBreve(r.scadenza) }}</td>
                <!-- Niente colonna «intestata a»: qui ci sono solo le quote di chi esce, e lo dice il piede. -->
                <td class="px-3 py-1.5 text-right tabular-nums font-semibold text-slate-800 dark:text-slate-200 whitespace-nowrap">{{ r.importo_formattato }}</td>
              </tr>
              <tr v-if="righeNascoste > 0">
                <td colspan="3" class="px-3 py-1.5">
                  <button type="button" @click="tutteLeRighe = true" class="text-[11px] font-medium text-indigo-600 hover:text-indigo-800 dark:text-indigo-400">
                    Mostra tutte le {{ dati.rate.emesse.length }} quote ({{ righeNascoste }} nascoste)
                  </button>
                </td>
              </tr>
            </tbody>
            <tfoot class="bg-slate-50 dark:bg-slate-800/50 border-t border-slate-200 dark:border-slate-700">
              <tr>
                <td colspan="2" class="px-3 py-1.5 text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                  Emesso {{ aChi(dati.rate.emesse[0].intestatario) }}
                  <span class="font-normal normal-case tracking-normal text-slate-400"> · {{ dati.rate.emesse.length }} {{ dati.rate.emesse.length === 1 ? 'quota' : 'quote' }}</span>
                </td>
                <td class="px-3 py-1.5 text-right tabular-nums font-bold text-slate-900 dark:text-slate-100 whitespace-nowrap">{{ dati.rate.totale_emesso_formattato }}</td>
              </tr>
            </tfoot>
          </table>
        </div>

        <p v-for="(f, i) in dati.rate.frasi" :key="i" class="text-sm text-slate-800 dark:text-slate-200 leading-relaxed"><FraseConImporti :testo="f" /></p>
        <p v-for="(f, i) in frasiDelConguaglio" :key="`calcolo-${i}`" class="text-sm text-slate-800 dark:text-slate-200 leading-relaxed"><FraseConImporti :testo="f" /></p>

        <!-- Decisione 25 (B3a): le bozze che passano a chi entra — cambia l'intestatario, non l'importo. -->
        <div v-if="riassegnazione.length" class="rounded-lg border border-slate-200 dark:border-slate-700 overflow-hidden">
          <table class="w-full table-fixed text-[12px]">
            <colgroup><col class="w-[44%]" /><col class="w-[30%]" /><col class="w-[26%]" /></colgroup>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
              <tr v-for="r in riassegnazione" :key="r.piano_rate_id" class="align-top">
                <td class="px-3 py-1.5 text-slate-700 dark:text-slate-300 truncate" :title="r.piano">{{ r.piano }}</td>
                <td class="px-3 py-1.5 text-[11px] text-slate-500 dark:text-slate-400 leading-snug">{{ r.n }} {{ r.n === 1 ? 'rata' : 'rate' }} · {{ dataBreve(r.dal) }}<template v-if="r.al !== r.dal">–{{ dataBreve(r.al) }}</template></td>
                <td class="px-3 py-1.5 text-right tabular-nums font-semibold text-slate-800 dark:text-slate-200 whitespace-nowrap">{{ r.preventivo_formattato }}</td>
              </tr>
            </tbody>
            <tfoot class="bg-slate-50 dark:bg-slate-800/50 border-t border-slate-200 dark:border-slate-700">
              <tr>
                <td colspan="3" class="px-3 py-1.5 text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                  Bozze che passano a chi entra
                  <span class="font-normal normal-case tracking-normal text-slate-400"> · cambia l'intestatario, non l'importo</span>
                </td>
              </tr>
            </tfoot>
          </table>
        </div>

        <!-- Il piano da ricalcolare prima (§6.3 della beta.51): l'avviso sta sopra il conguaglio, che resta con la sua via d'uscita. -->
        <div v-if="conguaglio && haCoppie && pianoDaRicalcolare" role="alert" data-testid="piano-da-ricalcolare" class="rounded-lg border border-amber-300 bg-amber-50 dark:border-amber-700/60 dark:bg-amber-900/10 p-3 flex items-start gap-2.5">
          <AlertTriangle class="w-4 h-4 shrink-0 mt-0.5 text-amber-600" />
          <div class="space-y-1.5 min-w-0">
            <p class="text-[12px] text-amber-900 dark:text-amber-200 leading-relaxed"><FraseConImporti :testo="pianoDaRicalcolare" /></p>
            <p class="text-[11px] text-amber-800/90 dark:text-amber-300/90 leading-snug">Finché non ricalcoli il piano o scegli di non scrivere il conguaglio, il passaggio non si registra.</p>
          </div>
        </div>

        <!-- Il conguaglio proposto, per gestione: le righe in saldi e la rinuncia motivata (S5), o la scelta della successione (.48). -->
        <div v-if="conguaglio" class="rounded-lg border border-indigo-200 dark:border-indigo-800/60 overflow-hidden">
          <table class="w-full table-fixed text-[12px]">
            <colgroup><col class="w-[44%]" /><col class="w-[30%]" /><col class="w-[26%]" /></colgroup>
            <tbody class="divide-y divide-indigo-100 dark:divide-indigo-900/40">
              <tr v-for="g in conguaglio.per_gestione" :key="`${g.gestione_id}-${g.immobile_id}`" class="align-top">
                <td class="px-3 py-1.5 text-slate-800 dark:text-slate-200 truncate">
                  <span class="font-medium">{{ g.gestione ?? 'gestione' }}</span>
                  <span v-if="conguaglio.per_gestione.some(x => x.immobile_id !== g.immobile_id)" class="block text-[10px] text-slate-400 truncate">{{ g.immobile_nome }}</span>
                </td>
                <td class="px-3 py-1.5 text-[11px] text-slate-500 dark:text-slate-400 leading-snug">
                  <!-- Fase 1-ter della beta.41: la competenza è nota, è la parte che passa a non separarsi (quote della 1.7.x, catene). -->
                  <!-- Decisione 35 (beta.42): quote che il passaggio di prima non ha fatto passare. -->
                  <template v-if="(g.mai_passate ?? 0) > 0 && g.importo === 0">non passate col passaggio di prima</template>
                  <template v-else-if="(g.non_separabili ?? 0) > 0 && g.importo === 0">parte che passa non separabile</template>
                  <template v-else-if="g.non_risolte > 0 && g.importo === 0">competenza non determinabile</template>
                  <!-- V1 della verifica a video (beta.38): l'etichetta dipende dalla natura — nella riserva resta fuori l'ordinaria. -->
                  <template v-else-if="g.escluse > 0 && g.importo === 0">{{ etichettaEsclusa(g) }}</template>
                  <!-- Straordinario: il gradino è quello congelato sulle righe (dichiarata sulla fattura, o la delibera), non un'etichetta fissa (S8-4). -->
                  <!-- Decisione 26 (1.11.0-beta.35): anche sull'ordinaria, una voce del piano rate straordinario con la competenza dichiarata sulla fattura mostra le sue date;
                       con voci miste la colonna dice «voce per voce» (R5 della Fase 1-bis, `competenzaDellaGestione`). -->
                  <template v-else-if="g.natura === 'straordinaria' || g.gradino.includes('dichiarata')">{{ competenzaDellaGestione(g, GRADINI).etichetta }}<span v-if="competenzaDellaGestione(g, GRADINI).intervallo" class="block">{{ competenzaDellaGestione(g, GRADINI).intervallo }}<template v-if="g.regola !== 'piano' && g.giorni_uscente !== null && g.giorni_uscente !== undefined"> · giorni {{ g.giorni_uscente }} / {{ g.giorni_entrante }}</template></span><span v-else-if="competenzaDellaGestione(g, GRADINI).giorno"> · {{ competenzaDellaGestione(g, GRADINI).giorno }}</span></template>
                  <!-- Coda 245: con la regola del piano la cifra non nasce dai giorni di chi esce e di chi entra, ma dalla parte dell'inquilino divisa per quota e per giorni fra chi è stato conduttore. -->
                  <template v-else-if="g.regola === 'piano'">regola del piano<span class="block">per quota e per giorni</span></template>
                  <template v-else>giorni {{ g.giorni_uscente ?? '—' }} / {{ g.giorni_entrante ?? '—' }}<span v-if="g.gradino[0]" class="block">{{ GRADINI[g.gradino[0]] ?? g.gradino[0] }}</span></template>
                  <span v-if="g.bozze_passate > 0" class="block">intero piano <span class="whitespace-nowrap">{{ g.importo_lordo_formattato }}</span> − bozze <span class="whitespace-nowrap">{{ g.bozze_passate_formattato }}</span></span>
                </td>
                <td class="px-3 py-1.5 text-right tabular-nums font-semibold whitespace-nowrap" :class="g.importo === 0 ? 'text-slate-400' : 'text-indigo-800 dark:text-indigo-300'">
                  {{ g.importo === 0 ? '—' : cifra(g.importo_formattato) }} <span v-if="g.importo !== 0 && aCredito(g.importo_formattato)" class="block text-[10px] font-normal">a credito</span>
                </td>
              </tr>
            </tbody>
            <!-- Più nudi proprietari all'estinzione dell'usufrutto: una coppia per nudo, con il nome (S8-30). -->
            <tbody v-if="righePerPersona" class="border-t border-indigo-200 dark:border-indigo-800/60 divide-y divide-indigo-100 dark:divide-indigo-900/40">
              <tr v-for="(c, i) in conguaglio.coppie" :key="`coppia-${i}`" class="align-top text-[11px]">
                <td class="px-3 py-1 text-slate-500 dark:text-slate-400 truncate">{{ c.gestione ?? 'gestione' }}</td>
                <td class="px-3 py-1 text-slate-700 dark:text-slate-300 truncate">{{ aChi(c.entrante_nome ?? 'chi entra') }}</td>
                <td class="px-3 py-1 text-right tabular-nums text-indigo-800 dark:text-indigo-300 whitespace-nowrap">{{ cifra(c.importo_formattato) }} <span v-if="aCredito(c.importo_formattato)" class="block text-[10px]">a credito</span></td>
              </tr>
            </tbody>
            <tfoot class="bg-indigo-50/60 dark:bg-indigo-900/20 border-t border-indigo-200 dark:border-indigo-800/60">
              <tr>
                <td colspan="2" class="px-3 py-1.5 text-[11px] font-semibold uppercase tracking-wider text-indigo-800 dark:text-indigo-300">
                  <!-- Rilievo L2 della Fase 1-bis: con più eredi le coppie hanno segni diversi, e la somma non è il debito di qualcuno. -->
                  <template v-if="haCoppie && nonScritto">Non scritte: nessuna riga in saldi</template>
                  <template v-else-if="haCoppie && arretrato && piuEntranti">{{ legato ? 'Somma delle coppie' : 'Somma delle coppie degli eredi' }}</template>
                  <!-- Decisione 77 (1.11.0-beta.50): alla fine di una locazione senza un nuovo inquilino nessuno entra. -->
                  <!-- Coda 245: con la regola del piano chi riceve può avere un credito e chi esce un debito; le righe qui sopra dicono il verso di ognuno. -->
                  <template v-else-if="haCoppie && senzaSuccessore && conLaRegolaDelPiano">{{ rovesciato ? 'Debito a chi esce, credito a chi riceve' : 'Credito a chi esce, debito a chi riceve' }}</template>
                  <template v-else-if="haCoppie && senzaSuccessore">{{ rovesciato ? 'Credito a chi paga dopo l\'uscita, debito a chi esce' : 'Debito a chi paga dopo l\'uscita, credito a chi esce' }}</template>
                  <template v-else-if="haCoppie && rovesciato">Credito a chi entra, debito a chi esce</template>
                  <template v-else-if="haCoppie">Debito a chi entra, credito a chi esce</template>
                  <template v-else>Nessuna riga in saldi da questo passaggio</template>
                </td>
                <td class="px-3 py-1.5 text-right tabular-nums font-bold text-indigo-900 dark:text-indigo-200 whitespace-nowrap">{{ haCoppie && !nonScritto ? conguaglio.totale_entrante_assoluto_formattato + (arretrato && piuEntranti && rovesciato ? ' a credito' : '') : '—' }}</td>
              </tr>
            </tfoot>
          </table>

          <p v-if="haCoppie && arretratoAgliEredi" class="px-3 py-2.5 border-t border-indigo-100 dark:border-indigo-900/40 text-[11px] text-slate-500 dark:text-slate-400 leading-snug bg-white dark:bg-slate-900">
            Con l'arretrato agli eredi il conguaglio e l'arretrato fanno un conto solo: insieme danno a ogni erede la sua quota di tutto ciò che il defunto ha lasciato aperto. Per non scrivere il conguaglio, lascia l'arretrato a nome del defunto.
          </p>
          <!-- Decisioni 72 e 73 (1.11.0-beta.48): nella successione e nel legato, con l'arretrato a nome del defunto, due voci e nessuna
               già scelta, al posto della casella; «Non scriverlo» non dichiara un accordo e la nota è facoltativa. Decisione 77
               (1.11.0-beta.50): le stesse due voci alla fine di una locazione, con i testi della locazione; una scelta sola per
               l'appartamento e le pertinenze spuntate. -->
          <div v-else-if="haCoppie && sceltaRichiesta" class="px-3 py-3 border-t border-indigo-100 dark:border-indigo-900/40 space-y-2 bg-white dark:bg-slate-900">
            <p class="text-[12px] text-slate-700 dark:text-slate-300 leading-snug">Il conguaglio si sceglie: il programma non lo sceglie al posto tuo.</p>
            <label class="flex items-start gap-2 cursor-pointer">
              <input type="radio" value="scrivi" v-model="sceltaConguaglio" class="mt-0.5 accent-indigo-600" />
              <span class="text-[12px] text-slate-700 dark:text-slate-300 leading-snug">
                <span class="font-medium">Scrivi il conguaglio</span>
                <span v-if="locazione && senzaSuccessore && regola === 'piano'" class="block text-[11px] text-slate-500">La parte dell'inquilino si ridivide per quota e per giorni fra chi è stato conduttore, come in un piano generato dopo l'uscita: le righe in saldi portano ognuno alla sua parte.</span>
                <!-- Coda 245: nel cambio d'inquilino la coppia è la differenza del piano, non i giorni: le righe portano ognuno alla sua parte. -->
                <span v-else-if="locazione && regola === 'piano'" class="block text-[11px] text-slate-500">I giorni dopo l'uscita passano a chi entra, e la parte dell'inquilino si ridivide con la regola del piano: le righe in saldi portano ognuno alla sua parte.</span>
                <!-- Coda 245: la coppia della regola del piano che lascia fuori una parte (la fine di chi restava dopo un'altra fine, il conduttore senza quote, i giorni che non copre nessuno). -->
                <span v-else-if="locazione && regola === 'piano_coppia'" class="block text-[11px] text-slate-500">Le righe in saldi sono quelle della regola del piano fra chi esce e chi riceve i suoi giorni: ciò che resta fuori il pannello lo dice, con la persona e la cifra.</span>
                <span v-else-if="locazione && regola === 'misto'" class="block text-[11px] text-slate-500">Le righe in saldi portano ognuno alla sua parte: dove la locazione continua con un coinquilino la voce si ridivide per quota e per giorni fra i conduttori, altrove i giorni dopo l'uscita passano {{ senzaSuccessore ? 'a chi paga al posto dell\'inquilino' : 'a chi entra' }}.</span>
                <span v-else-if="locazione && regola === 'misto_coppia'" class="block text-[11px] text-slate-500">Dove la locazione continua con un coinquilino le righe in saldi sono quelle della regola del piano, e ciò che resta fuori il pannello lo dice, con la persona e la cifra; altrove i giorni dopo l'uscita passano {{ senzaSuccessore ? 'a chi paga al posto dell\'inquilino' : 'a chi entra' }}.</span>
                <span v-else-if="locazione && senzaSuccessore" class="block text-[11px] text-slate-500">I giorni dopo l'uscita passano a chi paga al posto dell'inquilino{{ piuEntranti ? ', a ognuno per la sua parte' : '' }}: le righe in saldi rimettono ognuno ai suoi giorni.</span>
                <span v-else-if="locazione" class="block text-[11px] text-slate-500">I giorni dopo l'uscita passano a chi entra: le righe in saldi rimettono ognuno ai suoi giorni.</span>
                <span v-else class="block text-[11px] text-slate-500">Le righe in saldi rimettono ognuno ai suoi giorni{{ piuEntranti ? (legato ? ', una coppia per ognuno' : ', una coppia per ogni erede') : '' }}.</span>
              </span>
            </label>
            <label class="flex items-start gap-2 cursor-pointer">
              <input type="radio" value="non_scrivere" v-model="sceltaConguaglio" class="mt-0.5 accent-indigo-600" />
              <span class="text-[12px] text-slate-700 dark:text-slate-300 leading-snug">
                <span class="font-medium">Non scriverlo: la posizione resta com'è</span>
                <span class="block text-[11px] text-slate-500">
                  <!-- Decisione 77, strada A′: senza righe in saldi i giorni dopo l'uscita restano nelle rate di chi esce, e il prospetto degli
                       oneri accessori dice chi li rimborsa. -->
                  <template v-if="locazione && conLaRegolaDelPiano">Nessuna riga in saldi: le rate restano come sono, e le parti regolano la differenza fra loro con il prospetto degli oneri accessori.<template v-if="esclusiDove">{{ ' ' + esclusiDove }}</template></template>
                  <template v-else-if="locazione && senzaSuccessore">Nessuna riga in saldi: i giorni dopo l'uscita restano nelle rate di chi esce, e le parti li regolano fra loro con il prospetto degli oneri accessori.</template>
                  <!-- R10 della Fase 1-bis della .50: un piano della 1.10 il prospetto lo lascia fuori dopo il cambio, e lì il rimborso si regola fra le parti. -->
                  <template v-else-if="locazione && (dati?.rate.prospetto_esclude?.length ?? 0) > 0">Nessuna riga in saldi: i giorni dopo l'uscita restano nelle rate di chi esce. Il prospetto degli oneri accessori lascia fuori {{ dati!.rate.prospetto_esclude!.length === 1 ? 'il piano' : 'i piani' }} «{{ dati!.rate.prospetto_esclude!.join('», «') }}», generat{{ dati!.rate.prospetto_esclude!.length === 1 ? 'o' : 'i' }} da una versione precedente: lì chi entra glieli rimborsa fra le parti.<template v-if="prospettoRegolato.length">{{ ' ' + (prospettoRegolato.length === 1 ? 'Sul piano' : 'Sui piani') }} «{{ prospettoRegolato.join('», «') }}» il cambio d'inquilino di prima è stato regolato fra le parti, e per i giorni di quell'accordo il prospetto non fa rimborsare nessuno: anche lì il rimborso di chi entra si regola fra le parti.</template></template>
                  <!-- Giro stretto della .50 (G5): dopo un cambio d'inquilino regolato fra le parti, senza una fine «Scrivi» dopo, il prospetto non
                       segue la catena (decisione 83.3): il rimborso di chi entra a chi esce si regola fra le parti. -->
                  <template v-else-if="locazione && prospettoRegolato.length > 0">Nessuna riga in saldi. {{ prospettoRegolato.length === 1 ? 'Sul piano' : 'Sui piani' }} «{{ prospettoRegolato.join('», «') }}» il cambio d'inquilino di prima è stato regolato fra le parti, e per i giorni di quell'accordo il prospetto degli oneri accessori non fa rimborsare nessuno: il rimborso di chi entra a chi esce si regola fra le parti.</template>
                  <template v-else-if="locazione">Nessuna riga in saldi: i giorni dopo l'uscita restano nelle rate di chi esce, e chi entra glieli rimborsa con il prospetto degli oneri accessori.</template>
                  <!-- Rilievo AMM-5: passano le bozze dell'elenco, non tutte (quelle che scadono prima del decesso restano al defunto). -->
                  <template v-else-if="riassegnazione.length">Nessuna riga in saldi: le rate emesse restano al defunto, e le rate in bozza dell'elenco qui sopra passano {{ piuEntranti ? (legato ? 'a quello di riferimento' : 'all\'erede di riferimento') : 'a chi entra' }}.</template>
                  <template v-else>Nessuna riga in saldi: le rate restano a chi le ha.</template>
                </span>
              </span>
            </label>
            <p v-if="nonScritto && riassegnazione.length && piuEntranti && !locazione" class="pl-6 text-[11px] text-slate-500 leading-snug">Le rate in bozza passano {{ legato ? 'a quello di riferimento' : 'all\'erede di riferimento' }}, che le paga tutte: senza righe in saldi, su questo piano {{ legato ? 'gli altri' : 'gli altri eredi' }} non hanno niente da pagare.</p>
            <div v-if="nonScritto" class="pl-6 space-y-1">
              <Input :model-value="notaRinuncia" @update:model-value="(v: string | number) => notaRinuncia = String(v)" :placeholder="locazione ? 'Nota (facoltativa): per esempio, nessuno chiede i giorni dopo l\'uscita' : 'Nota (facoltativa): per esempio, gli eredi non chiedono il conguaglio'" class="h-8 text-[12px]" />
              <p v-if="notaSceltaTroppoCorta" class="text-[11px] text-amber-700 dark:text-amber-400">La nota è facoltativa, ma se la scrivi deve avere almeno 10 caratteri ({{ notaRinuncia.trim().length }}/10).</p>
            </div>
            <p v-if="sceltaConguaglio === null" class="text-[11px] text-amber-700 dark:text-amber-400">Scegli se scrivere il conguaglio.</p>
          </div>
          <div v-else-if="haCoppie" class="px-3 py-3 border-t border-indigo-100 dark:border-indigo-900/40 space-y-2 bg-white dark:bg-slate-900">
            <label class="flex items-start gap-2 cursor-pointer">
              <Checkbox :model-value="rinuncia" @update:model-value="(v: boolean | 'indeterminate') => rinuncia = v === true" class="mt-0.5" />
              <span class="text-[12px] text-slate-700 dark:text-slate-300 leading-snug">
                <span class="font-medium">Le parti hanno regolato il conguaglio fra loro</span>: non scrivere le righe in saldi.
                <span v-if="!tipo || tipo === 'vendita'" class="block text-[11px] text-slate-500">Vale fra venditore e acquirente («salvo diverso accordo», Cass. 11199/2021), non verso il condominio. La ragione resta nel passaggio.</span>
                <span v-else class="block text-[11px] text-slate-500">Vale fra le parti, non verso il condominio. La ragione resta nel passaggio.</span>
                <span v-if="riassegnazione.length" class="block text-[11px] text-slate-500">Le rate in bozza passano comunque a chi entra: la rinuncia riguarda solo le righe in saldi.</span>
              </span>
            </label>
            <div v-if="rinuncia" class="pl-6 space-y-1">
              <Input :model-value="notaRinuncia" @update:model-value="(v: string | number) => notaRinuncia = String(v)" placeholder="Es: regolato nel rogito del 30/04/2026, notaio Verdi" class="h-8 text-[12px]" />
              <p v-if="notaTroppoCorta && notaRinuncia.length > 0" class="text-[11px] text-amber-700 dark:text-amber-400">La ragione è troppo breve ({{ notaRinuncia.trim().length }}/10 caratteri).</p>
              <p v-else-if="notaTroppoCorta" class="text-[11px] text-slate-500">Scrivi perché (almeno 10 caratteri): è ciò che rileggerai fra un anno.</p>
            </div>
          </div>
        </div>

        <!-- Decisione 65 (2): l'arretrato del defunto, per erede, o la cifra che resta a suo nome. -->
        <div v-if="arretrato && (arretrato.eredi.length || fraseDellArretrato)" class="rounded-lg border border-emerald-200 dark:border-emerald-800/60 overflow-hidden">
          <p class="px-3 py-1.5 text-[11px] font-semibold uppercase tracking-wider text-emerald-800 dark:text-emerald-300 bg-emerald-50/60 dark:bg-emerald-900/20">
            {{ arretratoAgliEredi ? 'Arretrato del defunto agli eredi' : 'Arretrato del defunto' }}
          </p>
          <table v-if="arretratoAgliEredi && arretrato.eredi.length" class="w-full table-fixed text-[12px]">
            <tbody class="divide-y divide-emerald-100 dark:divide-emerald-900/40">
              <tr v-for="e in arretrato.eredi" :key="e.anagrafica_id">
                <td class="px-3 py-1 text-slate-700 dark:text-slate-300 truncate">{{ aChi(e.nome) }}</td>
                <td class="px-3 py-1 text-right tabular-nums text-emerald-800 dark:text-emerald-300 whitespace-nowrap">{{ cifra(e.importo_formattato) }} <span v-if="aCredito(e.importo_formattato)" class="block text-[10px]">a credito</span></td>
              </tr>
            </tbody>
          </table>
          <p v-if="fraseDellArretrato" class="px-3 py-2 text-[11px] text-slate-600 dark:text-slate-400 leading-snug border-t border-emerald-100 dark:border-emerald-900/40"><FraseConImporti :testo="fraseDellArretrato" /></p>
        </div>
      </section>

      <!-- 3. Chi resta obbligato -->
      <section class="p-5">
        <div class="rounded-lg border border-amber-200 bg-amber-50 dark:border-amber-800/50 dark:bg-amber-900/10 p-4 space-y-2">
          <h4 class="flex items-center gap-2 text-[10px] font-bold uppercase tracking-widest text-amber-800 dark:text-amber-300">
            <ShieldCheck class="w-3.5 h-3.5" /> 3. Chi resta obbligato
          </h4>
          <p v-for="(f, i) in dati.obbligati.frasi" :key="i" class="text-sm text-amber-900 dark:text-amber-200 leading-relaxed"><FraseConImporti :testo="f" /></p>
        </div>
      </section>

      <!-- 4. Cosa non cambia -->
      <section class="p-5 space-y-2">
        <h4 class="flex items-center gap-2 text-[10px] font-bold uppercase tracking-widest text-slate-500 dark:text-slate-400">
          <Info class="w-3.5 h-3.5" /> 4. Cosa non cambia
        </h4>
        <ul class="space-y-1">
          <li v-for="(f, i) in dati.invarianti.frasi" :key="i" class="text-[13px] text-slate-700 dark:text-slate-300 leading-relaxed flex gap-2">
            <span class="text-slate-300 dark:text-slate-600 select-none">—</span><span><FraseConImporti :testo="f" /></span>
          </li>
        </ul>
      </section>
    </div>
  </aside>
</template>
