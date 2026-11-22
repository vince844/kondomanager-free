<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * L'archivio delle assemblee del condominio (§3 P1): la voce «Assemblee» della barra del gestionale. Le tre card (la prossima, i punti che aspettano, le
 * delibere dell'anno), «Prepara l'assemblea», il menu «Azioni» con «Il registro dei verbali» e «Registra una delibera a mano», la riga della raccolta se ci sono date scritte a mano, i filtri anno e stato
 * senza ricaricare (una visita parziale di `assemblee` e `card`), la tabella per anno dalla più recente, «Mostra altre». Vuoto: la frase con le due strade.
 * Al telefono una card per assemblea.
 */
import { computed, ref, watch } from 'vue';
import { Head, InfiniteScroll, Link, router } from '@inertiajs/vue3';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { BookOpen, CalendarCheck, ChevronDown, FileSignature, Gavel, ListTodo, Zap } from 'lucide-vue-next';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import RegistraDeliberaAMano from '@/components/assemblee/RegistraDeliberaAMano.vue';
import AssembleaGuide from '@/components/guides/AssembleaGuide.vue';
import { cardDellArchivio } from '@/lib/assemblee/cardDellaGuida';
import { usePermission } from '@/composables/permissions';
import { BADGE, CLASSI_DEL_BADGE, coloreDelloStato, quanti } from '@/lib/assemblee/assemblee';
import type { Building } from '@/types/buildings';
import type { DeliberaDelMenu } from '@/types/assemblee';

interface RigaDellArchivio {
    id: number; nome: string; anno: string | null; data: string | null; stato: string; stato_parola: string; a_mano: boolean; perimetro: string;
    sottotitolo: string; punti: number; delibere: number; numero_verbale: string | null; verbale_da_caricare: boolean;
}
interface Card { valore: string; sotto: string | null; id?: number | null }

const props = defineProps<{
    condominio: Building;
    condomini: Building[];
    esercizio: any;
    filtri: { anno: string | null; stato: string | null };
    assemblee: { data: RigaDellArchivio[]; total?: number };
    anni: string[];
    card: { prossima: Card; in_attesa: Card; delibere: Card };
    raccolta: { date: number; frase: string } | null;
    puo: { gestire: boolean; registrare_il_verbale: boolean };
}>();

const { generatePath, generateRoute } = usePermission();
const mostraGuida = ref(false);
const finestraAMano = ref(false);

const anno = ref(props.filtri.anno ?? '');
const stato = ref(props.filtri.stato ?? '');
watch([anno, stato], () => {
    router.get(window.location.pathname, Object.fromEntries(Object.entries({ anno: anno.value, stato: stato.value }).filter(([, v]) => v !== '')), {
        only: ['assemblee', 'card', 'filtri'], reset: ['assemblee'], preserveState: true, preserveScroll: true, replace: true,
    });
});
const anni = computed(() => [{ valore: '', nome: 'Tutti gli anni' }, ...props.anni.map((a) => ({ valore: a, nome: a }))]);
const stati = [
    { valore: '', nome: 'Tutte' }, { valore: 'in_preparazione', nome: 'In preparazione' }, { valore: 'convocata', nome: 'Convocate' },
    { valore: 'in_corso', nome: 'In corso' }, { valore: 'chiusa', nome: 'Tenute in sala' }, { valore: 'deserta', nome: 'Deserte' },
    { valore: 'tenuta', nome: 'Tenute su carta' }, { valore: 'a_mano', nome: 'Registrate a mano' }, { valore: 'annullata', nome: 'Annullate' },
];

/** Le righe raggruppate per anno, nell'ordine in cui arrivano. */
const perAnno = computed(() => {
    const gruppi: { anno: string; righe: RigaDellArchivio[] }[] = [];
    for (const r of props.assemblee.data) {
        const a = r.anno ?? 'Senza data';
        const ultimo = gruppi[gruppi.length - 1];
        if (ultimo && ultimo.anno === a) ultimo.righe.push(r); else gruppi.push({ anno: a, righe: [r] });
    }
    return gruppi;
});
const totale = computed(() => props.assemblee.total ?? props.assemblee.data.length);
const altre = computed(() => Math.max(0, totale.value - props.assemblee.data.length));
const filtrato = computed(() => anno.value !== '' || stato.value !== '');

const breadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Assemblee' },
]);
const apri = (r: RigaDellArchivio) => route(generateRoute('gestionale.assemblee.show'), { condominio: props.condominio.id, assemblea: r.id });
const prepara = computed(() => route(generateRoute('gestionale.assemblee.create'), { condominio: props.condominio.id }));
const registroDeiVerbali = computed(() => route(generateRoute('gestionale.assemblee.registro'), { condominio: props.condominio.id }));
const raccogli = computed(() => route(generateRoute('gestionale.delibere.raccolta'), { condominio: props.condominio.id }));

function registrata(d: DeliberaDelMenu) {
    router.visit(route(generateRoute('gestionale.assemblee.show'), { condominio: props.condominio.id, assemblea: d.assemblea_id }));
}

/** Il pulsante «Azioni» ha la forma e il carattere di «Guida»: alto 32 pixel, la scritta a 12. */
const pulsanteDellaTesta = 'inline-flex h-8 items-center justify-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 text-xs font-medium text-slate-700 shadow-sm transition-colors hover:bg-slate-50 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800';
/** Una voce del menu «Azioni»: l'icona, il nome e la riga grigia sotto. */
const voce = 'flex cursor-pointer items-start gap-2 px-2 py-1.5 text-[13px]';
const iconaDellaVoce = 'mt-0.5 h-3.5 w-3.5 shrink-0';
const etichetta = 'mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400';
</script>

<template>
    <Head title="Assemblee" />
    <GestionaleLayout>
        <div class="space-y-4 px-4 py-6 sm:px-6 sm:py-8">
            <PageHeaderGuide
                page-title="Assemblee"
                :page-subtitle="`Le assemblee di ${condominio.nome}: quelle preparate nel programma e le riunioni di carta registrate a mano.`"
                :guides="cardDellArchivio()"
                :breadcrumbs="(breadcrumbs as any)"
                :video-url="null"
                :condominio="condominio"
                :condomini="condomini"
                has-text-guide
                text-guide-title="Guida"
                una-fila
                @open-text-guide="mostraGuida = true"
            >
                <template #actions>
                    <!-- Il menu «Azioni» (decisione 657): il registro dei verbali (e quello di nomina e revoca, art. 1130 n. 7 c.c.) e la delibera a mano, con l'icona e la riga grigia
                         sotto come nella pagina dell'assemblea; in testa restano «Guida» e il gesto principale, perché la riga non vada a capo. -->
                    <DropdownMenu :modal="false">
                        <DropdownMenuTrigger as-child>
                            <button type="button" :class="pulsanteDellaTesta" data-testid="menu-archivio">
                                <Zap class="h-3.5 w-3.5 text-slate-500 dark:text-slate-400" aria-hidden="true" /> Azioni <ChevronDown class="h-3.5 w-3.5 text-slate-400 dark:text-slate-500" aria-hidden="true" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-64">
                            <DropdownMenuItem as-child :class="voce">
                                <Link :href="registroDeiVerbali" data-testid="registro-dei-verbali">
                                    <BookOpen :class="iconaDellaVoce" aria-hidden="true" />
                                    <span><span class="block" data-testid="nome-voce">Il registro dei verbali</span><span class="block text-xs text-slate-500 dark:text-slate-400">I verbali registrati, in ordine</span></span>
                                </Link>
                            </DropdownMenuItem>
                            <DropdownMenuItem v-if="puo.gestire" :class="voce" data-testid="registra-a-mano" @select="finestraAMano = true">
                                <FileSignature :class="iconaDellaVoce" aria-hidden="true" />
                                <span><span class="block" data-testid="nome-voce">Registra una delibera a mano</span><span class="block text-xs text-slate-500 dark:text-slate-400">Una riunione tenuta senza il programma</span></span>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                    <Link v-if="puo.gestire" :href="prepara" class="inline-flex h-8 items-center rounded-md bg-slate-900 px-3 text-sm font-medium text-white hover:bg-slate-800 dark:bg-white dark:text-slate-900"
                        data-testid="prepara">Prepara l'assemblea</Link>
                </template>
            </PageHeaderGuide>

            <p v-if="raccolta && puo.gestire" class="rounded-xl border border-sky-200 bg-sky-50 px-4 py-2.5 text-sm text-sky-900 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-200" data-testid="raccolta">
                {{ raccolta.frase.replace(/: raccoglile$/, '') }}: <Link :href="raccogli" class="font-medium underline">raccoglile</Link>
            </p>

            <div class="grid grid-cols-1 gap-3.5 md:grid-cols-3" data-testid="card-archivio">
                <div class="flex flex-col rounded-xl border border-slate-200 bg-white px-4 py-3.5 dark:border-slate-800 dark:bg-slate-950">
                    <span class="flex items-center gap-1.5 text-xs font-semibold text-slate-500 dark:text-slate-400"><CalendarCheck class="h-3.5 w-3.5" /> La prossima</span>
                    <Link v-if="card.prossima.id" :href="route(generateRoute('gestionale.assemblee.show'), { condominio: condominio.id, assemblea: card.prossima.id })"
                        class="mt-1 text-xl font-bold text-slate-900 hover:underline dark:text-white">{{ card.prossima.valore }}</Link>
                    <span v-else class="mt-1 text-xl font-bold text-slate-900 dark:text-white">{{ card.prossima.valore }}</span>
                    <span class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ card.prossima.sotto }}</span>
                </div>
                <div class="flex flex-col rounded-xl border border-slate-200 bg-white px-4 py-3.5 dark:border-slate-800 dark:bg-slate-950">
                    <span class="flex items-center gap-1.5 text-xs font-semibold text-slate-500 dark:text-slate-400"><ListTodo class="h-3.5 w-3.5" /> In attesa</span>
                    <span class="mt-1 text-xl font-bold tabular-nums text-slate-900 dark:text-white">{{ card.in_attesa.valore }}</span>
                    <span class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ card.in_attesa.sotto }}</span>
                </div>
                <div class="flex flex-col rounded-xl border border-slate-200 bg-white px-4 py-3.5 dark:border-slate-800 dark:bg-slate-950">
                    <span class="flex items-center gap-1.5 text-xs font-semibold text-slate-500 dark:text-slate-400"><Gavel class="h-3.5 w-3.5" /> Delibere</span>
                    <span class="mt-1 text-xl font-bold tabular-nums text-slate-900 dark:text-white">{{ card.delibere.valore }}</span>
                    <span class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ card.delibere.sotto }}</span>
                </div>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
                <div class="grid grid-cols-1 gap-2.5 border-b border-slate-100 px-4 py-3 sm:grid-cols-2 lg:grid-cols-4 dark:border-slate-800" data-testid="filtri">
                    <div class="menu-campo"><span :class="etichetta">Anno</span>
                        <v-select v-model="anno" :options="anni" label="nome" :reduce="(o: { valore: string }) => o.valore" :clearable="false" :searchable="false" />
                    </div>
                    <div class="menu-campo"><span :class="etichetta">Stato</span>
                        <v-select v-model="stato" :options="stati" label="nome" :reduce="(o: { valore: string }) => o.valore" :clearable="false" :searchable="false" />
                    </div>
                </div>

                <p v-if="assemblee.data.length === 0" class="px-4 py-10 text-center text-sm text-slate-500 dark:text-slate-400" data-testid="archivio-vuoto">
                    <template v-if="filtrato">Nessuna assemblea con questi filtri. Prova «Tutti gli anni» o «Tutte».</template>
                    <template v-else>Nessuna assemblea di {{ condominio.nome }} nel programma. Prepara la prossima, o registra a mano una riunione di carta.</template>
                </p>

                <InfiniteScroll v-else data="assemblee" manual preserve-url>
                    <table class="hidden w-full table-fixed text-sm md:table" data-testid="tabella-archivio">
                        <colgroup><col /><col class="w-[170px]" /><col class="w-[190px]" /><col class="w-[80px]" /><col class="w-[90px]" /><col class="w-[90px]" /></colgroup>
                        <thead class="bg-slate-50 text-xs font-semibold text-slate-500 dark:bg-slate-900/60 dark:text-slate-400">
                            <tr>
                                <th class="whitespace-nowrap px-4 py-2 text-left">Assemblea</th>
                                <th class="whitespace-nowrap px-3 py-2 text-left">Per chi</th>
                                <th class="whitespace-nowrap px-3 py-2 text-left">Stato</th>
                                <th class="whitespace-nowrap px-3 py-2 text-right">Punti</th>
                                <th class="whitespace-nowrap px-3 py-2 text-right">Delibere</th>
                                <th class="px-3 py-2"><span class="sr-only">Apri</span></th>
                            </tr>
                        </thead>
                        <tbody v-for="g in perAnno" :key="g.anno">
                            <tr class="border-t border-slate-100 bg-slate-50/60 dark:border-slate-800 dark:bg-slate-900/40">
                                <td colspan="6" class="px-4 py-1.5 text-xs font-semibold text-slate-600 dark:text-slate-300">{{ g.anno }}</td>
                            </tr>
                            <tr v-for="r in g.righe" :key="r.id" class="border-t border-slate-100 align-top dark:border-slate-800" data-testid="riga-archivio">
                                <td class="px-4 py-2.5">
                                    <span class="block break-words font-medium text-slate-900 dark:text-slate-100">{{ r.nome }}</span>
                                    <span class="block break-words text-xs text-slate-500 dark:text-slate-400">{{ r.sottotitolo }}</span>
                                </td>
                                <td class="px-3 py-2.5 text-slate-700 dark:text-slate-300">{{ r.perimetro }}</td>
                                <td class="px-3 py-2.5">
                                    <span :class="[BADGE, CLASSI_DEL_BADGE[coloreDelloStato(r.stato, r.stato === 'registrata_a_mano' && !r.a_mano)]]">{{ r.stato_parola }}</span>
                                    <span v-if="r.verbale_da_caricare" :class="[BADGE, CLASSI_DEL_BADGE.ambra, 'mt-1']">Verbale da caricare</span>
                                </td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ r.punti }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ r.delibere }}</td>
                                <td class="px-3 py-2.5 text-right"><Link :href="apri(r)" class="inline-flex h-7 w-[70px] items-center justify-center rounded-md border border-slate-200 text-xs font-medium dark:border-slate-700">Apri</Link></td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Al telefono: una card per assemblea -->
                    <div class="space-y-3 p-3 md:hidden" data-testid="card-telefono">
                        <template v-for="g in perAnno" :key="`t-${g.anno}`">
                            <p class="px-1 text-xs font-semibold text-slate-600 dark:text-slate-300">{{ g.anno }}</p>
                            <Link v-for="r in g.righe" :key="`t-${r.id}`" :href="apri(r)" class="block rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                                <span class="block break-words font-medium text-slate-900 dark:text-slate-100">{{ r.nome }}</span>
                                <span class="block break-words text-xs text-slate-500 dark:text-slate-400">{{ r.perimetro }} · {{ quanti(r.punti, 'punto', 'punti') }} · {{ quanti(r.delibere, 'delibera', 'delibere') }}</span>
                                <span class="mt-1.5 flex flex-wrap gap-1.5">
                                    <span :class="[BADGE, CLASSI_DEL_BADGE[coloreDelloStato(r.stato, r.stato === 'registrata_a_mano' && !r.a_mano)]]">{{ r.stato_parola }}</span>
                                    <span v-if="r.verbale_da_caricare" :class="[BADGE, CLASSI_DEL_BADGE.ambra]">Verbale da caricare</span>
                                </span>
                            </Link>
                        </template>
                    </div>

                    <template #next="{ fetch, hasMore, loading }">
                        <div v-if="hasMore && altre > 0" class="border-t border-slate-100 bg-slate-50 px-4 py-2.5 text-xs font-semibold text-slate-500 dark:border-slate-800 dark:bg-slate-900/60 dark:text-slate-400">
                            {{ altre === 1 ? 'e un\'altra assemblea' : `e altre ${altre} assemblee` }} ·
                            <button type="button" class="underline" :disabled="loading" @click="fetch">{{ loading ? 'Arrivano…' : 'Mostra altre' }}</button>
                        </div>
                    </template>
                </InfiniteScroll>
            </div>
        </div>
        <RegistraDeliberaAMano v-if="puo.gestire" v-model:open="finestraAMano" :condominio-id="condominio.id" @registrata="registrata" />
        <AssembleaGuide v-model:open="mostraGuida" />
    </GestionaleLayout>
</template>
