<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * L'elenco della linguetta «Assemblee» nella scheda della persona (decisioni 622 (5) e 651): una riga per assemblea, con l'assemblea, il condominio, «Per lei»
 * (un badge) e il chevron; la prima riga della pagina aperta, le altre si aprono con un tocco. Dentro: la convocazione, in sala, i voti con le dichiarazioni,
 * «Può impugnare», le delibere sulle sue cose e il collegamento all'assemblea. Al telefono ogni riga è una scheda. **Solo lo studio**: non va nel portale dei
 * condòmini. I testi delle persone (le dichiarazioni) sono testo, mai HTML.
 */
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ChevronDown, ChevronUp } from 'lucide-vue-next';
import { usePermission } from '@/composables/permissions';
import type { PersonaInAssemblea } from '@/lib/assemblee/persona';

const props = defineProps<{ assemblee: PersonaInAssemblea[] }>();
const { generateRoute } = usePermission();
/** Le righe girate a mano: la prima della pagina parte aperta (chiave negativa), le altre chiuse. */
const girate = ref<Set<number>>(new Set());
const elenco = computed(() => props.assemblee);

const aperta = (a: PersonaInAssemblea, i: number) => (i === 0 ? !girate.value.has(-a.id) : girate.value.has(a.id));
function giraLaRiga(a: PersonaInAssemblea, i: number) {
    const chiave = i === 0 ? -a.id : a.id;
    const nuovo = new Set(girate.value);
    if (nuovo.has(chiave)) nuovo.delete(chiave);
    else nuovo.add(chiave);
    girate.value = nuovo;
}
const indirizzo = (a: PersonaInAssemblea) => route(generateRoute('gestionale.assemblee.show'), { condominio: a.condominio_id, assemblea: a.id });

/** «Favorevole ai punti 1, 2, 3 e 5», con «espressi da … per delega». */
function favorevoli(a: PersonaInAssemblea): string | null {
    const n = a.favorevole_ai_punti;
    if (n.length === 0) return null;
    const punti = n.length === 1 ? `al punto ${n[0]}` : `ai punti ${n.slice(0, -1).join(', ')} e ${n[n.length - 1]}`;
    return `Favorevole ${punti}${a.per_delega_da ? ` · espressi da ${a.per_delega_da} per delega` : ''}`;
}

const TONI: Record<string, string> = {
    ambra: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
    verde: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300',
    grigio: 'bg-slate-100 text-slate-700 dark:bg-slate-700/50 dark:text-slate-300',
};
</script>

<template>
    <div data-testid="elenco-assemblee">
        <p v-if="elenco.length === 0" class="px-4 py-6 text-center text-sm text-slate-600 dark:text-slate-300" data-testid="nessuna-assemblea">
            Nessuna assemblea per questi filtri.
        </p>
        <template v-else>
            <div class="hidden grid-cols-[minmax(0,1fr)_minmax(0,12rem)_9rem_2rem] gap-3 border-b border-slate-200/80 px-4 py-2 text-xs font-medium text-slate-500 dark:border-slate-700/50 dark:text-slate-400 sm:grid">
                <span>Assemblea</span><span>Condominio</span><span>Per lei</span><span class="sr-only">Apri o chiudi</span>
            </div>
            <div class="divide-y divide-slate-200/60 dark:divide-slate-700/40">
                <div v-for="(a, i) in elenco" :key="a.id" data-testid="assemblea-della-persona">
                    <button type="button" class="grid w-full grid-cols-[minmax(0,1fr)_2rem] items-center gap-x-3 gap-y-1 px-4 py-3 text-left sm:grid-cols-[minmax(0,1fr)_minmax(0,12rem)_9rem_2rem]"
                        :aria-expanded="aperta(a, i)" data-testid="riga-assemblea" @click="giraLaRiga(a, i)">
                        <span class="col-start-1 break-words text-sm font-medium text-slate-900 dark:text-slate-100">{{ a.nome }}<template v-if="a.verbale"> · verbale n. {{ a.verbale }}</template></span>
                        <span class="col-start-1 break-words text-xs text-slate-600 dark:text-slate-300 sm:col-start-2" data-testid="condominio-della-riga">{{ a.condominio }}</span>
                        <span class="col-start-1 sm:col-start-3">
                            <span v-if="a.per_lei" class="inline-block rounded-md px-2 py-0.5 text-xs font-medium" :class="TONI[a.per_lei.tono]" data-testid="per-lei">{{ a.per_lei.parola }}</span>
                        </span>
                        <component :is="aperta(a, i) ? ChevronUp : ChevronDown" class="col-start-2 row-start-1 h-4 w-4 shrink-0 justify-self-end text-slate-400 sm:col-start-4" />
                    </button>
                    <dl v-if="aperta(a, i)" class="grid grid-cols-1 gap-x-4 gap-y-1.5 px-4 pb-4 text-sm sm:grid-cols-[9rem_1fr]" data-testid="assemblea-aperta">
                        <template v-if="a.convocazione"><dt class="text-xs text-slate-500 dark:text-slate-400">Convocazione</dt><dd class="text-slate-800 dark:text-slate-200">{{ a.convocazione }}</dd></template>
                        <template v-if="a.in_sala"><dt class="text-xs text-slate-500 dark:text-slate-400">In sala</dt><dd class="text-slate-800 dark:text-slate-200">{{ a.in_sala }}</dd></template>
                        <template v-if="favorevoli(a) || a.altri_voti.length">
                            <dt class="text-xs text-slate-500 dark:text-slate-400">Voti</dt>
                            <dd class="space-y-1.5 text-slate-800 dark:text-slate-200">
                                <p v-if="favorevoli(a)">{{ favorevoli(a) }}</p>
                                <div v-for="v in a.altri_voti" :key="v.punto" data-testid="voto-della-persona">
                                    <p><span class="font-medium">Punto {{ v.punto }}</span> · {{ v.voto }}<template v-if="v.testo"> · <span class="break-words">{{ v.testo }}</span></template></p>
                                    <p v-for="(d, k) in v.dichiarazioni" :key="k" class="break-words text-xs text-slate-600 dark:text-slate-300">Dichiarazione a verbale: «{{ d }}»</p>
                                </div>
                            </dd>
                        </template>
                        <template v-if="a.puo_impugnare_fino_al || a.impugnare_dopo_la_consegna">
                            <dt class="text-xs text-slate-500 dark:text-slate-400">Può impugnare</dt>
                            <dd class="text-slate-800 dark:text-slate-200">
                                <template v-if="a.puo_impugnare_fino_al">fino al {{ a.puo_impugnare_fino_al }}</template>
                                <template v-else>trenta giorni da quando riceve il verbale</template>
                            </dd>
                        </template>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Sulle sue cose</dt>
                        <dd class="text-slate-800 dark:text-slate-200">
                            <template v-if="a.sulle_sue_cose.length"><p v-for="(c, k) in a.sulle_sue_cose" :key="k">{{ c.frase }}</p></template>
                            <template v-else>Nessuna delibera in questa assemblea.</template>
                        </dd>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Assemblea</dt>
                        <dd><Link :href="indirizzo(a)" class="text-xs font-medium text-sky-700 underline dark:text-sky-300">Apri l'assemblea</Link></dd>
                    </dl>
                </div>
            </div>
        </template>
    </div>
</template>
