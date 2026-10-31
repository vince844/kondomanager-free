/**
 * Le quote non assegnate accettate di un piano rate, come le dice la pagina del piano (`PianiRateShow.vue`): il badge «Quota aperta» di
 * «Copertura spese», la riga sotto una voce e i gruppi del riquadro in cima alla pagina.
 *
 * Le quote arrivano dalla colonna `piani_rate.quote_non_assegnate` (`GeneratePianoRateAction::registraQuoteNonAssegnate`): una per motivo,
 * unità, voce, tabella e ruolo, con l'importo in centesimi interi e in valore assoluto, e il segno della voce a parte (1 spesa, −1 entrata).
 * Tre cause registrano la quota senza unità (`immobile_id` nullo: la voce senza tabella, la tabella senza millesimi, le tabelle della voce
 * sotto il 100 %): la quota è di tutta la voce, o di una sua fetta che nessuno copre. Il millesimo non compilato non ha cifra: la sua parte è
 * andata alle altre unità, e non è una quota aperta.
 *
 * Le regole (decisioni 162 e 163):
 * - il badge dice la cifra con il segno, uguale allo scarto (una spesa da € 75,00 e un'entrata da € 30,00 dicono € 45,00), e conta le unità,
 *   non le quote: un'unità sola, anche su più voci, per nome; le quote senza unità contano come voci intere;
 * - la riga sotto una voce trova le sue quote con le chiavi che il server manda per quella voce (`non_assegnati_chiavi`, anche quelle dei
 *   sottoconti), nomina le prime due unità e «altre N», e dice la causa quando è la stessa per tutte;
 * - il riquadro raggruppa per causa («Nessun proprietario — 6 unità»), una riga per unità e voce con la cifra sommata; la causa si confronta
 *   sul motivo (con il ruolo e la tabella quando la frase li nomina), non sul testo, e i giorni diversi stanno sulla riga dell'unità.
 *
 * Due voci sono due voci anche se si chiamano allo stesso modo (si distinguono per `conto_id`). I nomi si ordinano come li legge una persona:
 * «Cantina 2» prima di «Cantina 10».
 */

/** Una quota non assegnata registrata alla generazione, in centesimi. */
export type QuotaNonAssegnata = {
    chiave: string;
    motivo: string | null;
    immobile_id?: number | null;
    immobile_nome: string | null;
    conto_id: number | null;
    conto_nome: string | null;
    tabella_nome: string | null;
    ruolo_richiesto: string | null;
    /** In valore assoluto: il segno sta in `segno`. */
    importo_cents: number;
    giorni: number | null;
    riga_descrizione: string | null;
    /** 1 per una voce di spesa, −1 per una voce di entrata (la sua quota è un credito che nessuno riceve). */
    segno?: number;
};

/** Una voce del piano come la manda il server per la pagina del piano (`PianoRateResource`). */
export type VoceConQuote = {
    /** La somma delle sue quote non assegnate e di quelle dei suoi sottoconti, in valore assoluto. */
    non_assegnati_cents?: number;
    /** Le chiavi delle stesse quote. */
    non_assegnati_chiavi?: string[];
};

/** Una riga del riquadro: il nome in grassetto, poi il resto (la voce, la cifra, i giorni). */
export type RigaDelGruppo = { chiave: string; nome: string; resto: string };

/** Un gruppo del riquadro: una causa, con le sue righe e il testo del pulsante che le mostra tutte. */
export type GruppoPerCausa = { chiave: string; intestazione: string; righe: RigaDelGruppo[]; mostraTutte: string };

/** Formatta i centesimi in euro (`useCurrencyFormatter().euro`). */
export type FormatoEuro = (centesimi: number) => string;

/** Le righe di un gruppo che il riquadro mostra prima di «Mostra tutte». */
export const RIGHE_VISIBILI = 5;

export const RUOLI: Record<string, string> = {
    proprietario: 'nessun proprietario',
    inquilino: 'nessun inquilino',
    usufruttuario: 'nessun usufruttuario',
    nuda_proprietario: 'nessun nudo proprietario',
};

/** Perché la quota non è di nessuno, con le parole della finestra «Procedi comunque» (`ScopertoWarning`). */
export function percheNonAssegnata(q: QuotaNonAssegnata): string {
    switch (q.motivo) {
        case 'conto_senza_tabella': return 'la voce non ha una tabella millesimale collegata';
        case 'tabella_senza_millesimi': return q.tabella_nome ? `la tabella «${q.tabella_nome}» non ha millesimi utilizzabili` : 'la tabella collegata non ha millesimi utilizzabili';
        case 'coefficienti_sotto_il_cento': return 'le tabelle collegate alla voce non arrivano al 100 %';
        case 'ripartizioni_sotto_il_cento': return 'le percentuali fra proprietario, inquilino e usufruttuario non arrivano al 100 %';
        case 'millesimo_non_compilato': return q.tabella_nome ? `non ha un millesimo nella tabella «${q.tabella_nome}»: la sua parte è stata ripartita fra le altre unità` : 'non ha un millesimo: la sua parte è stata ripartita fra le altre unità';
        case 'ad_personam_senza_titolare': return 'una spesa a suo carico, senza proprietario, nudo proprietario o usufruttuario attivo';
        case 'giorni_senza_titolare': return `per ${q.giorni ?? '?'} giorni del periodo di competenza nessun titolare è in vigore`;
        case 'titolari_fuori_competenza': return 'nessun titolare del ruolo richiesto è in vigore nei giorni di competenza della voce';
        default: return (q.ruolo_richiesto && RUOLI[q.ruolo_richiesto]) || 'nessun titolare';
    }
}

/** I motivi la cui frase non dipende dal ruolo richiesto. */
const MOTIVI_CON_LA_LORO_FRASE = [
    'conto_senza_tabella', 'tabella_senza_millesimi', 'coefficienti_sotto_il_cento', 'ripartizioni_sotto_il_cento', 'millesimo_non_compilato',
    'ad_personam_senza_titolare', 'giorni_senza_titolare', 'titolari_fuori_competenza',
];

/**
 * La causa di una quota, per raggrupparle: il motivo, con la tabella quando la frase la nomina, e con il ruolo quando la frase è il ruolo
 * («nessun proprietario», «nessun inquilino»). I giorni no: cambiano da un'unità all'altra e stanno sulla sua riga.
 */
export function chiaveDellaCausa(q: QuotaNonAssegnata): string {
    if (q.motivo === 'tabella_senza_millesimi' || q.motivo === 'millesimo_non_compilato') {
        return `${q.motivo}|${q.tabella_nome ?? ''}`;
    }

    return q.motivo !== null && MOTIVI_CON_LA_LORO_FRASE.includes(q.motivo) ? q.motivo : `${q.motivo ?? ''}|${q.ruolo_richiesto ?? ''}`;
}

const collatore = new Intl.Collator('it', { numeric: true });
const perNome = (a: string, b: string) => collatore.compare(a, b);

/** «a», «a e b», «a, b e c». */
const elenco = (parti: string[]) => (parti.length <= 1 ? (parti[0] ?? '') : `${parti.slice(0, -1).join(', ')} e ${parti[parti.length - 1]}`);
const maiuscola = (frase: string) => frase.charAt(0).toUpperCase() + frase.slice(1);

const haUnita = (q: QuotaNonAssegnata) => q.immobile_id !== null && q.immobile_id !== undefined;
const nomeUnita = (q: QuotaNonAssegnata) => q.immobile_nome ?? 'un\'unità senza nome';
/** Il nome di una quota senza unità: la voce, o la tabella, o la riga. */
const nomeVoceIntera = (q: QuotaNonAssegnata) => q.conto_nome ?? (q.tabella_nome ? `tabella «${q.tabella_nome}»` : (q.riga_descrizione ?? 'una voce del piano'));
const conSegno = (q: QuotaNonAssegnata) => ((q.segno ?? 1) < 0 ? -1 : 1) * Math.abs(q.importo_cents);
/** La voce di una quota: per `conto_id`, perché due voci possono avere lo stesso nome; per nome solo se la quota non porta l'id. */
const chiaveVoce = (q: QuotaNonAssegnata) => (q.conto_id !== null && q.conto_id !== undefined ? `conto ${q.conto_id}` : `nome ${q.conto_nome ?? ''}`);
/** Quante voci distinte hanno le quote. */
const numeroDiVoci = (quote: QuotaNonAssegnata[]) => new Set(quote.map(chiaveVoce)).size;

/** I nomi delle unità distinte delle quote (per `immobile_id`), in ordine di nome. */
function unitaDistinte(quote: QuotaNonAssegnata[]): string[] {
    const nomi = new Map<number, string>();
    quote.filter(haUnita).forEach((q) => {
        if (!nomi.has(q.immobile_id as number)) nomi.set(q.immobile_id as number, nomeUnita(q));
    });

    return [...nomi.values()].sort(perNome);
}

/** Le voci intere distinte (le quote senza unità, una per voce), in ordine di nome; il nome è null se la voce non lo porta. */
function vociIntere(quote: QuotaNonAssegnata[]): (string | null)[] {
    const nomi = new Map<string, string | null>();
    quote.filter((q) => !haUnita(q)).forEach((q) => {
        const chiave = q.conto_id !== null ? `conto ${q.conto_id}` : nomeVoceIntera(q);
        if (!nomi.has(chiave)) nomi.set(chiave, q.conto_nome);
    });

    return [...nomi.values()].sort((a, b) => perNome(a ?? '', b ?? ''));
}

/** Le quote con una cifra: il millesimo non compilato non ne ha, perché la sua parte è andata alle altre unità. */
export function quoteConCifra(quote: QuotaNonAssegnata[]): QuotaNonAssegnata[] {
    return quote.filter((q) => q.importo_cents !== 0);
}

/** La somma delle quote con il segno della loro voce, come la conta lo scarto: quella di una voce di entrata con il meno. */
export function totaleConSegno(quote: QuotaNonAssegnata[]): number {
    return quoteConCifra(quote).reduce((somma, q) => somma + conSegno(q), 0);
}

/** «Quota aperta · € 450,00 (6 unità)»: la cifra con il segno, e dove sta. */
export function etichettaBadge(quote: QuotaNonAssegnata[], euro: FormatoEuro): string {
    const conCifra = quoteConCifra(quote);
    const unita = unitaDistinte(conCifra);
    const voci = vociIntere(conCifra);
    const delleUnita = unita.length === 1 ? unita[0] : `${unita.length} unità`;
    const delleVoci = voci.length === 1 ? '1 voce intera' : `${voci.length} voci intere`;

    let dove: string;
    if (voci.length === 0) {
        dove = delleUnita;
    } else if (unita.length === 0) {
        dove = voci.length === 1 && voci[0] !== null ? `la voce ${voci[0]}` : delleVoci;
    } else {
        dove = `${delleUnita} e ${delleVoci}`;
    }

    return `Quota aperta · ${euro(totaleConSegno(quote))} (${dove})`;
}

/** La causa di un gruppo di quote con la stessa chiave, detta per tutte: al plurale quando le unità o le voci sono più d'una. */
export function causaDelGruppo(quote: QuotaNonAssegnata[]): string {
    const q = quote[0];
    const piuUnita = unitaDistinte(quote).length > 1;
    const piuVoci = vociIntere(quote).length > 1;

    switch (q.motivo) {
        case 'conto_senza_tabella':
            return piuVoci ? 'le voci non hanno una tabella millesimale collegata' : percheNonAssegnata(q);
        case 'coefficienti_sotto_il_cento':
            return piuVoci ? 'le tabelle collegate alle voci non arrivano al 100 %' : percheNonAssegnata(q);
        case 'millesimo_non_compilato':
            if (!piuUnita) return percheNonAssegnata(q);
            return q.tabella_nome
                ? `non hanno un millesimo nella tabella «${q.tabella_nome}»: la loro parte è stata ripartita fra le altre unità`
                : 'non hanno un millesimo: la loro parte è stata ripartita fra le altre unità';
        case 'ad_personam_senza_titolare':
            return piuUnita ? 'spese a loro carico, senza proprietario, nudo proprietario o usufruttuario attivo' : percheNonAssegnata(q);
        case 'giorni_senza_titolare':
            return new Set(quote.map((x) => x.giorni)).size === 1 ? percheNonAssegnata(q) : 'per una parte del periodo di competenza nessun titolare è in vigore';
        case 'titolari_fuori_competenza':
            return numeroDiVoci(quote) > 1 ? 'nessun titolare del ruolo richiesto è in vigore nei giorni di competenza delle voci' : percheNonAssegnata(q);
        default:
            return percheNonAssegnata(q);
    }
}

/**
 * I nomi delle unità e, dopo, delle voci intere: tutti se sono tre o meno; se no i primi due, e le altre dette per quello che sono (altre unità,
 * voci intere, o le due cose): «Cantina 1, Cantina 2 e altre 4», «Cantina 1, Cantina 2, un'altra unità e una voce intera».
 */
function nominaIPosti(unita: string[], voci: string[]): string {
    const posti = [...unita, ...voci];
    if (posti.length <= 3) return elenco(posti);

    const primi = `${posti[0]}, ${posti[1]}`;
    const altreUnita = Math.max(unita.length - 2, 0);
    const altreVoci = voci.length - Math.max(2 - unita.length, 0);
    if (altreVoci === 0) return `${primi} e altre ${altreUnita}`;
    // Con più di tre posti e nessuna altra unità, le unità sono al più due e le voci intere oltre ai nomi detti almeno due: mai una sola.
    if (altreUnita === 0) return `${primi} e altre ${altreVoci} voci intere`;

    return `${primi}, ${altreUnita === 1 ? 'un\'altra unità' : `altre ${altreUnita} unità`} e ${altreVoci === 1 ? 'una voce intera' : `${altreVoci} voci intere`}`;
}

/** Che fine fanno i non assegnati di una voce: una spesa resta fuori dalle rate; un'entrata è anche un credito che nessuno riceve. */
function fineDeiNonAssegnati(quote: QuotaNonAssegnata[]): string {
    const entrate = quote.filter((q) => (q.segno ?? 1) < 0).length;
    if (entrate === 0) return 'restano fuori dalle rate';

    return entrate === quote.length
        ? 'restano fuori dalle rate, e nessuno li riceve come credito'
        : 'restano fuori dalle rate, e quelli delle entrate nessuno li riceve come credito';
}

/**
 * La riga sotto una voce di «Copertura spese»: la cifra del server, le unità (le prime due e «altre N», tutte se sono tre) e, se è la stessa
 * per tutte, la causa. Le quote della voce sono quelle delle sue chiavi, anche dei sottoconti.
 */
export function rigaDellaVoce(quote: QuotaNonAssegnata[], voce: VoceConQuote, euro: FormatoEuro): string {
    const chiavi = new Set(voce.non_assegnati_chiavi ?? []);
    const sue = quoteConCifra(quote).filter((q) => chiavi.has(q.chiave));
    const unita = unitaDistinte(sue);
    const voci = vociIntere(sue).map((nome) => nome ?? 'una voce del piano');

    let dove = '';
    if (unita.length + voci.length > 0) {
        const nomi = nominaIPosti(unita, voci);
        dove = new Set(sue.map(chiaveDellaCausa)).size === 1 ? ` (${nomi}: ${causaDelGruppo(sue)})` : ` (${nomi})`;
    }

    return `Non assegnati ${euro(voce.non_assegnati_cents ?? 0)}${dove}: ${fineDeiNonAssegnati(sue)}.`;
}

/** Le righe di un gruppo: una per unità e voce (o per voce intera), con la cifra sommata e con il segno. */
function righeDelGruppo(quote: QuotaNonAssegnata[], euro: FormatoEuro, voceSullaRiga: boolean, giorniSullaRiga: boolean): RigaDelGruppo[] {
    type Accumulo = { chiave: string; nome: string; voce: string | null; cents: number; giorni: Set<number> };
    const righe = new Map<string, Accumulo>();

    quote.forEach((q) => {
        const chiave = haUnita(q) ? `unità ${q.immobile_id}|${q.conto_id ?? q.conto_nome ?? ''}` : `voce ${q.conto_id ?? nomeVoceIntera(q)}`;
        const riga = righe.get(chiave) ?? { chiave, nome: haUnita(q) ? nomeUnita(q) : nomeVoceIntera(q), voce: haUnita(q) ? q.conto_nome : null, cents: 0, giorni: new Set<number>() };
        riga.cents += conSegno(q);
        if (q.giorni !== null) riga.giorni.add(q.giorni);
        righe.set(chiave, riga);
    });

    return [...righe.values()]
        .sort((a, b) => perNome(a.nome, b.nome) || perNome(a.voce ?? '', b.voce ?? ''))
        .map((r) => {
            const voce = voceSullaRiga && r.voce !== null ? ` · ${r.voce}` : '';
            const cifra = r.cents !== 0 ? `: ${euro(r.cents)}` : '';
            const giorni = giorniSullaRiga && r.giorni.size > 0 ? ` (${elenco([...r.giorni].sort((a, b) => a - b).map(String))} giorni)` : '';

            return { chiave: r.chiave, nome: r.nome, resto: `${voce}${cifra}${giorni}` };
        });
}

/**
 * I gruppi del riquadro in cima alla pagina, uno per causa, nell'ordine in cui il motore li ha trovati; le quote senza cifra (il millesimo non
 * compilato) dopo le altre. L'intestazione dice la causa e quante sono le unità (o le voci); la voce, se è una sola per tutte le unità del
 * gruppo, la dice l'intestazione, se no ogni riga.
 */
export function gruppiPerCausa(quote: QuotaNonAssegnata[], euro: FormatoEuro): GruppoPerCausa[] {
    const perCausa = new Map<string, QuotaNonAssegnata[]>();
    quote.forEach((q) => {
        const chiave = chiaveDellaCausa(q);
        perCausa.set(chiave, [...(perCausa.get(chiave) ?? []), q]);
    });

    const gruppi = [...perCausa.entries()].map(([chiave, sue]) => {
        const delleUnita = sue.filter(haUnita);
        const vociDelleUnita = new Set(delleUnita.map(chiaveVoce));
        const unaVoce = vociDelleUnita.size === 1 ? delleUnita[0].conto_nome : null;
        const giorniDiversi = sue[0].motivo === 'giorni_senza_titolare' && new Set(sue.map((q) => q.giorni)).size > 1;

        const nUnita = unitaDistinte(sue).length;
        const nVoci = vociIntere(sue).length;
        const quante = [nUnita > 0 ? `${nUnita} unità` : null, nVoci > 0 ? (nVoci === 1 ? '1 voce' : `${nVoci} voci`) : null].filter((p) => p !== null).join(' e ');
        const righe = righeDelGruppo(sue, euro, vociDelleUnita.size > 1, giorniDiversi);

        return {
            chiave,
            intestazione: `${maiuscola(causaDelGruppo(sue))} — ${quante}${unaVoce !== null ? ` sulla voce ${unaVoce}` : ''}`,
            righe,
            // L'intestazione conta le unità (o le voci), il pulsante le righe: se non sono lo stesso numero (sei unità su tre voci sono 18 righe) lo dice.
            mostraTutte: righe.length === nUnita + nVoci ? `Mostra tutte (${righe.length})` : `Mostra tutte (${righe.length} righe)`,
            senzaCifra: sue.every((q) => q.importo_cents === 0),
        };
    });

    return [...gruppi.filter((g) => !g.senzaCifra), ...gruppi.filter((g) => g.senzaCifra)].map(({ chiave, intestazione, righe, mostraTutte }) => ({ chiave, intestazione, righe, mostraTutte }));
}

/**
 * La frase che segue «Nessuna quota è rimasta senza destinatario, ma»: le unità senza un millesimo, ognuna una volta anche se le manca in più
 * tabelle (come i gruppi del riquadro, che contano le unità e non le quote).
 */
export function fraseUnitaSenzaMillesimo(quote: QuotaNonAssegnata[]): string {
    const n = unitaDistinte(quote.filter((q) => q.motivo === 'millesimo_non_compilato')).length;

    return n === 1 ? 'un\'unità non ha un millesimo: la sua parte' : `${n} unità non hanno un millesimo: la loro parte`;
}
