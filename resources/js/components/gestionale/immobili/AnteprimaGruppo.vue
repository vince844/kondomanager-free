<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Il pannello «Cosa cambierà» quando escono più persone insieme (tappa 7 della 1.12.0-beta.14, decisione 705): la vendita, anche con la riserva
 * d'usufrutto, di due comproprietari che vendono con lo stesso atto. Il guscio è quello di `AnteprimaPassaggio.vue` (card sticky a destra, bordo
 * tratteggiato, stessi tre stati: in attesa, errore, pronto), e come lì **il componente non calcola nulla**: legge i dati che il server manda
 * (`AnteprimaGruppoDati`, §1.9 di `docs/riferimenti/beta14_1_12_lavori/tappa7_progetto.md`) e li mostra. Le cifre di ogni parte sono quelle che si
 * scriveranno: il server registra in prova i passaggi uno dopo l'altro e li toglie.
 *
 * Quattro blocchi come oggi, ma con il fatto detto una volta per tutte: (1) la frase di chi entra; (2) una parte a cassetto per persona che esce, che
 * usa la sezione delle rate del pannello di oggi da sola (`AnteprimaPassaggio` con `solo-rate`), e il riquadro «In tutto» con quello che arriva a chi
 * entra e la casella «Le parti hanno regolato il conguaglio fra loro», una per tutte; (3) chi resta obbligato, con i nomi di tutte; (4) cosa non cambia,
 * con le teste contate una volta prima e dopo tutti i passaggi. Il cancello (la spunta e la nota) sta nel modulo della pagina, raggruppato per persona.
 */
import { computed } from 'vue';
import { AlertTriangle, ChevronRight, Info, LoaderCircle, Receipt, Scale, ShieldCheck, Users, Layers } from 'lucide-vue-next';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import AnteprimaPassaggio from './AnteprimaPassaggio.vue';
import FraseConImporti from './FraseConImporti.vue';
import type { AnteprimaGruppoDati } from '@/types/gestionale/passaggi';
import { percentuale } from '@/lib/gestionale/passaggi/percentuale';

const props = defineProps<{
  dati: AnteprimaGruppoDati | null;
  /** Il server sta calcolando: si tiene l'ultimo pannello e si mostra l'attesa, senza svuotare. */
  inCorso: boolean;
  /** Il server non ha risposto (rete, 500). Non è una validazione: quella arriva come `bloccato`. */
  errore: boolean;
  /** Il server ha rifiutato un dato (422): l'errore è sotto il campo, qui si dice solo che si aspetta. */
  bloccato?: boolean;
  /** Cosa manca al modulo perché il server possa rispondere; vuoto se è completo. */
  mancante: string[];
}>();

/** La rinuncia alle coppie proposte e la sua ragione: una per tutte le persone che escono, stato del modulo. */
const rinuncia = defineModel<boolean>('rinuncia', { default: false });
const notaRinuncia = defineModel<string>('notaRinuncia', { default: '' });

const pronto = computed(() => props.dati !== null && !props.errore);
const numero = computed(() => props.dati?.parti.length ?? 0);
/** C'è almeno una coppia da scrivere, in una qualsiasi parte: solo allora ha senso poter rinunciare. */
const haCoppie = computed(() => (props.dati?.parti ?? []).some((p) => (p.anteprima.rate.conguaglio?.coppie.length ?? 0) > 0));
/** Il piano da ricalcolare prima: la frase la scrive il server nella parte che lo trova, qui si dice una volta. */
const pianoDaRicalcolare = computed(() => (props.dati?.parti ?? []).map((p) => p.anteprima.rate.piano_da_ricalcolare).find((f) => !!f) ?? null);
const notaTroppoCorta = computed(() => rinuncia.value && notaRinuncia.value.trim().length < 10);

/** Il riassunto in riga di una parte chiusa: «11 quote emesse · € 440,00 · conguaglio € 20,49». */
function riassunto(i: number): string {
  const rate = props.dati!.parti[i].anteprima.rate;
  const pezzi: string[] = [];
  if (rate.emesse.length) pezzi.push(`${rate.emesse.length} ${rate.emesse.length === 1 ? 'quota emessa' : 'quote emesse'}`, rate.totale_emesso_formattato);
  if (rate.conguaglio && rate.conguaglio.coppie.length) pezzi.push(`conguaglio ${rate.conguaglio.totale_entrante_assoluto_formattato}`);

  return pezzi.join(' · ');
}
</script>

<template>
  <aside class="lg:sticky lg:top-6 lg:max-h-[calc(100vh-3rem)] space-y-0 rounded-xl border-2 border-dashed border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 shadow-sm overflow-hidden lg:overflow-y-auto">
    <div class="lg:sticky lg:top-0 lg:z-10 px-5 py-4 border-b border-dashed border-slate-200 dark:border-slate-700 bg-slate-50/70 dark:bg-slate-800/40 lg:bg-slate-50 dark:lg:bg-slate-800 flex items-start gap-3">
      <div class="p-2 rounded-lg bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300 shrink-0">
        <Scale class="w-4 h-4" />
      </div>
      <div class="min-w-0">
        <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100 flex flex-wrap items-center gap-2">
          Cosa cambierà
          <span v-if="dati" data-testid="badge-insieme" class="inline-flex items-center gap-1 rounded-md border border-indigo-200 bg-indigo-50 px-2 py-0.5 text-xs font-semibold text-indigo-700 dark:border-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-300">{{ numero }} passaggi insieme</span>
        </h3>
        <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5 leading-snug">
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
        <p class="text-xs mt-1 opacity-80">Finché il pannello non risponde, i passaggi non si possono registrare. Nessun importo viene mostrato al posto di quello vero.</p>
      </div>
    </div>

    <!-- Bloccato: un dato del modulo non passa la validazione del server. -->
    <div v-else-if="bloccato" class="p-5 flex items-start gap-3 text-sm text-amber-900 dark:text-amber-200 bg-amber-50/70 dark:bg-amber-900/10">
      <AlertTriangle class="w-5 h-5 shrink-0 mt-0.5 text-amber-600" />
      <div>
        <p class="font-semibold">Un dato del modulo non è accettabile.</p>
        <p class="text-xs mt-1 opacity-80">L'errore è segnato sotto il campo (o sotto la persona): correggilo e il pannello riparte da lì. Finché non riparte, i passaggi non si registrano.</p>
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
        <h4 class="flex items-center gap-2 text-xs font-bold uppercase tracking-widest text-slate-500 dark:text-slate-400">
          <Users class="w-3.5 h-3.5" /> 1. Anagrafica
        </h4>
        <p v-for="(f, i) in dati.anagrafica.frasi" :key="i" class="text-sm text-slate-800 dark:text-slate-200 leading-relaxed"><FraseConImporti :testo="f" /></p>
        <p v-if="dati.anagrafica.pertinenze.length" class="text-xs text-slate-500 dark:text-slate-400">
          Pertinenze incluse: {{ dati.anagrafica.pertinenze.join(', ') }}.
        </p>
      </section>

      <!-- 2. Rate già emesse: una parte per persona che esce, e «In tutto» -->
      <section class="p-5 space-y-3">
        <h4 class="flex items-center gap-2 text-xs font-bold uppercase tracking-widest text-slate-500 dark:text-slate-400">
          <Receipt class="w-3.5 h-3.5" /> 2. Rate già emesse
          <span v-if="haCoppie && pianoDaRicalcolare && !rinuncia" class="ml-auto inline-flex items-center gap-1 rounded-md bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300 px-2 py-0.5 text-xs normal-case tracking-normal font-semibold">
            <AlertTriangle class="w-3 h-3" /> piano da ricalcolare
          </span>
          <span v-else-if="haCoppie && !rinuncia" class="ml-auto inline-flex items-center gap-1 rounded-md bg-indigo-100 text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-300 px-2 py-0.5 text-xs normal-case tracking-normal font-semibold">
            conguaglio proposto
          </span>
          <span v-else-if="haCoppie && rinuncia" class="ml-auto inline-flex items-center gap-1 rounded-md bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300 px-2 py-0.5 text-xs normal-case tracking-normal font-semibold">
            regolato fra le parti
          </span>
        </h4>

        <p class="text-sm text-slate-700 dark:text-slate-300 leading-relaxed">
          Le rate già emesse non si toccano. Il conguaglio è proposto per ciascuna persona che esce, come righe di saldo che sommano zero.
        </p>

        <details v-for="(parte, i) in dati.parti" :key="parte.riga_id" data-testid="parte" :open="i === 0" class="group rounded-lg border border-slate-200 dark:border-slate-700 overflow-hidden">
          <summary class="flex flex-wrap items-center gap-x-2 gap-y-1 px-3 py-2.5 cursor-pointer select-none list-none [&::-webkit-details-marker]:hidden bg-slate-50 dark:bg-slate-800/50 text-sm font-semibold text-slate-900 dark:text-slate-100">
            <ChevronRight class="w-3.5 h-3.5 shrink-0 text-slate-500 transition-transform group-open:rotate-90" />
            <span>La parte di {{ parte.nome }}</span>
            <span class="inline-flex items-center rounded-md border border-slate-200 bg-white px-2 py-0.5 text-xs font-semibold text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 tabular-nums">{{ percentuale(parte.quota) }}</span>
            <span class="ml-auto text-xs font-normal text-slate-500 dark:text-slate-400 tabular-nums">{{ riassunto(i) }}</span>
          </summary>
          <div class="p-3 space-y-3">
            <p v-if="parte.pertinenze.length" class="text-xs text-slate-500 dark:text-slate-400">Pertinenze incluse: {{ parte.pertinenze.join(', ') }}.</p>
            <AnteprimaPassaggio :dati="parte.anteprima" solo-rate :in-corso="false" :errore="false" :mancante="[]" tipo="vendita" />
          </div>
        </details>

        <!-- In tutto: quello che arriva a chi entra, la frase la scrive il server. -->
        <div data-testid="in-tutto" class="rounded-lg border border-emerald-200 bg-emerald-50 dark:border-emerald-800/50 dark:bg-emerald-900/10 px-3 py-2.5 text-sm text-emerald-950 dark:text-emerald-100 leading-relaxed">
          <FraseConImporti :testo="dati.totale.frase" />
        </div>

        <!-- La rinuncia è una per tutte: se una sola ha regolato fra le parti, si registrano due passaggi. -->
        <div v-if="haCoppie" class="rounded-lg border border-slate-200 dark:border-slate-700 px-3 py-3 space-y-2 bg-white dark:bg-slate-900">
          <label class="flex items-start gap-2 cursor-pointer">
            <Checkbox :model-value="rinuncia" @update:model-value="(v: boolean | 'indeterminate') => rinuncia = v === true" class="mt-0.5" />
            <span class="text-sm text-slate-700 dark:text-slate-300 leading-snug">
              <span class="font-medium">Le parti hanno regolato il conguaglio fra loro</span>: non scrivere le righe in saldi.
              <span class="block text-xs text-slate-500 dark:text-slate-400">Vale per tutte le persone che escono, fra chi vende e chi compra («salvo diverso accordo», Cass. 11199/2021), non verso il condominio. La ragione resta in ogni passaggio. Se ha regolato una persona sola, registra due passaggi.</span>
              <span v-if="dati.totale.bozze > 0" class="block text-xs text-slate-500 dark:text-slate-400">Le rate in bozza passano comunque a chi entra: la rinuncia riguarda solo le righe in saldi.</span>
            </span>
          </label>
          <div v-if="rinuncia" class="pl-6 space-y-1">
            <Input :model-value="notaRinuncia" @update:model-value="(v: string | number) => notaRinuncia = String(v)" placeholder="Es: regolato nel rogito del 30/04/2026, notaio Verdi" class="h-8 text-xs" />
            <p v-if="notaTroppoCorta && notaRinuncia.length > 0" class="text-xs text-amber-700 dark:text-amber-400">La ragione è troppo breve ({{ notaRinuncia.trim().length }}/10 caratteri).</p>
            <p v-else-if="notaTroppoCorta" class="text-xs text-slate-500">Scrivi perché (almeno 10 caratteri): è ciò che rileggerai fra un anno.</p>
          </div>
        </div>
      </section>

      <!-- 3. Chi resta obbligato: una frase sola, con tutti i nomi -->
      <section class="p-5">
        <div class="rounded-lg border border-amber-200 bg-amber-50 dark:border-amber-800/50 dark:bg-amber-900/10 p-4 space-y-2">
          <h4 class="flex items-center gap-2 text-xs font-bold uppercase tracking-widest text-amber-800 dark:text-amber-300">
            <ShieldCheck class="w-3.5 h-3.5" /> 3. Chi resta obbligato
          </h4>
          <p v-for="(f, i) in dati.obbligati.frasi" :key="i" class="text-sm text-amber-900 dark:text-amber-200 leading-relaxed"><FraseConImporti :testo="f" /></p>
        </div>
      </section>

      <!-- 4. Cosa non cambia: le teste una volta, prima e dopo tutti i passaggi -->
      <section class="p-5 space-y-2">
        <h4 class="flex items-center gap-2 text-xs font-bold uppercase tracking-widest text-slate-500 dark:text-slate-400">
          <Layers class="w-3.5 h-3.5" /> 4. Cosa non cambia
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
