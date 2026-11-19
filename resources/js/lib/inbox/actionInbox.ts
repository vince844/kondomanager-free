// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
/**
 * L'Action inbox fuori dalla pagina e senza Chart.js: le frasi delle card e delle righe, le schede e l'ordine dell'elenco, i gruppi con «e altri N» e
 * «Mostrati N di M», i filtri attivi, i parametri dell'indirizzo, i dati e le opzioni dei due grafici con le cifre sulle barre, le descrizioni per chi non vede
 * i grafici e la card «Sei mesi» mentre i condomini arrivano.
 *
 * I numeri arrivano tutti dal server (`ActionInboxController`, con la regola di «scaduto» di `FasceDeiCompiti`): qui non si conta niente dalla pagina
 * caricata, si scrive.
 */
import type { CompitoConRisposte } from '@/lib/inbox/risposteDelCompito';

export type Fascia = 'oltre_90' | 'da_31_a_90' | 'ultimi_30' | 'prossimi_7' | 'fra_8_e_30' | 'oltre_30' | 'segnalazioni' | 'bonifici' | 'da_moderare';
export type Gruppo = 'scaduti' | 'segnalazioni' | 'bonifici' | 'da_moderare' | 'avanti';
export type Scheda = 'tutti' | 'scaduti' | 'in_arrivo';

export interface Conteggi { all: number; scaduti: number; settimana: number; entro_30: number; bonifici: number; segnalazioni: number; da_moderare?: number; piu_vecchio: string | null }
export interface BarraEta { chiave: Fascia; etichetta: string; valore: number; scaduti: boolean }
export interface BarraTipo { tipo: string; etichetta: string; valore: number }
export interface Grafici { eta: BarraEta[]; tipi: BarraTipo[]; fuori: { segnalazioni?: number; bonifici: number; da_moderare?: number; oltre_30: number } }
export interface Dove { condominio_id: number | null; nome: string; valore: number }
export interface Schede { tutti: number; scaduti: number; in_arrivo: number }
export interface Gruppi { scaduti: number; segnalazioni?: number; bonifici: number; da_moderare?: number; avanti: number }
/** I filtri dell'indirizzo; `mie` è «Le mie» (la 487 del registro delle decisioni): solo i compiti delle segnalazioni assegnate a chi guarda. */
export interface Filtri { quando: string | null; tipo: string | null; tipo_nome?: string | null; cerca: string | null; ordine: string; condominio_id: number | null; mie?: boolean; scheda?: Scheda }

export interface RigaCompito extends CompitoConRisposte {
    titolo: string;
    sotto: string;
    description?: string | null;
    date: string;
    giorno: string;
    giorni: number;
    fascia: Fascia;
    gruppo: Gruppo;
    condominio: string;
    condominio_id: number | null;
    azione: { testo: string; url: string | null };
    /** La priorità di una segnalazione, per il badge: solo «urgente» e «alta». */
    priorita?: 'urgente' | 'alta' | null;
    /** Chi segue la segnalazione del compito (la 487 del registro delle decisioni); null per gli altri compiti. `attivo` falso: non ha più il pannello, o è sospeso. */
    assegnata?: Assegnata | null;
    context?: { anagrafica_nome: string | null; action_url: string | null; related_id: number | null };
}
export interface Assegnata { nome: string | null; te: boolean; attivo: boolean }
export interface GruppoInAnteprima { gruppo: Gruppo; numero: number; righe: RigaCompito[] }

export const SOPRATITOLO = 'Centro operativo';
export const TITOLO = 'Action inbox';

/** «I compiti aperti di tutti i condomini.», «I compiti aperti di Via Verdi 12.». */
export const descrizioneDellaPagina = (condominio: string | null): string => `I compiti aperti di ${condominio ?? 'tutti i condomini'}.`;

/** Le schede dell'elenco e il loro filtro per data. */
export const SCHEDE: ReadonlyArray<{ chiave: Scheda; etichetta: string; quando: string | null }> = [
    { chiave: 'tutti', etichetta: 'Tutti', quando: null },
    { chiave: 'scaduti', etichetta: 'Scaduti', quando: 'scaduti' },
    { chiave: 'in_arrivo', etichetta: 'In arrivo', quando: 'in_arrivo' },
];

/** Gli ordini dell'elenco: il valore nell'indirizzo e il nome nel menu. */
export const ORDINI: ReadonlyArray<{ valore: string; etichetta: string }> = [
    { valore: 'vecchi', etichetta: 'I più vecchi prima' },
    { valore: 'recenti', etichetta: 'I più recenti prima' },
    { valore: 'condominio', etichetta: 'Per condominio' },
];
export const ORDINE_DI_SERIE = 'vecchi';

/** La ricerca: il segnaposto, corto, e il nome per chi non vede il campo, che dice dove cerca. */
export const SEGNAPOSTO_RICERCA = 'Cerca fra i compiti';
export const ETICHETTA_RICERCA = 'Cerca fra i compiti per titolo, condominio o persona';

/** Le barre del grafico «Da quanto aspettano», nell'ordine. */
export const FASCE_DEL_GRAFICO: Fascia[] = ['oltre_90', 'da_31_a_90', 'ultimi_30', 'prossimi_7', 'fra_8_e_30'];

/** Le etichette delle barre su due righe, per intero. */
const ETICHETTE_SU_DUE_RIGHE: Record<string, [string, string]> = {
    oltre_90: ['oltre 90', 'giorni fa'],
    da_31_a_90: ['da 31 a 90', 'giorni fa'],
    ultimi_30: ['ultimi', '30 giorni'],
    prossimi_7: ['prossimi', '7 giorni'],
    fra_8_e_30: ['fra 8 e', '30 giorni'],
};

/** Il nome di una barra scelta come filtro, sopra l'elenco. */
const NOMI_DELLE_FASCE: Record<string, string> = {
    oltre_90: 'Oltre 90 giorni fa', da_31_a_90: 'Da 31 a 90 giorni', ultimi_30: 'Ultimi 30 giorni', prossimi_7: 'Prossimi 7 giorni', fra_8_e_30: 'Fra 8 e 30 giorni',
};

/** Le barre nella descrizione per chi non vede il grafico, e nel riquadro al passaggio del mouse. */
const FRASI_DESCRIZIONE: Record<string, string> = {
    oltre_90: 'scaduti da oltre 90 giorni', da_31_a_90: 'da 31 a 90 giorni', ultimi_30: 'negli ultimi 30 giorni', prossimi_7: 'nei prossimi 7 giorni', fra_8_e_30: 'fra 8 e 30 giorni',
};
const FRASI_RIQUADRO: Record<string, string> = {
    oltre_90: 'scaduti da oltre 90 giorni', da_31_a_90: 'scaduti da 31 a 90 giorni', ultimi_30: 'scaduti negli ultimi 30 giorni', prossimi_7: 'nei prossimi 7 giorni',
    fra_8_e_30: 'fra 8 e 30 giorni',
};

/** Una cifra all'italiana, con il punto anche nelle quattro cifre («1.250»: `toLocaleString('it-IT')` scrive «1250»). */
export const numero = (n: number): string => new Intl.NumberFormat('it-IT', { useGrouping: 'always' } as Intl.NumberFormatOptions).format(n);

/** «29/12/2025». */
export const giornoItaliano = (g: string | null): string => (g ? g.slice(0, 10).split('-').reverse().join('/') : '');

/** La frase della card «Scaduti»: il giorno del più vecchio. */
export function testoScaduti(c: Pick<Conteggi, 'scaduti' | 'piu_vecchio'>): string {
    return c.scaduti > 0 && c.piu_vecchio ? `il più vecchio del ${giornoItaliano(c.piu_vecchio)}` : 'nessun compito scaduto';
}

/** La frase della card «Prossimi 7 giorni»: quanti poi entro 30. */
export function testoSettimana(c: Pick<Conteggi, 'entro_30'>): string {
    return c.entro_30 > 0 ? `poi ${numero(c.entro_30)} entro 30 giorni` : 'nessuno fra 8 e 30 giorni';
}

/** I giorni della riga: «da 322 giorni», «da ieri», «oggi», «domani», «fra 12 giorni». */
export function testoQuando(giorni: number): string {
    if (giorni < -1) return `da ${numero(-giorni)} giorni`;
    if (giorni === -1) return 'da ieri';
    if (giorni === 0) return 'oggi';
    if (giorni === 1) return 'domani';

    return `fra ${numero(giorni)} giorni`;
}

/** L'icona e il colore del tipo: l'icona è una chiave, che la riga traduce nel suo disegno. */
const ASPETTI: Record<string, { icona: string; colore: string }> = {
    emissione_rata: { icona: 'emissione', colore: 'bg-indigo-50 text-indigo-600 dark:bg-indigo-950/50 dark:text-indigo-300' },
    emissione_rata_sopravvenienza: { icona: 'emissione', colore: 'bg-indigo-50 text-indigo-600 dark:bg-indigo-950/50 dark:text-indigo-300' },
    controllo_incassi: { icona: 'incassi', colore: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/50 dark:text-emerald-300' },
    verifica_pagamento: { icona: 'bonifico', colore: 'bg-sky-50 text-sky-600 dark:bg-sky-950/50 dark:text-sky-300' },
    pagamento_fornitore: { icona: 'fattura', colore: 'bg-amber-50 text-amber-600 dark:bg-amber-950/50 dark:text-amber-300' },
    gia_versato_acconto_dichiarato: { icona: 'fattura', colore: 'bg-amber-50 text-amber-600 dark:bg-amber-950/50 dark:text-amber-300' },
    versamento_ritenuta: { icona: 'f24', colore: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300' },
    f24: { icona: 'f24', colore: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300' },
    scoperto_documentato: { icona: 'quote', colore: 'bg-violet-50 text-violet-600 dark:bg-violet-950/50 dark:text-violet-300' },
    eccedenza_gia_versato_rilevata: { icona: 'quote', colore: 'bg-violet-50 text-violet-600 dark:bg-violet-950/50 dark:text-violet-300' },
    pianifica_ripianamento_deficit: { icona: 'deficit', colore: 'bg-rose-50 text-rose-600 dark:bg-rose-950/50 dark:text-rose-300' },
    convocazione_urgenza: { icona: 'assemblea', colore: 'bg-rose-50 text-rose-600 dark:bg-rose-950/50 dark:text-rose-300' },
    ratifica_sforo: { icona: 'assemblea', colore: 'bg-rose-50 text-rose-600 dark:bg-rose-950/50 dark:text-rose-300' },
    segnalazione_guasto: { icona: 'segnalazione', colore: 'bg-orange-50 text-orange-600 dark:bg-orange-950/50 dark:text-orange-300' },
    commento: { icona: 'commento', colore: 'bg-indigo-50 text-indigo-600 dark:bg-indigo-950/50 dark:text-indigo-300' },
    da_moderare: { icona: 'commento', colore: 'bg-indigo-50 text-indigo-600 dark:bg-indigo-950/50 dark:text-indigo-300' },
    allegato: { icona: 'allegato', colore: 'bg-indigo-50 text-indigo-600 dark:bg-indigo-950/50 dark:text-indigo-300' },
};
const ASPETTO_DI_SERIE = { icona: 'scadenza', colore: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300' };
export const aspettoDelTipo = (tipo: string): { icona: string; colore: string } => ASPETTI[tipo] ?? ASPETTO_DI_SERIE;

const TITOLI_DEI_GRUPPI: Record<Gruppo, string> = { scaduti: 'Scaduti', segnalazioni: 'Segnalazioni aperte', bonifici: 'Bonifici da verificare', da_moderare: 'Da moderare', avanti: 'Più avanti' };
export const titoloDelGruppo = (g: Gruppo): string => TITOLI_DEI_GRUPPI[g];

export type VoceDellElenco = { tipo: 'gruppo'; gruppo: Gruppo; titolo: string; numero: number } | { tipo: 'riga'; riga: RigaCompito };

/** Le righe con la testata del loro gruppo davanti alla prima: il numero è quello del server, non delle righe caricate. */
export function righeConIGruppi(righe: readonly RigaCompito[], gruppi: Gruppi): VoceDellElenco[] {
    const voci: VoceDellElenco[] = [];
    let ultimo: Gruppo | null = null;
    for (const riga of righe) {
        if (riga.gruppo !== ultimo) {
            voci.push({ tipo: 'gruppo', gruppo: riga.gruppo, titolo: titoloDelGruppo(riga.gruppo), numero: gruppi[riga.gruppo] ?? 0 });
            ultimo = riga.gruppo;
        }
        voci.push({ tipo: 'riga', riga });
    }

    return voci;
}

const UNO_E_TANTI: Record<Gruppo, [string, string]> = {
    scaduti: ['e un altro scaduto', 'e altri N scaduti'],
    segnalazioni: ['e un\'altra segnalazione aperta', 'e altre N segnalazioni aperte'],
    bonifici: ['e un altro bonifico da verificare', 'e altri N bonifici da verificare'],
    da_moderare: ['e un altro da moderare', 'e altri N da moderare'],
    avanti: ['e un altro più avanti', 'e altri N più avanti'],
};

/** Sotto l'anteprima di un gruppo: «e altri 100 scaduti», «e altre 3 segnalazioni aperte». */
export function testoEAltri(gruppo: Gruppo, quanti: number): string {
    const [uno, tanti] = UNO_E_TANTI[gruppo];

    return quanti === 1 ? uno : tanti.replace('N', numero(quanti));
}

/**
 * La data della riga: il giorno del compito, e per una segnalazione il giorno in cui è stata aperta («aperta il 22/02/2026»), perché una segnalazione non
 * ha una scadenza; per ciò che è da moderare il giorno da cui aspetta («dal 16/11/2026»), grigio come i giorni.
 */
export const testoDellaData = (r: Pick<RigaCompito, 'gruppo' | 'giorno'>): string => (r.gruppo === 'segnalazioni' ? `aperta il ${giornoItaliano(r.giorno)}`
    : r.gruppo === 'da_moderare' ? `dal ${giornoItaliano(r.giorno)}` : giornoItaliano(r.giorno));

/** In fondo all'elenco: «Mostrati 9 di 113». */
/**
 * Un indirizzo del sito («/admin/…»): la prossima azione si apre con un collegamento di Inertia, con il prefetch. Un indirizzo di un'altra origine (o un
 * indirizzo intero salvato in un compito di prima) resta un collegamento normale: Inertia non lo può aprire.
 */
export const eDelSito = (url: string): boolean => url.startsWith('/') && !url.startsWith('//');

/** «Mostra altri 15», o quanti ne mancano se sono meno: con 18 compiti e 15 mostrati, «Mostra altri 3». */
export const testoMostraAltri = (mostrati: number, di: number): string => `Mostra altri ${numero(Math.max(1, Math.min(15, di - mostrati)))}`;

/** «e un altro tipo», «e altri 4 tipi»: i tipi oltre le 8 barre del grafico «Per tipo». */
export const testoAltriTipi = (n: number): string => (n === 1 ? 'e un altro tipo' : `e altri ${numero(n)} tipi`);

export const testoMostrati = (mostrati: number, di: number): string => `${mostrati === 1 ? 'Mostrato' : 'Mostrati'} ${numero(mostrati)} di ${numero(di)}`;

/** I parametri dell'indirizzo: senza i vuoti e senza l'ordine di serie. */
export function parametri(f: Pick<Filtri, 'quando' | 'tipo' | 'cerca' | 'ordine' | 'condominio_id'> & Pick<Partial<Filtri>, 'mie'>): Record<string, string | number> {
    const p: Record<string, string | number> = {};
    if (f.mie) p.mie = 1;
    if (f.quando) p.quando = f.quando;
    if (f.tipo) p.tipo = f.tipo;
    if (f.cerca) p.cerca = f.cerca;
    if (f.ordine && f.ordine !== ORDINE_DI_SERIE) p.ordine = f.ordine;
    if (f.condominio_id) p.condominio_id = f.condominio_id;

    return p;
}

/**
 * I filtri attivi sopra l'elenco, oltre la scheda e la ricerca (che si vedono da sé): la barra del grafico scelta dentro la sua scheda, e il tipo. Il nome del
 * tipo arriva dal server, come quello del grafico.
 */
export type FiltroAttivo = 'quando' | 'tipo' | 'mie';
export function filtriAttivi(f: Pick<Filtri, 'quando' | 'tipo' | 'cerca' | 'tipo_nome'> & Pick<Partial<Filtri>, 'mie'>, grafici?: Grafici): Array<{ chiave: FiltroAttivo; testo: string }> {
    const attivi: Array<{ chiave: FiltroAttivo; testo: string }> = [];
    // «Le mie» si toglie da sé, come gli altri (la 487 del registro delle decisioni).
    if (f.mie) attivi.push({ chiave: 'mie', testo: LE_MIE });
    if (f.quando && NOMI_DELLE_FASCE[f.quando]) attivi.push({ chiave: 'quando', testo: NOMI_DELLE_FASCE[f.quando] });
    if (f.tipo) attivi.push({ chiave: 'tipo', testo: f.tipo_nome ?? grafici?.tipi.find((t) => t.tipo === f.tipo)?.etichetta ?? f.tipo });

    return attivi;
}

/** Il nome del filtro «Le mie» (la 487 del registro delle decisioni). */
export const LE_MIE = 'Le mie';

/**
 * Chi segue la segnalazione di un compito, sotto il titolo (la 487 del registro delle decisioni): «assegnata a te», «assegnata a Anna Neri», «non assegnata». Chi non può
 * più riceverla lo dice come la scheda della segnalazione.
 */
export function testoAssegnata(a: Assegnata): string {
    if (a.nome === null) return 'non assegnata';
    if (a.te) return 'assegnata a te';
    return `assegnata a ${a.nome}`;
}
export const FRASE_NON_ATTIVO = 'non ha più accesso al pannello';

/** La scheda di una barra del grafico: togliere la barra lascia la sua scheda. */
export const schedaDellaFascia = (fascia: string): string | null =>
    (['oltre_90', 'da_31_a_90', 'ultimi_30'].includes(fascia) ? 'scaduti' : ['prossimi_7', 'fra_8_e_30'].includes(fascia) ? 'in_arrivo' : null);

/** I colori dei grafici, letti dal tema quando si disegna. */
export interface ColoriDeiGrafici { rosso: string; rossoChiaro: string; indaco: string; indacoChiaro: string; grigio: string; testo: string; griglia: string; cifre: string }

/** Le scritte dei grafici: 12 pixel le cifre e l'asse, 11 le etichette delle età su due righe. */
export const PIXEL_CIFRE = 12;
export const PIXEL_ETICHETTE = 11;

/** Lo stesso colore, trasparente: le barre non scelte quando una barra filtra l'elenco. */
export function conAlfa(colore: string, alfa: number): string {
    const parti = colore.match(/rgba?\(([^)]+)\)/)?.[1].split(/[\s,/]+/).filter(Boolean).slice(0, 3);

    return parti && parti.length === 3 ? `rgba(${parti.join(', ')}, ${alfa})` : colore;
}

const ATTENUATA = 0.3;

/** I dati del grafico «Da quanto aspettano»: cinque barre, rosse gli scaduti e indaco quelli in arrivo; con una barra scelta le altre si attenuano. */
export function datiEta(g: Grafici, colori: ColoriDeiGrafici, scelta: string | null) {
    const barre = FASCE_DEL_GRAFICO.map((f) => g.eta.find((b) => b.chiave === f) ?? { chiave: f, etichetta: f, valore: 0, scaduti: false });
    const base: Record<string, string> = { oltre_90: colori.rosso, da_31_a_90: colori.rosso, ultimi_30: colori.rossoChiaro, prossimi_7: colori.indaco, fra_8_e_30: colori.indacoChiaro };

    return {
        labels: barre.map((b) => ETICHETTE_SU_DUE_RIGHE[b.chiave] ?? [b.etichetta]),
        datasets: [{
            label: 'Compiti',
            data: barre.map((b) => b.valore),
            backgroundColor: barre.map((b) => (scelta && scelta !== b.chiave ? conAlfa(base[b.chiave], ATTENUATA) : base[b.chiave])),
            borderRadius: 6,
            maxBarThickness: 56,
        }],
    };
}

/** I dati del grafico «Per tipo»: le barre grigie, i tipi con più compiti prima (l'ordine del server); la barra scelta indaco. */
export function datiTipi(g: Grafici, colori: ColoriDeiGrafici, scelta: string | null) {
    return {
        labels: g.tipi.map((t) => t.etichetta),
        datasets: [{
            label: 'Compiti',
            data: g.tipi.map((t) => t.valore),
            backgroundColor: g.tipi.map((t) => (scelta === t.tipo ? colori.indaco : scelta ? conAlfa(colori.grigio, 0.5) : colori.grigio)),
            borderRadius: 4,
            maxBarThickness: 16,
        }],
    };
}

type ClicSulGrafico = (evento: unknown, elementi: Array<{ index: number }>) => void;
const alClic = (scegli: (indice: number) => void): ClicSulGrafico => (_evento, elementi) => {
    if (elementi?.length) scegli(elementi[0].index);
};
const piuCompiti = (n: number): string => `${numero(n)} ${n === 1 ? 'compito' : 'compiti'}`;

/** Le opzioni del grafico «Da quanto aspettano»: nessuna legenda, le età su due righe in basso, cifre italiane, un clic sceglie la barra. */
export function opzioniEta(g: Grafici, colori: ColoriDeiGrafici, scegli: (indice: number) => void) {
    return {
        responsive: true,
        maintainAspectRatio: false,
        animation: false as const,
        layout: { padding: { top: 20 } },
        onClick: alClic(scegli),
        plugins: {
            legend: { display: false },
            tooltip: {
                displayColors: false,
                titleFont: { size: PIXEL_CIFRE },
                bodyFont: { size: PIXEL_CIFRE },
                callbacks: {
                    title: () => '',
                    label: (v: { dataIndex: number; raw: unknown }) => `${piuCompiti(Number(v.raw))} ${FRASI_RIQUADRO[FASCE_DEL_GRAFICO[v.dataIndex]] ?? ''}`.trim(),
                },
            },
        },
        scales: {
            x: {
                grid: { display: false },
                border: { display: false },
                ticks: { color: colori.testo, font: { size: PIXEL_ETICHETTE }, maxRotation: 0, autoSkip: false },
            },
            y: {
                beginAtZero: true,
                grid: { color: colori.griglia },
                border: { display: false },
                ticks: { color: colori.testo, font: { size: PIXEL_CIFRE }, precision: 0, callback: (v: number | string) => numero(Number(v)) },
            },
        },
    };
}

/** Le opzioni del grafico «Per tipo»: barre orizzontali, la cifra accanto, nessun asse dei valori, un clic sceglie il tipo. */
export function opzioniTipi(g: Grafici, colori: ColoriDeiGrafici, scegli: (indice: number) => void) {
    return {
        indexAxis: 'y' as const,
        responsive: true,
        maintainAspectRatio: false,
        animation: false as const,
        layout: { padding: { right: 32 } },
        onClick: alClic(scegli),
        plugins: {
            legend: { display: false },
            tooltip: {
                displayColors: false,
                titleFont: { size: PIXEL_CIFRE },
                bodyFont: { size: PIXEL_CIFRE },
                callbacks: { label: (v: { raw: unknown }) => piuCompiti(Number(v.raw)) },
            },
        },
        scales: {
            x: { display: false, beginAtZero: true },
            y: { grid: { display: false }, border: { display: false }, ticks: { color: colori.testo, font: { size: PIXEL_CIFRE }, autoSkip: false } },
        },
    };
}

/** Il disegno delle cifre sulle barre: sopra le barre verticali, accanto a quelle orizzontali. */
export function pluginCifre(orizzontale: boolean, colore: string) {
    return {
        id: 'cifre',
        afterDatasetsDraw(chart: { ctx: CanvasRenderingContext2D; data: { datasets: Array<{ data: number[] }> }; getDatasetMeta: (i: number) => { data: Array<{ x: number; y: number }> } }) {
            const ctx = chart.ctx;
            ctx.save();
            ctx.font = `600 ${PIXEL_CIFRE}px system-ui, sans-serif`;
            ctx.fillStyle = colore;
            ctx.textAlign = orizzontale ? 'left' : 'center';
            ctx.textBaseline = orizzontale ? 'middle' : 'bottom';
            chart.getDatasetMeta(0).data.forEach((barra, i) => {
                const testo = numero(chart.data.datasets[0].data[i] ?? 0);
                if (orizzontale) {
                    ctx.fillText(testo, barra.x + 6, barra.y);
                } else {
                    ctx.fillText(testo, barra.x, barra.y - 4);
                }
            });
            ctx.restore();
        },
    };
}

/** La descrizione del grafico per età, per chi non lo vede. */
export function descrizioneEta(g: Grafici): string {
    const parti = FASCE_DEL_GRAFICO.map((f) => {
        const n = g.eta.find((b) => b.chiave === f)?.valore ?? 0;

        return `${n === 0 ? 'nessuno' : numero(n)} ${FRASI_DESCRIZIONE[f]}`;
    });

    return `Compiti per data: ${parti.join(', ')}.`;
}

/** La descrizione del grafico per tipo, per chi non lo vede. */
export function descrizioneTipi(g: Grafici): string {
    return g.tipi.length ? `Compiti per tipo: ${g.tipi.map((t) => `${t.etichetta} ${numero(t.valore)}`).join(', ')}.` : 'Nessun compito aperto.';
}

/**
 * Sotto il grafico per età, ciò che non c'è: le segnalazioni aperte, i bonifici da verificare e ciò che è da moderare (non hanno una scadenza) e i compiti fra più
 * di 30 giorni.
 * Vuota se non manca niente.
 */
export function testoFuoriDalGrafico(fuori: Grafici['fuori']): string {
    const segnalazioni = fuori.segnalazioni ?? 0;
    const daModerare = fuori.da_moderare ?? 0;
    const parti: string[] = [];
    if (segnalazioni > 0) parti.push(`${numero(segnalazioni)} ${segnalazioni === 1 ? 'segnalazione aperta' : 'segnalazioni aperte'}`);
    if (fuori.bonifici > 0) parti.push(`${numero(fuori.bonifici)} ${fuori.bonifici === 1 ? 'bonifico da verificare' : 'bonifici da verificare'}`);
    if (daModerare > 0) parti.push(`${piuCompiti(daModerare)} da moderare`);
    if (fuori.oltre_30 > 0) parti.push(`${piuCompiti(fuori.oltre_30)} fra più di 30 giorni`);
    if (!parti.length) return '';
    const verbo = segnalazioni + fuori.bonifici + daModerare + fuori.oltre_30 === 1 ? 'Non è' : 'Non sono';
    const elenco = parti.length === 1 ? parti[0] : `${parti.slice(0, -1).join(', ')} e ${parti[parti.length - 1]}`;

    return `${verbo} nel grafico: ${elenco}.`;
}

/** La riga sotto il titolo del grafico per età: parla dei compiti con una scadenza. */
export const DESCRIZIONE_ETA = 'I compiti con una scadenza, per data: le segnalazioni e i bonifici da verificare non ce l\'hanno. Un clic su una barra filtra.';

/** La frase della card «Sei mesi»: mentre i condomini arrivano, a che punto è; poi le righe da seguire adesso e i rendiconti da portare in assemblea. */
export function testoCardSeiMesi(conto: { adesso: number; rendiconti: number }, carica: { inCorso: boolean; fatti: number; totale: number }): string {
    if (carica.inCorso) return `calcolo ${numero(carica.fatti)} su ${numero(carica.totale)}`;
    const righe = conto.adesso === 1 ? 'riga' : 'righe';
    const rendiconti = conto.rendiconti === 1 ? 'rendiconto' : 'rendiconti';
    if (conto.adesso && conto.rendiconti) return `${numero(conto.adesso)} ${righe} da seguire, ${numero(conto.rendiconti)} ${rendiconti} da portare`;
    if (conto.adesso) return `${righe} da seguire adesso`;
    if (conto.rendiconti) return `${rendiconti} da portare`;

    return 'niente entro 60 giorni';
}
