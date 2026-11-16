<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La scheda «Nota sintetica» del rendiconto: cinque parti, sempre nello stesso ordine; in ognuna i fatti che il programma propone, con la cifra e la scheda
 * da cui vengono, da tenere, togliere o riscrivere, e il testo dell'amministratore. Nessun fatto è tenuto di serie: la nota è sua. Gli elenchi (contratti,
 * polizze e verifiche; altri debiti certi; le cose da decidere), i dipendenti e la dichiarazione sulle liti, le impugnazioni e i sinistri. La copia
 * dall'anno prima o da un altro condominio porta i testi e gli elenchi, mai i fatti; una dichiarazione copiata resta da confermare. «Genera la nota» fa
 * tutto questo in un clic e lascia una riga nel registro. Le frasi e le cifre arrivano dal server (`NotaSinteticaDelRendiconto`, `FattiDellaNota`); la
 * pagina tiene la bozza finché si salva.
 */
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import { Head, Link, router } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import MovimentiLayout from '@/layouts/gestionale/MovimentiLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import RendicontoNotaGuide from '@/components/guides/RendicontoNotaGuide.vue';
import MoneyInput from '@/components/MoneyInput.vue';
import RendicontoSchede from '@/components/gestionale/movimenti/rendiconto/RendicontoSchede.vue';
import TestoDellaNota, { type TestoStudio } from '@/components/gestionale/movimenti/rendiconto/TestoDellaNota.vue';
import { usePermission } from '@/composables/permissions';
import { centsToEuro, euroToCents } from '@/lib/gestionale/money';
import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import vSelect from 'vue-select';
import { Printer, Save, FileText, ListChecks, Copy, WandSparkles } from 'lucide-vue-next';
import type { Building } from '@/types/buildings';
import type { Esercizio } from '@/types/gestionale/esercizi';

interface Riga { voce_id: number; testo: string; scostamento: number }
interface Fatto {
    chiave: string; parte: string; frase: string; righe: Riga[]; coda?: string | null; fonte: string; scheda: string; voto?: string | null;
    spiegazione: boolean; controllo?: string; banca?: string; cause: boolean; stato: 'proposto' | 'tenuto' | 'tolto'; riscritto: string | null; riscritto_il: string | null;
    proposto_allora: string | null; cambiato: boolean; testo_di_oggi: string;
}
interface Parte { chiave: string; titolo: string; testo: string; fatti: Fatto[] }
// Le cifre degli elenchi nella pagina sono euro, come li tiene `MoneyInput` (con il punto dei decimali); '' è «non scritta».
type Euro = number | string;
interface Contratto { tipo: string; cosa: string; con_chi: string; importo: Euro; scadenza: string; rinnovo: string; note: string }
interface Debito { creditore: string; importo: Euro; fonte: string; scadenza: string }
interface Risposta { stato: 'nessuna' | 'ci_sono' | null; testo: string; da_confermare?: boolean }
interface Banca { data: string; saldo: Euro; spiegazione: string }
interface ContoBanca { cassa_id: number; cassa: string; data: string; estratto: number | null; contabilita: number; sul_conto?: number; nei_fondi?: number; differenza: number | null; esito: string; spiegazione: string }
interface StatoFatto { stato: 'tenuto' | 'tolto'; riscritto?: string | null; proposto?: string | null; il?: string | null }
interface Contenuto {
    parti: Record<string, { testo: string }>; fatti: Record<string, StatoFatto>; cause: Record<string, string>; spiegazioni: Record<string, string>;
    contratti: Contratto[]; dipendenti: { stato: 'nessuno' | 'ci_sono' | null; testo: string; da_confermare?: boolean }; debiti_certi: Debito[];
    dichiarazione: Record<string, Risposta>; da_decidere: string[]; banca: Record<string, Banca>;
}
interface RigaRegistro { tipo: string; quando: string; utente: string; nota: string; dati: { mancanze?: string[]; tenuti?: number; copiato_da?: string | null; frase?: string } }
interface Nota {
    parti: Parte[]; non_proposti: { chiave: string; perche: string }[]; spariti: { chiave: string; frase: string | null }[]; contenuto: any;
    fornitori: { categoria: string; fornitore: string }[]; mancanze: string[]; parole: { parole: number; pagine: number }; vuota: boolean; oggi: string;
    banche?: ContoBanca[]; intatta?: boolean; frase_intatta?: string; registro?: RigaRegistro[];
}

const props = defineProps<{
    condominio: Building; condomini: Building[]; esercizio: Esercizio; esercizi: Esercizio[]; nota: Nota;
    anno_prima: { id: number; nome: string } | null; altri: { esercizio_id: number; esercizio: string; condominio_id: number; condominio: string }[];
    testi_studio: TestoStudio[]; puo_salvare_testi: boolean; tipi_contratto: Record<string, string>;
    dichiarazione: Record<string, { domanda: string; nessuna: string; ci_sono: string }>; lunghezza: number; righe_elenco: number;
    lunghezza_frase?: number; lunghezza_riga?: number;
}>();

const { generatePath, generateRoute } = usePermission();
const base = { condominio: props.condominio.id, esercizio: props.esercizio.id };
const lunghezzaFrase = computed(() => props.lunghezza_frase ?? 2000);
const lunghezzaRiga = computed(() => props.lunghezza_riga ?? 500);

/* ─── Le cifre: in centesimi dal server; nella pagina in euro, nel campo di `MoneyInput`; al server in euro con il punto, che li fa centesimi una volta ─── */
const inEuro = (c: number | null | undefined) => {
    if (c === null || c === undefined) return '';
    const [intera, decimali] = (Math.abs(c) / 100).toFixed(2).split('.');
    return `${c < 0 ? '-' : ''}${intera.replace(/\B(?=(\d{3})+(?!\d))/g, '.')},${decimali}`;
};
const dalServer = (c: number | null | undefined): Euro => (c === null || c === undefined ? '' : centsToEuro(c));
const scritta = (v: Euro | null | undefined) => v !== null && v !== undefined && String(v).trim() !== '' && String(v).trim() !== '-';
const perIlServer = (v: Euro | null | undefined) => (!scritta(v) ? null : typeof v === 'number' ? v.toFixed(2) : String(v).trim());
const opzioniCifra = { prefix: '', suffix: '', thousands: '.', decimal: ',', precision: 2, allowBlank: true, masked: false, disableNegative: true };
// Il saldo della banca può essere sotto zero.
const opzioniSaldo = { ...opzioniCifra, disableNegative: false };
const daServer = (c: any): Contenuto => ({
    parti: JSON.parse(JSON.stringify(c.parti)), fatti: JSON.parse(JSON.stringify(c.fatti ?? {})), cause: { ...(c.cause ?? {}) }, spiegazioni: { ...(c.spiegazioni ?? {}) },
    contratti: (c.contratti ?? []).map((r: any) => ({ ...r, importo: dalServer(r.importo), scadenza: r.scadenza ?? '' })),
    dipendenti: { ...c.dipendenti }, debiti_certi: (c.debiti_certi ?? []).map((r: any) => ({ ...r, importo: dalServer(r.importo), scadenza: r.scadenza ?? '' })),
    dichiarazione: JSON.parse(JSON.stringify(c.dichiarazione)), da_decidere: [...(c.da_decidere ?? [])],
    banca: Object.fromEntries(Object.entries(c.banca ?? {}).map(([id, b]: [string, any]) => [id, { data: b.data ?? '', saldo: dalServer(b.saldo), spiegazione: b.spiegazione ?? '' }])),
});
const banche = computed(() => props.nota.banche ?? []);
// Ogni conto della banca ha la sua riga dall'inizio (vuota: non si salva finché non ha una cifra o una spiegazione), con la data della contabilità di serie.
const conLeBanche = (c: Contenuto): Contenuto => {
    banche.value.forEach((b) => { c.banca[String(b.cassa_id)] ??= { data: b.data, saldo: '', spiegazione: '' }; });
    return c;
};
const cont = ref<Contenuto>(conLeBanche(daServer(props.nota.contenuto)));
const testi = ref<TestoStudio[]>(props.testi_studio);
const modificata = ref(false);
const tocca = () => { modificata.value = true; };
// La nota che torna dal server (dopo «Genera la nota») prende il posto di quella della pagina, se la pagina non ha cambiamenti da salvare.
watch(() => props.nota, (n) => { if (!modificata.value) cont.value = conLeBanche(daServer(n.contenuto)); });

/* ─── Tieni, togli, riscrivi ─── */
const statoDi = (f: Fatto) => cont.value.fatti[f.chiave]?.stato ?? 'proposto';
const riscrittoDi = (f: Fatto) => cont.value.fatti[f.chiave]?.riscritto ?? null;
const tieni = (f: Fatto) => { cont.value.fatti[f.chiave] = { stato: 'tenuto' }; tocca(); };
const togli = (f: Fatto) => { cont.value.fatti[f.chiave] = { stato: 'tolto' }; tocca(); };
const rimetti = (f: Fatto) => { delete cont.value.fatti[f.chiave]; tocca(); };
const tieniTutti = (parte?: string) => {
    props.nota.parti.filter((p) => parte === undefined || p.chiave === parte).forEach((p) => p.fatti.forEach((f) => {
        if (statoDi(f) === 'proposto') cont.value.fatti[f.chiave] = { stato: 'tenuto' };
    }));
    tocca();
};
const inRiscrittura = ref<string | null>(null);
const bozzaRiscritta = ref('');
const apriRiscrittura = (f: Fatto) => { inRiscrittura.value = f.chiave; bozzaRiscritta.value = riscrittoDi(f) ?? f.testo_di_oggi; };
const confermaRiscrittura = (f: Fatto) => {
    const t = bozzaRiscritta.value.trim();
    cont.value.fatti[f.chiave] = t === '' ? { stato: 'tenuto' } : { stato: 'tenuto', riscritto: t, proposto: f.testo_di_oggi, il: props.nota.oggi };
    inRiscrittura.value = null;
    tocca();
};
const comeLaProposta = (f: Fatto) => { cont.value.fatti[f.chiave] = { stato: 'tenuto' }; tocca(); };
const formatData = (iso: string | null) => { if (!iso) return '—'; const [a, m, g] = iso.slice(0, 10).split('-'); return `${g}/${m}/${a}`; };
// Come la stampa: il fatto del conto della banca e il controllo con la spiegazione scritta si stampano anche senza «Tieni».
const spiegazioneScritta = (f: Fatto) => f.spiegazione && (cont.value.spiegazioni[f.controllo as string] ?? '').trim() !== '';
const daSolo = (f: Fatto) => spiegazioneScritta(f) || f.banca !== undefined;
const siStampa = (f: Fatto) => statoDi(f) !== 'tolto' && (statoDi(f) === 'tenuto' || daSolo(f)) && !(f.spiegazione && !riscrittoDi(f) && !spiegazioneScritta(f));
const etichettaStato = (f: Fatto) => {
    if (statoDi(f) === 'tolto') return 'Tolto';
    if (statoDi(f) === 'proposto' && daSolo(f)) return `Si stampa: hai scritto ${f.banca !== undefined ? 'il saldo' : 'la spiegazione'}. «Togli» per non stamparlo`;
    if (statoDi(f) === 'tenuto') return !siStampa(f) ? 'Tenuto: si stampa quando scrivi la spiegazione' : riscrittoDi(f) ? 'Riscritto: si stampa la tua frase' : 'Tenuto: si stampa';
    return 'Proposto: non si stampa finché non lo tieni';
};
const hrefScheda: Record<string, string> = {
    sintesi: 'rendiconto', riepilogo: 'rendiconto/riepilogo-finanziario', situazione: 'stato-patrimoniale', cassa: 'rendiconto/cassa',
    condomini: 'rendiconto/condomini', conguaglio: 'rendiconto/conguaglio', controlli: 'rendiconto/controlli',
};
const verso = (s: string) => generatePath(`gestionale/:condominio/esercizi/:esercizio/${hrefScheda[s] ?? 'rendiconto'}`, base);
const nonProposti = (parte: string) => props.nota.non_proposti.filter((n) => parteDellaChiave(n.chiave) === parte);
const parteDellaChiave = (k: string) => (k.startsWith('K') ? 'criteri' : k.startsWith('G') || k === 'N3' ? 'gestione' : k.startsWith('R') ? 'rapporti' : k.startsWith('Q') ? 'questioni' : 'altre');
const voti = computed(() => props.nota.parti.flatMap((p) => p.fatti).filter((f) => f.voto && statoDi(f) === 'tenuto').map((f) => f.voto as string));

/* ─── Il saldo secondo la banca, per conto: la data e la cifra le scrive l'amministratore dall'estratto conto della banca; la differenza è con la contabilità ─── */
const bancaDi = (b: ContoBanca) => cont.value.banca[String(b.cassa_id)];
// La differenza a video, mentre si scrive: alla data della contabilità che la pagina ha; con un'altra data il confronto si rifà al salvataggio.
const differenzaDi = (b: ContoBanca): number | null => {
    const v = bancaDi(b).saldo;
    if (!scritta(v) || Number.isNaN(Number(v)) || bancaDi(b).data !== b.data) return null;
    return euroToCents(v) - b.contabilita;
};
const euroSegno = (c: number) => `${c < 0 ? 'sotto zero di ' : ''}€ ${inEuro(Math.abs(c))}`;
// Le possibili cause di una differenza, con la correzione consigliata e «Ignora», stanno sotto il controllo della banca, nella scheda «Controlli».
const hrefCause = generatePath('gestionale/:condominio/esercizi/:esercizio/rendiconto/controlli', base) + '#controllo-BC';
// Dal controllo della banca («Scrivi il saldo della banca», `?banca=<cassa>`): la pagina si apre sul campo del saldo di quel conto.
onMounted(async () => {
    const id = typeof window === 'undefined' ? null : new URLSearchParams(window.location.search).get('banca');
    if (!id || !/^\d+$/.test(id)) return;
    await nextTick();
    const riquadro = document.querySelector(`[data-banca="${id}"]`);
    riquadro?.scrollIntoView?.({ block: 'center' });
    (riquadro?.querySelector('[data-test="saldo-banca"] input, input[data-test="saldo-banca"]') as HTMLInputElement | null)?.focus();
});
/** Il registro della nota: «Genera la nota» e le cause della differenza con la banca ignorate o riaperte. */
const tipoRegistro = (t: string) => ({ genera: '«Genera la nota»', pista_ignorata: 'Causa della differenza con la banca ignorata', pista_riaperta: 'Causa della differenza con la banca riaperta' } as Record<string, string>)[t] ?? t;

/* ─── La dichiarazione e i dipendenti copiati da un'altra nota: non si stampano finché l'amministratore non li conferma ─── */
const confermaDichiarazione = (k: string) => { cont.value.dichiarazione[k].da_confermare = false; tocca(); };
const rispondi = (k: string) => { cont.value.dichiarazione[k].da_confermare = false; tocca(); };
const confermaDipendenti = () => { cont.value.dipendenti.da_confermare = false; tocca(); };

/* ─── Gli elenchi ─── */
const contrattoVuoto = (): Contratto => ({ tipo: 'contratto', cosa: '', con_chi: '', importo: '', scadenza: '', rinnovo: '', note: '' });
const aggiungiContratto = (r: Partial<Contratto> = {}) => { if (cont.value.contratti.length < props.righe_elenco) { cont.value.contratti.push({ ...contrattoVuoto(), ...r }); tocca(); } };
const aggiungiDebito = () => { if (cont.value.debiti_certi.length < props.righe_elenco) { cont.value.debiti_certi.push({ creditore: '', importo: '', fonte: '', scadenza: '' }); tocca(); } };
const aggiungiDaDecidere = () => { if (cont.value.da_decidere.length < props.righe_elenco) { cont.value.da_decidere.push(''); tocca(); } };
// Un contratto con la scadenza prima della fine dell'esercizio: la pagina lo dice, la stampa no.
const fineEsercizio = computed(() => String((props.esercizio as any).data_fine ?? '').slice(0, 10));
const scaduto = (c: Contratto) => c.scadenza !== '' && fineEsercizio.value !== '' && c.scadenza < fineEsercizio.value;
const suggeriti = computed(() => props.nota.fornitori.filter((f) => !cont.value.contratti.some((c) => c.con_chi.trim().toLowerCase() === f.fornitore.trim().toLowerCase())));

/* ─── La copia dall'anno prima o da un altro condominio: si vede prima che cosa si copia, riga per riga; i fatti mai ─── */
const copia = ref<{ da: string; contenuto: any; altro_condominio?: boolean } | null>(null);
const erroreCopia = ref<string | null>(null);
const cosa = ref({ testi: true, contratti: true, debiti_certi: true, dichiarazione: true, da_decidere: true });
const altroScelto = ref('');
// Le voci dei due menu della pagina: gli altri condomini da cui copiare, i tipi dei contratti.
const vociAltri = computed(() => props.altri.map((a) => ({ id: String(a.esercizio_id), etichetta: `${a.condominio} · ${a.esercizio}` })));
const vociTipoContratto = computed(() => Object.entries(props.tipi_contratto).map(([valore, etichetta]) => ({ valore, etichetta })));
async function chiediCopia(esercizioId: number) {
    erroreCopia.value = null;
    try {
        const r = await axios.get(`${route(generateRoute('gestionale.esercizi.rendiconto.nota.copia'), base)}?da=${esercizioId}`);
        copia.value = r.data;
        // Da un altro condominio, di serie, solo i testi e i contratti: i debiti, le cose da decidere e la dichiarazione sono di quel palazzo.
        const altro = r.data?.altro_condominio === true;
        cosa.value = { testi: true, contratti: true, debiti_certi: !altro, dichiarazione: !altro, da_decidere: !altro };
    } catch (e: any) {
        copia.value = null;
        erroreCopia.value = e?.response?.data?.message ?? 'Non c\'è una nota da copiare.';
    }
}
const daCopiare = computed(() => (copia.value === null ? null : daServer(copia.value.contenuto)));
const titoloParte = (k: string) => props.nota.parti.find((p) => p.chiave === k)?.titolo.slice(3) ?? k;
const partiConTesto = computed(() => (daCopiare.value === null ? [] : Object.entries(daCopiare.value.parti).filter(([, p]) => p.testo.trim() !== '').map(([k]) => titoloParte(k))));
const rispostaCopiata = (k: string, r: Risposta) => (r.stato === 'nessuna' ? props.dichiarazione[k]?.nessuna : r.stato === 'ci_sono' ? props.dichiarazione[k]?.ci_sono : null);
function applicaCopia() {
    const c = daCopiare.value;
    if (c === null) return;
    if (cosa.value.testi) cont.value.parti = c.parti;
    if (cosa.value.contratti) { cont.value.contratti = c.contratti; cont.value.dipendenti = c.dipendenti; }
    if (cosa.value.debiti_certi) cont.value.debiti_certi = c.debiti_certi;
    if (cosa.value.dichiarazione) cont.value.dichiarazione = c.dichiarazione;
    if (cosa.value.da_decidere) cont.value.da_decidere = c.da_decidere;
    copia.value = null;
    altroScelto.value = '';
    tocca();
}

/* ─── «Genera la nota»: in un clic, con la nota dell'amministratore nel registro; la finestra dice che cosa manca ─── */
const genera = ref<{ aperta: boolean; nota: string; inCorso: boolean; esito: { mancanze: string[]; tenuti: number; copiato_da: string | null } | null; errore: string | null }>(
    { aperta: false, nota: '', inCorso: false, esito: null, errore: null });
/** Il suggerimento di «Genera la nota»: che cosa fa da sola, e dove si fa la nota a modo proprio. */
const SPIEGA_GENERA = 'Genera la nota in automatico: tiene tutti i fatti che il programma propone e copia dall\'anno prima i testi e gli elenchi. Per farla a modo tuo, usa le parti qui sotto: tieni o togli i fatti, scrivi i tuoi testi e compila gli elenchi.';
const apriGenera = () => { genera.value = { aperta: true, nota: '', inCorso: false, esito: null, errore: null }; };
async function generaLaNota() {
    genera.value.inCorso = true;
    genera.value.errore = null;
    try {
        const r = await axios.post(route(generateRoute('gestionale.esercizi.rendiconto.nota.genera'), base), { nota: genera.value.nota });
        genera.value.esito = r.data;
        router.reload({ preserveScroll: true } as any);
    } catch (e: any) {
        const errori = e?.response?.data?.errors ?? {};
        genera.value.errore = (Object.values(errori)[0] as string[] | undefined)?.[0] ?? 'La nota non è stata generata.';
    } finally {
        genera.value.inCorso = false;
    }
}

/* ─── Le parole della nota che si stamperebbe ─── */
const parole = computed(() => {
    const pezzi: string[] = [];
    props.nota.parti.forEach((p) => {
        p.fatti.forEach((f) => {
            if (!siStampa(f)) return;
            pezzi.push(riscrittoDi(f) ?? f.testo_di_oggi);
            if (!riscrittoDi(f) && f.spiegazione) pezzi.push(cont.value.spiegazioni[f.controllo as string] ?? '');
            if (!riscrittoDi(f) && f.banca !== undefined) pezzi.push(cont.value.banca[f.banca]?.spiegazione ?? '');
        });
        pezzi.push(cont.value.parti[p.chiave]?.testo ?? '');
    });
    cont.value.contratti.forEach((c) => pezzi.push(`${c.cosa} ${c.con_chi} ${c.rinnovo} ${c.note}`));
    cont.value.debiti_certi.forEach((d) => pezzi.push(`${d.creditore} ${d.fonte}`));
    Object.values(cont.value.dichiarazione).forEach((d) => { if (!d.da_confermare) pezzi.push(d.testo); });
    pezzi.push(...cont.value.da_decidere);
    const n = pezzi.join(' ').split(/\s+/).filter((x) => x !== '').length;
    return { parole: n, pagine: n === 0 ? 0 : Math.max(1, Math.ceil(n / 350)) };
});

/* ─── Salva, stampa ─── */
const inSalvataggio = ref(false);
function salva() {
    const c = cont.value;
    const contenuto = {
        ...c,
        contratti: c.contratti.map((r) => ({ ...r, importo: perIlServer(r.importo), scadenza: r.scadenza || null })),
        debiti_certi: c.debiti_certi.map((r) => ({ ...r, importo: perIlServer(r.importo), scadenza: r.scadenza || null })),
        banca: Object.fromEntries(Object.entries(c.banca).map(([id, b]) => [id, { data: b.data || null, saldo: perIlServer(b.saldo), spiegazione: b.spiegazione }])),
    };
    inSalvataggio.value = true;
    router.put(route(generateRoute('gestionale.esercizi.rendiconto.nota.update'), base), { contenuto }, {
        preserveScroll: true, onSuccess: () => { modificata.value = false; }, onFinish: () => { inSalvataggio.value = false; },
    });
}
const stampa = () => window.open(route(generateRoute('gestionale.esercizi.rendiconto.nota.stampa'), base), '_blank');

// La guida della scheda, che si apre di lato dal pulsante «Guida» dell'intestazione, come nelle altre pagine.
const mostraGuida = ref(false);
const headerBreadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Movimenti' }, { title: 'Rendiconto' }, { title: 'Nota sintetica' },
]);
const pageGuides = [
    { title: 'La nota è tua', description: 'Il programma propone fatti presi dai conti, con la cifra e la scheda da cui vengono: nessuno si stampa finché non lo tieni, salvo il saldo della banca e la spiegazione di un controllo che scrivi tu. Puoi toglierlo, o riscriverlo con le tue parole. Il tuo testo, in ogni parte, si stampa com\'è.', icon: FileText, colorVariant: 'blue' as const },
    { title: 'Che cosa chiede la legge', description: 'La nota sintetica esplicativa è una delle tre parti del rendiconto, con il registro di contabilità e il riepilogo finanziario: dice la gestione, i rapporti in corso e le questioni pendenti. Il programma non dice che la nota è completa: lo sai tu. Quando la nota manca, il frontespizio del fascicolo lo dice ai condòmini.', icon: ListChecks, colorVariant: 'amber' as const },
    { title: 'Dall\'anno prima', description: '«Copia dall\'anno prima» e «Copia da un altro condominio» portano i testi, gli elenchi e la dichiarazione: mai i fatti, che sono dei conti di quest\'anno. Prima di copiare vedi che cosa arriva. La dichiarazione e i dipendenti copiati non si stampano finché non li confermi. «Genera la nota» tiene i fatti proposti e copia dall\'anno prima quello che manca, in un clic.', icon: Copy, colorVariant: 'emerald' as const },
];
</script>

<template>
    <Head title="Nota sintetica" />
    <GestionaleLayout>
        <div class="px-6 py-8 space-y-3">
            <PageHeaderGuide
                has-text-guide
                text-guide-title="Guida"
                @open-text-guide="mostraGuida = true"
                page-title="Nota sintetica esplicativa"
                :page-subtitle="`${props.esercizio.nome}: i fatti che il programma propone e il tuo testo, in cinque parti. Si stampa nel fascicolo subito dopo il frontespizio.`"
                :guides="pageGuides"
                :breadcrumbs="(headerBreadcrumbs as any)"
                :condominio="(props.condominio as any)"
                :condomini="(props.condomini as any)"
                :esercizio="(props.esercizio as any)"
                :esercizi="(props.esercizi as any)"
            />

            <div class="w-full">
                <section class="w-full space-y-4">
                    <MovimentiLayout>
                        <RendicontoSchede :condominio-id="props.condominio.id" :esercizio-id="props.esercizio.id" attiva="nota" />

                        <div class="rounded-md border bg-white dark:bg-slate-900 mb-4 px-4 py-3 text-[12.5px] space-y-2" data-test="testa-nota">
                            <div class="flex flex-wrap items-center gap-2 justify-between">
                                <span data-test="parole">La nota che si stampa: circa {{ parole.parole }} parole, circa {{ parole.pagine }} {{ parole.pagine === 1 ? 'pagina' : 'pagine' }}.<span v-if="modificata" class="text-amber-700 dark:text-amber-400"> Non ancora salvata.</span></span>
                                <div class="flex flex-wrap gap-2">
                                    <Button size="sm" variant="outline" class="h-7" data-test="tieni-tutti" @click="tieniTutti()">Tieni tutti i fatti proposti</Button>
                                    <TooltipProvider>
                                        <Tooltip :delay-duration="200">
                                            <TooltipTrigger as-child>
                                                <span class="inline-flex" data-test="genera-suggerimento">
                                                    <Button size="sm" variant="outline" class="h-7 gap-1.5" :disabled="modificata" data-test="genera" @click="apriGenera"><WandSparkles class="w-3.5 h-3.5" /> Genera la nota</Button>
                                                </span>
                                            </TooltipTrigger>
                                            <TooltipContent class="max-w-[300px] text-xs" data-test="genera-spiegazione">
                                                <p>{{ SPIEGA_GENERA }}</p>
                                                <p v-if="modificata" class="mt-1 font-semibold">Salva prima la nota: «Genera la nota» parte da quella salvata.</p>
                                            </TooltipContent>
                                        </Tooltip>
                                    </TooltipProvider>
                                    <Button v-if="props.anno_prima" size="sm" variant="outline" class="h-7" data-test="copia-anno-prima" @click="chiediCopia(props.anno_prima.id)">Copia dall'anno prima</Button>
                                    <div v-if="props.altri.length" class="menu-campo menu-campo-piccolo w-[260px] max-w-full text-[12px]" data-test="copia-altro">
                                        <v-select :model-value="altroScelto || null" :options="vociAltri" label="etichetta" :reduce="(a: { id: string }) => a.id" :searchable="false"
                                            :clearable="false" placeholder="Copia da un altro condominio…" class="w-full"
                                            @update:model-value="(v: string | null) => { altroScelto = v ?? ''; if (v) chiediCopia(Number(v)); }" />
                                    </div>
                                </div>
                            </div>
                            <p class="text-[11.5px] text-slate-500 dark:text-slate-400">Nessun fatto si stampa finché non lo tieni. «Tieni tutti» li tiene in un clic; «Genera la nota» li tiene e copia dall'anno prima i testi e gli elenchi che mancano. È una tua scelta, e la nota resta tua.</p>
                            <div v-if="props.nota.mancanze.length" class="rounded border border-amber-200 dark:border-amber-900 bg-amber-50 dark:bg-amber-900/20 px-3 py-2 text-amber-900 dark:text-amber-200" data-test="mancanze">
                                <p class="font-semibold">Da completare in questa nota: puoi fermarla e approvarla lo stesso</p>
                                <template v-for="m in props.nota.mancanze" :key="m">
                                    <p :data-test="props.nota.intatta && m === props.nota.frase_intatta ? 'intatta' : undefined">{{ m }}</p>
                                </template>
                            </div>
                            <div v-if="genera.aperta" class="rounded border border-violet-200 dark:border-violet-900 bg-violet-50/60 dark:bg-violet-900/10 px-3 py-2 space-y-2" data-test="finestra-genera">
                                <template v-if="genera.esito === null">
                                    <p class="font-semibold">Genera la nota</p>
                                    <p>Tiene tutti i fatti che il programma propone oggi (non quelli che hai tolto) e copia dall'anno prima i testi delle parti vuote, i contratti e i debiti certi se la nota non ne ha. La dichiarazione e i dipendenti copiati restano da confermare: non si stampano finché non li confermi. Non scrive niente di suo.</p>
                                    <label class="block text-[12px]">La tua nota, che resta nel registro (facoltativa)
                                        <input v-model="genera.nota" :maxlength="lunghezzaRiga" placeholder="Per esempio: la bozza per la riunione con il consiglio" class="mt-1 h-8 w-full rounded border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-2 text-[12px]" data-test="genera-nota" />
                                    </label>
                                    <p v-if="genera.errore" class="text-rose-600 dark:text-rose-400" data-test="genera-errore">{{ genera.errore }}</p>
                                    <div class="flex gap-2">
                                        <Button size="sm" class="h-7" :disabled="genera.inCorso" data-test="genera-conferma" @click="generaLaNota">Genera</Button>
                                        <Button size="sm" variant="ghost" class="h-7" @click="genera.aperta = false">Annulla</Button>
                                    </div>
                                </template>
                                <template v-else>
                                    <p class="font-semibold" data-test="genera-fatto">Fatto: {{ genera.esito.tenuti === 1 ? 'un fatto tenuto' : `${genera.esito.tenuti} fatti tenuti` }}{{ genera.esito.copiato_da ? `; i testi e gli elenchi che mancavano copiati da qui: ${genera.esito.copiato_da}` : '' }}.</p>
                                    <template v-if="genera.esito.mancanze.length">
                                        <p>Che cosa manca ancora:</p>
                                        <p v-for="m in genera.esito.mancanze" :key="m" data-test="genera-mancanza">{{ m }}</p>
                                    </template>
                                    <p v-else>Non manca niente di quello che il programma sa vedere: rileggi la nota prima di fermarla.</p>
                                    <Button size="sm" variant="outline" class="h-7" @click="genera.aperta = false">Chiudi</Button>
                                </template>
                            </div>
                            <p v-if="erroreCopia" class="text-rose-600 dark:text-rose-400" data-test="errore-copia">{{ erroreCopia }}</p>
                            <div v-if="copia && daCopiare" class="rounded border border-sky-200 dark:border-sky-900 bg-sky-50/60 dark:bg-sky-900/10 px-3 py-2 space-y-1" data-test="copia">
                                <p class="font-semibold">Si copia da qui: {{ copia.da }}. Che cosa prende il posto di quello che c'è; i fatti non si copiano mai.</p>
                                <p v-if="copia.altro_condominio" class="text-[11.5px]" data-test="copia-altro-avviso">È un altro condominio: i contratti arrivano senza importo e scadenza; i debiti, le cose da decidere e la dichiarazione sono di quel palazzo, e di serie non si copiano.</p>
                                <label class="flex items-center gap-2" data-cosa="testi"><input v-model="cosa.testi" type="checkbox" /> I testi delle parti<template v-if="partiConTesto.length">: {{ partiConTesto.join(', ') }}</template><template v-else> (nessuna parte ha un testo)</template></label>
                                <label class="flex items-center gap-2" data-cosa="contratti"><input v-model="cosa.contratti" type="checkbox" /> Contratti, polizze e verifiche ({{ daCopiare.contratti.length }}) e i dipendenti, da confermare</label>
                                <ul v-if="daCopiare.contratti.length" class="pl-8 list-disc text-[11.5px]"><li v-for="(c, i) in daCopiare.contratti" :key="i">{{ props.tipi_contratto[c.tipo] ?? c.tipo }} · {{ c.cosa }}{{ c.con_chi ? ` · ${c.con_chi}` : '' }}</li></ul>
                                <label class="flex items-center gap-2" data-cosa="debiti_certi"><input v-model="cosa.debiti_certi" type="checkbox" /> Altri debiti certi ({{ daCopiare.debiti_certi.length }})</label>
                                <ul v-if="daCopiare.debiti_certi.length" class="pl-8 list-disc text-[11.5px]"><li v-for="(d, i) in daCopiare.debiti_certi" :key="i">{{ d.creditore }}{{ scritta(d.importo) ? ` · € ${inEuro(euroToCents(d.importo))}` : '' }}</li></ul>
                                <label class="flex items-center gap-2" data-cosa="dichiarazione"><input v-model="cosa.dichiarazione" type="checkbox" /> La dichiarazione su liti, impugnazioni e sinistri: resta da confermare, e non si stampa finché non la confermi</label>
                                <ul class="pl-8 list-disc text-[11.5px]"><template v-for="(r, k) in daCopiare.dichiarazione" :key="k"><li v-if="rispostaCopiata(String(k), r)">{{ rispostaCopiata(String(k), r) }}</li></template></ul>
                                <label class="flex items-center gap-2" data-cosa="da_decidere"><input v-model="cosa.da_decidere" type="checkbox" /> Le cose da decidere scritte da te ({{ daCopiare.da_decidere.length }})</label>
                                <ul v-if="daCopiare.da_decidere.length" class="pl-8 list-disc text-[11.5px]"><li v-for="(r, i) in daCopiare.da_decidere" :key="i">{{ r }}</li></ul>
                                <div class="flex gap-2 pt-1">
                                    <Button size="sm" class="h-7" data-test="applica-copia" @click="applicaCopia">Copia nella nota</Button>
                                    <Button size="sm" variant="ghost" class="h-7" @click="copia = null; altroScelto = ''">Annulla</Button>
                                </div>
                            </div>
                            <div v-if="props.nota.spariti.length" class="text-[11.5px] text-slate-600 dark:text-slate-400" data-test="spariti">
                                <p class="font-semibold">Fatti tenuti che oggi il programma non propone più (non si stampano):</p>
                                <p v-for="s in props.nota.spariti" :key="s.chiave">{{ s.frase ?? s.chiave }}</p>
                            </div>
                            <details v-if="props.nota.registro?.length" class="text-[11.5px] text-slate-600 dark:text-slate-400" data-test="registro">
                                <summary class="cursor-pointer">Il registro della nota ({{ props.nota.registro.length }})</summary>
                                <div v-for="(r, i) in props.nota.registro" :key="i" class="mt-1 pl-3 border-l-2 border-slate-200 dark:border-slate-700" data-test="riga-registro">
                                    <p><b>{{ r.quando }}</b> · {{ r.utente || 'utente non più presente' }} · {{ tipoRegistro(r.tipo) }}<template v-if="r.nota"> · «{{ r.nota }}»</template></p>
                                    <p v-if="r.tipo !== 'genera'">{{ r.dati.frase }}</p>
                                    <p v-else-if="r.dati.mancanze?.length">Mancava: {{ r.dati.mancanze.join(' ') }}</p>
                                    <p v-else>Non mancava niente di quello che il programma sa vedere.</p>
                                </div>
                            </details>
                        </div>

                        <div v-for="p in props.nota.parti" :key="p.chiave" class="rounded-md border bg-white dark:bg-slate-900 mb-4" :data-parte="p.chiave">
                            <div class="px-4 py-3 border-b bg-gray-50/50 dark:bg-slate-800/30 flex flex-wrap items-center justify-between gap-2">
                                <h3 class="font-semibold text-slate-800 dark:text-slate-200">{{ p.titolo }}</h3>
                                <Button v-if="p.fatti.some((f) => statoDi(f) === 'proposto')" size="sm" variant="outline" class="h-7" data-test="tieni-tutti-parte" @click="tieniTutti(p.chiave)">Tieni tutti i fatti di questa parte</Button>
                            </div>
                            <div class="px-4 py-3 space-y-3 text-[13px]">
                                <div v-for="f in p.fatti" :key="f.chiave" class="rounded border px-3 py-2" :class="statoDi(f) === 'tolto' ? 'opacity-60 border-dashed' : statoDi(f) === 'tenuto' ? 'border-emerald-300 dark:border-emerald-800' : 'border-slate-200 dark:border-slate-700'" :data-fatto="f.chiave" :data-stato="statoDi(f)">
                                    <p class="whitespace-pre-line text-slate-800 dark:text-slate-200" data-test="frase">{{ riscrittoDi(f) ?? f.testo_di_oggi }}</p>
                                    <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-1">
                                        <span data-test="stato-fatto">{{ etichettaStato(f) }}</span> · da: <Link :href="verso(f.scheda)" class="underline">{{ f.fonte }}</Link>
                                    </p>
                                    <p v-if="riscrittoDi(f) && f.cambiato" class="mt-1 text-[11.5px] text-amber-800 dark:text-amber-300" data-test="cambiato">Hai riscritto questo fatto il {{ formatData(cont.fatti[f.chiave]?.il ?? f.riscritto_il) }}; oggi il programma direbbe: «{{ f.testo_di_oggi }}»</p>
                                    <!-- Le cause degli scostamenti, accanto alla loro voce: si stampano dopo la cifra. -->
                                    <div v-if="f.cause && !riscrittoDi(f)" class="mt-2 space-y-2" data-test="cause">
                                        <div v-for="r in f.righe" :key="r.voce_id" class="pl-3 border-l-2 border-slate-200 dark:border-slate-700">
                                            <TestoDellaNota :model-value="cont.cause[String(r.voce_id)] ?? ''" tipo="causa" :testi="testi" :puo-salvare="props.puo_salvare_testi" :condominio-id="props.condominio.id"
                                                :lunghezza="lunghezzaFrase" :righe="2" :etichetta="`La causa: ${r.testo.split(':')[0]}`" segnaposto="Perché la voce si è scostata (si stampa accanto alla cifra)"
                                                @update:model-value="(v: string) => { cont.cause[String(r.voce_id)] = v; tocca(); }" @testi="testi = $event" />
                                        </div>
                                    </div>
                                    <!-- La spiegazione di un controllo che non torna o è da leggere: con la spiegazione il fatto si stampa, anche senza «Tieni». -->
                                    <div v-if="f.spiegazione && !riscrittoDi(f)" class="mt-2" data-test="spiegazione">
                                        <TestoDellaNota :model-value="cont.spiegazioni[f.controllo as string] ?? ''" tipo="spiegazione" :testi="testi" :puo-salvare="props.puo_salvare_testi" :condominio-id="props.condominio.id"
                                            :lunghezza="lunghezzaFrase" :righe="2" etichetta="La tua spiegazione: con la spiegazione il fatto si stampa, senza no" @update:model-value="(v: string) => { cont.spiegazioni[f.controllo as string] = v; tocca(); }" @testi="testi = $event" />
                                    </div>
                                    <div v-if="inRiscrittura === f.chiave" class="mt-2 space-y-1" data-test="riscrittura">
                                        <textarea v-model="bozzaRiscritta" rows="3" :maxlength="lunghezzaFrase" class="w-full rounded-md border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-2.5 py-1.5 text-[13px]" />
                                        <div class="flex gap-2"><Button size="sm" class="h-7" data-test="conferma-riscrittura" @click="confermaRiscrittura(f)">Usa la mia frase</Button><Button size="sm" variant="ghost" class="h-7" @click="inRiscrittura = null">Annulla</Button></div>
                                    </div>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        <Button v-if="statoDi(f) !== 'tenuto'" size="sm" variant="outline" class="h-7" data-test="tieni" @click="tieni(f)">Tieni</Button>
                                        <Button v-if="statoDi(f) !== 'tolto'" size="sm" variant="outline" class="h-7" data-test="togli" @click="togli(f)">Togli</Button>
                                        <Button v-if="statoDi(f) === 'tolto'" size="sm" variant="ghost" class="h-7" data-test="rimetti" @click="rimetti(f)">Rimetti fra i proposti</Button>
                                        <Button size="sm" variant="ghost" class="h-7" data-test="riscrivi" @click="apriRiscrittura(f)">Riscrivi</Button>
                                        <Button v-if="riscrittoDi(f)" size="sm" variant="ghost" class="h-7" data-test="come-la-proposta" @click="comeLaProposta(f)">Torna alla frase del programma</Button>
                                    </div>
                                </div>
                                <details v-if="nonProposti(p.chiave).length" class="text-[11.5px] text-slate-500 dark:text-slate-400" data-test="non-proposti">
                                    <summary class="cursor-pointer">Che cosa il programma non propone in questa parte, e perché</summary>
                                    <p v-for="n in nonProposti(p.chiave)" :key="n.chiave">{{ n.perche }}</p>
                                </details>

                                <!-- 2. La gestione: accanto alla liquidità, il saldo secondo la banca di ogni conto. -->
                                <div v-if="p.chiave === 'gestione' && banche.length" class="space-y-2" data-test="banche">
                                    <p class="font-medium text-slate-700 dark:text-slate-300">Il saldo secondo la banca (dal suo estratto conto)</p>
                                    <p class="text-[11.5px] text-slate-500 dark:text-slate-400" data-test="spiega-saldo-banca">È il saldo che leggi sull'estratto conto che ti manda la banca, di solito alla fine dell'esercizio: il programma non legge i file della banca. Lo confronta con il saldo registrato in KondoManager alla stessa data, e nella nota scrive le due cifre. Se sono diverse, spiega perché.</p>
                                    <div v-for="b in banche" :key="b.cassa_id" class="rounded border border-slate-200 dark:border-slate-700 px-3 py-2 space-y-1" :data-banca="b.cassa_id">
                                        <p class="text-slate-800 dark:text-slate-200"><b>{{ b.cassa }}</b> · registrato in KondoManager al {{ formatData(b.data) }}: <span class="tabular-nums" data-test="contabilita">{{ euroSegno(b.contabilita) }}</span><span v-if="b.nei_fondi" class="text-[12px] text-slate-500 dark:text-slate-400" data-test="contabilita-fondi"> ({{ euroSegno(b.sul_conto ?? 0) }} sul conto e {{ euroSegno(b.nei_fondi) }} nei fondi, che stanno sullo stesso conto corrente)</span></p>
                                        <div class="flex flex-wrap gap-2 items-center">
                                            <label class="text-[12px]">Data del saldo <input v-model="bancaDi(b).data" type="date" class="h-8 rounded border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-2 text-[12px]" @input="tocca" /></label>
                                            <label class="text-[12px] flex items-center gap-1">Saldo secondo la banca <MoneyInput v-model="bancaDi(b).saldo" :money-options="opzioniSaldo" placeholder="in euro" class="h-8 w-36 text-[12px]" data-test="saldo-banca" @update:model-value="tocca" /></label>
                                            <span v-if="differenzaDi(b) === 0" class="text-[12px] text-emerald-700 dark:text-emerald-400" data-test="esito-banca">Coincidono.</span>
                                            <span v-else-if="differenzaDi(b) !== null" class="text-[12px] text-amber-800 dark:text-amber-300" data-test="esito-banca">La differenza è €&nbsp;{{ inEuro(Math.abs(differenzaDi(b) as number)) }}, in più {{ (differenzaDi(b) as number) > 0 ? 'secondo la banca' : 'nella contabilità' }}.
                                                <Link v-if="!modificata" :href="hrefCause" class="underline font-semibold whitespace-nowrap" data-test="cause-banca">Le possibili cause, nei controlli</Link></span>
                                        </div>
                                        <TestoDellaNota v-if="differenzaDi(b) !== null && differenzaDi(b) !== 0 || bancaDi(b).spiegazione !== ''" v-model="bancaDi(b).spiegazione" tipo="spiegazione" :testi="testi" :puo-salvare="props.puo_salvare_testi"
                                            :condominio-id="props.condominio.id" :lunghezza="lunghezzaFrase" :righe="2" etichetta="La tua spiegazione della differenza (si stampa accanto alle cifre)" @update:model-value="tocca" @testi="testi = $event" />
                                    </div>
                                </div>

                                <TestoDellaNota v-model="cont.parti[p.chiave].testo" :tipo="p.chiave" :testi="testi" :puo-salvare="props.puo_salvare_testi" :condominio-id="props.condominio.id"
                                    :lunghezza="props.lunghezza" :righe="4" etichetta="Il tuo testo per questa parte" @update:model-value="tocca" @testi="testi = $event" />

                                <!-- 3. I rapporti in corso: contratti, polizze e verifiche; i dipendenti. -->
                                <div v-if="p.chiave === 'rapporti'" class="space-y-2" data-test="contratti">
                                    <p class="font-medium text-slate-700 dark:text-slate-300">Contratti, polizze e verifiche periodiche</p>
                                    <div v-for="(c, i) in cont.contratti" :key="i" class="grid gap-1.5 md:grid-cols-6 items-start" :data-contratto="i">
                                        <div class="menu-campo text-[12px]" data-test="tipo-contratto">
                                            <v-select :model-value="c.tipo" :options="vociTipoContratto" label="etichetta" :reduce="(t: { valore: string }) => t.valore" :searchable="false"
                                                :clearable="false" class="w-full" @update:model-value="(v: string) => { c.tipo = v; tocca(); }" />
                                        </div>
                                        <input v-model="c.cosa" placeholder="Che cosa" class="h-8 rounded border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-2 text-[12px]" @input="tocca" />
                                        <input v-model="c.con_chi" placeholder="Con chi" class="h-8 rounded border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-2 text-[12px]" @input="tocca" />
                                        <MoneyInput v-model="c.importo" :money-options="opzioniCifra" placeholder="Importo in euro" class="h-8 text-[12px]" @update:model-value="tocca" />
                                        <input v-model="c.scadenza" type="date" class="h-8 rounded border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-2 text-[12px]" @input="tocca" />
                                        <div class="flex gap-1">
                                            <input v-model="c.rinnovo" placeholder="Rinnovo" class="h-8 flex-1 rounded border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-2 text-[12px]" @input="tocca" />
                                            <Button size="sm" variant="ghost" class="h-8" @click="cont.contratti.splice(i, 1); tocca()">Togli</Button>
                                        </div>
                                        <input v-model="c.note" :placeholder="c.tipo === 'polizza' ? 'Massimali, premio' : 'Note'" class="h-8 md:col-span-6 rounded border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-2 text-[12px]" @input="tocca" />
                                        <p v-if="scaduto(c)" class="md:col-span-6 text-[11.5px] text-amber-800 dark:text-amber-300" data-test="contratto-scaduto">Scaduto il {{ formatData(c.scadenza) }}, prima della fine dell'esercizio: aggiorna la data o togli la riga.</p>
                                    </div>
                                    <Button size="sm" variant="outline" class="h-7" :disabled="cont.contratti.length >= props.righe_elenco" data-test="aggiungi-contratto" @click="aggiungiContratto()">Aggiungi una riga</Button>
                                    <div v-if="suggeriti.length" class="text-[11.5px] text-slate-500 dark:text-slate-400" data-test="fornitori">
                                        <p>I fornitori che hanno fatturato nell'esercizio (un aiuto: una fattura non dice che il contratto è in corso, e qui non si stampano):</p>
                                        <p v-for="s in suggeriti" :key="s.fornitore">{{ s.categoria }} · {{ s.fornitore }} <button type="button" class="underline" data-test="aggiungi-fornitore" @click="aggiungiContratto({ cosa: s.categoria, con_chi: s.fornitore })">Aggiungi all'elenco</button></p>
                                    </div>
                                    <div class="space-y-1" data-test="dipendenti">
                                        <p class="font-medium text-slate-700 dark:text-slate-300">Dipendenti</p>
                                        <label class="mr-3"><input v-model="cont.dipendenti.stato" type="radio" value="nessuno" @change="confermaDipendenti" /> Il condominio non ha dipendenti</label>
                                        <label><input v-model="cont.dipendenti.stato" type="radio" value="ci_sono" @change="confermaDipendenti" /> Ci sono:</label>
                                        <p v-if="cont.dipendenti.stato && cont.dipendenti.da_confermare" class="flex flex-wrap items-center gap-2 text-[11.5px] text-amber-800 dark:text-amber-300" data-test="dipendenti-da-confermare">
                                            Copiata da un'altra nota: non si stampa finché non la confermi.
                                            <Button size="sm" variant="outline" class="h-7" data-test="conferma-dipendenti" @click="confermaDipendenti">Confermo</Button>
                                        </p>
                                        <input v-if="cont.dipendenti.stato === 'ci_sono'" v-model="cont.dipendenti.testo" :maxlength="lunghezzaRiga" placeholder="Chi, con quale contratto" class="h-8 w-full rounded border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-2 text-[12px]" @input="tocca" />
                                    </div>
                                </div>

                                <!-- 4. Le questioni pendenti: la dichiarazione, gli altri debiti certi, le cose da decidere in assemblea. -->
                                <div v-if="p.chiave === 'questioni'" class="space-y-3">
                                    <div class="space-y-2" data-test="dichiarazione">
                                        <p class="font-medium text-slate-700 dark:text-slate-300">La tua dichiarazione (il programma non sa delle cause: il silenzio non vuol dire che non ce ne sono)</p>
                                        <div v-for="(d, k) in props.dichiarazione" :key="k" :data-domanda="k" class="space-y-1">
                                            <p>{{ d.domanda }}</p>
                                            <label class="mr-3"><input v-model="cont.dichiarazione[k].stato" type="radio" value="nessuna" @change="rispondi(String(k))" /> Non ce ne sono</label>
                                            <label><input v-model="cont.dichiarazione[k].stato" type="radio" value="ci_sono" @change="rispondi(String(k))" /> Ci sono:</label>
                                            <p v-if="cont.dichiarazione[k].stato && cont.dichiarazione[k].da_confermare" class="flex flex-wrap items-center gap-2 text-[11.5px] text-amber-800 dark:text-amber-300" data-test="da-confermare">
                                                Copiata da un'altra nota: non si stampa finché non la confermi.
                                                <Button size="sm" variant="outline" class="h-7" data-test="conferma-dichiarazione" @click="confermaDichiarazione(String(k))">Confermo la dichiarazione</Button>
                                            </p>
                                            <TestoDellaNota v-if="cont.dichiarazione[k].stato === 'ci_sono'" v-model="cont.dichiarazione[k].testo" tipo="dichiarazione" :testi="testi" :puo-salvare="props.puo_salvare_testi"
                                                :condominio-id="props.condominio.id" :lunghezza="lunghezzaFrase" :righe="2" segnaposto="Quali, lo stato e il costo" @update:model-value="tocca" @testi="testi = $event" />
                                        </div>
                                    </div>
                                    <div class="space-y-1" data-test="debiti-certi">
                                        <p class="font-medium text-slate-700 dark:text-slate-300">Altri debiti certi, che non sono fatture (compaiono anche sotto la riga della situazione patrimoniale, fuori dal giornale)</p>
                                        <div v-for="(d, i) in cont.debiti_certi" :key="i" class="grid gap-1.5 md:grid-cols-5">
                                            <input v-model="d.creditore" placeholder="Creditore" class="h-8 rounded border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-2 text-[12px]" @input="tocca" />
                                            <MoneyInput v-model="d.importo" :money-options="opzioniCifra" placeholder="Importo in euro" class="h-8 text-[12px]" @update:model-value="tocca" />
                                            <input v-model="d.fonte" placeholder="Da dove viene (una sentenza, un contratto)" class="h-8 rounded border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-2 text-[12px]" @input="tocca" />
                                            <input v-model="d.scadenza" type="date" class="h-8 rounded border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-2 text-[12px]" @input="tocca" />
                                            <Button size="sm" variant="ghost" class="h-8" @click="cont.debiti_certi.splice(i, 1); tocca()">Togli</Button>
                                        </div>
                                        <Button size="sm" variant="outline" class="h-7" :disabled="cont.debiti_certi.length >= props.righe_elenco" data-test="aggiungi-debito" @click="aggiungiDebito">Aggiungi un debito</Button>
                                    </div>
                                    <div class="space-y-1" data-test="da-decidere">
                                        <p class="font-medium text-slate-700 dark:text-slate-300">Da decidere in assemblea</p>
                                        <p v-for="v in voti" :key="v" class="text-slate-600 dark:text-slate-400">{{ v }} <span class="text-[11px]">(dal fatto tenuto)</span></p>
                                        <div v-for="(r, i) in cont.da_decidere" :key="i" class="flex gap-1">
                                            <input v-model="cont.da_decidere[i]" :maxlength="lunghezzaRiga" placeholder="Una cosa che l'assemblea deve decidere" class="h-8 flex-1 rounded border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 px-2 text-[12px]" @input="tocca" />
                                            <Button size="sm" variant="ghost" class="h-8" @click="cont.da_decidere.splice(i, 1); tocca()">Togli</Button>
                                        </div>
                                        <Button size="sm" variant="outline" class="h-7" :disabled="cont.da_decidere.length >= props.righe_elenco" data-test="aggiungi-da-decidere" @click="aggiungiDaDecidere">Aggiungi una riga</Button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-2 mb-6" data-test="pulsanti-in-fondo">
                            <Button variant="outline" class="h-8 px-3 gap-2" data-test="stampa-nota" @click="stampa"><Printer class="w-4 h-4" /> Stampa la nota</Button>
                            <Button class="h-8 px-3 gap-2" :disabled="inSalvataggio" data-test="salva-nota" @click="salva"><Save class="w-4 h-4" /> Salva la nota</Button>
                        </div>
                    </MovimentiLayout>
                </section>
            </div>
        </div>
        <RendicontoNotaGuide v-model:open="mostraGuida" />
    </GestionaleLayout>
</template>
