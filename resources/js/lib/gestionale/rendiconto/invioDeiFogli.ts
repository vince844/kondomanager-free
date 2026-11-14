/**
 * Le parole dell'invio dei fogli del conguaglio per email: l'ultimo invio di una persona, lo stato di un indirizzo, l'esito di un invio e di un
 * rinvio, il tetto, i fogli in coda da tempo. Le regole stanno nel server (`InvioDeiFogli`): qui c'è quello che serve a dirle. Le date arrivano
 * come `Y-m-d H:i`.
 *
 * Lo stato degli invii (l'ultimo invio di ogni persona della pagina, i non partiti, i fogli in coda da tempo) arriva con la pagina
 * (`invio_fogli`); dopo un invio o un rinvio la scheda lo rilegge dal server ({@link rileggiStato}) e lo tiene qui, legato al condominio,
 * all'esercizio e ai dati della pagina con cui è stato riletto: una pagina nuova (un'altra visita, un altro esercizio) torna ai suoi dati
 * ({@link statoCorrente}). Con una coda vera lo stato non si aggiorna da solo, come le lettere: si rilegge riaprendo la scheda.
 */
import { shallowReactive, toRaw } from 'vue';
import axios from 'axios';
import { quando } from '@/lib/gestionale/lettere/lettere';
import { frasePerErrore } from '@/lib/gestionale/incassi/ricevute';

export type Scelta = 'tutte' | 'a_debito' | 'con_email';

export interface RigaDellInvio { id: number; destinatario: string | null; tipo: string | null; stato: string; quando: string; errore: string | null }

export interface UltimoInvio {
    quando: string;
    stato: 'inviato' | 'in_coda' | 'non_partito' | 'in_parte';
    righe: RigaDellInvio[];
    da_rimandare: boolean;
    /** La riga del PDF partito più recente. */
    pdf: number;
}

export interface PersonaDellAnteprima {
    id: number; nome: string; indirizzi: Array<{ tipo: string; indirizzo: string }>; senza_email: boolean; solo_pec: boolean; ultimo: UltimoInvio | null;
    /** Il suo foglio ha una parte «non disponibile»: parte lo stesso. */
    non_disponibile?: boolean;
}

/** Lo stato degli invii di una scheda, come lo dà il server. */
export interface StatoDegliInvii { ultimi: Record<number, UltimoInvio> | UltimoInvio[]; da_rimandare: number; in_coda_da_tempo?: number }

/** Il testo dell'email del programma, e la frase dei campi: gli stessi del server. */
export const FRASE_CAMPI = 'Nell\'oggetto e nel testo puoi usare [nome], [condominio] ed [esercizio]: si riempiono per ogni persona.';

/** Sotto il testo: che cosa ogni email dice sempre, fuori dal testo che si cambia. */
export function fraseFissa(proposta: string): string {
    return `In fondo a ogni email, sempre: «${proposta}» e la data e l'ora del calcolo delle cifre.`;
}

/** Il rinvio rifà il foglio con le cifre di oggi: i pulsanti lo dicono prima. */
export const CON_LE_CIFRE_DI_OGGI = 'con le cifre di oggi';

/** Quando una risposta non arriva (la rete, un proxy che chiude) o il server cade a metà (il tempo di PHP scaduto): i fogli possono essere partiti. */
export const FRASE_ESITO_INCERTO = 'Non so se i fogli sono partiti: guarda l\'ultimo invio accanto alle persone, o ricarica la scheda, prima di riprovare.';

/**
 * L'errore di un invio o di un rinvio: senza una risposta, o con un errore del server (500 e oltre, che dice «Server Error»), la frase
 * dell'incertezza; un rifiuto del programma (un 422 con il suo perché, la sessione scaduta, il permesso) come lo dice `frasePerErrore`.
 */
export function fraseDellErroreDiInvio(e: unknown): string {
    const stato = (e as { response?: { status?: number } } | null)?.response?.status;

    return stato === undefined || stato >= 500 ? FRASE_ESITO_INCERTO : frasePerErrore(e, FRASE_ESITO_INCERTO);
}

export const FRASE_CONSERVATO = 'Il programma conserva il PDF di ogni foglio partito, con la sua impronta: si riapre accanto alla persona.';

/** Dopo un'azione riuscita la finestra spegne il pulsante: un secondo clic non manda di nuovo gli stessi fogli. */
export const FRASE_FATTO = 'Fatto. Per un altro invio rifai l\'anteprima.';

/** Un errore della posta dentro una frase: senza il punto in fondo, con la minuscola. */
const dentroLaFrase = (errore: string): string => {
    const t = errore.trim().replace(/\.$/, '');

    return t.charAt(0).toLowerCase() + t.slice(1);
};

/** Lo stato di un indirizzo dell'ultimo invio, in parole. */
export function testoRigaDellInvio(r: RigaDellInvio): string {
    switch (r.stato) {
        case 'inviata':
            return `inviato${r.destinatario ? ` a ${r.destinatario}` : ''} il ${quando(r.quando)}`;
        case 'in_coda':
            return `in coda${r.destinatario ? `, per ${r.destinatario}` : ''}`;
        default:
            return `non partito${r.destinatario ? `, per ${r.destinatario}` : ''}${r.errore ? `: ${dentroLaFrase(r.errore)}` : ''}`;
    }
}

/**
 * L'ultimo invio di una persona, accanto al suo nome: «Foglio inviato il 13/11/2026 alle 23:10, consegna non provata.», «in coda», «non partito:
 * …» con l'errore in chiaro, e per un invio in parte lo stato di ogni indirizzo. Per l'email il programma sa solo che il server l'ha accettata: mai
 * «consegnato» né «ricevuto».
 */
export function testoUltimoInvio(u: UltimoInvio | null | undefined): string | null {
    if (!u) {
        return null;
    }
    switch (u.stato) {
        case 'inviato':
            return `Foglio inviato il ${quando(u.quando)}, consegna non provata.`;
        case 'in_coda':
            return `Foglio in coda dal ${quando(u.quando)}.`;
        case 'non_partito': {
            const errore = u.righe.find((r) => r.errore)?.errore;

            return `Foglio non partito il ${quando(u.quando)}${errore ? `: ${dentroLaFrase(errore)}` : ''}.`;
        }
        default:
            return `Foglio inviato in parte: ${u.righe.map(testoRigaDellInvio).join('; ')}.`;
    }
}

/** Quante email, quante persone. */
export const email = (n: number): string => (n === 1 ? '1 email' : `${n} email`);
export const persone = (n: number): string => (n === 1 ? '1 persona' : `${n} persone`);

/**
 * Le persone che partono con questo clic, a persone intere, la stessa regola del server (`InviiPerPersona::dentroIDueTetti`): prima i messaggi
 * per volta senza il servizio della coda, poi i fogli per volta. Si contano sulle persone rimaste nell'invio, nell'ordine dell'anteprima.
 */
export function partonoOra(gruppi: Array<{ messaggi: number }>, tettoMessaggi: number | null, tettoFogli: number): { persone: number; messaggi: number } {
    let persone = 0;
    let messaggi = 0;
    for (const g of gruppi) {
        if (persone > 0 && ((tettoMessaggi !== null && messaggi + g.messaggi > tettoMessaggi) || persone + 1 > tettoFogli)) {
            break;
        }
        persone++;
        messaggi += g.messaggi;
    }

    return { persone, messaggi };
}

/**
 * Il tetto, prima di partire: quanti messaggi partono con questo clic, e che cosa fare per gli altri. Senza il servizio della coda le email partono
 * mentre la pagina aspetta, al massimo N per volta; con qualunque coda al massimo N fogli per volta (ogni foglio è un PDF fatto subito).
 */
export function testoTetto(a: { messaggi: number; partono: { persone: number; messaggi: number }; tetto_messaggi: number | null; tetto_fogli: number }): string | null {
    if (a.partono.messaggi >= a.messaggi) {
        return null;
    }
    // Il tetto dei fogli si raggiunge solo con le persone piene; altrimenti, con il tetto delle email, è quello.
    const perche = a.tetto_messaggi !== null && a.partono.persone < a.tetto_fogli
        ? `senza il servizio della coda partono al massimo ${a.tetto_messaggi} email per volta`
        : `partono al massimo ${a.tetto_fogli} fogli per volta`;

    return `Con questo clic partono ${email(a.partono.messaggi)} a ${persone(a.partono.persone)}: ${perche}, a persone intere. Per le altre premi «Invia le altre».`;
}

/** Dopo un invio: quante email partite, in coda, non partite; gli errori in chiaro; chi resta senza indirizzo; quante restano da mandare. */
export function testoEsito(r: {
    inviate: number; in_coda: number; non_partite: number; errori?: string[]; senza_indirizzo?: string[]; restanti?: { persone: number[] | number } | null;
    aggiunte?: number; uscite?: number; fogli?: number;
}): string[] {
    const parti = [
        r.inviate > 0 ? `partite ${r.inviate}` : null,
        r.in_coda > 0 ? `in coda ${r.in_coda}` : null,
        r.non_partite > 0 ? `non partite ${r.non_partite}` : null,
    ].filter((p): p is string => p !== null);
    const riepilogo = parti.length ? `Email ${parti.join(', ')}.` : 'Nessuna email partita.';
    const senza = r.senza_indirizzo?.length ? `Senza un indirizzo fra quelli scelti: ${r.senza_indirizzo.join(', ')}. Scarica il loro foglio.` : null;
    const quanteRestano = r.restanti ? (Array.isArray(r.restanti.persone) ? r.restanti.persone.length : r.restanti.persone) : 0;
    const resto = quanteRestano > 0 ? `Restano ${persone(quanteRestano)}: premi «Invia le altre».` : null;
    const a = r.aggiunte ?? 0;
    const u = r.uscite ?? 0;
    const aggiunte = a === 0 ? null : a === 1 ? '1 persona è entrata nella scelta dopo l\'anteprima: non ha avuto il foglio. Rifai l\'anteprima.'
        : `${a} persone sono entrate nella scelta dopo l'anteprima: non hanno avuto il foglio. Rifai l'anteprima.`;
    const uscite = u === 0 ? null : u === 1 ? '1 persona dell\'anteprima non è più nella scelta: non ha avuto il foglio.'
        : `${u} persone dell'anteprima non sono più nella scelta: non hanno avuto il foglio.`;

    return [riepilogo, ...(r.errori ?? []), senza, aggiunte, uscite, resto].filter((p): p is string => !!p);
}

/**
 * Dopo un rinvio: quanti fogli rifatti con le cifre di oggi e come sono partiti, gli errori in chiaro, chi non ha più l'indirizzo, quante persone
 * restano oltre il tetto.
 */
export function testoDelRinvio(r: {
    fogli: number; inviate: number; in_coda: number; non_partite: number; errori?: string[]; senza_indirizzo?: string[]; restanti?: { persone: number } | null;
}): string[] {
    const fogli = r.fogli === 0 ? 'Nessun foglio rifatto.' : r.fogli === 1 ? '1 foglio rifatto con le cifre di oggi.' : `${r.fogli} fogli rifatti con le cifre di oggi.`;
    const resto = r.restanti && r.restanti.persone > 0 ? `Restano ${persone(r.restanti.persone)} da rimandare: premi di nuovo.` : null;

    return [fogli, ...(r.fogli > 0 ? testoEsito({ ...r, senza_indirizzo: [], restanti: null }) : []), ...(r.senza_indirizzo ?? []), resto].filter((p): p is string => !!p);
}

/** I fogli in coda da più di un'ora: la frase delle lettere, con che cosa fare. */
export function testoInCodaDaTempo(n: number): string | null {
    if (n <= 0) {
        return null;
    }

    return `${n === 1 ? '1 foglio per email è in coda da più di un\'ora: non è ancora partito.' : `${n} fogli per email sono in coda da più di un'ora: non sono ancora partiti.`} `
        + 'Di solito vuol dire che sul server non gira il servizio che manda le email in coda: chi gestisce l\'installazione controlla che sia attivo '
        + '(SCHEDULE_QUEUE_WORKER nel file .env, o un processo queue:work).';
}

/** La chiave dello stato riletto: il condominio e l'esercizio della scheda. */
export const chiaveDegliInvii = (condominio: number, esercizio: number): string => `${condominio}-${esercizio}`;

/**
 * Lo stato riletto dal server dopo un invio o un rinvio, con la chiave della scheda e i dati della pagina con cui è stato riletto (`base`, l'oggetto
 * `invio_fogli` che la pagina aveva): vale solo finché la pagina è quella.
 */
const riletto = shallowReactive<{ chiave: string | null; base: unknown; stato: StatoDegliInvii | null }>({ chiave: null, base: null, stato: null });

/**
 * Lo stato da mostrare: quello riletto, se è di questa scheda e di questi dati della pagina; altrimenti null (valgono quelli della pagina). I dati
 * della pagina si confrontano senza i proxy di Vue: Inertia ne dà un oggetto nuovo a ogni visita e a ogni ricarica.
 */
export function statoCorrente(chiave: string, base: unknown): StatoDegliInvii | null {
    return riletto.chiave === chiave && toRaw(riletto.base) === toRaw(base) ? riletto.stato : null;
}

/** L'ultimo invio di una persona da mostrare: lo stato riletto o quello della pagina. */
export function ultimoDi(chiave: string, base: { ultimi?: Record<number, UltimoInvio> | UltimoInvio[] } | null | undefined, persona: number): UltimoInvio | null {
    const ultimi = statoCorrente(chiave, base)?.ultimi ?? base?.ultimi;

    return ultimi && !Array.isArray(ultimi) ? (ultimi[persona] ?? null) : null;
}

/** Rilegge dal server lo stato degli invii delle persone della pagina, e lo tiene per questa scheda e per questi dati della pagina. */
export async function rileggiStato(url: string, persone: number[], chiave: string, base: unknown): Promise<StatoDegliInvii> {
    const stato = (await axios.get(url, { params: { persone } })).data as StatoDegliInvii;
    riletto.chiave = chiave;
    riletto.base = base;
    riletto.stato = stato;

    return stato;
}

/** Per le prove: lo stato riletto dimenticato. */
export function dimenticaStato(): void {
    riletto.chiave = null;
    riletto.base = null;
    riletto.stato = null;
}
