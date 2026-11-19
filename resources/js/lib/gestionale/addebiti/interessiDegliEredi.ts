// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Decisioni 489, 493 e 494: gli interessi sul debito ereditato nella pagina. Le otto scelte dell'amministratore (in «Recupero dei crediti» e, per una sola
 * successione, nella pagina del passaggio), le frasi della finestra della proposta sulle righe ereditate e sulla riga del defunto, e le cifre cambiate prima
 * di registrare (solo in meno, con il perché). I calcoli li fa il server: qui si dicono, e si controlla quello che l'amministratore scrive prima di mandarlo.
 */
import { euroTesto, giorno } from '@/lib/gestionale/debiti/rateScadute';

export type SceltaDegliEredi = 'interessi_sugli_interessi' | 'dal_decesso' | 'tolleranza_rata_nuova' | 'rata_per_rata' | 'versamento_prima' | 'anche_accordo'
    | 'buco_continuano' | 'interessi_defunto_agli_eredi';

export interface VoceInteressiEredi {
    chiave: SceltaDegliEredi; titolo: string; valore: boolean; adesso: string; acceso: string; spento: string; esempio: string; di_serie: boolean;
}

/** La riga «Adesso: …» di una scelta con il valore che ha nel modulo (decisione 494 (1)). */
export function adessoDellaScelta(v: Pick<VoceInteressiEredi, 'acceso' | 'spento'>, valore: boolean): string {
    return valore ? v.acceso : v.spento;
}

/** Le scelte come le manda il modulo: le otto chiavi, vere o false. */
export function scelteDalleVoci(voci: VoceInteressiEredi[]): Record<SceltaDegliEredi, boolean> {
    return Object.fromEntries(voci.map((v) => [v.chiave, v.valore])) as Record<SceltaDegliEredi, boolean>;
}

/**
 * Seconda prova della beta.12: la successione tiene solo le scelte diverse dalle regole del condominio; null se non ne resta nessuna (valgono le regole).
 */
export function scelteCambiate(tutte: Record<SceltaDegliEredi, boolean>, delCondominio: Record<SceltaDegliEredi, boolean>): Partial<Record<SceltaDegliEredi, boolean>> | null {
    const cambiate = Object.fromEntries(Object.entries(tutte).filter(([k, v]) => delCondominio[k as SceltaDegliEredi] !== v)) as Partial<Record<SceltaDegliEredi, boolean>>;

    return Object.keys(cambiate).length ? cambiate : null;
}

/** «Cambiate per questa successione: «Partono dal giorno del decesso» spento.»: le scelte che la successione tiene, con il titolo della voce. */
export function testoScelteCambiate(cambiate: Partial<Record<SceltaDegliEredi, boolean>>, voci: Pick<VoceInteressiEredi, 'chiave' | 'titolo'>[]): string {
    const dette = voci.filter((v) => cambiate[v.chiave] !== undefined).map((v) => `«${v.titolo}» ${cambiate[v.chiave] ? 'acceso' : 'spento'}`);

    return `Cambiate per questa successione: ${dette.join(', ')}.`;
}

/** Le scelte cambiate rispetto a quelle date: per dire nella successione che non sono più quelle del condominio. */
export function scelteDiverse(a: Record<string, boolean>, b: Record<string, boolean>): boolean {
    return Object.keys(a).some((k) => a[k] !== b[k]);
}

/** La riga del debito ereditato nella proposta, come la manda il server. */
export interface Ereditata {
    subentro_id: number; da_anagrafica_id: number; da: string; percentuale: string; decesso: string; modo: 'al_decesso' | 'continuano'; parte: number; accessorio: boolean;
}

/**
 * La cifra scritta in «Cambia», in euro come la scrive l'amministratore («0,30», «€ 1,05»): i centesimi, o null se non è una cifra. Solo per i controlli della
 * finestra: al server va la stringa, e i centesimi li fa lui, una volta sola.
 */
export function centesimiScritti(testo: string): number | null {
    const t = testo.replace(/€/g, '').replace(/\s/g, '');
    if (!/^\d{1,7}([.,]\d{1,2})?$/.test(t)) {
        return null;
    }
    const [interi, decimali = ''] = t.split(/[.,]/);

    return Number(interi) * 100 + Number((decimali + '00').slice(0, 2));
}

/**
 * Perché la cifra scritta non va (decisione 493 (10)): solo in meno, da 1 centesimo alla cifra proposta; null se va. Le stesse frasi del server.
 */
export function perchéNonVa(scritta: string, proposti: number): string | null {
    const c = centesimiScritti(scritta);
    if (c === null) {
        return 'Scrivi la cifra in euro, per esempio 0,30.';
    }
    if (c <= 0) {
        return 'Per non chiedere niente, togli la riga.';
    }
    if (c > proposti) {
        return 'Più della proposta no: è il massimo che le regole del condominio danno. Per un tasso diverso cambia la regola in «Recupero dei crediti».';
    }

    return null;
}

/**
 * Sotto la riga con la cifra cambiata, le conseguenze (decisione 493 (11)): «I giorni fino al 31/12/2026 restano registrati: la prossima proposta riparte
 * dal 01/01/2027, e i € 0,07 di differenza non si chiedono più. Se invece togli la riga, quei giorni tornano nella prossima proposta.»
 */
export function fraseDelleConseguenze(al: string, proposti: number, registrati: number): string {
    // Il giorno dopo contato sul calendario, senza passare dall'ora di Greenwich.
    const [a, m, g] = al.split('-').map(Number);
    const dopo = new Date(Date.UTC(a, m - 1, g + 1));
    const due = (n: number) => String(n).padStart(2, '0');
    const giornoDopo = `${dopo.getUTCFullYear()}-${due(dopo.getUTCMonth() + 1)}-${due(dopo.getUTCDate())}`;

    return `I giorni fino al ${giorno(al)} restano registrati: la prossima proposta riparte dal ${giorno(giornoDopo)}, e i ${euroTesto(proposti - registrati)} di `
        + 'differenza non si chiedono più. Se invece togli la riga, quei giorni tornano nella prossima proposta.';
}

/** Il perché delle cifre cambiate: da 5 a 255 caratteri. Null se va. */
export function perchéDelMotivo(motivo: string, cambiate: number): string | null {
    const m = motivo.trim();
    if (cambiate > 0 && m.length < 5) {
        return 'Scrivi perché cambi le cifre, in almeno 5 caratteri: resta scritto nell\'addebito.';
    }
    if (m.length > 255) {
        return 'Il perché sta in 255 caratteri.';
    }

    return null;
}

/** Il debito ereditato di una riga di «Rate scadute» (decisioni 494 (3) e 516), come lo manda il server. */
export interface EreditatoInElenco {
    da: string; da_anagrafica_id?: number; successione_del: string; subentro_id: number;
    /** Le parti già in una rata nuova emessa che non conta ancora i suoi interessi (decisione 516). */
    nelle_rate?: Array<{ rata: string; rata_id: number; scadenza: string; parte: number }>;
    /** La parte non ancora in una rata emessa. */
    fuori_rata?: number;
    /** La frase della riga, la stessa delle lettere: la scrive il server. */
    frase?: string;
}

/**
 * «Rate scadute» (decisioni 494 (3) e 516): la riga del debito ereditato. «da Ettore Fabbri, successione del 17/11/2026, non ancora in una rata», o, con la
 * rata nuova emessa e i suoi interessi che non contano ancora, «…, nella Rata 1 — Preventivo 2027 che scade il 31/03/2027». La frase la scrive il server.
 */
export function testoEreditatoInElenco(e: EreditatoInElenco): string {
    return e.frase ?? `da ${e.da}, successione del ${giorno(e.successione_del)}, non ancora in una rata`;
}

/**
 * Accanto al nome della persona in «Rate scadute» (correzione della prova a video della beta.12): il debito ereditato si vede anche con la riga chiusa, una
 * voce per successione, con la cifra. Le rate si leggono aprendo la riga.
 */
export function ereditatiDellaPersona(rate: Array<{ residuo: number; ereditato?: EreditatoInElenco | null }>): Array<{ subentro_id: number; testo: string }> {
    const perSuccessione = new Map<number, { e: EreditatoInElenco; importo: number }>();
    for (const r of rate) {
        if (!r.ereditato) {
            continue;
        }
        const gia = perSuccessione.get(r.ereditato.subentro_id);
        perSuccessione.set(r.ereditato.subentro_id, { e: r.ereditato, importo: (gia?.importo ?? 0) + r.residuo });
    }

    return [...perSuccessione.values()].map(({ e, importo }) => ({
        subentro_id: e.subentro_id,
        testo: `${euroTesto(importo)} da ${e.da}, successione del ${giorno(e.successione_del)}`,
    }));
}

/**
 * Le otto scelte nelle guide («Recupero dei crediti», «Passaggio di proprietà»), con il caso di Ettore Fabbri: gli stessi testi della «i» della pagina
 * (`RegoleDegliEredi::TESTI`), che le guide mostrano senza chiederli al server.
 */
export const GUIDA_INTERESSI_EREDI: ReadonlyArray<{ chiave: SceltaDegliEredi; titolo: string; esempio: string }> = [
    { chiave: 'interessi_sugli_interessi', titolo: 'Interessi anche sugli interessi già addebitati al defunto', esempio: 'Per esempio: Ettore doveva la rata di aprile, € 300,00, e € 1,20 di interessi già addebitati. Spento (di serie) Elena e Marco contano gli interessi sui € 300,00, € 150,00 a testa; acceso anche sui € 1,20, finché non li versano.' },
    { chiave: 'dal_decesso', titolo: 'Partono dal giorno del decesso', esempio: 'Per esempio: Ettore muore il 17/11/2026. Acceso (di serie) gli interessi di Elena e Marco partono il 17/11; spento il 18/11, e il 17/11 non li conta nessuno.' },
    { chiave: 'tolleranza_rata_nuova', titolo: 'Il debito ereditato ha gli «Interessi dopo la scadenza» della rata nuova', esempio: 'Per esempio: la rata nuova di Elena e Marco scade il 31/03/2027, e nelle regole del recupero «Interessi dopo la scadenza» è 10 giorni. Spento (di serie) il debito ereditato conta fino al 10/04, € 1,19 a testa; acceso fino al 31/03, € 1,11 a testa. La «Tolleranza prima del sollecito» non c\'entra.' },
    { chiave: 'rata_per_rata', titolo: 'Su più rate, scende rata per rata', esempio: 'Per esempio: il piano 2027 divide i € 300,00 di Ettore su quattro rate. Acceso (di serie) ogni parte conta fino alla sua rata; spento tutto il debito smette di contare alla prima rata, e le parti delle rate dopo aspettano la loro.' },
    { chiave: 'versamento_prima', titolo: 'Un versamento dell\'erede copre prima il debito ereditato', esempio: 'Per esempio: Elena versa € 150,00 il 20/11/2026, prima che il debito sia in una sua rata. Acceso (di serie) il versamento copre prima il debito ereditato e i suoi interessi si fermano dal 21/11, € 0,03; spento copre prima le sue rate.' },
    { chiave: 'anche_accordo', titolo: 'Anche il debito dell\'accordo di rientro in regola', esempio: 'Per esempio: Ettore aveva un accordo di rientro in regola sui € 300,00. Acceso (di serie) dal 17/11 Elena e Marco contano gli interessi anche su quel debito; spento quella parte resta senza interessi finché non entra in una loro rata.' },
    { chiave: 'buco_continuano', titolo: 'Con «continuano a suo nome», gli eredi coprono il buco fino alla scadenza', esempio: 'Per esempio: con «continuano a suo nome» Ettore conta fino all\'emissione della rata nuova, il 15/01/2027, che scade il 31/03/2027. Spento (di serie) dal 16/01 al 31/03 nessuno li conta, € 1,23 sui € 300,00; acceso li contano Elena e Marco.' },
    { chiave: 'interessi_defunto_agli_eredi', titolo: 'Gli interessi del defunto registrati dopo vanno agli eredi', esempio: 'Per esempio: gli interessi di Ettore dal 01/05 al 16/11/2026, € 3,29, registrati dopo la successione. Acceso (di serie) passano a Elena e Marco, metà ciascuno, come gli interessi già addebitati prima del decesso; spento restano a nome di Ettore, e la sua posizione chiusa si riapre.' },
];

/**
 * Decisione 512: il tasso scritto in «Cambia», in percento come lo scrive l'amministratore («1,00», «1,5 %»): in decimillesimi di punto (1,00 % = 10.000), o
 * null se non è un tasso. Al server va la stringa: il numero lo fa lui, una volta sola; questo serve alla cifra che la finestra mostra.
 */
export function tassoScritto(testo: string): number | null {
    const t = testo.replace(/%/g, '').replace(/\s/g, '');
    if (!/^\d{1,3}([.,]\d{1,4})?$/.test(t)) {
        return null;
    }
    const [interi, decimali = ''] = t.split(/[.,]/);

    return Number(interi) * 10000 + Number((decimali + '0000').slice(0, 4));
}

/** «1,00 %», «1,605 %»: un tasso in decimillesimi di punto, come lo scrive il server (almeno due decimali, lo spazio che non va a capo). */
export function etichettaDelTasso(e4: number): string {
    const decimali = String(e4 % 10000).padStart(4, '0').replace(/0+$/, '').padEnd(2, '0');

    return `${new Intl.NumberFormat('it-IT', { maximumFractionDigits: 0 }).format(Math.floor(e4 / 10000))},${decimali}\u00A0%`;
}

/**
 * Decisione 512: la riga rifatta con un altro tasso, sugli stessi giorni e tratti: capitale × tasso × giorni / (1.000.000 × divisore) per tratto, la somma
 * esatta, un arrotondamento per riga, il mezzo centesimo in su. È il conto del server (`InteressiDiMora::arrotonda`), con gli interi grandi.
 */
export function interessiAlTasso(tratti: Array<{ capitale: number; giorni: number; divisore: number }>, e4: number): number {
    const comune = BigInt(1_000_000) * BigInt(365) * BigInt(366);
    let interi = BigInt(0);
    let resti = BigInt(0);
    for (const t of tratti) {
        const n = BigInt(t.capitale) * BigInt(e4) * BigInt(t.giorni);
        const d = BigInt(1_000_000) * BigInt(t.divisore);
        interi += n / d;
        resti += (n % d) * (comune / d);
    }
    interi += resti / comune;

    return Number(interi + (BigInt(2) * (resti % comune) >= comune ? BigInt(1) : BigInt(0)));
}

/**
 * Perché il tasso scritto non va (decisione 512): solo in meno, mai più alto di quello delle regole in nessun tratto; null se va. Le stesse frasi del server.
 */
export function perchéNonVaIlTasso(testo: string, tratti: Array<{ capitale: number; giorni: number; divisore: number; tasso_e4?: number }>): string | null {
    const e4 = tassoScritto(testo);
    if (e4 === null) {
        return 'Scrivi il tasso in percento, per esempio 1,00.';
    }
    if (e4 <= 0) {
        return 'Per non chiedere niente, togli la riga.';
    }
    const massimo = Math.min(...tratti.map((t) => t.tasso_e4 ?? Number.MAX_SAFE_INTEGER));
    if (e4 > massimo) {
        return `Più del tasso delle regole no: per questa riga è ${etichettaDelTasso(massimo)}. Per un tasso diverso su tutte le righe cambia la regola in «Recupero dei crediti».`;
    }
    if (interessiAlTasso(tratti, e4) <= 0) {
        return 'Con questo tasso la riga dà € 0,00: per non chiedere niente, togli la riga.';
    }

    return null;
}

/** L'articolo davanti a un tasso: «l'» davanti a 1, 8, 11 e da 80 a 89 (si leggono con la vocale: «l'1,00 %»), «il» davanti agli altri. */
export function conLArticolo(e4: number): string {
    const intero = Math.floor(e4 / 10000);
    const elide = intero === 1 || intero === 8 || intero === 11 || (intero >= 80 && intero <= 89);

    return `${elide ? 'l\'' : 'il '}${etichettaDelTasso(e4)}`;
}

/** «Con l'1,00 % sugli stessi giorni: € 0,31.»: la cifra che il tasso scritto dà, sotto la riga. */
export function fraseDelTasso(e4: number, cifra: number): string {
    return `Con ${conLArticolo(e4)} sugli stessi giorni: ${euroTesto(cifra)}.`;
}

