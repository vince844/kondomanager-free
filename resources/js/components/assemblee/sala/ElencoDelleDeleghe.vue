<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * L'elenco delle deleghe ricevute, uno solo per i due posti dove si vede (la bozza delle card dei passi e del cassetto delle deleghe, proposta 5): la
 * sezione «Deleghe ricevute» del passo «In sala» (`forma="sezione"`) e il cassetto che «Vedi le deleghe» apre (`forma="cassetto"`). Così le due viste non
 * possono dire cose diverse, e «Segna una delega ricevuta» e «Revoca» fanno la stessa cosa in tutti e due.
 *
 * - Il conto: «1 valida · 1 rifiutata · 1 revocata».
 * - **Sezione**: in testa «Deleghe ricevute» con «Segna una delega ricevuta»; al telefono una card per delega, dal tablet in su la tabella a colonne fisse
 *   (chi delega, a chi, ricevuta, scansione, stato, «Revoca»).
 * - **Cassetto**: il conto con «Segna una delega ricevuta» in alto, poi le valide, le rifiutate e le revocate, in gruppi con il titolo piccolo e grigio;
 *   ogni delega con chi delega e a chi, l'unità e i millesimi, ricevuta il, «Apri la scansione», «Revoca», il motivo.
 * - Il conto del quinto per ogni delegato, sui titolari di oggi.
 * - «Revoca» chiede il motivo; la revoca e la delega nuova rileggono con una visita parziale la sala e i passi (le card sopra il passo).
 */
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { BADGE, CLASSI_DEL_BADGE, giornoBreve } from '@/lib/assemblee/assemblee';
import { Plus } from 'lucide-vue-next';
import { millesimi } from '@/lib/assemblee/sala/conti';
import { usePermission } from '@/composables/permissions';
import type { DelegaDellaSala, SalaDellAssemblea } from '@/types/assemblee';
import { quintoDelDelegato } from './sala';
import SegnaUnaDelegaRicevuta from './SegnaUnaDelegaRicevuta.vue';
import FinestraDiTesto from './FinestraDiTesto.vue';

const props = withDefaults(defineProps<{ sala: SalaDellAssemblea; puoGestire: boolean; condominioId: number; assembleaId: number; forma?: 'sezione' | 'cassetto' }>(),
    { forma: 'sezione' });
const { generateRoute } = usePermission();

const unitaDi = (d: DelegaDellaSala) => d.unita.map((u) => props.sala.fotografia?.unita[String(u)]?.nome ?? '').filter(Boolean).join(', ');
const millesimiDi = (d: DelegaDellaSala) => millesimi((props.sala.fotografia?.costituzione.teste ?? [])
    .filter((t) => d.delegante_anagrafica_id !== null && t.persone.includes(d.delegante_anagrafica_id)).reduce((n, t) => n + t.millesimi, 0));
const STATO: Record<string, { parola: string; colore: keyof typeof CLASSI_DEL_BADGE }> = {
    valida: { parola: 'Valida', colore: 'verde' }, rifiutata: { parola: 'Rifiutata', colore: 'rosso' }, revocata: { parola: 'Revocata', colore: 'neutro' },
};
const valide = computed(() => props.sala.deleghe.filter((d) => d.stato === 'valida'));
const rifiutate = computed(() => props.sala.deleghe.filter((d) => d.stato === 'rifiutata'));
const revocate = computed(() => props.sala.deleghe.filter((d) => d.stato === 'revocata'));
/** «1 valida · 1 rifiutata · 1 revocata»: le valide sempre, le altre quando ci sono. */
const conto = computed(() => [valide.value.length === 1 ? '1 valida' : `${valide.value.length} valide`,
    rifiutate.value.length ? (rifiutate.value.length === 1 ? '1 rifiutata' : `${rifiutate.value.length} rifiutate`) : null,
    revocate.value.length ? (revocate.value.length === 1 ? '1 revocata' : `${revocate.value.length} revocate`) : null].filter(Boolean).join(' · '));
/** I gruppi del cassetto: le valide, poi le rifiutate e le revocate; un gruppo vuoto non c'è. */
const gruppi = computed(() => [{ titolo: 'Valide', deleghe: valide.value }, { titolo: 'Rifiutate', deleghe: rifiutate.value },
    { titolo: 'Revocate', deleghe: revocate.value }].filter((g) => g.deleghe.length > 0));
/** Il conto del quinto per ogni delegato, con le deleghe valide. */
const delegati = computed(() => {
    const visti = new Map<string, DelegaDellaSala>();
    for (const d of valide.value) if (!visti.has(d.chiave_delegato)) visti.set(d.chiave_delegato, d);

    return [...visti.values()].map((d) => ({ nome: d.delegato_nome, conto: props.sala.fotografia && d.delegante_anagrafica_id !== null
        ? quintoDelDelegato(props.sala.fotografia, props.sala.deleghe, d.delegante_anagrafica_id, d.delegato_anagrafica_id !== null
            ? { anagrafica_id: d.delegato_anagrafica_id } : { nome: d.delegato_nome }) : null }));
});
const totale = computed(() => props.sala.fotografia?.costituzione.totale_teste ?? 0);
const puntiDi = (d: DelegaDellaSala) => (d.punti ?? []).map((id) => props.sala.punti.find((p) => p.id === id)?.numero).join(', ');
const finestraDelega = ref(false);

// La revoca, con il motivo.
const finestraRevoca = ref(false);
const daRevocare = ref<DelegaDellaSala | null>(null);
function revoca(d: DelegaDellaSala) {
    daRevocare.value = d;
    finestraRevoca.value = true;
}
function revocata(motivo: string) {
    if (!daRevocare.value) return;
    router.post(route(generateRoute('gestionale.assemblee.deleghe.revoca'), { condominio: props.condominioId, assemblea: props.assembleaId,
        delegaRicevuta: daRevocare.value.id }), { motivo }, { preserveScroll: true, only: ['sala', 'passi', 'flash'] });
}

/** «Segna una delega ricevuta» nella sezione: il pulsante pieno del passo, alto 32 pixel come gli altri della sezione. */
const principale = 'inline-flex h-8 items-center justify-center rounded-md bg-slate-900 px-3 text-xs font-semibold text-white hover:bg-slate-800 dark:bg-white dark:text-slate-900';
/** I pulsanti piccoli del modulo, come «Revoca»: alti 28 pixel, il testo a 12, il bordo e il fondo bianco. */
const piccolo = 'inline-flex h-7 items-center justify-center rounded-md border border-slate-200 bg-white px-2.5 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800';
/** «Segna una delega ricevuta» nel cassetto: piccolo e pieno, al telefono a tutta riga. */
const piccoloPieno = 'inline-flex h-7 w-full items-center justify-center rounded-md bg-slate-900 px-2.5 text-xs font-semibold text-white hover:bg-slate-800 sm:w-auto dark:bg-white dark:text-slate-900 dark:hover:bg-slate-200';
</script>

<template>
    <div class="space-y-3" data-testid="elenco-deleghe" :data-forma="forma">
        <!-- ── La sezione del passo ── -->
        <template v-if="forma === 'sezione'">
            <!-- La testa (Vincenzo, 21/11/2026 sera): il titolo con il conto in un badge, la frase sotto, a destra sulla stessa riga il pulsante piccolo e chiaro;
                 al telefono il pulsante va sotto, a tutta riga. -->
            <div class="flex flex-col gap-2 sm:flex-row sm:flex-nowrap sm:items-start sm:justify-between" data-testid="testa-deleghe">
                <div class="min-w-0">
                    <h3 class="flex flex-wrap items-center gap-2 text-sm font-semibold text-slate-900 dark:text-white">Deleghe ricevute
                        <span :class="[BADGE, CLASSI_DEL_BADGE.neutro, 'font-normal']" data-testid="riepilogo-deleghe">{{ conto }}</span></h3>
                    <p class="mt-0.5 text-xs text-slate-600 dark:text-slate-400">Le deleghe arrivate in studio prima dell'assemblea. Valgono per tutte le sue date.</p>
                </div>
                <button v-if="puoGestire" type="button" :class="[piccolo, 'w-full shrink-0 gap-1 sm:w-auto']" data-testid="segna-delega-ricevuta" @click="finestraDelega = true">
                    <Plus class="h-3.5 w-3.5" aria-hidden="true" />Segna una delega</button>
            </div>
            <!-- Al telefono: una card per delega, senza scorrere di lato -->
            <ul class="space-y-2 sm:hidden" data-testid="deleghe-card">
                <li v-for="d in sala.deleghe" :key="d.id" class="rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-800">
                    <div class="flex items-start justify-between gap-2">
                        <p class="min-w-0 break-words"><b class="font-medium text-slate-900 dark:text-white">{{ d.delegante_nome }}</b> a {{ d.delegato_nome }}</p>
                        <span :class="[BADGE, CLASSI_DEL_BADGE[STATO[d.stato]?.colore ?? 'neutro']]">{{ STATO[d.stato]?.parola ?? d.stato }}</span>
                    </div>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        {{ millesimiDi(d) }} · ricevuta il {{ giornoBreve(d.ricevuta_il) }}<template v-if="d.scansione"> ·
                            <a :href="d.scansione" target="_blank" rel="noopener" class="underline underline-offset-2" data-testid="apri-scansione">Apri la scansione</a></template>
                        <template v-if="d.delegato_anagrafica_id === null"> · non è un condòmino</template>
                    </p>
                    <p v-if="d.motivo" class="break-words text-xs text-slate-500 dark:text-slate-400">{{ d.motivo }}</p>
                </li>
            </ul>
            <div class="hidden overflow-x-auto sm:block">
                <table class="w-full min-w-[34rem] text-sm">
                    <thead class="border-b border-slate-100 text-left text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
                        <tr><th class="py-1.5 pr-2 font-medium">Chi delega</th><th class="py-1.5 pr-2 font-medium">A chi</th><th class="py-1.5 pr-2 font-medium">Ricevuta</th>
                            <th class="py-1.5 pr-2 font-medium">Scansione</th><th class="py-1.5 pr-2 font-medium">Stato</th><th class="py-1.5 font-medium"><span class="sr-only">Azioni</span></th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="d in sala.deleghe" :key="d.id" :data-delega="d.id" data-testid="riga-delega">
                            <td class="py-2 pr-2 align-top">
                                <p class="font-medium text-slate-900 dark:text-white">{{ d.delegante_nome }}</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400">{{ unitaDi(d) }}<template v-if="unitaDi(d)"> · </template>{{ millesimiDi(d) }}</p>
                            </td>
                            <td class="py-2 pr-2 align-top">
                                <p class="text-slate-900 dark:text-white">{{ d.delegato_nome }}</p>
                                <p v-if="d.delegato_anagrafica_id === null" class="text-xs text-slate-500 dark:text-slate-400">non è un condòmino</p>
                                <p v-if="d.punti?.length" class="text-xs text-slate-500 dark:text-slate-400">per i punti {{ puntiDi(d) }}</p>
                            </td>
                            <td class="py-2 pr-2 align-top tabular-nums text-slate-700 dark:text-slate-300">{{ giornoBreve(d.ricevuta_il) }}</td>
                            <td class="py-2 pr-2 align-top text-slate-700 dark:text-slate-300" data-testid="scansione">
                                <a v-if="d.scansione" :href="d.scansione" target="_blank" rel="noopener" class="font-medium underline underline-offset-2"
                                    :aria-label="`Apri la scansione della delega di ${d.delegante_nome}`">Apri</a>
                                <template v-else>—</template>
                            </td>
                            <td class="py-2 pr-2 align-top" data-testid="stato-delega">
                                <span :class="[BADGE, CLASSI_DEL_BADGE[STATO[d.stato]?.colore ?? 'neutro']]">{{ STATO[d.stato]?.parola ?? d.stato }}</span>
                                <span v-if="d.da_decidere" :class="[BADGE, CLASSI_DEL_BADGE.ambra, 'ml-1']">Decide il presidente</span>
                                <p v-if="d.motivo" class="mt-0.5 max-w-xs break-words text-xs text-slate-500 dark:text-slate-400">{{ d.motivo }}</p>
                            </td>
                            <!-- «Revoca» nella sua colonna, a destra: un pulsante piccolo, staccato dall'etichetta dello stato. -->
                            <td class="py-2 text-right align-top" data-testid="azione-delega">
                                <button v-if="puoGestire && d.stato === 'valida'" type="button" :class="piccolo" data-testid="revoca-delega" @click="revoca(d)">Revoca</button>
                            </td>
                        </tr>
                        <tr v-if="!sala.deleghe.length"><td colspan="6" class="py-3 text-xs text-slate-500 dark:text-slate-400">Nessuna delega ricevuta, per ora.</td></tr>
                    </tbody>
                </table>
            </div>
        </template>

        <!-- ── Il cassetto ── -->
        <template v-else>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-xs text-slate-600 dark:text-slate-400" data-testid="riepilogo-deleghe">{{ conto }}</p>
                <button v-if="puoGestire" type="button" :class="piccoloPieno" data-testid="segna-delega-ricevuta" @click="finestraDelega = true">Segna una delega ricevuta</button>
            </div>
            <p v-if="!sala.deleghe.length" class="rounded-lg border border-dashed border-slate-300 px-3 py-4 text-center text-xs text-slate-500 dark:border-slate-700 dark:text-slate-400">
                Nessuna delega ricevuta, per ora.
            </p>
            <section v-for="g in gruppi" :key="g.titolo" class="space-y-2">
                <h4 class="text-xs font-semibold text-slate-500 dark:text-slate-400" data-testid="gruppo-deleghe">{{ g.titolo }}</h4>
                <ul class="space-y-2">
                    <li v-for="d in g.deleghe" :key="d.id" class="rounded-lg border border-slate-200 px-3 py-2.5 text-sm dark:border-slate-800" :data-delega="d.id"
                        data-testid="delega-del-cassetto">
                        <div class="flex items-start justify-between gap-2">
                            <p class="min-w-0 break-words text-slate-700 dark:text-slate-300"><b class="font-semibold text-slate-900 dark:text-white">{{ d.delegante_nome }}</b> a {{ d.delegato_nome }}</p>
                            <span class="flex shrink-0 flex-wrap justify-end gap-1">
                                <span :class="[BADGE, CLASSI_DEL_BADGE[STATO[d.stato]?.colore ?? 'neutro']]" data-testid="stato-delega">{{ STATO[d.stato]?.parola ?? d.stato }}</span>
                                <span v-if="d.da_decidere" :class="[BADGE, CLASSI_DEL_BADGE.ambra]">Decide il presidente</span>
                            </span>
                        </div>
                        <p class="mt-0.5 break-words text-xs text-slate-500 dark:text-slate-400">
                            <template v-if="unitaDi(d)">{{ unitaDi(d) }} · </template>{{ millesimiDi(d) }} · ricevuta il {{ giornoBreve(d.ricevuta_il) }}<template
                                v-if="d.delegato_anagrafica_id === null"> · non è un condòmino</template><template v-if="d.punti?.length"> · per i punti {{ puntiDi(d) }}</template>
                        </p>
                        <p v-if="d.motivo" class="mt-0.5 break-words text-xs text-slate-500 dark:text-slate-400">{{ d.motivo }}</p>
                        <div v-if="d.scansione || (puoGestire && d.stato === 'valida')" class="mt-2 flex flex-wrap gap-1.5">
                            <a v-if="d.scansione" :href="d.scansione" target="_blank" rel="noopener" :class="piccolo" data-testid="apri-scansione">Apri la scansione</a>
                            <button v-if="puoGestire && d.stato === 'valida'" type="button" :class="piccolo" data-testid="revoca-delega" @click="revoca(d)">Revoca</button>
                        </div>
                    </li>
                </ul>
            </section>
        </template>

        <!-- Il conto del quinto, nei due posti -->
        <div v-if="totale > 20 || delegati.length" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 dark:border-slate-800 dark:bg-slate-900" data-testid="conto-dei-delegati">
            <p class="text-xs font-semibold text-slate-900 dark:text-white">Il conto del quinto</p>
            <ul class="mt-1 space-y-0.5 text-xs text-slate-700 dark:text-slate-300">
                <li v-for="x in delegati" :key="x.nome"><b class="font-medium">{{ x.nome }}</b> {{ x.conto?.frase ?? '' }}</li>
            </ul>
            <ul class="mt-1 list-disc space-y-0.5 pl-4 text-xs text-slate-500 dark:text-slate-400">
                <li v-if="totale > 20">Il limite: un quinto dei {{ totale }} condòmini e dei millesimi, perché i condòmini sono più di venti.</li>
                <li v-else>Con venti condòmini o meno il quinto non si applica.</li>
                <li>Il conto usa i titolari di oggi; all'apertura si rifà su quelli del giorno dell'assemblea, e una differenza la dice il controllo.</li>
                <li>L'amministratore non può ricevere deleghe (art. 67 c. 5 disp. att.).</li>
            </ul>
        </div>

        <SegnaUnaDelegaRicevuta v-model:open="finestraDelega" :sala="sala" />
        <FinestraDiTesto v-model:open="finestraRevoca" titolo="Revoca la delega" etichetta="Perché si revoca" pulsante="Revoca la delega"
            :descrizione="daRevocare ? `${daRevocare.delegante_nome} a ${daRevocare.delegato_nome}.` : ''" esempio="Per esempio: Rossi Mario verrà di persona."
            testid-finestra="finestra-revoca" testid-testo="motivo-revoca" testid-conferma="conferma-revoca" @salva="revocata" />
    </div>
</template>
