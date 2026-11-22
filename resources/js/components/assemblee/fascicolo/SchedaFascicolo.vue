<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Il passo «Documenti» della pagina dell'assemblea (la bozza approvata dei documenti dei punti, scene 1 e 1b): che cosa riceve ogni condòmino insieme
 * all'avviso, in un PDF solo con la copertina, l'avviso e i documenti dei punti; per la posta lo stesso per persona («Uno per persona (.zip)»).
 *
 * - In cima la riga che dice che cosa si fa nel passo.
 * - Una riga per punto: il numero, il testo con la riga grigia sotto (la scheda da cui nasce, o la versione fermata), a destra «Aggiungi un documento»;
 *   la copertina con «Pronti», il rendiconto con il suo stato, il preventivo con «Ferma il preventivo» al posto di «Aggiungi un documento».
 * - I documenti come etichette: il nome, le pagine e la × di «Togli». Con molti, le prime tre e «e altri 4 · Mostra tutti».
 * - La proposta del programma su una riga azzurra («Proposto dalla fattura n. 45» con «Aggiungi»); più PDF dalla stessa scheda in una riga con
 *   «Aggiungili tutti» e «Scegli», che apre la finestra con quelli già spuntati. Niente entra da solo.
 * - Il piede come nelle finestre: il riepilogo del fascicolo a sinistra (arancio vicino al tetto), «Uno per persona (.zip)» e «Componi il fascicolo».
 * - Al telefono ogni riga va in colonna: il testo, i documenti, la proposta, poi il pulsante; i pulsanti del piede uno sotto l'altro.
 *
 * Le righe arrivano già scritte dal server nella prop `fascicolo`; ogni gesto ricarica il passo, la strada e il diario.
 */
import { computed, reactive, ref } from 'vue';
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import { FileText, Plus, X } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { apriPdfInUnaScheda, fraseDaErroreConFile, nomeDalContentDisposition, scaricaFile, verificaIlFile } from '@/lib/gestionale/incassi/ricevute';
import FinestraDocumentiDelPunto from './FinestraDocumentiDelPunto.vue';
import { SOLO_FASCICOLO, classiBadge, testoDelFile } from '../convocazione/aiuti';
import type { DocumentoDelPunto, PropostaDelPunto, RigaDelFascicolo, SchedaDelFascicolo } from '../convocazione/tipi';

const props = defineProps<{ fascicolo: SchedaDelFascicolo | Record<string, never> }>();

/** Le etichette che si vedono prima di «Mostra tutti». */
const ETICHETTE = 3;

const f = computed<SchedaDelFascicolo | null>(() => ('rotte' in props.fascicolo ? (props.fascicolo as SchedaDelFascicolo) : null));
const esito = ref<string | null>(null);
const problema = ref<string | null>(null);
const inCorso = ref(false);
const gestoInCorso = ref(false);
/** I punti con l'elenco dei documenti aperto per intero. */
const tutti = reactive<Record<number, boolean>>({});
const finestra = reactive<{ aperta: boolean; punto: { id: number; numero: string; testo: string; sotto: string | null } | null; scelti: number[] }>({
    aperta: false, punto: null, scelti: [],
});

const conPunto = (url: string, punto: number) => url.replace('__PUNTO__', String(punto));
const pagine = (n: number | null, breve = false) => (n === null ? '' : breve ? `${n} p.` : n === 1 ? '1 pagina' : `${n} pagine`);
const documentiVisibili = (r: RigaDelFascicolo): DocumentoDelPunto[] => (tutti[r.punto_id ?? 0] ? r.documenti : r.documenti.slice(0, ETICHETTE));
/** La riga grigia sotto il punto: la scheda da cui nasce; per la copertina, il rendiconto e il preventivo, il loro documento. */
const sotto = (r: RigaDelFascicolo) => {
    const t = r.prende ? r.sotto : r.documento;

    return t ? t.charAt(0).toUpperCase() + t.slice(1) : null;
};
/** Il badge di stato: la copertina e il rendiconto (per gli altri punti parlano i documenti). */
const badge = (r: RigaDelFascicolo) => (!r.prende && r.azione !== 'ferma_preventivo' ? r.stato : null);
const nomeDellaProposta = (p: PropostaDelPunto) => (p.documenti.length === 1 ? p.documenti[0].nome : `${p.documenti.length} PDF`);
const pagineDellaProposta = (p: PropostaDelPunto) => (p.pagine === null ? '' : ` · ${pagine(p.pagine)}`);

const riepilogo = computed(() => f.value?.riepilogo ?? { documenti: 0, pagine: 0, mb: 0, tetto: 40, dire: false, vicino: false });

function quandoSbaglia(e: Record<string, string>) {
    problema.value = Object.values(e).join(' ');
}

function fermaIlPreventivo() {
    if (!f.value?.ferma_preventivo.attivo) return;
    problema.value = null;
    router.post(f.value.rotte.ferma_preventivo, {}, { preserveScroll: true, only: SOLO_FASCICOLO, onError: quandoSbaglia });
}

/** «Aggiungi» e «Aggiungili tutti» della proposta: i documenti entrano con il clic, mai da soli. */
function aggiungi(r: RigaDelFascicolo, documenti: number[]) {
    if (!f.value || r.punto_id === null || gestoInCorso.value) return;
    problema.value = null;
    gestoInCorso.value = true;
    router.post(conPunto(f.value.rotte.aggiungi, r.punto_id), { documenti }, {
        preserveScroll: true, only: SOLO_FASCICOLO, onError: quandoSbaglia, onFinish: () => { gestoInCorso.value = false; },
    });
}

/** «Togli»: il documento esce dal punto e resta nei documenti del condominio. */
function togli(r: RigaDelFascicolo, d: DocumentoDelPunto) {
    if (!f.value || r.punto_id === null || gestoInCorso.value) return;
    problema.value = null;
    gestoInCorso.value = true;
    router.delete(conPunto(f.value.rotte.togli, r.punto_id).replace('__DOCUMENTO__', String(d.id)), {
        preserveScroll: true, only: SOLO_FASCICOLO, onError: quandoSbaglia, onFinish: () => { gestoInCorso.value = false; },
    });
}

/** «Aggiungi un documento», o «Scegli» di una proposta con i suoi PDF già spuntati. */
function apriLaFinestra(r: RigaDelFascicolo, scelti: number[] = []) {
    if (r.punto_id === null) return;
    finestra.punto = { id: r.punto_id, numero: r.numero, testo: r.punto, sotto: r.sotto };
    finestra.scelti = scelti;
    finestra.aperta = true;
}

function componi() {
    if (!f.value?.componi.attivo) return;
    esito.value = null;
    problema.value = null;
    // La scheda si apre nel clic, prima della richiesta.
    apriPdfInUnaScheda(() => axios.post(f.value!.rotte.componi, {}, { responseType: 'blob' }), 'fascicolo-dell-assemblea.pdf')
        .then((arrivato) => {
            esito.value = testoDelFile(arrivato);
            router.reload({ only: SOLO_FASCICOLO });
        })
        .catch(async (e) => { problema.value = await fraseDaErroreConFile(e, 'Il fascicolo non si è composto. Riprova.'); });
}

async function perPersona() {
    if (!f.value?.per_persona.attivo) return;
    esito.value = null;
    problema.value = null;
    inCorso.value = true;
    try {
        const r = await axios.post(f.value.rotte.per_persona, {}, { responseType: 'blob' });
        await verificaIlFile(r, 'zip');
        const nome = nomeDalContentDisposition(r.headers?.['content-disposition'], 'fascicolo-per-persona.zip');
        scaricaFile(r.data, nome);
        esito.value = `L'archivio si scarica con il nome «${nome}»: un PDF per persona, con il suo nome e la delega in fondo.`;
    } catch (e) {
        problema.value = await fraseDaErroreConFile(e, 'L\'archivio non è arrivato. Riprova.');
    } finally {
        inCorso.value = false;
    }
}

const numero = 'inline-flex h-[22px] min-w-[22px] items-center justify-center rounded-md bg-slate-100 px-1 text-xs font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300';
const pulsantePiccolo = 'inline-flex h-7 items-center gap-1 whitespace-nowrap rounded-lg border border-slate-200 bg-white px-2.5 text-xs font-medium text-slate-700 transition-colors hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-55 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800';
const pulsanteDellaProposta = 'inline-flex h-[26px] items-center gap-1 whitespace-nowrap rounded-md border border-sky-200 bg-white px-2 text-xs font-medium text-sky-800 transition-colors hover:bg-sky-50 disabled:cursor-not-allowed disabled:opacity-55 dark:border-sky-800 dark:bg-slate-900 dark:text-sky-300 dark:hover:bg-slate-800';
</script>

<template>
    <div data-testid="scheda-fascicolo">
        <div v-if="!f" class="rounded-xl border border-dashed border-slate-200 px-5 py-6 text-sm text-slate-500 dark:border-slate-800 dark:text-slate-400" data-testid="fascicolo-vuoto">
            Il fascicolo si compone quando l'assemblea ha il suo ordine del giorno.
        </div>

        <div v-else class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
            <p class="border-b border-slate-100 px-3.5 py-3 text-xs text-slate-500 sm:px-[18px] dark:border-slate-800 dark:text-slate-400" data-testid="riga-in-cima">
                Che cosa riceve ogni condòmino insieme all'avviso. Poi «Avanti: Convocazione».
            </p>

            <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                <!-- Una riga per punto: dal tablet in su il numero, il testo e il pulsante a destra; al telefono in colonna, il pulsante sotto. -->
                <li v-for="r in f.righe" :key="`${r.numero}-${r.punto_id}`" class="grid grid-cols-[24px_minmax(0,1fr)] items-start gap-x-3 gap-y-1.5 px-3.5 py-3 sm:grid-cols-[28px_minmax(0,1fr)_auto] sm:px-[18px]"
                    data-testid="riga-fascicolo" :data-punto="r.numero">
                    <span :class="numero"><FileText v-if="r.numero === '—'" class="h-3 w-3" aria-label="Copertina" /><template v-else>{{ r.numero }}</template></span>
                    <div class="min-w-0 text-[13px] leading-snug text-slate-900 dark:text-slate-100">
                        <span class="break-words">{{ r.punto }}</span>
                        <!-- Al telefono lo stato sta accanto al titolo. -->
                        <span v-if="badge(r)" :class="[classiBadge(badge(r)!.colore), 'ml-1 sm:hidden']">{{ badge(r)!.parola }}</span>
                        <span v-if="sotto(r)" class="block break-words text-xs text-slate-500 dark:text-slate-400">{{ sotto(r) }}</span>
                        <span v-if="r.azione === 'ferma_preventivo' && f.frase" class="block text-xs text-amber-800 dark:text-amber-300">{{ f.frase }}</span>
                    </div>
                    <div class="order-last col-start-2 justify-self-start sm:order-none sm:col-start-3 sm:row-start-1 sm:justify-self-end" data-testid="azione-della-riga">
                        <Button v-if="r.azione === 'ferma_preventivo' && f.gestisce" variant="outline" size="sm" class="h-7 text-xs" :disabled="!f.ferma_preventivo.attivo"
                            :title="f.ferma_preventivo.perche ?? undefined" @click="fermaIlPreventivo">Ferma il preventivo</Button>
                        <button v-else-if="r.aggiungi" type="button" :class="pulsantePiccolo" :disabled="gestoInCorso" data-testid="aggiungi-documento" @click="apriLaFinestra(r)">
                            <Plus class="h-3 w-3" aria-hidden="true" />Aggiungi un documento
                        </button>
                        <span v-else-if="badge(r)" :class="[classiBadge(badge(r)!.colore), 'hidden sm:inline-flex']">{{ badge(r)!.parola }}</span>
                    </div>

                    <!-- I documenti del punto: il nome, le pagine e la × di «Togli»; le prime tre, poi «Mostra tutti». -->
                    <div v-if="r.documenti.length" class="col-start-2 flex flex-wrap gap-1.5 sm:col-span-2" data-testid="documenti-del-punto">
                        <span v-for="d in documentiVisibili(r)" :key="d.id" class="inline-flex max-w-full items-center gap-1.5 rounded-md border border-slate-200 bg-white py-1 pr-1 pl-2 text-xs text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300"
                            data-testid="documento-del-punto">
                            <FileText class="h-3 w-3 shrink-0" aria-hidden="true" />
                            <span class="max-w-[170px] truncate text-slate-900 sm:max-w-[240px] dark:text-slate-100" :title="d.nome">{{ d.nome }}</span>
                            <span v-if="d.pagine !== null" class="shrink-0 text-slate-500 dark:text-slate-400"><span class="hidden sm:inline">{{ pagine(d.pagine) }}</span><span class="sm:hidden">{{ pagine(d.pagine, true) }}</span></span>
                            <!-- Un documento che non si legge più come PDF (il file sparito dal disco): non partirebbe, e il fascicolo lo dice con il nome. -->
                            <span v-else class="shrink-0 text-amber-800 dark:text-amber-300" data-testid="documento-che-non-si-legge">non si legge</span>
                            <button v-if="r.togli" type="button" class="inline-flex h-[18px] w-[18px] shrink-0 items-center justify-center rounded text-slate-500 hover:bg-slate-100 hover:text-slate-800 disabled:opacity-55 dark:hover:bg-slate-800 dark:hover:text-slate-200"
                                :aria-label="`Togli «${d.nome}»`" title="Togli" :disabled="gestoInCorso" data-testid="togli-documento" @click="togli(r, d)">
                                <X class="h-3 w-3" aria-hidden="true" />
                            </button>
                        </span>
                        <button v-if="r.documenti.length > ETICHETTE" type="button"
                            class="inline-flex items-center rounded-md border border-dashed border-slate-300 px-2 py-1 text-xs font-medium text-sky-700 hover:bg-slate-50 dark:border-slate-700 dark:text-sky-400 dark:hover:bg-slate-900"
                            @click="tutti[r.punto_id ?? 0] = !tutti[r.punto_id ?? 0]">
                            {{ tutti[r.punto_id ?? 0] ? 'Mostra meno' : `e altri ${r.documenti.length - ETICHETTE} · Mostra tutti` }}
                        </button>
                    </div>

                    <!-- La proposta del programma: una riga azzurra per scheda, mai aggiunta da sola. -->
                    <div v-for="(p, i) in r.proposte" :key="i" class="col-start-2 flex flex-wrap items-center gap-2 rounded-md bg-sky-50 px-2 py-1.5 text-xs text-sky-800 sm:col-span-2 dark:bg-sky-950/40 dark:text-sky-300"
                        data-testid="proposta">
                        <span class="min-w-0 break-words"><span class="hidden sm:inline" data-testid="da-lungo">{{ p.da }}</span><span class="sm:hidden" data-testid="da-breve">{{ p.breve }}</span>: <b class="font-semibold">{{ nomeDellaProposta(p) }}</b>{{ pagineDellaProposta(p) }}</span>
                        <span class="ml-auto flex gap-1.5">
                            <template v-if="p.documenti.length > 1">
                                <button type="button" :class="pulsanteDellaProposta" @click="apriLaFinestra(r, p.documenti.map((d) => d.id))">Scegli</button>
                                <button type="button" :class="pulsanteDellaProposta" :disabled="gestoInCorso" @click="aggiungi(r, p.documenti.map((d) => d.id))">
                                    <Plus class="h-3 w-3" aria-hidden="true" />Aggiungili tutti
                                </button>
                            </template>
                            <button v-else type="button" :class="pulsanteDellaProposta" :disabled="gestoInCorso" @click="aggiungi(r, [p.documenti[0].id])">
                                <Plus class="h-3 w-3" aria-hidden="true" />Aggiungi
                            </button>
                        </span>
                    </div>

                    <p v-if="r.prende && r.documenti.length === 0" class="col-start-2 text-xs text-slate-500 sm:col-span-2 dark:text-slate-400" data-testid="nessun-documento">Nessun documento.</p>
                </li>
            </ul>

            <!-- Il piede come nelle finestre: il riepilogo a sinistra, i pulsanti a destra; al telefono uno sotto l'altro. -->
            <div class="flex flex-col gap-3 border-t border-slate-200 bg-slate-50 px-3.5 py-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between sm:px-[18px] dark:border-slate-800 dark:bg-slate-900/60">
                <p class="flex min-w-0 items-start gap-2 text-[13px]" :class="riepilogo.vicino ? 'text-amber-800 dark:text-amber-300' : 'text-slate-600 dark:text-slate-300'" role="status"
                    data-testid="riepilogo-fascicolo">
                    <FileText class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    <span v-if="riepilogo.documenti === 0">Nel fascicolo: la copertina e l'avviso, nessun documento.</span>
                    <span v-else>Nel fascicolo: <b class="font-semibold text-slate-900 dark:text-white">{{ riepilogo.documenti === 1 ? '1 documento' : `${riepilogo.documenti} documenti` }}</b>
                        · <b class="font-semibold text-slate-900 dark:text-white">{{ pagine(riepilogo.pagine) }}</b>, più la copertina e l'avviso<template v-if="riepilogo.dire"> · {{ riepilogo.mb }} MB su {{ riepilogo.tetto }}</template><template v-if="riepilogo.vicino">: manda i documenti più pesanti a parte</template>.</span>
                </p>
                <div v-if="f.gestisce" class="flex flex-col gap-1 sm:items-end">
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <Button variant="outline" size="sm" class="h-8" :disabled="!f.per_persona.attivo || inCorso" :title="f.per_persona.perche ?? undefined" @click="perPersona">Uno per persona (.zip)</Button>
                        <Button size="sm" class="h-8" :disabled="!f.componi.attivo" :title="f.componi.perche ?? undefined" @click="componi">
                            <FileText class="h-3.5 w-3.5" /> Componi il fascicolo
                        </Button>
                    </div>
                    <p v-if="!f.per_persona.attivo && f.per_persona.perche" class="text-xs text-slate-500 dark:text-slate-400" data-testid="perche-per-persona">{{ f.per_persona.perche }}</p>
                    <p v-if="!f.componi.attivo && f.componi.perche" class="text-xs text-slate-500 dark:text-slate-400" data-testid="perche-componi">{{ f.componi.perche }}</p>
                </div>
            </div>
            <p v-if="!f.ferma_preventivo.attivo && f.ferma_preventivo.perche" class="px-3.5 pb-3 text-xs text-slate-500 sm:px-[18px] dark:text-slate-400">{{ f.ferma_preventivo.perche }}</p>
            <p v-if="esito" class="px-3.5 py-2 text-xs text-slate-600 sm:px-[18px] dark:text-slate-300" data-testid="esito-fascicolo">{{ esito }}</p>
            <p v-if="problema" class="px-3.5 py-2 text-xs text-rose-700 sm:px-[18px] dark:text-rose-300" data-testid="problema-fascicolo">{{ problema }}</p>
        </div>

        <FinestraDocumentiDelPunto v-if="f" v-model:open="finestra.aperta" :punto="finestra.punto" :scelti="finestra.scelti"
            :rotte="{ documenti: f.rotte.documenti, aggiungi: f.rotte.aggiungi }" :condominio="f.condominio" />
    </div>
</template>
