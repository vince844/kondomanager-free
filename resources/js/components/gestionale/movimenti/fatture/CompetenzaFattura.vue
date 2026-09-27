<script setup lang="ts">
/**
 * La competenza della fattura (1.11.0-beta.31, B2 S6): due estremi sulla testata, `competenza_dal` e
 * `competenza_al`, dichiarati in uno di due modi — un periodo («costo maturato dal … al …») o un giorno
 * («spesa deliberata il …», che scrive la stessa data nei due campi: è la delibera puntuale, D2).
 *
 * Il testo dice la verità intera: il motore la legge quando la fattura è ripartita da un **piano rate straordinario**
 * — quello che finanzia le fatture — **qualunque sia la gestione** (decisione 26, 1.11.0-beta.35: fino alla beta.34
 * solo su gestione straordinaria). Nel piano ordinario per capitoli non sposta le rate (decisione 19): decide il
 * periodo della voce, altrimenti quello della gestione o dell'esercizio. Il testo non dice chi la leggerà in futuro:
 * prometteva «il rendiconto e il prospetto degli oneri», e il prospetto della beta.34 legge il preventivo. E va
 * dichiarata prima di mettere la fattura in un piano, perché dentro un piano approvato la fattura non si modifica più.
 *
 * Sulla **pregressa con una parte non coperta** dai saldi iniziali il periodo è obbligatorio e si chiude prima
 * dell'esercizio (decisione 26, punto 2): la regola sta in `StoreFatturaRequest`, qui si annuncia prima dell'invio.
 *
 * Entrambi gli estremi o nessuno: il motore con uno solo scende al gradino successivo in silenzio, quindi
 * «Nessuna» azzera tutti e due e il modo «deliberata il» li tiene uguali.
 */
import { computed, ref, watch } from 'vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { CalendarRange, Gavel, Minus } from 'lucide-vue-next';

const dal = defineModel<string>('dal', { default: '' });
const al = defineModel<string>('al', { default: '' });

const props = defineProps<{
    /** La gestione scelta sulla fattura è straordinaria: si dice, senza promettere che decida (decide il piano). */
    gestioneStraordinaria?: boolean;
    /** Fattura pregressa (costo di un esercizio passato): l'eccedenza non coperta dai saldi iniziali va a straordinario. */
    pregressa?: boolean;
    /** Pregressa con una parte non coperta dai saldi iniziali: il periodo è obbligatorio (decisione 26). */
    periodoObbligatorio?: boolean;
    /** L'inizio dell'esercizio della fattura (AAAA-MM-GG): il periodo di una pregressa si chiude prima. */
    inizioEsercizio?: string | null;
    errori?: { dal?: string; al?: string };
}>();

type Modo = 'nessuna' | 'periodo' | 'delibera';

const modoDaiValori = (): Modo => {
    if (!dal.value && !al.value) return 'nessuna';
    return dal.value && dal.value === al.value ? 'delibera' : 'periodo';
};

const modo = ref<Modo>(modoDaiValori());

// Chi precompila da fuori (la «Data assemblea» del modale della spesa imprevista, il reset del form)
// deve trovare il pannello coerente con i valori, non con l'ultimo clic.
watch([dal, al], () => {
    const atteso = modoDaiValori();
    if (atteso === 'nessuna' || modo.value === 'nessuna') modo.value = atteso;
});

const scegli = (m: Modo) => {
    modo.value = m;
    if (m === 'nessuna') {
        dal.value = '';
        al.value = '';
    } else if (m === 'delibera') {
        al.value = dal.value;
    }
};

const giorno = computed({
    get: () => dal.value,
    set: (v: string) => {
        dal.value = v;
        al.value = v;
    },
});

const errore = computed(() => props.errori?.dal || props.errori?.al || '');

const inizioEsercizioIt = computed(() => (props.inizioEsercizio ? props.inizioEsercizio.slice(0, 10).split('-').reverse().join('/') : ''));

const MODI: { id: Modo; label: string; icona: any }[] = [
    { id: 'nessuna', label: 'Nessuna', icona: Minus },
    { id: 'periodo', label: 'Costo maturato dal … al …', icona: CalendarRange },
    { id: 'delibera', label: 'Spesa deliberata il …', icona: Gavel },
];
</script>

<template>
    <div class="space-y-2">
        <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Competenza</Label>

        <!-- Una scelta esclusiva, una riga per modo a tutta larghezza: nella colonna stretta del modulo i tre bottoni in fila
             andavano a capo in modo irregolare (segnalato da Vincenzo a video il 27/09/2026). Le etichette non cambiano: le
             citano altri testi del programma e la guida del sito. -->
        <div class="grid grid-cols-1 gap-1.5" role="radiogroup" aria-label="Competenza">
            <button
                v-for="m in MODI"
                :key="m.id"
                type="button"
                role="radio"
                :aria-checked="modo === m.id"
                @click="scegli(m.id)"
                :class="[
                    'flex w-full items-center gap-2 rounded-md border px-3 py-2 text-left text-[11px] font-semibold transition-colors',
                    modo === m.id
                        ? 'border-primary/40 bg-primary/5 text-primary'
                        : 'border-slate-200 bg-white text-slate-500 hover:border-slate-300 hover:text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400',
                ]">
                <component :is="m.icona" class="h-3.5 w-3.5 shrink-0" />
                <span class="min-w-0">{{ m.label }}</span>
            </button>
        </div>

        <div v-if="modo === 'periodo'" class="grid grid-cols-2 gap-3">
            <div class="space-y-1.5 col-span-2 md:col-span-1">
                <Label class="text-[10.5px] font-semibold text-slate-500">Dal</Label>
                <Input type="date" v-model="dal" class="h-9 text-sm" />
            </div>
            <div class="space-y-1.5 col-span-2 md:col-span-1">
                <Label class="text-[10.5px] font-semibold text-slate-500">Al</Label>
                <Input type="date" v-model="al" :min="dal || undefined" class="h-9 text-sm" />
            </div>
        </div>

        <div v-else-if="modo === 'delibera'" class="space-y-1.5">
            <Label class="text-[10.5px] font-semibold text-slate-500">Giorno della delibera</Label>
            <Input type="date" v-model="giorno" class="h-9 text-sm" />
        </div>

        <p v-if="errore" class="text-[11px] font-medium text-red-600 dark:text-red-400">{{ errore }}</p>

        <p class="text-[10.5px] leading-relaxed text-slate-500">
            Guida le rate quando la fattura è ripartita da un piano rate straordinario, quello che finanzia le fatture
            fuori preventivo, anche su una gestione ordinaria: lì paga chi era titolare in quel periodo, o quel giorno.
            Nel piano ordinario per capitoli resta un dato della fattura e non sposta le rate: il riparto usa il periodo
            della voce o, se non ne ha uno, quello della gestione. Dichiarala prima di mettere la fattura in un piano:
            dentro un piano approvato la fattura non si modifica più.
        </p>
        <!-- Coda 155 (1.11.0-beta.34): sulla straordinaria la data che decide per legge è la delibera, e «Costo maturato»
             è il primo gradino (decisione 12, non si tocca) — la scavalca. Il pannello lo dice; la pregressa no, perché lì
             il periodo di maturazione è proprio ciò che serve (caso reale del 23/09/2026, `richieste_utenti.md`). -->
        <p v-if="gestioneStraordinaria && !pregressa && modo === 'periodo'" class="text-[10.5px] leading-relaxed text-amber-700 dark:text-amber-400">
            Sulla straordinaria decide la delibera che approva lavori e prezzo (art. 63 disp. att. c.c.; Cass. 24654/2010).
            «Costo maturato» la scavalca: con una vendita nel mezzo paga chi era titolare in questo periodo, non chi lo era
            il giorno della delibera. Se la spesa è stata deliberata, usa «Spesa deliberata il …».
        </p>
        <p v-else-if="gestioneStraordinaria && !pregressa" class="text-[10.5px] leading-relaxed text-slate-500">
            Sulla straordinaria decide la delibera che approva lavori e prezzo (art. 63 disp. att. c.c.; Cass. 24654/2010):
            se la dichiari qui, il modo è «Spesa deliberata il …».
        </p>
        <p v-if="gestioneStraordinaria && modo === 'nessuna'" class="text-[10.5px] leading-relaxed text-amber-700 dark:text-amber-400">
            La gestione di questa fattura è straordinaria: senza una competenza qui, il piano che la ripartisce userà la
            data della sua delibera, e senza nemmeno quella si fermerà.
        </p>
        <!-- La pregressa è un costo dell'anno prima: la parte non coperta dai saldi iniziali finisce in un piano, e senza il
             periodo il piano la ripartirebbe sui giorni di quest'anno (o sulla delibera di quest'anno) — dopo un passaggio
             pagherebbe chi è entrato, per un costo di quando l'unità era di chi è uscito. Dalla beta.35 il periodo è
             obbligatorio (decisione 26, punto 2): una pregressa non si modifica dopo, si storna. -->
        <p v-if="pregressa && periodoObbligatorio && modo === 'nessuna'" class="text-[10.5px] leading-relaxed text-amber-700 dark:text-amber-400">
            Fattura pregressa con una parte non coperta dai saldi iniziali: il periodo in cui il costo è maturato è
            obbligatorio<template v-if="inizioEsercizioIt">, e si chiude prima del {{ inizioEsercizioIt }}, quando inizia questo esercizio</template>.
            Quella parte finisce in un piano rate: senza il periodo, dopo un passaggio, la pagherebbe chi è entrato per un
            costo di quando l'unità era di chi è uscito. Dopo non si potrà aggiungere: una pregressa si storna, non si modifica.
        </p>
        <p v-else-if="pregressa && modo === 'nessuna'" class="text-[10.5px] leading-relaxed text-slate-500">
            Fattura pregressa: se una parte non è coperta dai saldi iniziali, qui andrà il periodo in cui il costo è
            maturato (l'esercizio passato).
        </p>
    </div>
</template>
