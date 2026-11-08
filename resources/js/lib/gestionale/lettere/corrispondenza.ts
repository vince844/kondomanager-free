/**
 * La scheda «Corrispondenza» dell'estratto conto di una persona (Coda 533, decisione 231): le lettere e gli altri documenti che il programma
 * le ha emesso in quel condominio (ricevute, avvisi di compensazione, la scelta «non serve comunicarla»), insieme, dalla più recente; i filtri
 * per tipo e per periodo, che non si ricordano (decisione 223); dieci per pagina.
 *
 * Il numero nel nome della scheda si calcola con la stessa funzione dell'elenco ({@link numeroDellaScheda} chiama
 * {@link vociDellaCorrispondenza}): i due non possono divergere. I dati arrivano dal server (`RegistroLettere::perPersona` per le lettere,
 * `CorrispondenzaDellaPersona::documenti` per il resto) con il giorno e l'ora dell'amministratore: qui si ordinano, si filtrano, si dividono
 * in pagine e si scrivono. Le cifre sono in centesimi.
 */
import { euroTesto, giorno } from '@/lib/gestionale/debiti/rateScadute';
import { NOMI_TIPI, quando, testoRigaDelRegistro, type TipoLettera } from '@/lib/gestionale/lettere/lettere';

export const PER_PAGINA = 10;

export const FRASE_NIENTE = 'Nessuna lettera, ricevuta o avviso a questa persona in questo condominio.';
export const FRASE_NIENTE_COI_FILTRI = 'Nessun documento con questi filtri.';
export const FRASE_DATE_ROVESCIATE = '«Dal» è dopo «al»: nessun giorno è in mezzo.';

/** Una riga del registro di una ricevuta o di un avviso: la stampa, o un indirizzo se è andato per email. */
export interface RigaDelDocumento { id: number; canale: string; destinatario: string | null; stato: string; quando: string; errore: string | null }

/** Una ricevuta, un avviso di compensazione o una scelta «non serve comunicarla», come la porta il server. */
export interface DocumentoInviato {
    chiave: string; id: number; quando: string; tipo: string; non_da_comunicare: boolean; emessa_il: string; ora: string; emesso_da: string | null;
    numero: string | number | null; versamento_del: string | null; importo: number | null; annullato: boolean; copia_del: string | null;
    /** L'incasso, dove si ristampa e si rimanda; nullo se l'incasso non c'è più. */
    incasso_id: number | null;
    /** Di una ricevuta mandata a chi ha versato per un altro, la persona per cui ha versato. */
    per_conto_di: string | null;
    righe: RigaDelDocumento[];
}

/** Di una lettera, quello che serve alla scheda per metterla in fila: il resto lo mostra il registro delle lettere. */
export interface LetteraPerLaScheda { lettera: string; tipo: string; emessa_il: string; ora: string; righe: Array<{ id: number }> }

export type VoceCorrispondenza<L extends LetteraPerLaScheda = LetteraPerLaScheda> =
    | { genere: 'lettera'; chiave: string; tipo: string; emessa_il: string; ora: string; id: number; lettera: L }
    | { genere: 'documento'; chiave: string; tipo: string; emessa_il: string; ora: string; id: number; documento: DocumentoInviato };

const TIPI_LETTERE: TipoLettera[] = ['promemoria', 'sollecito', 'diffida', 'messa_in_mora'];

/** Le scelte del filtro per tipo: «Tutto» è il valore vuoto. */
export const TIPI_DEI_FILTRI: Array<{ valore: string; nome: string }> = [
    { valore: '', nome: 'Tutto' },
    { valore: 'lettere', nome: 'Lettere' },
    ...TIPI_LETTERE.map((t) => ({ valore: t, nome: NOMI_TIPI[t] })),
    { valore: 'ricevuta', nome: 'Ricevute' },
    { valore: 'avviso_compensazione', nome: 'Avvisi di compensazione' },
];

export interface FiltriCorrispondenza { tipo: string; dal: string; al: string }

/**
 * Lettere e documenti in un elenco solo, dalla più recente: per giorno e ora di emissione (dell'amministratore), poi per id. Le righe di
 * lettere e documenti stanno nella stessa tabella del server, quindi a pari minuto l'id più alto è il più recente.
 */
export function vociDellaCorrispondenza<L extends LetteraPerLaScheda>(lettere: L[], documenti: DocumentoInviato[]): VoceCorrispondenza<L>[] {
    const voci: VoceCorrispondenza<L>[] = [
        ...lettere.map((l) => ({ genere: 'lettera' as const, chiave: `lettera-${l.lettera}`, tipo: l.tipo, emessa_il: l.emessa_il, ora: l.ora,
            id: Math.max(0, ...l.righe.map((r) => r.id)), lettera: l })),
        ...documenti.map((d) => ({ genere: 'documento' as const, chiave: d.chiave, tipo: d.tipo, emessa_il: d.emessa_il, ora: d.ora, id: d.id, documento: d })),
    ];

    return voci.sort((a, b) => `${b.emessa_il} ${b.ora}`.localeCompare(`${a.emessa_il} ${a.ora}`) || b.id - a.id);
}

/** Il numero nel nome della scheda: le voci dell'elenco, senza filtri. */
export function numeroDellaScheda(lettere: LetteraPerLaScheda[], documenti: DocumentoInviato[]): number {
    return vociDellaCorrispondenza(lettere, documenti).length;
}

/** Il filtro per tipo («lettere» vuol dire i quattro tipi) e per periodo, sul giorno di emissione, con gli estremi compresi. */
export function filtraCorrispondenza<V extends { genere: string; tipo: string; emessa_il: string }>(voci: V[], f: FiltriCorrispondenza): V[] {
    return voci.filter((v) => (f.tipo === '' || (f.tipo === 'lettere' ? v.genere === 'lettera' : v.tipo === f.tipo))
        && (!f.dal || v.emessa_il >= f.dal) && (!f.al || v.emessa_il <= f.al));
}

/** La frase quando i filtri non trovano niente: con «dal» dopo «al» dice perché. */
export function fraseDelVuoto(f: FiltriCorrispondenza): string {
    return f.dal && f.al && f.dal > f.al ? FRASE_DATE_ROVESCIATE : FRASE_NIENTE_COI_FILTRI;
}

/** Una pagina dell'elenco; una pagina che non c'è più (dopo un filtro o un ricaricamento) diventa l'ultima che c'è. */
export function paginaDi<V>(voci: V[], pagina: number, perPagina: number = PER_PAGINA): { voci: V[]; pagina: number; pagine: number } {
    const pagine = Math.max(1, Math.ceil(voci.length / perPagina));
    const p = Math.min(Math.max(1, Math.floor(pagina) || 1), pagine);

    return { voci: voci.slice((p - 1) * perPagina, p * perPagina), pagina: p, pagine };
}

const NOMI_DOCUMENTI: Record<string, string> = { ricevuta: 'Ricevuta', avviso_compensazione: 'Avviso di compensazione' };

const numeroDi = (d: DocumentoInviato) => (d.numero !== null && d.numero !== '' ? ` n. ${d.numero}` : '');

/**
 * La prima riga di un documento: che cosa, quando, chi. Il numero della ricevuta è il protocollo dell'incasso; l'avviso di compensazione non ha un
 * numero suo (il suo PDF dice quello della registrazione), e il numero dell'incasso sta nelle cifre.
 */
export function testoDocumento(d: DocumentoInviato): string {
    const quandoDa = `${giorno(d.emessa_il)} alle ${d.ora}${d.emesso_da ? `, da ${d.emesso_da}` : ''}`;
    if (d.non_da_comunicare) {
        return `Incasso${numeroDi(d)} annullato: «non serve comunicarlo», segnato il ${quandoDa}`;
    }
    if (d.tipo === 'avviso_compensazione') {
        return `${NOMI_DOCUMENTI[d.tipo]} del ${quandoDa}`;
    }

    return `${NOMI_DOCUMENTI[d.tipo] ?? d.tipo}${numeroDi(d)} del ${quandoDa}`;
}

/** Le cifre del documento com'era quando è stato emesso: il versamento della ricevuta, la parte che il credito ha coperto per l'avviso. */
export function testoCifreDelDocumento(d: DocumentoInviato): string {
    if (d.non_da_comunicare) {
        return '';
    }
    if (d.tipo === 'avviso_compensazione') {
        const del = d.versamento_del ? ` del ${giorno(d.versamento_del)}` : '';

        return d.importo !== null ? `Incasso${numeroDi(d)}${del}: ${euroTesto(d.importo)} coperti dal credito.` : '';
    }
    const parti = [
        d.versamento_del || d.importo !== null
            ? `Versamento${d.versamento_del ? ` del ${giorno(d.versamento_del)}` : ''}${d.importo !== null ? `, ${euroTesto(d.importo)}` : ''}`
                + (d.per_conto_di ? `, per conto di ${d.per_conto_di}` : '')
            : '',
        d.annullato ? 'incasso annullato' : '',
        d.copia_del ? `copia della ricevuta del ${giorno(d.copia_del)}` : '',
    ].filter(Boolean);

    return parti.length ? `${parti.join('; ')}.` : '';
}

/** Lo stato di una riga di una ricevuta o di un avviso: come quelle delle lettere, ma una stampa non ha una spedizione da registrare. */
export function testoRigaDelDocumento(r: RigaDelDocumento): string {
    if (r.stato === 'stampata') {
        return `Stampata il ${quando(r.quando)}`;
    }
    if (r.stato === 'non_da_comunicare') {
        return 'Nessun invio, per scelta dell\'amministratore';
    }

    return testoRigaDelRegistro(r);
}
