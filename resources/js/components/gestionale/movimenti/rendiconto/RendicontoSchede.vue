<script setup lang="ts">
/**
 * Le otto schede del rendiconto dell'esercizio: ognuna è una pagina con la sua richiesta (le letture di una scheda non si sommano a quelle delle altre),
 * e questa riga le lega. La situazione patrimoniale resta sulla pagina di sempre, così i collegamenti che portavano lì portano ancora lì.
 *
 * Sotto le schede, lo stato del rendiconto: «Bozza» finché non è fermato per l'assemblea, poi presentato, poi approvato. Lo manda il server a ogni
 * scheda (`stato_rendiconto`, da `VersioniDelRendiconto::statoInTesta`); la riga si legge dalle proprietà della pagina, così ogni scheda la ha. Dopo
 * l'approvazione, se il giornale è cambiato, la riga lo dice (`dopo`) e porta al confronto nella Sintesi: un avviso, niente si blocca.
 */
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { usePermission } from '@/composables/permissions';

export type SchedaRendiconto = 'sintesi' | 'riepilogo' | 'situazione' | 'cassa' | 'condomini' | 'conguaglio' | 'controlli' | 'nota';
export interface StatoRendiconto { stato: 'bozza' | 'presentata' | 'approvata'; frase: string; versione_id: number | null; dopo?: string | null }

const props = defineProps<{ condominioId: number; esercizioId: number; attiva: SchedaRendiconto }>();
const { generatePath } = usePermission();
const page = usePage<{ stato_rendiconto?: StatoRendiconto }>();
const stato = computed(() => page.props?.stato_rendiconto ?? null);

const schede = computed(() => {
    const base = { condominio: props.condominioId, esercizio: props.esercizioId };
    const del = (p: string) => generatePath(`gestionale/:condominio/esercizi/:esercizio/${p}`, base);

    return [
        { chiave: 'sintesi', titolo: 'Sintesi', href: del('rendiconto') },
        { chiave: 'riepilogo', titolo: 'Riepilogo finanziario', href: del('rendiconto/riepilogo-finanziario') },
        { chiave: 'situazione', titolo: 'Situazione patrimoniale', href: del('stato-patrimoniale') },
        { chiave: 'cassa', titolo: 'Cassa', href: del('rendiconto/cassa') },
        { chiave: 'condomini', titolo: 'Condòmini', href: del('rendiconto/condomini') },
        { chiave: 'conguaglio', titolo: 'Conguaglio', href: del('rendiconto/conguaglio') },
        { chiave: 'controlli', titolo: 'Controlli', href: del('rendiconto/controlli') },
        // L'ultima cosa che l'amministratore scrive, quando i controlli li ha letti.
        { chiave: 'nota', titolo: 'Nota sintetica', href: del('rendiconto/nota') },
    ];
});
const coloreStato = computed(() => ({
    bozza: 'bg-slate-50 dark:bg-slate-800/40 border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300',
    presentata: 'bg-sky-50 dark:bg-sky-900/20 border-sky-200 dark:border-sky-900 text-sky-900 dark:text-sky-200',
    approvata: 'bg-emerald-50 dark:bg-emerald-900/20 border-emerald-200 dark:border-emerald-900 text-emerald-900 dark:text-emerald-200',
}[stato.value?.stato ?? 'bozza']));
const hrefSintesi = computed(() => generatePath('gestionale/:condominio/esercizi/:esercizio/rendiconto', { condominio: props.condominioId, esercizio: props.esercizioId }));
</script>

<template>
    <nav class="flex gap-1 border-b border-slate-200 dark:border-slate-800 overflow-x-auto" :class="stato ? 'mb-3' : 'mb-6'" aria-label="Schede del rendiconto">
        <Link
            v-for="s in schede"
            :key="s.chiave"
            :href="s.href"
            :data-scheda="s.chiave"
            class="px-3 py-2 text-sm -mb-px border-b-2 whitespace-nowrap"
            :class="s.chiave === attiva ? 'border-primary text-slate-900 dark:text-slate-100 font-semibold' : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-300'"
            :aria-current="s.chiave === attiva ? 'page' : undefined"
        >{{ s.titolo }}</Link>
    </nav>
    <div v-if="stato" class="rounded-md border px-3 py-1.5 text-[12px] flex flex-wrap items-center justify-between gap-2" :class="[coloreStato, stato.dopo ? 'mb-2' : 'mb-6']" data-test="stato-rendiconto" :data-stato="stato.stato">
        <span>{{ stato.frase }}</span>
        <Link v-if="attiva !== 'sintesi' && stato.stato !== 'bozza'" :href="hrefSintesi" class="font-semibold underline whitespace-nowrap">Le versioni nella Sintesi</Link>
    </div>
    <div v-if="stato?.dopo" class="mb-6 rounded-md border border-amber-200 dark:border-amber-900 bg-amber-50 dark:bg-amber-900/20 px-3 py-1.5 text-[12px] text-amber-900 dark:text-amber-200 flex flex-wrap items-center justify-between gap-2" data-test="dopo-l-approvazione">
        <span>{{ stato.dopo }}</span>
        <a v-if="attiva === 'sintesi'" href="#confronto" class="font-semibold underline whitespace-nowrap">Vedi che cosa è cambiato</a>
        <Link v-else :href="`${hrefSintesi}#confronto`" class="font-semibold underline whitespace-nowrap">Vedi che cosa è cambiato</Link>
    </div>
</template>
