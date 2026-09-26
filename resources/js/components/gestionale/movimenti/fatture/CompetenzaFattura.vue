<script setup lang="ts">
/**
 * La competenza della fattura (1.11.0-beta.31, B2 S6): due estremi sulla testata, `competenza_dal` e
 * `competenza_al`, dichiarati in uno di due modi — un periodo («costo maturato dal … al …») o un giorno
 * («spesa deliberata il …», che scrive la stessa data nei due campi: è la delibera puntuale, D2).
 *
 * Il testo dice la verità intera: il motore la legge **solo** quando la fattura è ripartita da un piano su
 * una gestione straordinaria (decisione 19: sull'ordinario si va pro rata sulla base, e la dichiarazione
 * resta un fatto registrato per il rendiconto e il prospetto degli oneri). E va dichiarata prima di mettere
 * la fattura in un piano, perché dentro un piano approvato la fattura non si modifica più.
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

const MODI: { id: Modo; label: string; icona: any }[] = [
    { id: 'nessuna', label: 'Nessuna', icona: Minus },
    { id: 'periodo', label: 'Costo maturato dal … al …', icona: CalendarRange },
    { id: 'delibera', label: 'Spesa deliberata il …', icona: Gavel },
];
</script>

<template>
    <div class="space-y-2">
        <Label class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Competenza</Label>

        <div class="flex flex-wrap gap-1.5">
            <button
                v-for="m in MODI"
                :key="m.id"
                type="button"
                @click="scegli(m.id)"
                :class="[
                    'inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-[11px] font-semibold transition-colors',
                    modo === m.id
                        ? 'border-primary/40 bg-primary/5 text-primary'
                        : 'border-slate-200 bg-white text-slate-500 hover:border-slate-300 hover:text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400',
                ]">
                <component :is="m.icona" class="h-3.5 w-3.5 shrink-0" />
                {{ m.label }}
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
            Guida le rate solo se la fattura è ripartita da un piano su una gestione straordinaria: lì paga chi era
            titolare in quel periodo, o quel giorno. Sull'ordinario si registra e la leggono il rendiconto e il
            prospetto degli oneri. Dichiarala prima di mettere la fattura in un piano: dentro un piano approvato la
            fattura non si modifica più.
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
        <!-- La pregressa è un costo dell'anno prima: se l'eccedenza va a straordinario, senza il periodo dichiarato decide
             la delibera di quest'anno — e dopo un passaggio pagherebbe chi è entrato, per un costo maturato quando
             l'unità era di chi è uscito (domanda di Vincenzo, 20/09/2026; il motore lo prova nel test «PREGRESSA»). -->
        <p v-if="pregressa && modo === 'nessuna'" class="text-[10.5px] leading-relaxed text-amber-700 dark:text-amber-400">
            Fattura pregressa: dichiara qui il periodo in cui il costo è maturato (l'esercizio passato). Se la parte non
            coperta dai saldi iniziali finisce in un piano straordinario, senza questa data la ripartisce la delibera di
            quest'anno — e dopo un passaggio pagherebbe chi è entrato, per un costo di quando l'unità era di chi è uscito.
        </p>
    </div>
</template>
