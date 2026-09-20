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
 */
import { computed, ref } from 'vue';
import { AlertTriangle, CalendarClock, Info, LoaderCircle, Receipt, Scale, ShieldCheck, Users, ArrowLeftRight } from 'lucide-vue-next';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import type { AnteprimaPassaggioDati } from '@/types/gestionale/passaggi';

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
}>();

/** La rinuncia alla coppia proposta e la sua ragione: stato del modulo, tenuto da chi ci sta sopra. */
const rinuncia = defineModel<boolean>('rinuncia', { default: false });
const notaRinuncia = defineModel<string>('notaRinuncia', { default: '' });

const pronto = computed(() => props.dati !== null && !props.errore);
const conguaglio = computed(() => props.dati?.rate.conguaglio ?? null);
/** C'è almeno una coppia da scrivere: solo allora ha senso poter rinunciare. */
const haCoppie = computed(() => (conguaglio.value?.coppie.length ?? 0) > 0);
// Le coppie hanno più di un «chi entra» (S8-30): si elencano con il nome, perché la tabella per gestione non lo dice.
const piuEntranti = computed(() => new Set((conguaglio.value?.coppie ?? []).map(c => c.anagrafica_entrante_id)).size > 1);
const notaTroppoCorta = computed(() => rinuncia.value && notaRinuncia.value.trim().length < 10);
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
  <aside class="lg:sticky lg:top-6 space-y-0 rounded-xl border-2 border-dashed border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 shadow-sm overflow-hidden">
    <div class="px-5 py-4 border-b border-dashed border-slate-200 dark:border-slate-700 bg-slate-50/70 dark:bg-slate-800/40 flex items-start gap-3">
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
        <p class="font-semibold">Un dato del modulo non è accettabile.</p>
        <p class="text-xs mt-1 opacity-80">L'errore è segnato sotto il campo: correggilo e il pannello riparte da lì. Finché non riparte, il passaggio non si registra.</p>
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
        <p v-for="(f, i) in dati.anagrafica.frasi" :key="i" class="text-sm text-slate-800 dark:text-slate-200 leading-relaxed">{{ f }}</p>
        <p v-if="dati.anagrafica.pertinenze.length" class="text-xs text-slate-500 dark:text-slate-400">
          Pertinenze incluse: {{ dati.anagrafica.pertinenze.join(', ') }}.
        </p>
      </section>

      <!-- 2. Rate già emesse -->
      <section class="p-5 space-y-3">
        <h4 class="flex items-center gap-2 text-[10px] font-bold uppercase tracking-widest text-slate-500 dark:text-slate-400">
          <Receipt class="w-3.5 h-3.5" /> 2. Rate già emesse
          <span v-if="conguaglio && haCoppie && !rinuncia" class="ml-auto inline-flex items-center gap-1 rounded-md bg-indigo-100 text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-300 px-1.5 py-0.5 text-[9px] normal-case tracking-normal font-semibold">
            <ArrowLeftRight class="w-3 h-3" /> conguaglio proposto
          </span>
          <span v-else-if="conguaglio && haCoppie && rinuncia" class="ml-auto inline-flex items-center gap-1 rounded-md bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300 px-1.5 py-0.5 text-[9px] normal-case tracking-normal font-semibold">
            regolato fra le parti
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
                  Emesso a {{ dati.rate.emesse[0].intestatario }}
                  <span class="font-normal normal-case tracking-normal text-slate-400"> · {{ dati.rate.emesse.length }} {{ dati.rate.emesse.length === 1 ? 'quota' : 'quote' }}</span>
                </td>
                <td class="px-3 py-1.5 text-right tabular-nums font-bold text-slate-900 dark:text-slate-100 whitespace-nowrap">{{ dati.rate.totale_emesso_formattato }}</td>
              </tr>
            </tfoot>
          </table>
        </div>

        <p v-for="(f, i) in dati.rate.frasi" :key="i" class="text-sm text-slate-800 dark:text-slate-200 leading-relaxed">{{ f }}</p>

        <!-- Il conguaglio proposto, per gestione: le due righe in saldi e la rinuncia motivata (S5). -->
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
                  <template v-if="g.non_risolte > 0 && g.importo === 0">competenza non determinabile</template>
                  <template v-else-if="g.escluse > 0 && g.importo === 0">straordinaria: resta al nudo proprietario</template>
                  <!-- Straordinario: il gradino è quello congelato sulle righe (dichiarata sulla fattura, o la delibera), non un'etichetta fissa (S8-4). -->
                  <template v-else-if="g.natura === 'straordinaria'">{{ GRADINI[g.gradino[0]] ?? GRADINI.delibera }}<span v-if="g.gradino[0] === 'dichiarata' && g.periodo?.length" class="block">{{ dataBreve(g.periodo[0].dal) }}–{{ dataBreve(g.periodo[g.periodo.length - 1].al) }}<template v-if="g.giorni_uscente !== null && g.giorni_uscente !== undefined"> · giorni {{ g.giorni_uscente }} / {{ g.giorni_entrante }}</template></span><span v-else-if="g.periodo?.[0]"> · {{ dataBreve(g.periodo[0].dal) }}</span></template>
                  <template v-else>giorni {{ g.giorni_uscente ?? '—' }} / {{ g.giorni_entrante ?? '—' }}<span v-if="g.gradino[0]" class="block">{{ GRADINI[g.gradino[0]] ?? g.gradino[0] }}</span></template>
                </td>
                <td class="px-3 py-1.5 text-right tabular-nums font-semibold whitespace-nowrap" :class="g.importo === 0 ? 'text-slate-400' : 'text-indigo-800 dark:text-indigo-300'">
                  {{ g.importo === 0 ? '—' : g.importo_formattato }}
                </td>
              </tr>
            </tbody>
            <!-- Più nudi proprietari all'estinzione dell'usufrutto: una coppia per nudo, con il nome (S8-30). -->
            <tbody v-if="piuEntranti" class="border-t border-indigo-200 dark:border-indigo-800/60 divide-y divide-indigo-100 dark:divide-indigo-900/40">
              <tr v-for="(c, i) in conguaglio.coppie" :key="`coppia-${i}`" class="align-top text-[11px]">
                <td class="px-3 py-1 text-slate-500 dark:text-slate-400 truncate">{{ c.gestione ?? 'gestione' }}</td>
                <td class="px-3 py-1 text-slate-700 dark:text-slate-300 truncate">a {{ c.entrante_nome }}</td>
                <td class="px-3 py-1 text-right tabular-nums text-indigo-800 dark:text-indigo-300 whitespace-nowrap">{{ c.importo_formattato }}</td>
              </tr>
            </tbody>
            <tfoot class="bg-indigo-50/60 dark:bg-indigo-900/20 border-t border-indigo-200 dark:border-indigo-800/60">
              <tr>
                <td colspan="2" class="px-3 py-1.5 text-[11px] font-semibold uppercase tracking-wider text-indigo-800 dark:text-indigo-300">
                  <template v-if="haCoppie">Debito a chi entra, credito a chi esce</template>
                  <template v-else>Nessuna riga in saldi da questo passaggio</template>
                </td>
                <td class="px-3 py-1.5 text-right tabular-nums font-bold text-indigo-900 dark:text-indigo-200 whitespace-nowrap">{{ haCoppie ? conguaglio.totale_entrante_formattato : '—' }}</td>
              </tr>
            </tfoot>
          </table>

          <div v-if="haCoppie" class="px-3 py-3 border-t border-indigo-100 dark:border-indigo-900/40 space-y-2 bg-white dark:bg-slate-900">
            <label class="flex items-start gap-2 cursor-pointer">
              <Checkbox :model-value="rinuncia" @update:model-value="(v: boolean | 'indeterminate') => rinuncia = v === true" class="mt-0.5" />
              <span class="text-[12px] text-slate-700 dark:text-slate-300 leading-snug">
                <span class="font-medium">Le parti hanno regolato il conguaglio fra loro</span>: non scrivere le due righe in saldi.
                <span class="block text-[11px] text-slate-500">Vale fra venditore e acquirente («salvo diverso accordo», Cass. 11199/2021), non verso il condominio. La ragione resta nel passaggio.</span>
              </span>
            </label>
            <div v-if="rinuncia" class="pl-6 space-y-1">
              <Input :model-value="notaRinuncia" @update:model-value="(v: string | number) => notaRinuncia = String(v)" placeholder="Es: regolato nel rogito del 30/04/2026, notaio Verdi" class="h-8 text-[12px]" />
              <p v-if="notaTroppoCorta && notaRinuncia.length > 0" class="text-[11px] text-amber-700 dark:text-amber-400">La ragione è troppo breve ({{ notaRinuncia.trim().length }}/10 caratteri).</p>
              <p v-else-if="notaTroppoCorta" class="text-[11px] text-slate-500">Scrivi perché (almeno 10 caratteri): è ciò che rileggerai fra un anno.</p>
            </div>
          </div>
        </div>
      </section>

      <!-- 3. Chi resta obbligato -->
      <section class="p-5">
        <div class="rounded-lg border border-amber-200 bg-amber-50 dark:border-amber-800/50 dark:bg-amber-900/10 p-4 space-y-2">
          <h4 class="flex items-center gap-2 text-[10px] font-bold uppercase tracking-widest text-amber-800 dark:text-amber-300">
            <ShieldCheck class="w-3.5 h-3.5" /> 3. Chi resta obbligato
          </h4>
          <p v-for="(f, i) in dati.obbligati.frasi" :key="i" class="text-sm text-amber-900 dark:text-amber-200 leading-relaxed">{{ f }}</p>
        </div>
      </section>

      <!-- 4. Cosa non cambia -->
      <section class="p-5 space-y-2">
        <h4 class="flex items-center gap-2 text-[10px] font-bold uppercase tracking-widest text-slate-500 dark:text-slate-400">
          <Info class="w-3.5 h-3.5" /> 4. Cosa non cambia
        </h4>
        <ul class="space-y-1">
          <li v-for="(f, i) in dati.invarianti.frasi" :key="i" class="text-[13px] text-slate-700 dark:text-slate-300 leading-relaxed flex gap-2">
            <span class="text-slate-300 dark:text-slate-600 select-none">—</span><span>{{ f }}</span>
          </li>
        </ul>
      </section>
    </div>
  </aside>
</template>
