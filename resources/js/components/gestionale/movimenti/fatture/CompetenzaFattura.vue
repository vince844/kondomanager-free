<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
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
 *
 * 1.12.0-beta.13 (§1.4 del progetto della tappa 2): nel modo «Spesa deliberata il …» la data viene dalla delibera scelta con «Scegli la delibera» (ruolo
 * `competenza`), con «Deliberata il … dalla delibera»; una data di prima, senza la delibera, resta e si dice «Scritta a mano». Il giorno della delibera lo
 * riscrive anche il server dalla delibera. Senza il condominio (le prove del componente da solo) resta il campo del giorno di prima.
 *
 * Coda 688: quando una voce della fattura sta in un piano d'urgenza (`lavoriUrgenti`, il giorno e il nome del piano) c'è un quarto modo, **«Lavori ordinati
 * il …»**, con le stesse parole del piano: un giorno solo nei due estremi, come la delibera, ma senza una delibera. Il pannello si apre lì quando la fattura
 * dice un giorno solo, e dice da quale piano viene; senza una competenza, sulla straordinaria, dice che il conguaglio dell'opera usa il giorno del piano.
 */
import { computed, ref, watch } from 'vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { CalendarRange, Gavel, Minus, Zap } from 'lucide-vue-next';
import ScegliLaDelibera from '@/components/assemblee/ScegliLaDelibera.vue';
import { giornoItaliano } from '@/lib/giorno';
import type { LavoriUrgenti } from '@/lib/gestionale/pianiRate/urgenza';
import type { DeliberaDelMenu } from '@/types/assemblee';

const dal = defineModel<string>('dal', { default: '' });
const al = defineModel<string>('al', { default: '' });
const delibera = defineModel<DeliberaDelMenu | null>('delibera', { default: null });

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
    /** Il condominio, per «Scegli la delibera»: senza, il modo «Spesa deliberata il …» ha il campo del giorno di prima. */
    condominioId?: number | null;
    condominioNome?: string;
    /** La fattura in modifica: la proposta la trova al livello del punto nato da questa fattura. */
    fatturaId?: number | null;
    /** Coda 688: il piano d'urgenza di una voce della fattura, con il giorno dei lavori. Con questo c'è il modo «Lavori ordinati il …». */
    lavoriUrgenti?: LavoriUrgenti | null;
}>();

type Modo = 'nessuna' | 'periodo' | 'lavori' | 'delibera';

const modoDaiValori = (): Modo => {
    if (!dal.value && !al.value) return 'nessuna';
    if (dal.value && dal.value === al.value) return props.lavoriUrgenti && !delibera.value ? 'lavori' : 'delibera';
    return 'periodo';
};

const modo = ref<Modo>(modoDaiValori());

// Chi precompila da fuori (la «Data assemblea» del modale della spesa imprevista, il reset del form)
// deve trovare il pannello coerente con i valori, non con l'ultimo clic.
watch([dal, al], () => {
    const atteso = modoDaiValori();
    if (atteso === 'nessuna' || modo.value === 'nessuna') modo.value = atteso;
});
// La voce con il piano d'urgenza arriva o se ne va (una riga cambiata): un giorno solo si legge con le parole giuste, salvo una delibera scelta.
watch(() => props.lavoriUrgenti?.giorno ?? null, () => {
    if (modo.value === 'lavori' || modo.value === 'delibera') modo.value = modoDaiValori();
});

const scegli = (m: Modo) => {
    modo.value = m;
    if (m !== 'delibera') {
        delibera.value = null;
    }
    if (m === 'nessuna') {
        dal.value = '';
        al.value = '';
    } else if (m === 'delibera') {
        al.value = dal.value;
    } else if (m === 'lavori') {
        // Il giorno del piano, se la fattura non ne dice uno solo; altrimenti quello che c'è.
        const giorno = dal.value && dal.value === al.value ? dal.value : (props.lavoriUrgenti?.giorno ?? dal.value);
        dal.value = giorno;
        al.value = giorno;
    }
};

// La delibera scelta scrive il giorno della riunione nei due estremi.
watch(delibera, (d) => {
    if (d) {
        modo.value = 'delibera';
        dal.value = d.deliberata_il;
        al.value = d.deliberata_il;
    }
});
const conLaDelibera = computed(() => props.condominioId !== null && props.condominioId !== undefined);

const giorno = computed({
    get: () => dal.value,
    set: (v: string) => {
        dal.value = v;
        al.value = v;
    },
});

const errore = computed(() => props.errori?.dal || props.errori?.al || '');

const inizioEsercizioIt = computed(() => (props.inizioEsercizio ? props.inizioEsercizio.slice(0, 10).split('-').reverse().join('/') : ''));

const MODI_DI_SEMPRE: { id: Modo; label: string; icona: any; spiega: string }[] = [
    { id: 'nessuna', label: 'Nessuna', icona: Minus, spiega: 'Il caso normale: la spesa segue il periodo della sua voce, o della gestione.' },
    { id: 'periodo', label: 'Costo maturato dal … al …', icona: CalendarRange,
        spiega: 'Un costo di un periodo (il riscaldamento da ottobre a marzo): paga chi era proprietario in quel periodo.' },
    { id: 'delibera', label: 'Spesa deliberata il …', icona: Gavel,
        spiega: 'Una spesa decisa dall\'assemblea (i lavori del portone): paga chi era proprietario il giorno della delibera.' },
];
// Coda 688: il modo dei lavori urgenti, prima della delibera, solo quando una voce della fattura sta in un piano d'urgenza.
const MODO_DEI_LAVORI = { id: 'lavori' as Modo, label: 'Lavori ordinati il …', icona: Zap,
    spiega: 'Lavori urgenti senza delibera (art. 1135 c.c.): paga chi era proprietario il giorno in cui sono stati ordinati.' };
const MODI = computed(() => props.lavoriUrgenti ? [MODI_DI_SEMPRE[0], MODI_DI_SEMPRE[1], MODO_DEI_LAVORI, MODI_DI_SEMPRE[2]] : MODI_DI_SEMPRE);

// La riga sotto il giorno dei lavori: da quale piano viene, o il giorno del piano se la fattura ne dice un altro.
const fraseDeiLavori = computed(() => {
    const l = props.lavoriUrgenti;
    if (!l || !dal.value) return '';
    return dal.value === l.giorno
        ? `Lavori ordinati il ${giornoItaliano(dal.value)}, dal piano d'urgenza «${l.piano}». Lo cambi se la fattura dice altro.`
        : `Lavori ordinati il ${giornoItaliano(dal.value)}. Il piano d'urgenza «${l.piano}» dice lavori ordinati il ${giornoItaliano(l.giorno)}.`;
});
</script>

<template>
    <div class="space-y-2">
        <Label class="text-xs font-bold uppercase tracking-wider text-slate-500">Competenza</Label>

        <!-- Una scelta esclusiva, una riga per modo a tutta larghezza: nella colonna stretta del modulo i tre bottoni in fila
             andavano a capo in modo irregolare (segnalato da Vincenzo a video il 27/09/2026). Le etichette non cambiano: le
             citano altri testi del programma e la guida del sito. -->
        <p class="text-xs leading-relaxed text-slate-600 dark:text-slate-300" data-testid="competenza-a-che-serve">
            <span class="font-semibold text-slate-700 dark:text-slate-200">A che cosa serve:</span> dice a chi tocca la spesa se l'unità cambia proprietario.
        </p>
        <div class="grid grid-cols-1 gap-1.5" role="radiogroup" aria-label="Competenza">
            <button
                v-for="m in MODI"
                :key="m.id"
                type="button"
                role="radio"
                :aria-checked="modo === m.id"
                @click="scegli(m.id)"
                :class="[
                    'flex w-full items-start gap-2 rounded-md border px-3 py-2 text-left text-xs font-semibold transition-colors',
                    modo === m.id
                        ? 'border-primary/40 bg-primary/5 text-primary'
                        : 'border-slate-200 bg-white text-slate-500 hover:border-slate-300 hover:text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400',
                ]">
                <component :is="m.icona" class="mt-0.5 h-3.5 w-3.5 shrink-0 self-start" />
                <span class="min-w-0">
                    <span class="block">{{ m.label }}</span>
                    <!-- La spiegazione dentro il pulsante: in un elenco a parte sotto i pulsanti i nomi si ripetevano e sembravano altre scelte. -->
                    <span class="mt-0.5 block font-normal leading-snug text-slate-500 dark:text-slate-400">{{ m.spiega }}</span>
                </span>
            </button>
        </div>

        <div v-if="modo === 'periodo'" class="grid grid-cols-2 gap-3">
            <div class="space-y-1.5 col-span-2 md:col-span-1">
                <Label class="text-xs font-semibold text-slate-500">Dal</Label>
                <Input type="date" v-model="dal" class="h-9 text-sm" />
            </div>
            <div class="space-y-1.5 col-span-2 md:col-span-1">
                <Label class="text-xs font-semibold text-slate-500">Al</Label>
                <Input type="date" v-model="al" :min="dal || undefined" class="h-9 text-sm" />
            </div>
        </div>

        <div v-else-if="modo === 'lavori'" class="space-y-1.5">
            <Label class="text-xs font-semibold text-slate-500">Lavori ordinati il</Label>
            <Input type="date" v-model="giorno" class="h-9 text-sm" data-testid="giorno-dei-lavori-della-fattura" />
            <p v-if="fraseDeiLavori" class="text-xs text-slate-600 dark:text-slate-300" data-testid="competenza-lavori-urgenti">{{ fraseDeiLavori }}</p>
        </div>

        <div v-else-if="modo === 'delibera' && conLaDelibera" class="space-y-1.5" data-testid="competenza-dalla-delibera">
            <ScegliLaDelibera v-model="delibera" :condominio-id="condominioId!" :condominio-nome="condominioNome ?? ''" ruolo="competenza"
                :oggetto-tipo="fatturaId ? 'fattura_passiva' : null" :oggetto-id="fatturaId ?? null" per-questo="questa fattura" :con-memo="false"
                info="La delibera che approva la spesa: il suo giorno è la competenza della fattura." />
            <p v-if="delibera" class="text-xs text-slate-600 dark:text-slate-300">
                Deliberata il <span class="font-semibold tabular-nums">{{ giornoItaliano(delibera.deliberata_il) }}</span> dalla delibera
            </p>
            <p v-else-if="dal" class="text-xs text-slate-600 dark:text-slate-300" data-testid="scritta-a-mano">
                Scritta a mano: {{ giornoItaliano(dal) }}. Scegli la delibera per legarla alla riunione, o registrala a mano.
            </p>
        </div>
        <div v-else-if="modo === 'delibera'" class="space-y-1.5">
            <Label class="text-xs font-semibold text-slate-500">Giorno della delibera</Label>
            <Input type="date" v-model="giorno" class="h-9 text-sm" />
        </div>

        <p v-if="errore" class="text-xs font-medium text-red-600 dark:text-red-400">{{ errore }}</p>

        <p class="text-xs leading-relaxed text-slate-500 dark:text-slate-400" data-testid="competenza-spiegazione">
            Conta quando la fattura va in un piano rate straordinario, anche su una gestione ordinaria. Si sceglie prima: in un piano approvato non si cambia più.
        </p>
        <!-- Coda 155 (1.11.0-beta.34): sulla straordinaria la data che decide per legge è la delibera, e «Costo maturato»
             è il primo gradino (decisione 12, non si tocca) — la scavalca. Il pannello lo dice; la pregressa no, perché lì
             il periodo di maturazione è proprio ciò che serve (caso reale del 23/09/2026, `richieste_utenti.md`). -->
        <p v-if="gestioneStraordinaria && !pregressa && modo === 'periodo'" class="text-xs leading-relaxed text-amber-700 dark:text-amber-400">
            Sulla straordinaria decide la delibera che approva lavori e prezzo (art. 63 disp. att. c.c.; Cass. 24654/2010).
            «Costo maturato» la scavalca: con una vendita nel mezzo paga chi era titolare in questo periodo, non chi lo era
            il giorno della delibera. Se la spesa è stata deliberata, usa «Spesa deliberata il …».
        </p>
        <p v-else-if="gestioneStraordinaria && !pregressa && modo !== 'lavori'" class="text-xs leading-relaxed text-slate-500">
            Sulla straordinaria decide la delibera che approva lavori e prezzo (art. 63 disp. att. c.c.; Cass. 24654/2010):
            se la dichiari qui, il modo è «Spesa deliberata il …».
        </p>
        <!-- Coda 688: sulla voce di un piano d'urgenza non c'è una delibera da aspettare: il conguaglio dell'opera usa il giorno dei lavori del piano. -->
        <p v-if="gestioneStraordinaria && modo === 'nessuna' && lavoriUrgenti" class="text-xs leading-relaxed text-slate-600 dark:text-slate-300">
            Senza una competenza qui, il conguaglio dell'opera usa il giorno del piano d'urgenza «{{ lavoriUrgenti.piano }}»: lavori ordinati il
            {{ giornoItaliano(lavoriUrgenti.giorno) }}.
        </p>
        <p v-else-if="gestioneStraordinaria && modo === 'nessuna'" class="text-xs leading-relaxed text-amber-700 dark:text-amber-400">
            La gestione di questa fattura è straordinaria: senza una competenza qui, il piano che la ripartisce userà la
            data della sua delibera, e senza nemmeno quella si fermerà.
        </p>
        <!-- La pregressa è un costo dell'anno prima: la parte non coperta dai saldi iniziali finisce in un piano, e senza il
             periodo il piano la ripartirebbe sui giorni di quest'anno (o sulla delibera di quest'anno) — dopo un passaggio
             pagherebbe chi è entrato, per un costo di quando l'unità era di chi è uscito. Dalla beta.35 il periodo è
             obbligatorio (decisione 26, punto 2): una pregressa non si modifica dopo, si storna. -->
        <p v-if="pregressa && periodoObbligatorio && modo === 'nessuna'" class="text-xs leading-relaxed text-amber-700 dark:text-amber-400">
            Fattura pregressa con una parte non coperta dai saldi iniziali: il periodo in cui il costo è maturato è
            obbligatorio<template v-if="inizioEsercizioIt">, e si chiude prima del {{ inizioEsercizioIt }}, quando inizia questo esercizio</template>.
            Quella parte finisce in un piano rate: senza il periodo, dopo un passaggio, la pagherebbe chi è entrato per un
            costo di quando l'unità era di chi è uscito. Dopo non si potrà aggiungere: una pregressa si storna, non si modifica.
        </p>
        <p v-else-if="pregressa && modo === 'nessuna'" class="text-xs leading-relaxed text-slate-500">
            Fattura pregressa: se una parte non è coperta dai saldi iniziali, qui andrà il periodo in cui il costo è
            maturato (l'esercizio passato).
        </p>
    </div>
</template>
