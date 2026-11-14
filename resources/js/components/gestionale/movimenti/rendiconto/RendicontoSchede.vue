<script setup lang="ts">
/**
 * Le sette schede del rendiconto dell'esercizio: ognuna è una pagina con la sua richiesta (le letture di una scheda non si sommano a quelle delle altre),
 * e questa riga le lega. La situazione patrimoniale resta sulla pagina di sempre, così i collegamenti che portavano lì portano ancora lì.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { usePermission } from '@/composables/permissions';

export type SchedaRendiconto = 'sintesi' | 'riepilogo' | 'situazione' | 'cassa' | 'condomini' | 'conguaglio' | 'controlli';

const props = defineProps<{ condominioId: number; esercizioId: number; attiva: SchedaRendiconto }>();
const { generatePath } = usePermission();

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
    ];
});
</script>

<template>
    <nav class="mb-6 flex gap-1 border-b border-slate-200 dark:border-slate-800 overflow-x-auto" aria-label="Schede del rendiconto">
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
</template>
