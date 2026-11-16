<script setup lang="ts">
/**
 * «Da dove viene» della cifra «Disponibile oggi» della tesoreria, nel riquadro che si apre accanto al titolo: tutto il denaro voce per voce (le casse, le
 * altre voci della liquidità, i fondi con il loro tipo), «Tutto il denaro», i fondi vincolati come righe tolte, la cifra della casella; ciò che non è nella
 * cifra; la frase sull'estratto conto; il saldo della banca scritto nella nota del rendiconto. Le righe arrivano dal server (`DisponibilitaDiOggi`) e sommano la
 * cifra: qui non si ricalcola niente.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Lock, LockOpen } from 'lucide-vue-next';
import { Badge } from '@/components/ui/badge';
import { usePermission } from '@/composables/permissions';
import { euroSegno } from '@/lib/gestionale/tesoreria/saldoPrevisto';
import { FRASE_ESTRATTO, FRASE_FONDI, nomeDellaRiga, testoFuturi, testoNotaBanca, type Disponibile, type RigaDelDenaro } from '@/lib/gestionale/tesoreria/tesoreria';

const props = defineProps<{ disponibile: Disponibile; condominioId: number }>();

const { generateRoute } = usePermission();
const rotta = (nome: string, altro: Record<string, unknown>) => {
    try {
        return route(generateRoute(`gestionale.${nome}`), { condominio: props.condominioId, ...altro });
    } catch {
        return null;
    }
};

/** I movimenti di una voce: il mastrino del suo conto nell'esercizio di oggi; senza esercizio, la pagina della cassa. */
function movimenti(r: RigaDelDenaro): string | null {
    if (props.disponibile.esercizio_id && r.conto_contabile_id) {
        return rotta('esercizi.conti.movimenti', { esercizio: props.disponibile.esercizio_id, contoContabile: r.conto_contabile_id });
    }

    return r.cassa_id ? rotta('casse.show', { cassa: r.cassa_id }) : null;
}

const TONI: Record<string, string> = {
    libero: 'border-emerald-200 dark:border-emerald-900 bg-emerald-50 dark:bg-emerald-950/40 text-emerald-800 dark:text-emerald-200',
    vincolato: 'border-amber-200 dark:border-amber-900 bg-amber-50 dark:bg-amber-950/40 text-amber-800 dark:text-amber-200',
    sbloccato: 'border-sky-200 dark:border-sky-900 bg-sky-50 dark:bg-sky-950/40 text-sky-800 dark:text-sky-200',
    non_indicato: 'border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/60 text-slate-700 dark:text-slate-200',
};
const classeBadge = (tono: string) => `${TONI[tono] ?? TONI.non_indicato} max-w-full whitespace-normal text-left px-1.5 py-0 text-xs font-semibold leading-5`;

const conFondi = computed(() => props.disponibile.righe.some((r) => r.tipo === 'fondo'));
const futuri = computed(() => testoFuturi(props.disponibile.futuri));
</script>

<template>
    <div class="space-y-3 text-sm text-slate-700 dark:text-slate-200" data-testid="da-dove-viene">
        <h4 class="text-sm font-bold text-slate-900 dark:text-white">Disponibile oggi: da dove viene</h4>

        <!-- Tutto il denaro, voce per voce: il nome a sinistra, la cifra a destra. -->
        <ul class="space-y-1.5">
            <li v-for="(r, i) in disponibile.righe" :key="`${r.tipo}-${r.cassa_id ?? r.conto_contabile_id ?? i}`" data-testid="riga-denaro"
                class="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-x-3">
                <div class="min-w-0">
                    <p class="leading-snug">{{ nomeDellaRiga(r) }}</p>
                    <Badge v-if="r.etichetta" variant="outline" :class="classeBadge(r.etichetta.tono)" data-testid="tipo-fondo" class="mt-0.5">
                        <component :is="r.si_puo_usare ? LockOpen : Lock" class="w-3 h-3 shrink-0" />{{ r.etichetta.testo }}
                    </Badge>
                    <Link v-if="movimenti(r)" :href="movimenti(r)!" class="block text-xs font-medium text-primary hover:underline">Vedi i movimenti →</Link>
                </div>
                <span class="text-right tabular-nums whitespace-nowrap" :class="(r.saldo ?? 0) < 0 ? 'text-red-600 dark:text-red-400' : (r.saldo ?? 0) === 0 ? 'text-slate-400' : ''">
                    {{ euroSegno(r.saldo ?? 0) }}
                </span>
            </li>
        </ul>

        <div class="grid grid-cols-[minmax(0,1fr)_auto] gap-x-3 border-t border-slate-200 pt-2 font-semibold dark:border-slate-700" data-testid="tutto-il-denaro">
            <span>Tutto il denaro</span>
            <span class="text-right tabular-nums whitespace-nowrap">{{ euroSegno(disponibile.totale) }}</span>
        </div>

        <!-- I fondi vincolati: si vedono, ognuno con la sua cifra, come righe tolte dalla somma. -->
        <ul v-if="disponibile.tolte.length" class="space-y-1">
            <li v-for="t in disponibile.tolte" :key="t.cassa_id ?? t.voce" data-testid="riga-tolta"
                class="grid grid-cols-[minmax(0,1fr)_auto] gap-x-3 text-rose-700 dark:text-rose-300">
                <span class="min-w-0">{{ t.voce }} <span class="text-xs text-slate-500 dark:text-slate-400">vincolato, non disponibile</span></span>
                <span class="text-right tabular-nums whitespace-nowrap">{{ euroSegno(-t.saldo) }}</span>
            </li>
        </ul>

        <div class="grid grid-cols-[minmax(0,1fr)_auto] gap-x-3 border-t-4 border-double border-slate-300 pt-2 font-bold text-slate-900 dark:border-slate-600 dark:text-white"
            data-testid="disponibile-oggi">
            <span>Disponibile oggi</span>
            <span class="text-right tabular-nums whitespace-nowrap">{{ euroSegno(disponibile.cifra) }}</span>
        </div>

        <p v-if="conFondi" class="text-xs text-slate-500 dark:text-slate-400">{{ FRASE_FONDI }}</p>

        <p v-if="futuri" class="rounded-md bg-slate-50 p-2 text-xs text-slate-600 dark:bg-slate-800/60 dark:text-slate-300">{{ futuri }}</p>

        <p class="text-xs italic text-slate-500 dark:text-slate-400">{{ FRASE_ESTRATTO }}</p>

        <p v-for="b in disponibile.banca" :key="b.cassa_id" class="text-xs text-slate-600 dark:text-slate-300">{{ testoNotaBanca(b) }}</p>
    </div>
</template>
