<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Il riquadro «Il rendiconto per l'assemblea» della Sintesi: «Ferma per l'assemblea» con le parti scelte del fascicolo, l'elenco delle versioni con i
 * loro documenti, «Registra l'approvazione» su una versione presentata, e quando il PDF non è stato conservato perché il fascicolo era troppo grande
 * «Conserva le parti una per una» e «Carica il PDF che hai mandato». Ogni cifra e ogni frase arriva dal server (`VersioniDelRendiconto`).
 *
 * L'approvazione chiede se l'assemblea ha approvato anche lo stato di riparto, sì o no: nessuna delle due è scelta prima, perché lo dice il verbale.
 * Una versione fermata e mai approvata si elimina, con il suo PDF; un'approvata o una sostituita resta.
 *
 * Dopo l'approvazione, se le cifre di oggi non sono quelle della versione approvata, l'avviso con le cifre di allora e di oggi, «Vedi che cosa è cambiato»
 * (le registrazioni e le persone) e «Ferma una versione nuova»: niente si blocca. La finestra dell'approvazione dice se le cifre sono cambiate dal giorno
 * della versione fermata. Il confronto arriva dal server (`ConfrontoDelRendiconto`); sopra la soglia di peso si chiede con un pulsante.
 */
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { usePermission } from '@/composables/permissions';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import CampoFile from '@/components/CampoFile.vue';
import { AlertTriangle, Archive, FileDown, Stamp, Trash2 } from 'lucide-vue-next';

export interface DocumentoVersione { id: number; parte: string; nome: string | null; impronta: string }
export interface Versione {
    id: number; numero: number; stato: 'presentata' | 'approvata' | 'sostituita'; emessa_il: string; creata_da: string | null;
    data_delibera: string | null; numero_verbale: string | null; riparto_approvato: boolean | null; nota_approvazione: string | null; approvata_da: string | null;
    parti: string[]; troppo: string | null; parti_conservate: string[]; documenti: DocumentoVersione[]; verbale: { id: number; nome: string | null } | null;
    /** Che cosa mancava alla nota fermata con la versione: la finestra dell'approvazione lo dice. */
    nota_mancanze?: string[];
    /** Le cifre fermate che non tornano fra loro (un errore del programma): la frase per l'amministratore. */
    cifre_non_tornano?: string | null;
    /** Il file del PDF conservato che non c'è più, o non è quello conservato: la frase, e «Carica il PDF che hai mandato». */
    pdf_frase?: string | null;
}
export interface ConfrontoVersione {
    versione_id: number; numero: number; stato: string; giorno: string; cambiato: boolean; frasi: string[]; frase: string | null;
    scritture: { scrittura_id: number; cosa: string; data: string | null; protocollo: string | null; causale: string | null }[];
    /** Per persona il conguaglio (`prima`, `oggi`) e il saldo finale di allora e di oggi: un incasso cambia il saldo, non il conguaglio. */
    persone: { nome: string; unita: string | null; gestione: string; prima: number | null; oggi: number | null; saldo_prima?: number | null; saldo_oggi?: number | null }[];
    persone_cambiate: number;
}
/** Sopra la soglia, senza la richiesta: di ogni versione fermata solo se il giornale è cambiato dal suo giorno. */
export interface GiornaleVersione { versione_id: number; numero: number; giornale_cambiato: boolean | null }
export interface Confronto {
    automatico: boolean; fatto: boolean; approvata: ConfrontoVersione | null; presentate: (ConfrontoVersione | GiornaleVersione)[]; giornale_cambiato: boolean | null;
}

const props = defineProps<{
    condominioId: number; esercizioId: number; versioni: Versione[]; nomiParti: Record<string, string>; oggi: string;
    /** Le parti scelte per il fascicolo nella Sintesi, nell'ordine fisso; il dettaglio del riepilogo e la colonna delle lettere. */
    scelte: string[]; dettaglio: boolean; lettere: boolean;
    /** Che cosa manca alla nota sintetica di oggi: la finestra di «Ferma» lo dice, e non ferma niente. */
    notaMancanze?: string[];
    /** Le cifre di oggi contro la versione approvata e contro l'ultima fermata; null senza versioni. */
    confronto?: Confronto | null;
}>();

const { generateRoute } = usePermission();
const base = computed(() => ({ condominio: props.condominioId, esercizio: props.esercizioId }));
const formatData = (iso: string | null) => { if (!iso) return '—'; const [a, m, g] = iso.slice(0, 10).split('-'); return `${g}/${m}/${a}`; };
const formatOra = (s: string) => `${formatData(s)} alle ${s.slice(11, 16)}`;
const nomeParte = (p: string) => (p === 'fascicolo' ? 'Il fascicolo' : p === 'caricato' ? 'Il PDF caricato' : (props.nomiParti[p] ?? p));
// Le migliaia sempre col punto: `toLocaleString` dell'italiano non lo mette sotto i 10.000 («1716,12»).
const cifra = new Intl.NumberFormat('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2, useGrouping: 'always' } as Intl.NumberFormatOptions);
const euro = (c: number | null | undefined) => (c === null || c === undefined ? '—' : `€\u00a0${cifra.format(c / 100)}`);
/** Una coppia di cifre uguali, allora e oggi: grigia, e lo dice; quella che è cambiata resta in chiaro. */
const uguali = (a: number | null | undefined, b: number | null | undefined) => (a ?? null) === (b ?? null);
const cosaDi = { nuova: 'nuova', cambiata: 'cambiata', tolta: 'tolta' } as Record<string, string>;

/* ─── Dopo l'approvazione: l'avviso, che cosa è cambiato, il confronto a richiesta ─── */
const dopo = computed(() => (props.confronto?.approvata?.cambiato ? props.confronto.approvata : null));
const vediCambiato = ref(false);
const confrontoInCorso = ref(false);
const confronta = () => {
    confrontoInCorso.value = true;
    router.reload({ data: { confronta: 1 }, only: ['confronto'], preserveScroll: true, onFinish: () => { confrontoInCorso.value = false; } } as any);
};
const fermaNuova = () => { confermaFerma.value = true; };
/** Il confronto di una versione fermata, nella finestra della sua approvazione: con le cifre (fatto), o il solo giornale (sopra la soglia). */
const confrontoDi = (v: Versione) => (props.confronto?.presentate ?? []).find((c) => c.versione_id === v.id) ?? null;
const presentataCambiata = (v: Versione) => {
    const c = confrontoDi(v);
    return c && 'cambiato' in c && c.cambiato ? c : null;
};
/** Sopra la soglia, una versione fermata non ancora confrontata: solo se il suo giornale è cambiato, e il pulsante per chiedere le cifre. */
const nonConfrontata = (v: Versione) => {
    const c = confrontoDi(v);
    return c !== null && !('cambiato' in c) ? c : null;
};
/** Sopra la soglia, il confronto a richiesta: per la versione approvata o per una fermata da approvare. */
const daChiedere = computed(() => !!props.confronto && !props.confronto.fatto
    && (props.versioni.some((v) => v.stato === 'approvata') || (props.confronto.presentate ?? []).length > 0));
const hrefDocumento = (v: Versione, id: number) => route(generateRoute('gestionale.esercizi.rendiconto.versioni.documento'), { ...base.value, versione: v.id, documento: id });

/* ─── Ferma per l'assemblea ─── */
const confermaFerma = ref(false);
const inCorso = ref(false);
const ferma = () => {
    if (inCorso.value) return;
    inCorso.value = true;
    router.post(route(generateRoute('gestionale.esercizi.rendiconto.versioni.store'), base.value),
        { parti: props.scelte, dettaglio: props.dettaglio, lettere: props.lettere && props.scelte.includes('condomini') },
        { preserveScroll: true, onFinish: () => { inCorso.value = false; confermaFerma.value = false; } });
};

/* ─── Conserva le parti una per una, carica il PDF mandato ─── */
const conserva = (v: Versione, parte: string) => {
    if (inCorso.value) return;
    inCorso.value = true;
    router.post(route(generateRoute('gestionale.esercizi.rendiconto.versioni.parte'), { ...base.value, versione: v.id }), { parte },
        { preserveScroll: true, onFinish: () => { inCorso.value = false; } });
};
const daConservare = (v: Versione) => v.parti.filter((p) => !v.parti_conservate.includes(p));
const caricaPdf = (v: Versione, evento: Event) => {
    const file = (evento.target as HTMLInputElement).files?.[0];
    if (!file) return;
    router.post(route(generateRoute('gestionale.esercizi.rendiconto.versioni.carica'), { ...base.value, versione: v.id }), { pdf: file },
        { forceFormData: true, preserveScroll: true });
};

/* ─── Elimina una versione fermata e mai approvata ─── */
const eliminaDi = ref<number | null>(null);
const elimina = (v: Versione) => {
    if (inCorso.value) return;
    inCorso.value = true;
    router.delete(route(generateRoute('gestionale.esercizi.rendiconto.versioni.destroy'), { ...base.value, versione: v.id }),
        { preserveScroll: true, onFinish: () => { inCorso.value = false; eliminaDi.value = null; } });
};

/* ─── Registra l'approvazione ─── */
const approvaDi = ref<number | null>(null);
const vuota = () => ({ data_delibera: '', numero_verbale: '', riparto_approvato: '' as '' | '1' | '0', nota_approvazione: '' });
const approvazione = ref(vuota());
const verbale = ref<File | null>(null);
const apriApprovazione = (v: Versione) => { approvaDi.value = v.id; approvazione.value = vuota(); verbale.value = null; };
const registra = () => {
    if (approvaDi.value === null) return;
    router.post(route(generateRoute('gestionale.esercizi.rendiconto.versioni.approva'), { ...base.value, versione: approvaDi.value }),
        { ...approvazione.value, ...(verbale.value ? { verbale: verbale.value } : {}) },
        { forceFormData: true, preserveScroll: true, onSuccess: () => { approvaDi.value = null; } });
};
const statoDi = (v: Versione) => ({
    // Il programma sa quando la versione è stata fermata, e da chi; non quando è stata presentata all'assemblea.
    presentata: `Fermata il ${formatOra(v.emessa_il)}${v.creata_da ? ` da ${v.creata_da}` : ''}`,
    approvata: `Approvata il ${formatData(v.data_delibera)}${v.numero_verbale ? `, verbale n. ${v.numero_verbale}` : ''}`,
    sostituita: `Approvata il ${formatData(v.data_delibera)}${v.numero_verbale ? `, verbale n. ${v.numero_verbale}` : ''}, poi sostituita da un'approvazione successiva`,
}[v.stato]);
</script>

<template>
    <div class="rounded-md border bg-white dark:bg-slate-900 mb-6" data-test="per-l-assemblea">
        <div class="px-4 py-3 border-b bg-gray-50/50 dark:bg-slate-800/30 flex flex-wrap items-center justify-between gap-2">
            <h3 class="font-semibold text-slate-800 dark:text-slate-200 flex items-center gap-2"><Archive class="w-4 h-4 text-slate-500 dark:text-slate-400" /> Il rendiconto per l'assemblea</h3>
            <Button v-if="!confermaFerma" size="sm" variant="outline" class="h-8" data-test="ferma" @click="confermaFerma = true">Ferma per l'assemblea</Button>
        </div>

        <!-- Dopo l'approvazione: che cosa è cambiato, con le cifre di allora e di oggi. Un avviso: niente si blocca. -->
        <div v-if="dopo" id="confronto" class="px-4 py-3 border-b text-[12.5px] space-y-2 bg-amber-50 dark:bg-amber-900/20 text-amber-900 dark:text-amber-200" data-test="confronto">
            <p class="flex items-start gap-2"><AlertTriangle class="w-4 h-4 shrink-0 mt-0.5" /> <span data-test="confronto-frase">{{ dopo.frase }}</span></p>
            <div class="flex flex-wrap gap-2">
                <Button size="sm" variant="outline" class="h-7" data-test="vedi-cambiato" @click="vediCambiato = !vediCambiato">{{ vediCambiato ? 'Chiudi l\'elenco' : 'Vedi che cosa è cambiato' }}</Button>
                <Button v-if="!confermaFerma" size="sm" variant="outline" class="h-7" data-test="ferma-nuova" @click="fermaNuova">Ferma una versione nuova</Button>
            </div>
            <div v-if="vediCambiato" class="space-y-2 text-[12px]" data-test="elenco-cambiato">
                <template v-if="dopo.scritture.length">
                    <p class="font-semibold">Le registrazioni</p>
                    <div class="rounded border border-amber-200 dark:border-amber-900 overflow-x-auto bg-white/60 dark:bg-slate-900/40"><table class="w-full text-[12px]">
                        <thead><tr class="text-[11px] uppercase tracking-wider text-slate-500 dark:text-slate-400"><th class="text-left px-2 py-1">Data</th><th class="text-left px-2 py-1">Protocollo</th><th class="text-left px-2 py-1">Causale</th><th class="text-left px-2 py-1"></th></tr></thead>
                        <tbody><tr v-for="r in dopo.scritture" :key="r.scrittura_id" class="border-t border-amber-100 dark:border-amber-900/50" :data-scrittura="r.scrittura_id">
                            <td class="px-2 py-1 tabular-nums whitespace-nowrap">{{ formatData(r.data) }}</td><td class="px-2 py-1 whitespace-nowrap">{{ r.protocollo ?? '—' }}</td><td class="px-2 py-1">{{ r.causale ?? '' }}</td><td class="px-2 py-1">{{ cosaDi[r.cosa] ?? r.cosa }}</td>
                        </tr></tbody>
                    </table></div>
                </template>
                <template v-if="dopo.persone.length">
                    <p class="font-semibold">Il conguaglio per persona<span v-if="dopo.persone_cambiate > dopo.persone.length" class="font-normal"> (le prime {{ dopo.persone.length }} di {{ dopo.persone_cambiate }})</span></p>
                    <div class="rounded border border-amber-200 dark:border-amber-900 overflow-x-auto bg-white/60 dark:bg-slate-900/40"><table class="w-full min-w-[640px] table-fixed text-[12px]" data-test="tabella-persone">
                        <colgroup><col /><col class="w-[110px]" /><col class="w-[105px]" /><col class="w-[105px]" /><col class="w-[105px]" /><col class="w-[105px]" /></colgroup>
                        <thead class="text-[11px] uppercase tracking-wider text-slate-500 dark:text-slate-400">
                            <tr><th class="px-2 pt-1" colspan="2"></th><th class="text-center px-2 pt-1 whitespace-nowrap" colspan="2">Conguaglio</th><th class="text-center px-2 pt-1 whitespace-nowrap" colspan="2">Saldo finale</th></tr>
                            <tr><th class="text-left px-2 pb-1">Persona</th><th class="text-left px-2 pb-1">Unità</th><th class="text-right px-2 pb-1">Approvato</th><th class="text-right px-2 pb-1">Oggi</th><th class="text-right px-2 pb-1">Approvato</th><th class="text-right px-2 pb-1">Oggi</th></tr>
                        </thead>
                        <tbody><tr v-for="(r, i) in dopo.persone" :key="i" class="border-t border-amber-100 dark:border-amber-900/50" data-test="persona-cambiata">
                            <td class="px-2 py-1">{{ r.nome }}</td><td class="px-2 py-1 whitespace-nowrap">{{ r.unita ?? '—' }}</td>
                            <td v-for="(c, j) in [r.prima, r.oggi, r.saldo_prima, r.saldo_oggi]" :key="j" class="px-2 py-1 text-right tabular-nums whitespace-nowrap"
                                :class="uguali(j < 2 ? r.prima : r.saldo_prima, j < 2 ? r.oggi : r.saldo_oggi) ? 'text-slate-400 dark:text-slate-500' : ''"
                                :data-uguale="uguali(j < 2 ? r.prima : r.saldo_prima, j < 2 ? r.oggi : r.saldo_oggi) ? 'si' : undefined">{{ euro(c) }}</td>
                        </tr></tbody>
                    </table></div>
                </template>
                <p v-if="dopo.persone.length" class="text-[12px]" data-test="spiega-coppie">Il conguaglio sono le spese dell'esercizio meno le quote dei piani; il saldo finale gli aggiunge la posizione sulle rate: un incasso cambia il saldo, non il conguaglio. In grigio la coppia che non è cambiata.</p>
                <p class="text-[11.5px]">Perché è cambiato lo sai tu: il programma non lo giudica. Il rendiconto approvato resta quello del PDF della versione {{ dopo.numero }}.</p>
            </div>
        </div>
        <div v-else-if="daChiedere" id="confronto" class="px-4 py-3 border-b text-[12.5px] space-y-2" data-test="confronto-a-richiesta">
            <p class="text-slate-700 dark:text-slate-300">
                <template v-if="props.confronto.giornale_cambiato">Dal giorno della versione approvata il giornale dell'esercizio è cambiato. </template>
                <template v-else-if="props.confronto.giornale_cambiato === false">Dal giorno della versione approvata il giornale dell'esercizio non è cambiato; un passaggio o una tabella possono cambiare lo stesso il conguaglio. </template>
                Con questo condominio il confronto delle cifre con {{ props.versioni.some((v) => v.stato === 'approvata') ? 'la versione approvata' : 'la versione fermata' }}, persona per persona, non si fa da sé: chiede tempo.
            </p>
            <Button size="sm" variant="outline" class="h-7" :disabled="confrontoInCorso" data-test="confronta" @click="confronta">{{ confrontoInCorso ? 'Confronto in corso…' : (props.versioni.some((v) => v.stato === 'approvata') ? 'Confronta con la versione approvata' : 'Confronta con la versione fermata') }}</Button>
        </div>
        <p v-else-if="props.confronto?.fatto && props.confronto.approvata && !props.confronto.approvata.cambiato" class="px-4 py-2 border-b text-[12px] text-emerald-800 dark:text-emerald-300" data-test="confronto-uguale">
            Dall'approvazione del {{ props.confronto.approvata.giorno }} le cifre dell'esercizio non sono cambiate.
        </p>

        <div v-if="confermaFerma" class="px-4 py-3 border-b text-[12.5px] space-y-2 bg-sky-50/50 dark:bg-sky-900/10" data-test="conferma-ferma">
            <p>Si conservano le cifre di oggi e il PDF del fascicolo con le parti scelte: {{ props.scelte.map(nomeParte).join(', ') || 'tutte' }}. Dopo, le schede continuano a cambiare a ogni registrazione; la versione fermata no.</p>
            <div v-if="props.notaMancanze && props.notaMancanze.length" class="text-[11.5px] text-amber-800 dark:text-amber-300" data-test="ferma-nota-mancanze">
                <p class="font-semibold">La nota sintetica (si può fermare lo stesso):</p>
                <p v-for="m in props.notaMancanze" :key="m">{{ m }}</p>
            </div>
            <p class="text-[11.5px] text-slate-500 dark:text-slate-400">Con molti condòmini può volerci più di un minuto. Se il fascicolo è troppo grande per farlo in una volta, la versione nasce con le cifre e le parti si conservano una per una.</p>
            <div class="flex gap-2">
                <Button size="sm" :disabled="inCorso" data-test="ferma-conferma" @click="ferma">Ferma</Button>
                <Button size="sm" variant="ghost" :disabled="inCorso" @click="confermaFerma = false">Annulla</Button>
            </div>
        </div>

        <p v-if="props.versioni.length === 0" class="px-4 py-3 text-[12.5px] text-slate-500 dark:text-slate-400" data-test="nessuna-versione">
            Nessuna versione: il rendiconto è in bozza. «Ferma per l'assemblea» conserva le cifre e il PDF che mandi ai condòmini con la convocazione.
        </p>
        <ul v-else class="divide-y divide-slate-100 dark:divide-slate-800">
            <li v-for="v in props.versioni" :key="v.id" class="px-4 py-3 text-[12.5px] space-y-1.5" :data-versione="v.numero" :data-stato="v.stato">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span><b>Versione {{ v.numero }}</b> · {{ statoDi(v) }}</span>
                    <span v-if="v.stato === 'presentata' && approvaDi !== v.id" class="flex gap-2">
                        <Button size="sm" variant="outline" class="h-7" data-test="apri-approvazione" @click="apriApprovazione(v)"><Stamp class="w-3.5 h-3.5 mr-1" /> Registra l'approvazione</Button>
                        <Button v-if="eliminaDi !== v.id" size="sm" variant="ghost" class="h-7" data-test="apri-elimina" @click="eliminaDi = v.id"><Trash2 class="w-3.5 h-3.5 mr-1" /> Elimina la versione</Button>
                    </span>
                </div>
                <div v-if="eliminaDi === v.id" class="rounded border border-rose-200 dark:border-rose-900 bg-rose-50 dark:bg-rose-900/20 p-2 space-y-1.5 text-rose-900 dark:text-rose-200" data-test="conferma-elimina">
                    <p>Si elimina la versione {{ v.numero }}, fermata il {{ formatData(v.emessa_il) }} e mai approvata. Il PDF conservato se ne va con lei: se l'hai mandato ai condòmini, tienine una copia. Le schede del rendiconto non cambiano.</p>
                    <div class="flex gap-2">
                        <Button size="sm" variant="destructive" class="h-7" :disabled="inCorso" data-test="elimina-conferma" @click="elimina(v)">Elimina la versione e il suo PDF</Button>
                        <Button size="sm" variant="ghost" class="h-7" :disabled="inCorso" @click="eliminaDi = null">Annulla</Button>
                    </div>
                </div>
                <p v-if="v.cifre_non_tornano" class="rounded border border-rose-200 dark:border-rose-900 bg-rose-50 dark:bg-rose-900/20 p-2 text-rose-900 dark:text-rose-200" data-test="cifre-non-tornano">{{ v.cifre_non_tornano }}</p>
                <div v-if="v.pdf_frase" class="rounded border border-amber-200 dark:border-amber-900 bg-amber-50 dark:bg-amber-900/20 p-2 space-y-1.5 text-amber-900 dark:text-amber-200" data-test="pdf-frase">
                    <p>{{ v.pdf_frase }}</p>
                    <label class="inline-flex items-center gap-1 text-[12px] underline cursor-pointer">Carica il PDF che hai mandato<input type="file" accept="application/pdf,.pdf" class="sr-only" data-test="carica-pdf-file" @change="caricaPdf(v, $event)" /></label>
                </div>
                <p v-if="v.stato !== 'presentata'" class="text-slate-600 dark:text-slate-300" data-test="riparto">
                    Anche lo stato di riparto: {{ v.riparto_approvato === null ? 'non detto' : (v.riparto_approvato ? 'sì, approvato' : 'no, non approvato') }}.<span v-if="v.nota_approvazione"> {{ v.nota_approvazione }}</span>
                </p>
                <div class="flex flex-wrap gap-x-4 gap-y-1">
                    <a v-for="d in v.documenti" :key="d.id" :href="hrefDocumento(v, d.id)" target="_blank" class="inline-flex items-center gap-1 underline" :data-documento="d.parte">
                        <FileDown class="w-3.5 h-3.5" /> {{ nomeParte(d.parte) }}
                    </a>
                    <a v-if="v.verbale" :href="hrefDocumento(v, v.verbale.id)" target="_blank" class="inline-flex items-center gap-1 underline" data-documento="verbale"><FileDown class="w-3.5 h-3.5" /> Il verbale</a>
                </div>
                <div v-if="v.troppo" class="rounded border border-amber-200 dark:border-amber-900 bg-amber-50 dark:bg-amber-900/20 p-2 space-y-1.5 text-amber-900 dark:text-amber-200" data-test="senza-pdf">
                    <p>{{ v.troppo }}</p>
                    <div class="flex flex-wrap gap-2">
                        <Button v-for="p in daConservare(v)" :key="p" size="sm" variant="outline" class="h-7" :disabled="inCorso" :data-conserva="p" @click="conserva(v, p)">Conserva «{{ nomeParte(p) }}»</Button>
                        <label class="inline-flex items-center gap-1 text-[12px] underline cursor-pointer">Carica il PDF che hai mandato<input type="file" accept="application/pdf,.pdf" class="sr-only" data-test="carica-pdf" @change="caricaPdf(v, $event)" /></label>
                    </div>
                </div>

                <form v-if="approvaDi === v.id" class="rounded border p-3 space-y-2 bg-slate-50/60 dark:bg-slate-800/30" data-test="approvazione" @submit.prevent="registra">
                    <div v-if="presentataCambiata(v)" class="rounded border border-amber-200 dark:border-amber-900 bg-amber-50 dark:bg-amber-900/20 p-2 space-y-1.5 text-[11.5px] text-amber-900 dark:text-amber-200" data-test="approvazione-cambiato">
                        <p>{{ presentataCambiata(v)!.frase }}</p>
                        <p>Puoi registrare l'approvazione di questa versione, che è quella che l'assemblea ha votato, o fermare una versione nuova con le cifre di oggi e approvare quella.</p>
                        <Button type="button" size="sm" variant="outline" class="h-7" data-test="approvazione-ferma-nuova" @click="approvaDi = null; fermaNuova()">Ferma una versione nuova</Button>
                    </div>
                    <div v-else-if="nonConfrontata(v)" class="rounded border border-amber-200 dark:border-amber-900 bg-amber-50 dark:bg-amber-900/20 p-2 space-y-1.5 text-[11.5px] text-amber-900 dark:text-amber-200" data-test="approvazione-giornale">
                        <p>{{ nonConfrontata(v)!.giornale_cambiato === true ? `Dal giorno della versione ${v.numero} il giornale è cambiato. `
                            : nonConfrontata(v)!.giornale_cambiato === false ? `Dal giorno della versione ${v.numero} il giornale non è cambiato; un passaggio o una tabella possono cambiare lo stesso il conguaglio. ` : '' }}Con questo condominio il confronto delle cifre non si fa da sé: chiedilo, e la finestra dice che cosa è cambiato.</p>
                        <Button type="button" size="sm" variant="outline" class="h-7" :disabled="confrontoInCorso" data-test="approvazione-confronta" @click="confronta">{{ confrontoInCorso ? 'Confronto in corso…' : 'Confronta con la versione fermata' }}</Button>
                    </div>
                    <div v-if="v.nota_mancanze && v.nota_mancanze.length" class="text-[11.5px] text-amber-800 dark:text-amber-300" data-test="approvazione-nota-mancanze">
                        <p class="font-semibold">La nota sintetica di questa versione (si registra lo stesso):</p>
                        <p v-for="m in v.nota_mancanze" :key="m">{{ m }}</p>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <label class="text-xs flex flex-col gap-1"><span>Data della delibera</span><Input v-model="approvazione.data_delibera" type="date" :max="props.oggi" class="h-8 w-[150px] text-xs" /></label>
                        <label class="text-xs flex flex-col gap-1"><span>Numero del verbale</span><Input v-model="approvazione.numero_verbale" placeholder="per esempio 2/2027" class="h-8 w-[160px] text-xs" /></label>
                        <div class="text-xs flex flex-col gap-1"><span>Il verbale (facoltativo)</span><CampoFile v-model="verbale" accept="application/pdf,image/jpeg,image/png,.pdf,.jpg,.jpeg,.png" testid="file-verbale-approvazione" class="max-w-[320px]" /></div>
                    </div>
                    <fieldset class="text-xs space-y-1" data-test="riparto-scelta">
                        <legend class="mb-1">L'assemblea ha approvato anche lo stato di riparto (il conguaglio per persona)?</legend>
                        <label class="mr-4"><input v-model="approvazione.riparto_approvato" type="radio" value="1" /> Sì</label>
                        <label><input v-model="approvazione.riparto_approvato" type="radio" value="0" /> No</label>
                    </fieldset>
                    <label class="text-xs flex flex-col gap-1"><span>Nota (facoltativa)</span><Input v-model="approvazione.nota_approvazione" class="h-8 text-xs" /></label>
                    <p class="text-[11px] text-slate-500 dark:text-slate-400">Si registra ciò che dice il verbale. Lo stato dell'esercizio non cambia, e niente si blocca.</p>
                    <div class="flex gap-2">
                        <Button type="submit" size="sm" :disabled="approvazione.data_delibera === '' || approvazione.riparto_approvato === ''" data-test="registra-approvazione">Registra l'approvazione</Button>
                        <Button type="button" size="sm" variant="ghost" @click="approvaDi = null">Annulla</Button>
                    </div>
                </form>
            </li>
        </ul>
    </div>
</template>
