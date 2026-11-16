// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
/**
 * I calcoli del grafico «Saldo previsto» della tesoreria a 30 giorni, fuori dal componente e senza Chart.js: i punti, le etichette, i
 * dati e le opzioni che il componente passa a Chart.js, il riquadro al passaggio del mouse, la descrizione per chi non vede il grafico e la tabella dei giorni.
 *
 * Il saldo di ogni giorno arriva dal server (`TreasuryTimelineBuilder`): è il saldo a fine giornata con le uscite previste e i movimenti già registrati, senza le
 * entrate attese (le rate dei condòmini, «se arrivano»). Il primo giorno sotto zero è quello della scritta in testa: lo stesso del punto sul grafico.
 *
 * **I tre scenari**, da scegliere nella card: «Senza le rate» (il saldo del server), «Con le rate in scadenza» (in più le rate dei condòmini che
 * scadono nei 30 giorni, ognuna al suo giorno, al netto del credito di ognuno nella stessa gestione), «Se pagano tutti» (in più le rate già scadute, al giorno di
 * oggi). Lo scenario cambia la linea, la scritta in testa con il punto, la cifra a 30 giorni, il riquadro e la descrizione; il semaforo no (lo decide il server).
 */

export interface VoceDelGiorno {
    voce: string;
    importo: number;
}

/** Una fattura che scade quel giorno, per le azioni consigliate. */
export interface FatturaDelGiorno {
    id: number;
    fornitore: string;
    numero: string;
    importo: number;
}

export interface GiornoDelSaldo {
    giorno: string;
    /** Il saldo a fine giornata, in centesimi, senza le entrate attese. */
    saldo: number;
    /** Lo stesso con le entrate attese, se arrivano. */
    con_entrate: number;
    uscite: number;
    quante_uscite: number;
    voci_uscite: VoceDelGiorno[];
    entrate: number;
    quante_entrate: number;
    /** Le sole rate dei condòmini che scadono quel giorno (non le già scadute), al netto del credito: lo scenario «Con le rate in scadenza». */
    rate_in_scadenza?: number;
    quante_rate_in_scadenza?: number;
    /** Le fatture del giorno, fino a 8. */
    fatture?: FatturaDelGiorno[];
    registrati: number;
    quanti_registrati: number;
    voci_registrati: VoceDelGiorno[];
}

export interface SaldoPrevisto {
    giorni: GiornoDelSaldo[];
    primoSottoZero: string | null;
    minimoCents: number;
    finaleCents: number;
    finaleConEntrateCents: number;
    /** Le rate già scadute, al netto del credito: entrano oggi nello scenario «Se pagano tutti». */
    rateScaduteCents?: number;
}

export type Scenario = 'senza' | 'con_rate' | 'tutti';

/** I tre scenari, nell'ordine dei pulsanti: il nome sul pulsante, la frase di che cosa conta, il nome dentro una frase. */
export const SCENARI: ReadonlyArray<{ chiave: Scenario; etichetta: string; frase: string; nome: string }> = [
    { chiave: 'senza', etichetta: 'Senza le rate', frase: 'Solo le fatture e i movimenti già registrati.', nome: 'senza le rate' },
    { chiave: 'con_rate', etichetta: 'Con le rate in scadenza', frase: 'In più le rate dei condòmini che scadono nei 30 giorni, ognuna al suo giorno; le rate già scadute no.',
        nome: 'con le rate in scadenza' },
    { chiave: 'tutti', etichetta: 'Se pagano tutti', frase: 'In più anche le rate già scadute, al giorno di oggi.', nome: 'se pagano tutti' },
];

/** All'apertura: il più prudente. */
export const SCENARIO_DI_SERIE: Scenario = 'senza';

export const scenarioDi = (s: Scenario) => SCENARI.find((x) => x.chiave === s) ?? SCENARI[0];

/**
 * Il saldo a fine giornata di ogni giorno nello scenario, in centesimi: «Senza le rate» quello del server; «Con le rate in scadenza» più le rate che scadono
 * fino a quel giorno; «Se pagano tutti» anche le rate già scadute, da oggi.
 */
export function saldiDelloScenario(sp: SaldoPrevisto, scenario: Scenario = SCENARIO_DI_SERIE): number[] {
    let rate = scenario === 'tutti' ? (sp.rateScaduteCents ?? 0) : 0;

    return sp.giorni.map((g) => {
        if (scenario !== 'senza') rate += g.rate_in_scadenza ?? 0;

        return g.saldo + rate;
    });
}

/** Il saldo fra 30 giorni nello scenario: la cifra «Fra 30 giorni». */
export function finaleDelloScenario(sp: SaldoPrevisto, scenario: Scenario = SCENARIO_DI_SERIE): number {
    return saldiDelloScenario(sp, scenario).at(-1) ?? 0;
}

/** I colori del grafico, letti dal tema quando si disegna. */
export interface ColoriDelGrafico {
    linea: string;
    rosso: string;
    zona: string;
    testo: string;
    griglia: string;
    zero: string;
    sfondo: string;
}

/** Le scritte del grafico: mai sotto gli 11 pixel. */
export const PIXEL_DELLE_SCRITTE = 12;

/** Il raggio del punto sul primo giorno sotto zero. */
const RAGGIO = 5;

const numero = (n: number, decimali: number) =>
    new Intl.NumberFormat('it-IT', { minimumFractionDigits: decimali, maximumFractionDigits: decimali, useGrouping: 'always' } as Intl.NumberFormatOptions).format(n);

/** «−€ 1.852,00», «€ 0,00», «€ 1.852,00»: i centesimi con il segno meno davanti all'euro. */
export function euroSegno(centesimi: number): string {
    const testo = `€ ${numero(Math.abs(centesimi) / 100, 2)}`;

    return centesimi < 0 ? `−${testo}` : testo;
}

/** Le cifre dell'asse, in euro interi: «€ 0», «€ 1.000», «−€ 500». */
export function euroAsse(euro: number): string {
    const testo = `€ ${numero(Math.abs(Math.round(euro)), 0)}`;

    return euro < 0 ? `−${testo}` : testo;
}

/** «18/11». */
export const giornoBreve = (d: string): string => `${d.slice(8, 10)}/${d.slice(5, 7)}`;

/** «18/11/2026». */
export const giornoIntero = (d: string): string => d.slice(0, 10).split('-').reverse().join('/');

/** I centesimi in euro, per Chart.js: arrotondati al centesimo (la somma in virgola mobile non lascia code). */
const inEuro = (centesimi: number) => Math.round(centesimi) / 100;

/**
 * I punti nello scenario: un'etichetta e un valore (in euro) per giorno; il primo giorno sotto zero e i raggi dei punti (solo quello si vede).
 */
export function puntiDelGrafico(sp: SaldoPrevisto, scenario: Scenario = SCENARIO_DI_SERIE): { etichette: string[]; valori: number[]; indiceSottoZero: number | null; raggi: number[] } {
    const saldi = saldiDelloScenario(sp, scenario);
    const etichette = sp.giorni.map((g) => giornoBreve(g.giorno));
    const valori = saldi.map(inEuro);
    const indice = saldi.findIndex((v) => v < 0);
    const indiceSottoZero = indice < 0 ? null : indice;

    return { etichette, valori, indiceSottoZero, raggi: sp.giorni.map((_, i) => (i === indiceSottoZero ? RAGGIO : 0)) };
}

/** La scritta in testa alla card nello scenario: lo stesso giorno del punto sul grafico, o «mai sotto zero». */
export function testoInTesta(sp: SaldoPrevisto, scenario: Scenario = SCENARIO_DI_SERIE): string {
    const { indiceSottoZero } = puntiDelGrafico(sp, scenario);
    if (indiceSottoZero === null) return 'mai sotto zero';

    return indiceSottoZero === 0 ? 'sotto zero da oggi' : `sotto zero dal ${giornoBreve(sp.giorni[indiceSottoZero].giorno)}`;
}

/**
 * Le righe di che cosa succede in un giorno, senza il saldo: le fatture che scadono, le altre contate, i movimenti già registrati, le rate dei condòmini che
 * scadono (nel saldo con le rate, fuori senza), e oggi le rate già scadute se pagano tutti.
 */
function eventiDelGiorno(g: GiornoDelSaldo, scenario: Scenario = SCENARIO_DI_SERIE, rateScadute = 0, oggi = false): string[] {
    const righe = g.voci_uscite.map((v) => `Scade: ${v.voce}, ${euroSegno(v.importo)}`);
    const altre = g.quante_uscite - g.voci_uscite.length;
    if (altre > 0) {
        const cifra = g.uscite - g.voci_uscite.reduce((s, v) => s + v.importo, 0);
        righe.push(`e ${altre === 1 ? 'un\'altra fattura' : `altre ${altre} fatture`}, ${euroSegno(cifra)}`);
    }
    righe.push(...g.voci_registrati.map((v) => `Già registrato: ${v.voce}, ${euroSegno(v.importo)}`));
    const altriRegistrati = g.quanti_registrati - g.voci_registrati.length;
    if (altriRegistrati > 0) {
        righe.push(`e ${altriRegistrati === 1 ? 'un altro movimento registrato' : `altri ${altriRegistrati} movimenti registrati`}`);
    }
    // Le rate che scadono quel giorno: dal server divise dalle già scadute; da un server di prima, le entrate attese del giorno.
    const quanteRate = g.quante_rate_in_scadenza ?? g.quante_entrate;
    const rate = g.rate_in_scadenza ?? g.entrate;
    if (quanteRate > 0 && rate > 0) {
        righe.push(`Rate dei condòmini che scadono: ${quanteRate}, ${euroSegno(rate)}${scenario === 'senza' ? ' (non sono in questo saldo)' : ''}`);
    }
    if (oggi && scenario === 'tutti' && rateScadute > 0) {
        righe.push(`Rate già scadute, se pagano tutti: ${euroSegno(rateScadute)}`);
    }

    return righe;
}

/** Il riquadro al passaggio del mouse: il saldo del giorno nello scenario e che cosa scade. */
export function righeDelRiquadro(sp: SaldoPrevisto, indice: number, scenario: Scenario = SCENARIO_DI_SERIE): string[] {
    const saldo = saldiDelloScenario(sp, scenario)[indice] ?? 0;
    const eventi = eventiDelGiorno(sp.giorni[indice], scenario, sp.rateScaduteCents ?? 0, indice === 0);

    return [`Saldo previsto ${scenarioDi(scenario).nome}: ${euroSegno(saldo)}`, ...(eventi.length ? eventi : ['Niente in scadenza'])];
}

/** La descrizione del grafico nello scenario, per chi non lo vede (`aria-label`). */
export function descrizioneDelGrafico(sp: SaldoPrevisto, scenario: Scenario = SCENARIO_DI_SERIE): string {
    const saldi = saldiDelloScenario(sp, scenario);
    const base = `Saldo previsto giorno per giorno, ${scenarioDi(scenario).nome}: oggi ${euroSegno(saldi[0] ?? 0)}, fra 30 giorni ${euroSegno(saldi.at(-1) ?? 0)}`;
    const { indiceSottoZero } = puntiDelGrafico(sp, scenario);
    if (indiceSottoZero === null) return `${base}; mai sotto zero.`;

    return `${base}; sotto zero dal ${giornoIntero(sp.giorni[indiceSottoZero].giorno)}, al più basso ${euroSegno(Math.min(...saldi))}.`;
}

/** La tabella dei giorni nello scenario: oggi, ogni giorno in cui succede qualcosa, l'ultimo. */
export function righeDellaTabella(sp: SaldoPrevisto, scenario: Scenario = SCENARIO_DI_SERIE): Array<{ giorno: string; saldo: string; sottoZero: boolean; cheCosa: string }> {
    const ultimo = sp.giorni.length - 1;
    const saldi = saldiDelloScenario(sp, scenario);

    return sp.giorni
        .map((g, i) => ({ g, i, eventi: eventiDelGiorno(g, scenario, sp.rateScaduteCents ?? 0, i === 0) }))
        .filter(({ i, eventi }) => i === 0 || i === ultimo || eventi.length > 0)
        .map(({ g, i, eventi }) => ({
            giorno: giornoIntero(g.giorno),
            saldo: euroSegno(saldi[i]),
            sottoZero: saldi[i] < 0,
            cheCosa: eventi.length ? eventi.join('; ') : i === 0 ? 'Oggi' : 'Fra 30 giorni',
        }));
}

/** I dati per Chart.js nello scenario: una serie sola, a gradini; sotto lo zero la zona rossa, sopra niente; il punto sul primo giorno sotto zero. */
export function datiDelGrafico(sp: SaldoPrevisto, colori: ColoriDelGrafico, scenario: Scenario = SCENARIO_DI_SERIE) {
    const p = puntiDelGrafico(sp, scenario);

    return {
        labels: p.etichette,
        datasets: [{
            label: `Saldo previsto ${scenarioDi(scenario).nome}`,
            data: p.valori,
            stepped: true as const,
            borderColor: colori.linea,
            borderWidth: 2,
            fill: { target: { value: 0 }, above: 'transparent', below: colori.zona },
            pointRadius: p.raggi,
            pointHoverRadius: p.raggi.map((r) => (r > 0 ? r + 1 : 4)),
            pointBackgroundColor: colori.rosso,
            pointBorderColor: colori.sfondo,
            pointBorderWidth: 2,
            tension: 0,
        }],
    };
}

/**
 * Le opzioni per Chart.js: nessuna legenda, le date in basso, lo zero sempre sull'asse con la sua linea più marcata e la scritta «€ 0», le cifre all'italiana, il
 * riquadro in italiano con il saldo del giorno nello scenario e che cosa scade.
 */
export function opzioniDelGrafico(sp: SaldoPrevisto, colori: ColoriDelGrafico, scenario: Scenario = SCENARIO_DI_SERIE) {
    const font = { size: PIXEL_DELLE_SCRITTE };
    const eZero = (ctx: { tick: { value: number } }) => ctx.tick.value === 0;

    return {
        responsive: true,
        maintainAspectRatio: false,
        animation: false as const,
        interaction: { mode: 'index' as const, intersect: false },
        plugins: {
            legend: { display: false },
            tooltip: {
                displayColors: false,
                titleFont: font,
                bodyFont: font,
                callbacks: {
                    title: (voci: Array<{ dataIndex: number }>) => (voci.length ? giornoIntero(sp.giorni[voci[0].dataIndex].giorno) : ''),
                    label: (voce: { dataIndex: number }) => righeDelRiquadro(sp, voce.dataIndex, scenario),
                },
            },
        },
        scales: {
            x: {
                position: 'bottom' as const,
                grid: { display: false },
                border: { color: colori.griglia },
                ticks: { color: colori.testo, font, maxRotation: 0, autoSkip: true, maxTicksLimit: 7 },
            },
            y: {
                beginAtZero: true,
                grace: '5%',
                border: { display: false },
                grid: {
                    color: (ctx: { tick: { value: number } }) => (eZero(ctx) ? colori.zero : colori.griglia),
                    lineWidth: (ctx: { tick: { value: number } }) => (eZero(ctx) ? 2 : 1),
                },
                ticks: { color: colori.testo, font, maxTicksLimit: 5, callback: (valore: number | string) => euroAsse(Number(valore)) },
            },
        },
    };
}
