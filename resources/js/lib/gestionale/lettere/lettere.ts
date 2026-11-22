// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
/**
 * Le parole delle lettere ai condòmini (promemoria, sollecito, diffida, messa in mora): i tipi, i canali, l'ultima lettera di una persona,
 * gli stati del registro, le frasi della finestra. Le regole stanno nel server (`EmissioneLettere`, `RegistroLettere`): qui c'è quello che
 * serve a mostrarle. Le date arrivano dal server come `Y-m-d` (o `Y-m-d H:i`): qui si formattano e basta. Le cifre sono in centesimi.
 */
import { euroTesto, giorno } from '@/lib/gestionale/debiti/rateScadute';
import { MANCATA_CONSEGNA, testoMancataConsegna, testoPecDellUltimaLettera, type ConsegnaDellaPec } from '@/lib/gestionale/lettere/consegnaProvata';

export type TipoLettera = 'promemoria' | 'sollecito' | 'diffida' | 'messa_in_mora';
/** I canali della finestra: l'email, la PEC dalla casella dello studio, «PEC e carta» (la stessa lettera per PEC e stampata), la stampa, il PDF per persona. */
export type CanaleLettera = 'email' | 'pec' | 'pec_e_carta' | 'stampa' | 'zip';

export const NOMI_TIPI: Record<TipoLettera, string> = { promemoria: 'Promemoria', sollecito: 'Sollecito', diffida: 'Diffida', messa_in_mora: 'Messa in mora' };

/** Il genere di ogni tipo, per accordare le parole («stampato», «stampata»). */
const FEMMINILE: Record<string, boolean> = { promemoria: false, sollecito: false, diffida: true, messa_in_mora: true };

export const NOMI_CANALI: Record<CanaleLettera, string> = {
    email: 'Email', pec: 'PEC dello studio', pec_e_carta: 'PEC e carta', stampa: 'Stampa per la posta', zip: 'PDF per persona (.zip)',
};

/** Sotto il nome di ogni canale nel menu: che cosa succede. */
const SOTTO_I_CANALI: Record<CanaleLettera, string> = {
    email: 'dalla posta dello studio', pec: 'dalla casella PEC, con la ricevuta', pec_e_carta: 'per PEC e stampata per la posta',
    stampa: 'poi registri la spedizione', zip: 'da mandare o stampare tu',
};

/** I canali che mandano la PEC dalla casella dello studio, e quelli che stampano la lettera. */
export const CANALI_CON_LA_PEC: CanaleLettera[] = ['pec', 'pec_e_carta'];
export const CANALI_CON_LA_STAMPA: CanaleLettera[] = ['stampa', 'pec_e_carta'];

/** Una voce del menu del canale: il nome, che cosa succede o perché è spenta, e se si sceglie. */
export interface VoceDelCanale { valore: CanaleLettera; nome: string; sotto: string; spento: boolean }

/** Le voci del menu del canale di una persona: i canali del tipo; la PEC senza la casella o senza la PEC nella scheda c'è, spenta, con il perché. */
export function opzioniDelCanale(p: Pick<PersonaDellAnteprima, 'canali' | 'canali_spenti'>): VoceDelCanale[] {
    const spenti = (p.canali_spenti && !Array.isArray(p.canali_spenti) ? p.canali_spenti : {}) as Partial<Record<CanaleLettera, string>>;

    return p.canali.map((c) => ({ valore: c, nome: NOMI_CANALI[c], sotto: spenti[c] ?? SOTTO_I_CANALI[c], spento: spenti[c] !== undefined }));
}

export const CANALI_SPEDIZIONE: Record<string, string> = {
    raccomandata: 'Raccomandata A/R', pec: 'PEC mandata fuori dal programma', a_mano: 'A mano', posta: 'Posta ordinaria', altro: 'Altro',
};

/** Diffida e messa in mora non partono mai per email (la stessa frase del server). */
export const SOLO_CARTA = 'Diffida e messa in mora non partono per email: mandale per PEC dalla casella dello studio, o stampale o scarica un PDF per persona e '
    + 'registra la spedizione.';

/** La riga sopra la tabella: quante PEC scelte la persona non ha comunicato. La ricevuta prova l'arrivo nella casella; il programma avvisa, non blocca. */
export function testoPecNonComunicate(n: number): string | null {
    if (n <= 0) {
        return null;
    }
    const quante = n === 1 ? '1 PEC non risulta comunicata dalla persona' : `${n} PEC non risultano comunicate dalle persone`;

    return `${quante}: la ricevuta dirà che la PEC è arrivata in quella casella. Se la persona non l'ha mai data, scegli tu se mandarla lì o stamparla.`;
}

/** I nomi plurali dei tipi, per la conferma («2 diffide»). */
const PLURALI: Record<string, [string, string]> = {
    promemoria: ['promemoria', 'promemoria'], sollecito: ['sollecito', 'solleciti'], diffida: ['diffida', 'diffide'], messa_in_mora: ['messa in mora', 'messe in mora'],
};

/**
 * La conferma di «Manda N PEC»: la domanda, la casella da cui partono, quante adesso oltre il tetto per volta, che cosa vuol dire «partita», dove arriva la
 * ricevuta e che cosa farne.
 */
export function testoConfermaPec(n: number, tipo: string, casella: string | null, tetto: number | null = null): string[] {
    const [uno, tanti] = PLURALI[tipo] ?? ['lettera', 'lettere'];

    return [
        `Mandare ${n} ${n === 1 ? uno : tanti} per PEC?`,
        `Partono adesso dalla casella PEC dello studio${casella ? `, ${casella}` : ''}, ognuna con il suo PDF.`,
        ...(tetto !== null && n > tetto ? [`Ne partono ${tetto} adesso; le altre ${n - tetto} con «Manda le altre», dopo.`] : []),
        '«Partita» vuol dire che il gestore l\'ha accettata. La consegna la dice la ricevuta di consegna, che arriva nella casella dello studio.',
        'Salva la ricevuta (il file .eml) e trascinala sulla lettera, nella corrispondenza della persona: il programma ne legge il giorno e l\'ora.',
    ];
}

/** Dopo «Manda le PEC»: dove si trovano. */
export const FRASE_DOPO_LE_PEC = 'Le trovi nella corrispondenza di ognuno, nella pagina del suo estratto conto.';

/**
 * «Manda N PEC» senza la risposta del programma (il server web ha smesso di aspettare): alcune PEC possono essere partite. Si guarda prima di rimandarle; il
 * programma non manda due volte la stessa PEC nello stesso giorno.
 */
export const FRASE_PEC_SENZA_RISPOSTA = 'La pagina non ha avuto la risposta del programma: alcune PEC possono essere partite. Prima di rimandarle guarda la '
    + 'corrispondenza delle persone, o rifai l\'anteprima: a chi una PEC è già partita oggi non parte di nuovo.';

/** Accanto alla persona con la PEC dello stesso tipo già partita oggi: «Manda N PEC» non la conta. */
export function fraseGiaPartita(ora: string): string {
    return `Già partita oggi alle ${ora}: non parte di nuovo.`;
}

/** La «i» del badge «PEC non comunicata dalla persona»: che cosa vuol dire, con l'esempio. */
export const FRASE_PEC_NON_COMUNICATA = 'La PEC c\'è nella scheda, ma la persona non l\'ha comunicata. Esempio: scritta dallo studio da un vecchio contratto. '
    + 'Puoi mandarla lo stesso: decidi tu.';

/** L'avviso delle rate scadute: le lettere per PEC non partite, o con l'avviso di mancata consegna, da rimandare o da stampare. */
export function testoPecDaRimandare(n: number): string | null {
    if (n <= 0) {
        return null;
    }

    return n === 1 ? '1 lettera per PEC non è partita, o ha l\'avviso di mancata consegna.'
        : `${n} lettere per PEC non sono partite, o hanno l'avviso di mancata consegna.`;
}

/** Le PEC oltre il tetto per volta, dopo un invio: quante restano, e come mandarle. */
export function testoRestantiPec(n: number): string | null {
    return n > 0 ? (n === 1 ? 'Ne resta 1 da mandare per PEC: premi «Manda le altre».' : `Ne restano ${n} da mandare per PEC: premi «Manda le altre».`) : null;
}

/** Il termine «N giorni dal ricevimento» contato dalla consegna della PEC provata, come lo dà il server. */
export interface TermineDallaConsegna { entro: string; giorni: number; consegna: string; a_mano: boolean }

/** «Entro il 18/12/2026: 15 giorni dal ricevimento, contati dalla consegna del 03/12/2026.» */
export function testoTermineDallaConsegna(t: TermineDallaConsegna | null | undefined): string | null {
    if (!t) {
        return null;
    }
    const giorni = t.giorni === 1 ? '1 giorno dal ricevimento, contato' : `${t.giorni} giorni dal ricevimento, contati`;

    return `Entro il ${giorno(t.entro)}: ${giorni} dalla consegna del ${giorno(t.consegna)}${t.a_mano ? ', scritta a mano' : ''}.`;
}

/**
 * La riga dei campi della finestra, dai campi che il server manda con le opzioni (`TestiLettere::CAMPI`): tutti, nel loro ordine. Una lista sola,
 * quella del server: un campo nuovo entra nella frase da sé. Null senza la lista.
 */
export function fraseDeiCampi(campi: Record<string, string> | null | undefined): string | null {
    const nomi = Object.keys(campi ?? {});
    if (nomi.length === 0) return null;
    const elenco = nomi.length === 1 ? nomi[0] : `${nomi.slice(0, -1).join(', ')} e ${nomi[nomi.length - 1]}`;

    return `Nel testo puoi usare ${elenco}: ${nomi.length === 1 ? 'si riempie' : 'si riempiono'} per ogni persona.`;
}

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
    /** La consegna della PEC provata: la ricevuta del gestore, o il giorno scritto a mano con la prova. */
    consegna?: ConsegnaDellaPec | null;
    /** La PEC della lettera: partita, non partita, in partenza, con l'avviso di mancata consegna, da mandare («PEC e carta» solo stampata). */
    pec?: 'partita' | 'non_partita' | 'in_coda' | typeof MANCATA_CONSEGNA | 'da_mandare' | null;
    /** Il giorno dell'avviso di mancata consegna. */
    mancata_consegna_il?: string | null;
}

/**
 * L'ultima lettera di una persona, nell'elenco: il tipo, come e quando. Per l'email «consegna non provata»: il server di posta l'ha accettata,
 * che sia arrivata il programma non lo sa. Mai «ricevuta», «notificata»; «consegnata» solo per la PEC con la consegna provata, con le parole di
 * `consegnaProvata.ts`.
 */
export function testoUltimaLettera(l: UltimaLettera | null | undefined): string {
    if (!l) {
        return 'Nessuna lettera';
    }
    const nome = NOMI_TIPI[l.tipo as TipoLettera] ?? l.tipo;
    // La lettera per PEC dalla casella dello studio, con lo stato della sua PEC (le righe di prima non lo hanno: allora dallo stato della lettera).
    if (l.canale === 'pec' || l.canale === 'pec_e_carta') {
        const pec = l.pec ?? null;
        const conLaCarta = l.canale === 'pec_e_carta';
        // «PEC e carta» con la PEC da mandare o non partita: la frase dice la carta, e poi la PEC. Mai «PEC partita».
        if (conLaCarta && (pec === 'da_mandare' || pec === 'non_partita')) {
            const carta = l.stato === 'spedita' ? testoSpedizione(l.spedizione ?? { data: l.data }, l.tipo) : `${participio(l.tipo, 'stampato')} il ${giorno(l.data)}`;

            return `${nome} ${carta}, PEC ${pec === 'da_mandare' ? 'da mandare' : 'non partita'}`;
        }
        if (pec === MANCATA_CONSEGNA) {
            return `${nome} · ${testoMancataConsegna(l.mancata_consegna_il ?? l.data)}`;
        }
        if (pec === 'in_coda') {
            return `${nome} · PEC in partenza dal ${giorno(l.data)}`;
        }
        if (pec === 'partita' || (pec === null && l.stato !== 'in_coda' && l.stato !== 'non_partita')) {
            const testoPec = l.consegna ? testoPecDellUltimaLettera(l.consegna) : `PEC partita il ${giorno(l.data)}`;
            const carta = conLaCarta ? (l.spedizione ? `, e ${testoSpedizione(l.spedizione, l.tipo)}` : ', e su carta') : '';

            return `${nome} · ${testoPec}${carta}`;
        }
    }
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

/** Lo stato di una riga del registro (un indirizzo, la stampa, la PEC), in parole. Per una PEC la frase la scrive il server, con la consegna se è provata. */
export function testoRigaDelRegistro(r: { canale: string; formato?: string | null; destinatario: string | null; stato: string; quando: string; errore: string | null;
    spedizione?: SpedizioneLettera | null; pec?: { frase: string } | null }): string {
    if (r.pec) {
        return r.pec.frase;
    }
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
export function testoConteggi(c: Partial<Record<CanaleLettera, number>>): string {
    const parti = [
        (c.email ?? 0) > 0 ? `${c.email} per email` : null,
        (c.pec ?? 0) > 0 ? `${c.pec} per PEC` : null,
        (c.pec_e_carta ?? 0) > 0 ? `${c.pec_e_carta} per PEC e su carta` : null,
        (c.stampa ?? 0) > 0 ? `${c.stampa} da stampare` : null,
        (c.zip ?? 0) > 0 ? `${c.zip} in un PDF per persona` : null,
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

/** Dopo un'azione riuscita la finestra spegne il suo pulsante: un secondo clic non manda di nuovo le stesse lettere. */
export const FRASE_FATTO = 'Fatto. Per un altro invio rifai l\'anteprima.';

/**
 * Il tetto di un PDF (la stessa frase del server): conta chi si stampa e chi si scarica in un PDF per persona, non chi va per email; oltre,
 * dice come fare il resto.
 */
export function testoOltreTetto(c: Partial<Record<CanaleLettera, number>>, tetto: number): string | null {
    // Le copie su carta di «PEC e carta» si stampano anche loro.
    const stampa = (c.stampa ?? 0) + (c.pec_e_carta ?? 0);
    const zip = c.zip ?? 0;
    const [quali, n, dove] = stampa > tetto ? ['da stampare', stampa, 'in un PDF'] : zip > tetto ? ['da scaricare', zip, 'in un .zip'] : [null, 0, ''];
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
    /** I canali spenti per la persona, con il perché: la PEC senza la casella dello studio, o senza la PEC nella scheda. */
    canali_spenti?: Partial<Record<CanaleLettera, string>> | never[];
    /** «Ha solo la PEC: …», con la casella o senza. */
    frase_solo_pec?: string | null;
    /** La PEC della persona, il giorno in cui l'ha comunicata, e la sua scheda (a chi la può modificare). */
    pec?: string | null; pec_comunicata_il?: string | null; pec_non_comunicata?: boolean; scheda?: string | null;
    /** L'ora della PEC dello stesso tipo già partita oggi: non parte di nuovo. */
    pec_partita_oggi?: string | null;
}

/** Le persone di un canale (o di uno dei canali), tolte quelle escluse dall'amministratore. */
export function personeDelCanale(persone: PersonaDellAnteprima[], canali: Record<number, CanaleLettera>, escluse: number[],
    canale: CanaleLettera | CanaleLettera[]): PersonaDellAnteprima[] {
    const quali = Array.isArray(canale) ? canale : [canale];

    return persone.filter((p) => !escluse.includes(p.id) && quali.includes(canali[p.id] ?? p.canale));
}

/** Le persone viste nell'anteprima e rimaste nell'invio: il confine delle azioni. */
export function personeViste(persone: PersonaDellAnteprima[], escluse: number[]): number[] {
    return persone.filter((p) => !escluse.includes(p.id)).map((p) => p.id);
}

/**
 * I segnaposto `[interessi]` e `[spese]` si riempiono con gli addebiti registrati fra le righe della lettera, e stanno nel suo totale: chi non ne ha
 * fra le righe riceve «€ 0,00». La finestra lo dice, quando il testo li usa.
 */
export function testiSegnapostiAZero(testo: string, aZero: { interessi_a_zero?: number; spese_a_zero?: number }): string[] {
    const frasi: string[] = [];
    const persone = (n: number) => (n === 1 ? '1 persona' : `${n} persone`);
    if (testo.includes('[interessi]') && (aZero.interessi_a_zero ?? 0) > 0) {
        frasi.push(`Per ${persone(aZero.interessi_a_zero!)} [interessi] è € 0,00: fra le righe della lettera non hanno interessi di mora registrati.`);
    }
    if (testo.includes('[spese]') && (aZero.spese_a_zero ?? 0) > 0) {
        frasi.push(`Per ${persone(aZero.spese_a_zero!)} [spese] è € 0,00: fra le righe della lettera non hanno spese per il ritardo registrate.`);
    }

    return frasi;
}
