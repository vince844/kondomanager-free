// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le forme dei dati che il server manda alle pagine delle assemblee (`App\Http\Controllers\Assemblee\PerLePagine`): l'intestazione, l'ordine del giorno con i
 * badge, i passi della strada, i menu. Le date `YYYY-MM-DD`, le ore `HH:MM` come si leggono, gli importi in centesimi.
 */
import type { ElencoDelloStatoDiEsecuzione, MateriaPunto, RigaDelDiario } from '@/types/assemblee';

export interface Scelta { valore: string; nome: string }

export interface ControlloDellaTabella {
    frase: string;
    avvisi: string[];
    unita: number;
    con_quota: number;
    somma: string;
}

export interface SedutaDellaPagina {
    id?: number;
    convocazione: 'prima' | 'seconda';
    numero: number;
    giorno: string;
    ora: string;
    luogo?: string | null;
}

export interface AssembleaDellaPagina {
    id: number;
    nome: string;
    titolo: string;
    sottotitolo: string;
    stato: string;
    stato_parola: string;
    origine: string;
    a_mano: boolean;
    tenuta_fuori: boolean;
    in_preparazione: boolean;
    /** Il numero che la riunione di carta prenderebbe nel registro dei verbali, proposto in «Tenuta su carta»; null se il registro non sa da dove parte. */
    numero_verbale_proposto?: number | null;
    tipo: string;
    tipo_parola: string;
    ambito: string;
    palazzina_id: number | null;
    scala_id: number | null;
    perimetro: string;
    luogo: string | null;
    modalita: string;
    piattaforma: string | null;
    consenso_videoconferenza_il: string | null;
    consenso_videoconferenza_modo: string | null;
    data: string | null;
    sedute: SedutaDellaPagina[];
    tabella: { id: number; nome: string; controllo: ControlloDellaTabella } | null;
    tabella_di_proprieta: { id: number; nome: string } | null;
    perimetro_tipo: 'condominio' | 'palazzina' | 'scala' | 'gruppo';
    perimetro_id: number;
    /** L'assemblea dei condòmini di un gruppo (Coda 734): la tabella del gruppo, con le sue unità; null per le altre. */
    gruppo?: GruppoDellAssemblea | null;
    numero_verbale: string | null;
    verbale: { id: number; nome: string; peso: number } | null;
    verbale_da_caricare: boolean;
    riconvocata_da: { id: number; nome: string } | null;
    annullata_motivo: string | null;
    /** «Apri la sala» dal giorno della prima convocazione; «Torna in sala» quando è in corso: con l'indirizzo della sala. */
    apri_la_sala: { attiva: boolean; perche: string; url?: string; testo?: string };
}

/** Il gruppo dell'assemblea dei condòmini di una tabella segnata «di un gruppo» (Coda 734): la tabella, il nome, le unità con la quota. */
export interface GruppoDellAssemblea {
    tabella_id: number;
    nome: string;
    unita: number;
}

export interface DeliberaDellaPagina {
    id: number;
    testo: string;
    esito: string;
    esito_parola: string;
    deliberata_il: string | null;
    frase: string;
    posti: { nome: string; parola: string; data: string | null; nota: string | null }[];
    usata: string | null;
}

export interface PuntoDellaPagina {
    id: number;
    numero: number;
    testo: string;
    testo_pubblico: string | null;
    materia: MateriaPunto;
    materia_parola: string;
    maggioranza: string | null;
    maggioranza_parola: string | null;
    maggioranza_libera: { soglia?: string; norma?: string } | null;
    avviso: string | null;
    amministrazione: string | null;
    conduttore: string | null;
    conduttore_parola: string | null;
    ambito: string | null;
    palazzina_id: number | null;
    scala_id: number | null;
    perimetro_parola: string | null;
    tabella_id: number | null;
    tabella_nome: string | null;
    /** Il badge del punto votato con una tabella segnata «di un gruppo» (Coda 734): «Fra i condòmini della tabella «Box»». */
    gruppo_parola?: string | null;
    tabella_controllo: string | null;
    esercizio_id: number | null;
    importo: number | null;
    /** Per la modifica della destinazione d'uso (art. 1117-ter): le parti comuni che cambiano e la nuova destinazione, che l'avviso deve dire. */
    dati?: { parti_comuni: string | null; nuova_destinazione: string | null; delega?: DelegaDelPunto | null; stato_esecuzione?: ElencoDelloStatoDiEsecuzione;
        stampa_stato?: string | null } | null;
    fonte: string;
    chiave_fonte: string | null;
    da_memo: boolean;
    delibere: DeliberaDellaPagina[];
}

/**
 * Un punto come lo scrive la finestra del punto (i campi di `PuntoRequest`): per la pagina dell'assemblea va al server, in «Prepara l'assemblea» resta nella
 * pagina finché l'assemblea nasce. L'importo è in euro come scritto: diventa centesimi sul server, una volta sola.
 */
export interface DatiDelPunto {
    testo: string;
    testo_pubblico: string | null;
    materia: string;
    maggioranza: string | null;
    conduttore: string | null;
    ambito: string;
    palazzina_id: number | null;
    scala_id: number | null;
    tabella_id: number | null;
    importo: string | null;
    dati?: { parti_comuni?: string | null; nuova_destinazione?: string | null; delega?: DelegaDelPunto | null };
    /** «Mettilo lo stesso» (decisione 699 (3)): il punto di tutto il condominio nell'assemblea di un gruppo, scelto con il rischio. */
    mettilo_lo_stesso?: boolean;
}

/** La delega all'amministratore di un punto (decisione 622 (3)): per che cosa e fino a quando; il tetto è l'importo del punto. */
export interface DelegaDelPunto {
    ambito: string | null;
    fino_al: string | null;
}

/** Il modulo delle date e della testata dell'assemblea (`DateDellAssemblea.vue`). */
export interface ModuloDelleDate {
    tipo: string;
    /** `condominio`, `palazzina:3`, `scala:5`, `gruppo:7` (i condòmini della tabella 7, segnata «di un gruppo»). */
    perimetro: string;
    luogo: string;
    modalita: string;
    piattaforma: string;
    consenso_videoconferenza_il: string;
    consenso_videoconferenza_modo: string;
    sedute: SedutaDellaPagina[];
}

/** Il modulo dai dati della pagina. */
export function moduloDa(a: AssembleaDellaPagina): ModuloDelleDate {
    return {
        tipo: a.tipo, perimetro: chiaveDelPerimetro(a.ambito, a.palazzina_id, a.scala_id, a.gruppo?.tabella_id ?? a.tabella?.id), luogo: a.luogo ?? '', modalita: a.modalita,
        piattaforma: a.piattaforma ?? '', consenso_videoconferenza_il: a.consenso_videoconferenza_il ?? '', consenso_videoconferenza_modo: a.consenso_videoconferenza_modo ?? '',
        sedute: a.sedute.map((s) => ({ ...s })),
    };
}

/** Il modulo nella forma della richiesta (`PreparaAssembleaRequest`): per i condòmini di un gruppo anche la tabella, che è il gruppo. */
export function datiDelModulo(m: ModuloDelleDate): Record<string, unknown> {
    const [ambito, id] = m.perimetro.split(':');
    return {
        tipo: m.tipo, ambito, palazzina_id: ambito === 'palazzina' ? Number(id) : null, scala_id: ambito === 'scala' ? Number(id) : null,
        ...(ambito === 'gruppo' ? { tabella_id: Number(id) } : {}), luogo: m.luogo || null,
        modalita: m.modalita, piattaforma: m.piattaforma || null, consenso_videoconferenza_il: m.consenso_videoconferenza_il || null,
        consenso_videoconferenza_modo: m.consenso_videoconferenza_modo || null,
        sedute: m.sedute.filter((s) => s.giorno !== '').map((s) => ({ convocazione: s.convocazione, numero: s.numero, giorno: s.giorno, ora: s.ora || '00:00' })),
    };
}

/** I passi della strada, nell'ordine (`PassiDellAssemblea::PASSI` del server). */
export type ChiaveDelPasso = 'date' | 'documenti' | 'convocazione' | 'sala' | 'verbale' | 'delibere';

/** `fatto` verde con la spunta, `adesso` scuro con il numero, `dopo` grigio con il numero, `chiuso` con il lucchetto e il perché. */
export type StatoDelPasso = 'fatto' | 'adesso' | 'dopo' | 'chiuso';

/** Le finestre della pagina dell'assemblea che una card apre senza lasciarla: il cassetto delle deleghe, «Segna una delega ricevuta». */
export type FinestraDellaVoce = 'deleghe' | 'segna_delega';

/**
 * Una voce di «che cosa manca», una card sopra il passo con il pulsante in fondo: il collegamento porta a una pagina (`url`, anche `#convocati` nella
 * pagina), a un altro passo (`passo`), apre una finestra della pagina (`apre`), o c'è ma è spento (`spento`, con il perché). Una voce che `informa` (la
 * tabella che non copre tutte le unità, il luogo che manca, i punti proposti che aspettano) non conta fra le cose da fare e non blocca il passo; una voce
 * da `rifare` (il foglio della sala da ristampare, le lettere da ristampare) è in arancio. La chiave: `date`, `ordine`, `tabella` nel passo 1; `avviso`,
 * `casella`, `convocati` nella convocazione; `deleghe`, `foglio`, `apri`, `convocazione`, `prospetto` in sala; `verbale`, `bozza`, `cifre`, `copia`,
 * `trasmissione`, `libro` nel verbale; `da_eseguire`, `trenta_giorni` nelle delibere.
 */
export interface VoceCheCosaManca {
    chiave: string;
    fatto: boolean;
    informa?: boolean;
    rifare?: boolean;
    titolo: string;
    dettaglio: string;
    /**
     * `stampa`: l'indirizzo è un PDF (il foglio della sala, le presenze e i voti), e si apre in una scheda nuova. `apre`: la finestra della pagina.
     * `spento`: il pulsante c'è e non si preme, e questo è il perché («La sala si apre il giorno della prima convocazione, il 22/11/2026.»).
     */
    collegamento: { testo: string; url: string | null; passo: ChiaveDelPasso | null; stampa?: boolean; apre?: FinestraDellaVoce; spento?: string } | null;
}

/** Un passo della strada, come lo scrive il server: la riga sotto il nome; per il passo di adesso «che cosa manca» e il suo scopo («prima di spedire»). */
export interface PassoDellaStrada {
    chiave: ChiaveDelPasso;
    numero: number;
    nome: string;
    stato: StatoDelPasso;
    riga: string | null;
    perche: string | null;
    manca: VoceCheCosaManca[];
    scopo: string | null;
    piede: string;
    /** Il badge del passo aperto quando non è il conto di «che cosa manca» («1 delibera da eseguire»). */
    badge?: string | null;
    /** Un passo chiuso che si apre lo stesso: le delibere con il verbale annullato, per le schede che le usano e le cose aperte. */
    apribile?: boolean;
}

/** Un passo si apre con un clic quando è fatto o è quello di adesso, o quando è chiuso ma si guarda lo stesso. */
export function siApre(p: Pick<PassoDellaStrada, 'stato' | 'apribile'> | null | undefined): boolean {
    return p?.stato === 'fatto' || p?.stato === 'adesso' || p?.apribile === true;
}

/** La riga del primo passo mentre si scrive: «14/12 e 15/12 · 6 punti», come la scrive il server; senza un giorno «senza le date». */
export function rigaDelleDate(sedute: { giorno: string; ora?: string }[], punti: number): string {
    const giorni = [...sedute].filter((s) => s.giorno !== '').sort((x, y) => `${x.giorno} ${x.ora ?? ''}`.localeCompare(`${y.giorno} ${y.ora ?? ''}`))
        .map((s) => `${s.giorno.slice(8, 10)}/${s.giorno.slice(5, 7)}`).filter((g, i, tutti) => tutti.indexOf(g) === i);
    const date = giorni.length === 0 ? 'senza le date' : giorni.length === 1 ? giorni[0] : `${giorni.slice(0, -1).join(', ')} e ${giorni[giorni.length - 1]}`;

    return `${date} · ${punti === 0 ? 'nessun punto' : punti === 1 ? '1 punto' : `${punti} punti`}`;
}

export interface TabellaDelleScelte {
    id: number;
    nome: string;
    unita: number;
    /** La tabella segnata «di un gruppo» (Coda 734): sui suoi punti votano solo i condòmini delle sue unità. */
    di_un_gruppo?: boolean;
    /** La somma delle sue quote: «1.000,00». */
    millesimi?: string;
    /**
     * Il controllo per ogni perimetro: `condominio`, `palazzina:3`, `scala:5`, e `gruppo:7` per ogni tabella di un gruppo; con le unità del perimetro con la
     * quota e quante sono, e (per il gruppo) se la tabella ha tutte le sue unità dentro.
     */
    controlli: Record<string, { frase: string; copre: boolean; esatta: boolean; con_quota: number; unita: number; dentro?: boolean }>;
}

export interface ScelteDelleAssemblee {
    tipi: Scelta[];
    perimetri: Scelta[];
    modalita: Scelta[];
    materie: Scelta[];
    maggioranze: Scelta[];
    esiti: Scelta[];
    a_chi: Scelta[];
    unita: number;
    palazzine: { id: number; nome: string; unita: number }[];
    scale: { id: number; nome: string; palazzina_id: number | null; unita: number }[];
    tabelle: TabellaDelleScelte[];
    /** Per materia: la maggioranza proposta, l'avviso, e il conduttore proposto («vota» sul riscaldamento, art. 10 L. 392/1978). */
    maggioranze_proposte: Record<string, { maggioranza: string | null; avviso: string | null; conduttore?: string | null }>;
}

export interface DiarioDellaPagina {
    righe: RigaDelDiario[];
    dopo: string | null;
    altre: boolean;
}

/** La chiave del perimetro per i controlli delle tabelle: `condominio`, `palazzina:3`, `scala:5`, `gruppo:7` (con la tabella del gruppo). */
export function chiaveDelPerimetro(ambito: string | null | undefined, palazzinaId: number | null | undefined, scalaId: number | null | undefined,
    tabellaId?: number | null): string {
    if (ambito === 'scala' && scalaId) return `scala:${scalaId}`;
    if (ambito === 'palazzina' && palazzinaId) return `palazzina:${palazzinaId}`;
    if (ambito === 'gruppo' && tabellaId) return `gruppo:${tabellaId}`;
    return 'condominio';
}

/** Le materie che per legge sono di tutto il condominio, con l'inizio della frase del rischio (le stesse del server, `OrdineDelGiorno::MATERIE_DI_TUTTI`). */
export const MATERIE_DI_TUTTI: Record<string, string> = {
    rendiconto: 'Il rendiconto è',
    preventivo: 'Il preventivo è',
    nomina: 'La nomina e la revoca dell\'amministratore sono',
    dispensa: 'La dispensa dei sei mesi è',
    regole: 'Le regole del recupero dei crediti sono',
};

/** La materia è una di quelle che per legge sono di tutto il condominio ({@see MATERIE_DI_TUTTI}). */
export function materiaDiTutti(materia: string | null | undefined): boolean {
    return !!materia && materia in MATERIE_DI_TUTTI;
}

/**
 * Le voci di «Per chi» dell'assemblea (scena 7 della bozza della tappa 3): tutto il condominio, le palazzine, le scale, e dopo le scale una voce per ogni
 * tabella segnata «di un gruppo», «I condòmini della tabella «Box»», con le unità e i millesimi (valore `gruppo:<id>`).
 */
export function perimetriDellAssemblea(scelte: ScelteDelleAssemblee): { valore: string; nome: string; sotto?: string }[] {
    return [
        { valore: 'condominio', nome: 'Tutto il condominio' },
        ...scelte.palazzine.map((p) => ({ valore: `palazzina:${p.id}`, nome: `La palazzina ${p.nome}` })),
        ...scelte.scale.map((s) => ({ valore: `scala:${s.id}`, nome: `La scala ${s.nome}` })),
        ...scelte.tabelle.filter((t) => t.di_un_gruppo === true).map((t) => ({ valore: `gruppo:${t.id}`, nome: `I condòmini della tabella «${t.nome}»`,
            sotto: `${t.unita} unità${t.millesimi ? ` · ${t.millesimi} millesimi` : ''}` })),
    ];
}

/** Il gruppo scelto in «Per chi» (`gruppo:<id>`), con il nome e le unità della sua tabella; null per gli altri perimetri. */
export function gruppoDelPerimetro(scelte: ScelteDelleAssemblee, perimetro: string): GruppoDellAssemblea | null {
    const [ambito, id] = perimetro.split(':');
    const t = ambito === 'gruppo' ? scelte.tabelle.find((x) => x.id === Number(id)) : undefined;
    return t ? { tabella_id: t.id, nome: t.nome, unita: t.unita } : null;
}

/** La riga della tabella del valore dell'assemblea di un gruppo, ferma: «Box · la tabella del gruppo · 8 unità su 8 · 1.000,00 millesimi». */
export function rigaDellaTabellaDelGruppo(scelte: ScelteDelleAssemblee, gruppo: GruppoDellAssemblea): string {
    const frase = scelte.tabelle.find((t) => t.id === gruppo.tabella_id)?.controlli[`gruppo:${gruppo.tabella_id}`]?.frase ?? `${gruppo.unita} unità`;
    return `${gruppo.nome} · la tabella del gruppo · ${frase}`;
}

/**
 * Il punto di tutto il condominio nell'assemblea dei condòmini di un gruppo (decisione 699 (3)): la frase del rischio, che ferma il punto finché
 * l'amministratore non preme «Mettilo lo stesso». Null per le altre materie.
 */
export function rischioDelPunto(materia: string | null | undefined): string | null {
    const inizio = materia ? MATERIE_DI_TUTTI[materia] : undefined;
    return inizio ? `${inizio} di tutto il condominio: con i soli condòmini del gruppo la delibera è annullabile da chi non è convocato.` : null;
}

/**
 * Le tabelle di un punto nell'assemblea dei condòmini di un gruppo (scena 11 della bozza): quella del gruppo e le tabelle di un gruppo che stanno dentro di
 * lei, con la frase del controllo sul gruppo.
 */
export function tabelleDelGruppo(tabelle: TabellaDelleScelte[], gruppo: number): TabellaDelleScelte[] {
    return tabelle.filter((t) => t.id !== gruppo && t.di_un_gruppo === true && t.controlli[`gruppo:${gruppo}`]?.dentro === true);
}

/**
 * Le tabelle per il menu «Con quale tabella» (scena 2 della bozza delle scene della tappa 2): per una scala o una palazzina in testa «Proposta per la scala B»
 * con la tabella che ha tutte e sole le sue unità, poi le altre del condominio; la frase accanto dice le unità e i millesimi nel perimetro.
 */
export function tabellePerIlPerimetro(tabelle: TabellaDelleScelte[], chiave: string): { proposte: TabellaDelleScelte[]; altre: TabellaDelleScelte[] } {
    if (chiave === 'condominio') {
        return { proposte: [], altre: tabelle };
    }
    const proposte = tabelle.filter((t) => t.controlli[chiave]?.esatta);
    return { proposte, altre: tabelle.filter((t) => !proposte.includes(t)) };
}

/**
 * L'avviso della tabella parziale nella finestra del punto (decisione 640 (2)): la tabella scelta per il punto non comprende tutte le unità del perimetro,
 * e le altre in sala contano come teste a zero millesimi. Solo l'avviso: il conto non cambia. Null se la tabella le comprende tutte.
 */
export function avvisoDellaTabellaParziale(controllo: { con_quota: number; unita: number } | null | undefined): string | null {
    if (!controllo || controllo.unita === 0 || controllo.con_quota >= controllo.unita) return null;
    const altre = controllo.unita - controllo.con_quota;
    const leAltre = altre === 1 ? 'l\'altra conta come testa a zero millesimi' : `le altre ${altre} contano come teste a zero millesimi`;

    return `La tabella ha ${controllo.con_quota} unità su ${controllo.unita}: ${leAltre}.`;
}

/** Le date dell'avviso con il giorno e l'ora uniti, per il controllo. */
export function seduteDaControllare(sedute: SedutaDellaPagina[]) {
    return sedute.map((s) => ({ convocazione: s.convocazione, numero: s.numero, fissata_il: s.giorno && s.ora ? `${s.giorno} ${s.ora}` : s.giorno || null }));
}

/** Le righe del diario raggruppate per giorno, nell'ordine in cui arrivano (dalla più recente). */
export function righePerGiorno(righe: RigaDelDiario[]): { giorno: string; righe: RigaDelDiario[] }[] {
    const gruppi: { giorno: string; righe: RigaDelDiario[] }[] = [];
    for (const r of righe) {
        const ultimo = gruppi[gruppi.length - 1];
        if (ultimo && ultimo.giorno === r.giorno) {
            ultimo.righe.push(r);
        } else {
            gruppi.push({ giorno: r.giorno, righe: [r] });
        }
    }
    return gruppi;
}

/** Un punto della card «Per la prossima assemblea» (`MemoriaDelCondominio::perLaDashboard`). */
export interface PuntoDellaCard {
    testo: string;
    origine: string;
    gruppo: 'di_serie' | 'da_te' | 'dal_programma' | 'dalla_nota';
    chiave_fonte: string;
    memo_id: number | null;
    frase: string | null;
    /** «Apri …» del menu ⋯ (decisione 574): la scheda da cui nasce il punto, dove una pagina c'è. */
    apri?: { voce: string; url: string } | null;
    /** Il memo del punto, per «Modifica» e «Non serve più». */
    memo?: MemoDellaFinestra | null;
}

/** Un memo come lo apre la finestra in modifica (`MemoriaDelCondominio::perLaFinestra`). */
export interface MemoDellaFinestra {
    id: number;
    testo: string;
    nota: string | null;
    assemblea_id: number | null;
    /** `YYYY-MM-DD`, o vuoto senza «Ricordamelo». */
    ricordami_dal: string | null;
    a_chi: string | null;
    assegnato_a: number | null;
    origine_tipo: string | null;
    origine_id: number | null;
}
