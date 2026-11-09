/**
 * Le parole delle lettere ai condòmini (promemoria, sollecito, diffida, messa in mora): i tipi, i canali, l'ultima lettera di una persona,
 * gli stati del registro, le frasi della finestra. Le regole stanno nel server (`EmissioneLettere`, `RegistroLettere`): qui c'è quello che
 * serve a mostrarle. Le date arrivano dal server come `Y-m-d` (o `Y-m-d H:i`): qui si formattano e basta. Le cifre sono in centesimi.
 */
import { euroTesto, giorno } from '@/lib/gestionale/debiti/rateScadute';

export type TipoLettera = 'promemoria' | 'sollecito' | 'diffida' | 'messa_in_mora';
export type CanaleLettera = 'email' | 'stampa' | 'zip';

export const NOMI_TIPI: Record<TipoLettera, string> = { promemoria: 'Promemoria', sollecito: 'Sollecito', diffida: 'Diffida', messa_in_mora: 'Messa in mora' };

/** Il genere di ogni tipo, per accordare le parole («stampato», «stampata»). */
const FEMMINILE: Record<string, boolean> = { promemoria: false, sollecito: false, diffida: true, messa_in_mora: true };

export const NOMI_CANALI: Record<CanaleLettera, string> = { email: 'Email', stampa: 'Stampa per la posta', zip: 'PDF per persona (.zip)' };

export const CANALI_SPEDIZIONE: Record<string, string> = {
    raccomandata: 'Raccomandata A/R', pec: 'PEC mandata fuori dal programma', a_mano: 'A mano', posta: 'Posta ordinaria', altro: 'Altro',
};

/** Diffida e messa in mora non partono per email dal programma (la stessa frase del server). */
export const SOLO_CARTA = 'Diffida e messa in mora non partono per email dal programma: stampale o scarica un PDF per persona, spediscile, poi registra la spedizione.';

export const FRASE_CAMPI = 'Nel testo puoi usare [nome], [totale], [termine] e [condominio]: si riempiono per ogni persona.';

export const FRASE_SENZA_TESTO = 'Una diffida o una messa in mora non parte senza testo: scrivi il tuo, o parti da quello del programma o da un modello.';

/** Per diffida e messa in mora con il testo di partenza del programma: l'amministratore lo controlla prima di mandarlo. */
export const FRASE_TESTO_DEL_PROGRAMMA = 'È il testo di partenza del programma: neutro, senza articoli di legge, interessi o spese; diffida e messa in mora '
    + 'differiscono solo nel tono. Controllalo, e cambialo se serve, prima di mandarlo.';

/** Il giorno dopo una data `Y-m-d`: la data del termine si sceglie da domani (il server rifiuta oggi). */
export function domani(oggi: string): string {
    const [a, m, g] = oggi.split('-').map(Number);
    const d = new Date(Date.UTC(a, m - 1, g + 1));
    const due = (n: number) => String(n).padStart(2, '0');

    // Il calendario della data, letto in UTC come è stato costruito: non il giorno di oggi del browser.
    return `${d.getUTCFullYear()}-${due(d.getUTCMonth() + 1)}-${due(d.getUTCDate())}`;
}

/** Le forme del termine per il versamento, come le dice la finestra. */
export type FormaDelTermine = 'giorni' | 'data' | 'nessuno';

/** Le rate di un accordo con «fino al» passato, nella lettera di una persona. */
export function testoAccordoPassato(a: { fino_al: string; importo: number } | null | undefined): string | null {
    return a ? `Accordo di rientro fino al ${giorno(a.fino_al)} (data passata): ${euroTesto(a.importo)} delle rate dell'accordo sono nella lettera.` : null;
}

/** In testa all'anteprima: chi ha le rate di un accordo con «fino al» passato nella lettera. */
export function testoAccordiPassati(nomi: string[]): string | null {
    return nomi.length ? `Le rate degli accordi con «fino al» passato sono nella lettera: ${nomi.join(', ')}. Togli chi non deve riceverla.` : null;
}

/** Senza il nome dello studio e con la carta a campi: la lettera porta il solo condominio. */
export const FRASE_STUDIO_NON_COMPILATO = 'I dati dello studio non sono compilati: la lettera porta il solo condominio. Li scrivi nelle impostazioni di stampa.';

/** Senza il nome dello studio e con una carta intestata che si legge per le lettere: lo studio lo porta la carta. */
export const FRASE_STUDIO_SULLA_CARTA = 'La lettera esce sulla carta intestata, che porta lo studio. Il nome dello studio nelle impostazioni di stampa è vuoto: '
    + 'se la carta non si legge, la lettera esce con il solo condominio.';

/** La frase dello studio nella finestra delle lettere: nessuna con il nome dello studio; senza, quella della carta delle lettere (`carta_intestata` dal server). */
export function fraseDelloStudio(o: { studio_compilato: boolean; carta_intestata?: boolean }): string | null {
    if (o.studio_compilato) return null;

    return o.carta_intestata ? FRASE_STUDIO_SULLA_CARTA : FRASE_STUDIO_NON_COMPILATO;
}

export const FRASE_SENZA_IBAN = 'Il condominio non ha un conto con l\'IBAN: la lettera non porta le coordinate del bonifico. Aggiungilo alla cassa di tipo banca.';

export const FRASE_TESTO_SINGOLO = 'Il testo che scrivi qui vale solo per questa lettera: non cambia i modelli.';

export const FRASE_CONSERVATO = 'Del sollecito, della diffida e della messa in mora il programma conserva il PDF come è stato emesso, con la sua impronta.';

/** Un credito accanto: compensare è una scelta dell'amministratore, la lettera non lo dice. */
export function testoCredito(c: { nome: string; credito: number }): string {
    return `${c.nome} ha un credito di ${euroTesto(c.credito)}: puoi usarlo prima di scrivere.`;
}

const participio = (tipo: string, maschile: string): string => (FEMMINILE[tipo] ? `${maschile.slice(0, -1)}a` : maschile);

/** «05/11/2026 10:30» → «05/11/2026 alle 10:30». */
export function quando(valore: string | null | undefined): string {
    if (!valore) {
        return '';
    }
    const [data, ora] = valore.split(' ');

    return `${giorno(data)}${ora ? ` alle ${ora}` : ''}`;
}

export interface SpedizioneLettera { canale?: string; data?: string; numero?: string; nota?: string }

/**
 * Come è stata spedita: «spedita il … con raccomandata A/R n. 1234», «data a mano il …», «mandata per PEC il …, estremi …». Con il tipo della
 * lettera davanti il participio si accorda con lui («Sollecito spedito»); senza, è la lettera (al femminile).
 */
export function testoSpedizione(s: SpedizioneLettera | null | undefined, tipo?: string): string {
    if (!s) {
        return '';
    }
    const p = (femminile: string) => (tipo === undefined ? femminile : participio(tipo, `${femminile.slice(0, -1)}o`));
    const numero = s.numero ? ` n. ${s.numero}` : '';
    switch (s.canale) {
        case 'raccomandata':
            return `${p('spedita')} il ${giorno(s.data)} con raccomandata A/R${numero}`;
        case 'a_mano':
            return `${p('data')} a mano il ${giorno(s.data)}`;
        case 'pec':
            return `${p('mandata')} per PEC il ${giorno(s.data)}${s.numero ? `, estremi ${s.numero}` : ''}`;
        case 'posta':
            return `${p('spedita')} il ${giorno(s.data)} per posta ordinaria`;
        default:
            return `${p('spedita')} il ${giorno(s.data)}${numero}`;
    }
}

export interface UltimaLettera {
    tipo: string; data: string; canale: string; stato: string; destinatari?: string[]; spedizione?: SpedizioneLettera | null;
}

/**
 * L'ultima lettera di una persona, nell'elenco: il tipo, come e quando. Per l'email «consegna non provata»: il server di posta l'ha accettata,
 * che sia arrivata il programma non lo sa. Mai «consegnata», «ricevuta», «notificata».
 */
export function testoUltimaLettera(l: UltimaLettera | null | undefined): string {
    if (!l) {
        return 'Nessuna lettera';
    }
    const nome = NOMI_TIPI[l.tipo as TipoLettera] ?? l.tipo;
    if (l.stato === 'spedita') {
        return `${nome} ${testoSpedizione(l.spedizione ?? { data: l.data }, l.tipo)}`;
    }
    if (l.stato === 'inviata') {
        const a = l.destinatari?.length ? ` a ${l.destinatari.join(', ')}` : '';

        return `${nome} ${participio(l.tipo, 'inviato')} il ${giorno(l.data)}${a}, consegna non provata`;
    }
    // Solo stampata o scaricata: non conta per «Mai scritto» né per «Nessuna lettera negli ultimi N giorni» finché la spedizione non è registrata.
    if (l.stato === 'stampata') {
        return l.canale === 'zip'
            ? `${nome} ${participio(l.tipo, 'scaricato')} il ${giorno(l.data)} in un PDF per persona; spedizione non registrata`
            : `${nome} ${participio(l.tipo, 'stampato')} il ${giorno(l.data)}; spedizione non registrata`;
    }
    if (l.stato === 'in_coda') {
        return `${nome} in coda dal ${giorno(l.data)}`;
    }

    return `${nome} non ${participio(l.tipo, 'partito')} il ${giorno(l.data)}`;
}

/** Lo stato di una riga del registro (un indirizzo, o la stampa), in parole. */
export function testoRigaDelRegistro(r: { canale: string; formato?: string | null; destinatario: string | null; stato: string; quando: string; errore: string | null;
    spedizione?: SpedizioneLettera | null }): string {
    switch (r.stato) {
        case 'inviata':
            return `Inviata il ${quando(r.quando)}${r.destinatario ? ` a ${r.destinatario}` : ''}, consegna non provata`;
        case 'in_coda':
            return `In coda dal ${quando(r.quando)}${r.destinatario ? `, per ${r.destinatario}` : ''}`;
        case 'non_partita':
            return `Non partita il ${quando(r.quando)}${r.destinatario ? `, per ${r.destinatario}` : ''}${r.errore ? `: ${r.errore}` : ''}`;
        case 'spedita':
            return `Stampata il ${quando(r.quando)}; ${testoSpedizione(r.spedizione)}`;
        case 'stampata':
            return r.formato === 'zip' ? `Scaricata il ${quando(r.quando)} in un PDF per persona; spedizione non registrata` : `Stampata il ${quando(r.quando)}; spedizione non registrata`;
        default:
            return r.stato;
    }
}

/** Il totale della lettera e il residuo di oggi delle stesse rate. */
export function testoTotaleEResiduo(l: { totale: number; residuo_oggi: number; alla: string | null }): string {
    const oggi = l.residuo_oggi === l.totale ? 'oggi lo stesso' : `oggi ${euroTesto(l.residuo_oggi)}`;

    return `Nella lettera ${euroTesto(l.totale)}${l.alla ? ` (situazione al ${giorno(l.alla)})` : ''}; sulle stesse rate ${oggi}.`;
}

/** Dopo un invio: le persone entrate nell'elenco dopo l'anteprima, che non hanno avuto la lettera. */
export function testoAggiunte(n: number): string | null {
    if (n <= 0) {
        return null;
    }

    return n === 1
        ? '1 persona è entrata nell\'elenco dopo l\'anteprima: non ha avuto la lettera. Rifai l\'anteprima per scriverle.'
        : `${n} persone sono entrate nell'elenco dopo l'anteprima: non hanno avuto la lettera. Rifai l'anteprima per scrivere anche a loro.`;
}

/** Dopo un invio: le persone viste nell'anteprima che adesso non hanno più niente da scrivere. */
export function testoUscite(n: number): string | null {
    if (n <= 0) {
        return null;
    }

    return n === 1
        ? '1 persona vista nell\'anteprima adesso non ha più rate da mettere nella lettera: non l\'ha avuta.'
        : `${n} persone viste nell'anteprima adesso non hanno più rate da mettere nella lettera: non l'hanno avuta.`;
}

/** I conteggi dell'anteprima per canale, in una riga. */
export function testoConteggi(c: Record<CanaleLettera, number>): string {
    const parti = [
        c.email > 0 ? `${c.email} per email` : null,
        c.stampa > 0 ? `${c.stampa} da stampare` : null,
        c.zip > 0 ? `${c.zip} in un PDF per persona` : null,
    ].filter(Boolean);

    return parti.length ? `Lettere: ${parti.join(', ')}.` : 'Nessuna lettera.';
}

/**
 * «Riprova le non partite»: quante lettere rifatte (una lettera a due indirizzi è una), quante non servono più perché non c'è più niente da
 * scrivere (non che la persona non debba più niente: un promemoria la cui rata è scaduta non serve più, e per quella persona l'amministratore prepara un
 * sollecito dall'elenco: il programma non lo fa da solo),
 * e quante restano quando ne partono al massimo N messaggi per volta.
 */
export function testoRiprova(r: { messaggi: number; lettere?: number; saldate: number; promemoria_scaduti?: number; tetto_messaggi?: number | null;
    restanti?: { lettere: number; messaggi: number } | null; conti_cambiati?: ContoCambiato[]; termine_passato?: number }): string {
    const n = r.lettere ?? r.messaggi;
    const rifatte = n === 1 ? '1 lettera rifatta con le cifre di oggi' : `${n} lettere rifatte con le cifre di oggi`;
    const promemoria = Math.min(r.promemoria_scaduti ?? 0, r.saldate);
    const altre = r.saldate - promemoria;
    const parti = [
        altre > 0 ? (altre === 1 ? '1 non serve più: in quella lettera non c\'è più niente da scrivere' : `${altre} non servono più: in quelle lettere non c'è più niente da scrivere`) : null,
        promemoria > 0 ? (promemoria === 1
            ? '1 promemoria non serve più: le rate sono scadute. Per quella persona prepara un sollecito dall\'elenco'
            : `${promemoria} promemoria non servono più: le rate sono scadute. Per quelle persone prepara un sollecito dall'elenco`) : null,
    ].filter(Boolean);
    const resto = r.restanti && r.restanti.lettere > 0
        ? (r.restanti.lettere === 1
            ? ` Ne resta 1 da rifare: ne partono al massimo ${r.tetto_messaggi ?? r.messaggi} messaggi per volta, premi di nuovo per rifarla.`
            : ` Ne restano ${r.restanti.lettere} da rifare: ne partono al massimo ${r.tetto_messaggi ?? r.messaggi} messaggi per volta, premi di nuovo per rifarle.`)
        : '';

    const conti = testoContiCambiati(r.conti_cambiati ?? []);
    const passate = r.termine_passato ?? 0;
    const termine = passate === 0 ? ''
        : passate === 1 ? ' 1 lettera non si rifà: la data del termine è passata. Preparane una nuova dall\'elenco, con un termine nuovo.'
            : ` ${passate} lettere non si rifanno: la data del termine è passata. Preparane di nuove dall'elenco, con un termine nuovo.`;

    return `${rifatte}${parti.length ? `; ${parti.join('; ')}` : ''}.${resto}${termine}${conti ? ` ${conti}` : ''}`;
}

/** Una lettera rifatta senza il conto di prima: quello di prima, e quello di oggi (null: senza l'IBAN). */
export interface ContoCambiato { prima: string; ora: string | null }

/**
 * Le lettere rifatte senza il conto di prima, che oggi non è più attivo o non ha più l'IBAN: con il conto solo di oggi, o senza l'IBAN quando
 * oggi il condominio non ne ha uno solo (il programma non sceglie fra più conti).
 */
export function testoContiCambiati(c: ContoCambiato[]): string | null {
    if (c.length === 0) {
        return null;
    }
    const gruppi = new Map<string, { prima: string; ora: string | null; n: number }>();
    c.forEach((x) => {
        const k = `${x.prima}|${x.ora ?? ''}`;
        gruppi.set(k, { ...x, n: (gruppi.get(k)?.n ?? 0) + 1 });
    });

    return [...gruppi.values()].map((g) => {
        const quante = g.n === 1 ? 'Rifatta' : `${g.n} lettere rifatte`;

        return g.ora
            ? `${quante} con il conto «${g.ora}»: il conto «${g.prima}» non è più attivo o non ha più l'IBAN.`
            : `${quante} senza l'IBAN: il conto «${g.prima}» non è più attivo o non ha più l'IBAN, e oggi il condominio non ne ha uno solo.`;
    }).join(' ');
}

/** Le lettere oltre il tetto per volta, dopo un invio: quante restano, e come mandarle. */
export function testoRestanti(n: number): string | null {
    return n > 0 ? (n === 1 ? 'Ne resta 1 da mandare: premi «Invia le altre».' : `Ne restano ${n} da mandare: premi «Invia le altre».`) : null;
}

/** Chi ha un credito che copre tutta la lettera: il credito accanto è almeno il totale. */
export function coperteDalCredito(persone: Array<{ id: number; totale: number; credito: number }>): number[] {
    return persone.filter((p) => p.credito > 0 && p.credito >= p.totale).map((p) => p.id);
}

/** Dopo un'azione riuscita la finestra spegne i pulsanti: un secondo clic non manda di nuovo le stesse lettere. */
export const FRASE_FATTO = 'Fatto. Per un altro invio rifai l\'anteprima.';

/**
 * Il tetto di un PDF (la stessa frase del server): conta chi si stampa e chi si scarica in un PDF per persona, non chi va per email; oltre,
 * dice come fare il resto.
 */
export function testoOltreTetto(c: Record<CanaleLettera, number>, tetto: number): string | null {
    const [quali, n, dove] = c.stampa > tetto ? ['da stampare', c.stampa, 'in un PDF'] : c.zip > tetto ? ['da scaricare', c.zip, 'in un .zip'] : [null, 0, ''];
    if (quali === null) {
        return null;
    }

    return `Le lettere ${quali} sono ${n}: ${dove} ne stanno al massimo ${tetto}. `
        + 'Scegli le persone di una pagina dell\'elenco e prepara le loro, poi passa alla pagina dopo: stampare o scaricare le lettere non cambia l\'ordine dell\'elenco. '
        + 'Se registri la spedizione e l\'elenco ha il filtro «Mai scritto» o «Nessuna lettera negli ultimi N giorni», chi l\'ha avuta esce dall\'elenco: '
        + 'le altre persone ricominciano dalla pagina 1.';
}

/** La gestione scelta nell'elenco, nell'anteprima delle lettere. */
export function testoGestione(g: { id: number; nome: string } | null | undefined): string | null {
    return g ? `Solo la gestione «${g.nome}»: le rate delle altre gestioni non sono nelle lettere.` : null;
}

export interface PersonaDellAnteprima {
    id: number; nome: string; totale: number; righe: number; credito: number; canale: CanaleLettera; canali: CanaleLettera[]; in_accordo?: number;
    accordo_passato?: { fino_al: string; importo: number } | null;
    indirizzi: Array<{ tipo: string; indirizzo: string }>; senza_email: boolean; solo_pec: boolean; senza_indirizzo_postale: boolean;
}

/** Le persone di un canale, tolte quelle escluse dall'amministratore. */
export function personeDelCanale(persone: PersonaDellAnteprima[], canali: Record<number, CanaleLettera>, escluse: number[], canale: CanaleLettera): PersonaDellAnteprima[] {
    return persone.filter((p) => !escluse.includes(p.id) && (canali[p.id] ?? p.canale) === canale);
}

/** Le persone viste nell'anteprima e rimaste nell'invio: il confine delle azioni. */
export function personeViste(persone: PersonaDellAnteprima[], escluse: number[]): number[] {
    return persone.filter((p) => !escluse.includes(p.id)).map((p) => p.id);
}
