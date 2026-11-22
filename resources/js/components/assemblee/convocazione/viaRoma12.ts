// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * I dati di Via Roma 12 per le prove dei passi «Convocazione» e «Documenti»: l'ordinaria con la prima convocazione lunedì 14/12/2026 alle 8:00, le
 * raccomandate da spedire entro giovedì 03/12, le persone della bozza approvata. Non è un test: lo importano i test accanto.
 */
import type { EntroQuandoSpedire, RigaDelConvocato, RigaDelFascicolo, SchedaDelFascicolo, SchedaDellaConvocazione } from './tipi';

export function entroProposta(): EntroQuandoSpedire {
    return {
        frase: 'Per la prima convocazione di lunedì 14/12 devono arrivare entro mercoledì 09/12: il giorno in cui arrivano conta, il 14/12 no.',
        frase_breve: 'Devono arrivare entro mercoledì 09/12.',
        ultimo_giorno: '2026-12-09',
        ultimo_giorno_parole: 'mercoledì 09/12',
        oggi: '2026-11-26',
        raccomandate: {
            quante: 5, frase: 'Spedisci le raccomandate entro giovedì 03/12', entro: '2026-12-03', entro_parole: 'giovedì 03/12',
            proposta: '2026-12-03', proposta_parole: 'giovedì 03/12', scelta_da_te: false, torna: null, passata: null,
        },
        vicina: null,
        altri: { etichetta: 'PEC (11) e consegne a mano (10)', entro_parole: 'mercoledì 09/12' },
        conto: {
            titolo: 'Come conto giovedì 03/12',
            frase: 'La raccomandata deve arrivare entro mercoledì 09/12. Lo studio conta tre giorni di posta, dal lunedì al venerdì, dal giorno dopo la spedizione.',
            giorni: [
                { giorno: 'gio 03', nota: 'spedisci · non conta', tipo: 'no' },
                { giorno: 'ven 04', nota: '1° giorno di posta', tipo: 'po' },
                { giorno: 'sab 05', nota: 'non conta', tipo: 'no' },
                { giorno: 'dom 06', nota: 'non conta', tipo: 'no' },
                { giorno: 'lun 07', nota: '2° giorno di posta', tipo: 'po' },
                { giorno: 'mar 08', nota: 'festa · non conta', tipo: 'no' },
                { giorno: 'mer 09', nota: '3° · arriva in tempo', tipo: 'ar' },
            ],
            sotto: 'Non contano il giorno della spedizione, il sabato, la domenica e martedì 08/12, l\'Immacolata. Poi lunedì 14/12 la prima convocazione.',
            esito: { in_tempo: true, titolo: 'Arriva in tempo', frase: ', l\'ultimo giorno utile. Spedita venerdì 04/12 arriverebbe giovedì 10/12: un giorno dopo.' },
            piede: 'Conto prudente: spedire un giorno prima non costa niente.',
        },
        righe: [
            'Puoi scegliere un altro giorno, da oggi in poi: si salva quando lo cambi.',
            'Il compito «Spedire la convocazione entro il 03/12» è nell\'Action inbox; il promemoria per email arriva martedì 01/12 e giovedì 03/12.',
        ],
        segue: [],
        giorni_di_posta: { adesso: '3 lavorativi', dove: '/impostazioni/assemblee' },
        si_cambia: true,
        destinazione_uso: null,
    };
}

/** La scena 3b: Andrea sceglie venerdì 04/12, che arriva tardi. */
export function entroSceltaDaTe(): EntroQuandoSpedire {
    const e = entroProposta();

    return {
        ...e,
        raccomandate: {
            ...e.raccomandate!, frase: 'Spedisci le raccomandate entro venerdì 04/12', entro: '2026-12-04', entro_parole: 'venerdì 04/12', scelta_da_te: true,
            torna: 'Torna alla proposta (gio 03/12)',
        },
        conto: {
            ...e.conto!,
            titolo: 'Come conto venerdì 04/12',
            giorni: [
                { giorno: 'ven 04', nota: 'spedisci · non conta', tipo: 'no' },
                { giorno: 'sab 05', nota: 'non conta', tipo: 'no' },
                { giorno: 'dom 06', nota: 'non conta', tipo: 'no' },
                { giorno: 'lun 07', nota: '1° giorno di posta', tipo: 'po' },
                { giorno: 'mar 08', nota: 'festa · non conta', tipo: 'no' },
                { giorno: 'mer 09', nota: '2° giorno di posta', tipo: 'po' },
                { giorno: 'gio 10', nota: '3° · arriva tardi', tipo: 'tardi' },
            ],
            esito: {
                in_tempo: false, titolo: 'Arriva tardi',
                frase: ': spedita venerdì 04/12 arriverebbe giovedì 10/12, un giorno dopo il termine del 09/12. Per chi la riceve dopo il 09/12 i cinque giorni non ci sono, e la delibera si può impugnare. Se tieni il 04/12, decidi tu.',
            },
        },
        righe: ['Scelta da te il 26/11/2026; la proposta del programma resta giovedì 03/12.'],
        segue: [
            'Il compito nell\'Action inbox: «Spedire la convocazione entro il 04/12».',
            'Il promemoria per email: mercoledì 02/12 e venerdì 04/12.',
            'Le raccomandate stampate: nella tabella dei convocati, «entro il 04/12».',
        ],
    };
}

/** La scena 2 della bozza approvata: una data prima della proposta, martedì 01/12, che arriva in tempo con cinque giorni di margine. */
export function entroSceltaPrima(): EntroQuandoSpedire {
    const e = entroProposta();

    return {
        ...e,
        raccomandate: {
            ...e.raccomandate!, frase: 'Spedisci le raccomandate entro martedì 01/12', entro: '2026-12-01', entro_parole: 'martedì 01/12', scelta_da_te: true,
            torna: 'Torna alla proposta (gio 03/12)',
        },
        conto: {
            ...e.conto!,
            titolo: 'Come conto martedì 01/12',
            giorni: [
                { giorno: 'mar 01', nota: 'spedisci · non conta', tipo: 'no' },
                { giorno: 'mer 02', nota: '1° giorno di posta', tipo: 'po' },
                { giorno: 'gio 03', nota: '2° giorno di posta', tipo: 'po' },
                { giorno: 'ven 04', nota: '3° · arriva in tempo', tipo: 'ar' },
            ],
            sotto: null,
            esito: { in_tempo: true, titolo: 'Arriva in tempo', frase: ', con 5 giorni di margine sul 09/12.' },
        },
        righe: ['Scelta da te il 26/11/2026; la proposta del programma resta giovedì 03/12.'],
        segue: [
            'Il compito nell\'Action inbox: «Spedire la convocazione entro il 01/12».',
            'Il promemoria per email: venerdì 27/11 e martedì 01/12.',
            'Le raccomandate stampate: nella tabella dei convocati, «entro il 01/12».',
        ],
    };
}

const azioniVuote = { spedizione: false, consegna: false, correggi: false, prova: false, ritiro: false, ricevuta: false };

export function riga(nome: string, piu: Partial<RigaDelConvocato> = {}): RigaDelConvocato {
    return {
        chiave: `persona-${nome}`, anagrafica_id: nome.length, nome, ruolo: null, unita: 'Int. 3', quota: null, mezzo: 'raccomandata',
        mezzo_parola: 'Raccomandata', mezzo_proposto: false, pec_non_comunicata: false, stato: 'da_spedire', stato_parola: 'Da spedire', colore: 'sl',
        dettaglio: null, prova: null, invio_id: null, pdf_url: null, in_tempo: null, azioni: { ...azioniVuote }, per: { lettere: false, pec: false },
        scelta: { si_puo: false, ha_pec: false, per_assemblea: false, della_scheda: null }, esito: null, ricevuta_il: null,
        esito_il: null, ...piu,
    };
}

/** Le sette persone della scena 2 della bozza approvata, più venti altre. */
export function righeDellaBozza(altre = 20): RigaDelConvocato[] {
    const bozza = [
        riga('Rossi Mario', { anagrafica_id: 1, unita: 'Int. 3, box 21', mezzo: 'pec', mezzo_parola: 'PEC', stato: 'consegnata', stato_parola: 'Consegnata', colore: 'em',
            dettaglio: '03/12 alle 10:14', prova: { nome: 'Ricevuta', url: '/prova/1' }, invio_id: 101, in_tempo: true, esito: 'consegnata',
            ricevuta_il: '2026-12-03', azioni: { ...azioniVuote, correggi: true, prova: true, ricevuta: true } }),
        riga('Russo Anna', { anagrafica_id: 2, unita: 'Int. 5', quota: '½', mezzo: 'a_mano', mezzo_parola: 'A mano', stato: 'consegnata', stato_parola: 'Consegnata',
            colore: 'em', dettaglio: '04/12, firma', prova: { nome: 'Foglio', url: '/prova/2' }, invio_id: 102, esito: 'consegnata', ricevuta_il: '2026-12-04',
            azioni: { ...azioniVuote, correggi: true } }),
        riga('Russo Marco', { anagrafica_id: 3, unita: 'Int. 5', quota: '½', stato: 'in_giacenza', stato_parola: 'In giacenza', colore: 'am',
            dettaglio: 'avviso del 07/12: conta da qui', prova: { nome: 'Avviso', url: '/prova/3' }, invio_id: 103, esito: 'in_giacenza', ricevuta_il: '2026-12-07',
            azioni: { ...azioniVuote, correggi: true, prova: true, ritiro: true } }),
        riga('Neri Carla', { anagrafica_id: 4, ruolo: 'usufruttuaria', unita: 'Int. 9', stato: 'spedita', stato_parola: 'Spedita', colore: 'sk',
            dettaglio: '04/12 · n. 61 2345 6789', invio_id: 104, azioni: { ...azioniVuote, consegna: true, prova: true } }),
        riga('Neri Paolo', { anagrafica_id: 5, ruolo: 'nudo proprietario', unita: 'Int. 9', mezzo: 'pec', mezzo_parola: 'PEC', stato: 'consegnata',
            stato_parola: 'Consegnata', colore: 'em', dettaglio: '03/12 alle 10:14', prova: { nome: 'Ricevuta', url: '/prova/5' }, invio_id: 105, esito: 'consegnata' }),
        riga('Ferri Luca', { anagrafica_id: 6, ruolo: 'conduttore, per il punto 5', unita: 'Int. 12', mezzo: 'a_mano', mezzo_parola: 'A mano', stato: 'da_consegnare',
            stato_parola: 'Da consegnare', dettaglio: 'entro il 09/12', invio_id: 106, azioni: { ...azioniVuote, consegna: true } }),
        riga('Bianchi Anna', { anagrafica_id: 7, unita: 'Int. 14', mezzo: null, mezzo_parola: 'Nessun mezzo', stato: 'non_convocabile', stato_parola: 'Non convocabile',
            colore: 'ro', dettaglio: 'ha solo l\'email: aggiungi la PEC o l\'indirizzo' }),
    ];
    // Cinque su carta e undici per PEC, come «Stampa le lettere (5)» e «Manda le PEC (11)» della scheda; si sceglie il mezzo in tutte.
    const piu = Array.from({ length: altre }, (_, i) => riga(`Persona ${i + 1}`, {
        anagrafica_id: 100 + i, chiave: `altra-${i}`, unita: `Int. ${20 + i}`,
        ...(i >= 5 && i < 16 ? { mezzo: 'pec' as const, mezzo_parola: 'PEC' } : {}),
        per: { lettere: i < 5, pec: i >= 5 && i < 16 },
        scelta: { si_puo: true, ha_pec: i >= 5 && i < 16, per_assemblea: false, della_scheda: null },
    }));

    return [...bozza, ...piu];
}

export function schedaDellaBozza(piu: Partial<SchedaDellaConvocazione> = {}): SchedaDellaConvocazione {
    return {
        stato: 'in_corso',
        entro: entroProposta(),
        convocati: {
            totale: 27,
            descrizione: '26 condòmini, più l\'usufruttuaria e il conduttore che il punto 5 chiama. I due comproprietari dell\'interno 5 ricevono un avviso ciascuno.',
            fissati: true,
            righe: righeDellaBozza(),
        },
        lettere: { quante: 5, attivo: true, perche: null },
        pec: { quante: 11, attivo: true, perche: null },
        pec_non_comunicate: null,
        videoconferenza: null,
        venduti: [{
            titolo: 'Dopo l\'invio, l\'interno 7 è stato venduto.',
            frase: 'Il passaggio da Gallo Enzo a Verdi Sara è registrato con decorrenza 10/12. Verdi non ha ricevuto la convocazione, e all\'assemblea vota lei. Un compito nell\'Action inbox ti ricorda di avvisarla; decidi tu come procedere.',
            anagrafica_id: 70,
        }],
        bacheca: { acceso: true, adesso: 'pubblicato il 03/12. Dice che non vale come convocazione; lo leggono anche gli inquilini, quindi la frase non ha dati personali.', si_puo: true, perche: null, url: '/comunicazioni/9' },
        prova: { attivo: true, perche: null },
        foglio: { quante: 2 },
        email_semplice: 'L\'email semplice si può mandare come avviso in più, ma non vale come convocazione (art. 66 c. 3; Cass. 16399/2025).',
        riconvoca: { si_puo: true, perche: null, costo: 'Ventisette avvisi da rifare: le raccomandate che non arrivano più in tempo per il 14/12 spostano l\'assemblea.' },
        gestisce: true,
        rotte: {
            lettere: '/a/1/convocazione/lettere', pec: '/a/1/convocazione/pec', spedizione: '/a/1/convocazione/spedizione', prova: '/a/1/convocazione/prova',
            foglio: '/a/1/convocazione/foglio-a-mano', foglio_firmato: '/a/1/convocazione/foglio-a-mano/firmato', bacheca: '/a/1/convocazione/bacheca',
            raccomandate_entro: '/a/1/convocazione/raccomandate-entro', riconvoca: '/a/1/riconvoca', affissione: '/a/1', consegna: '/a/1/invii/__INVIO__/consegna',
            prova_invio: '/a/1/invii/__INVIO__/prova', ricevuta: '/a/1/invii/__INVIO__/ricevuta', mezzo: '/a/1/convocazione/mezzo',
        },
        ...piu,
    };
}

export function fascicoloDellaBozza(piu: Partial<SchedaDelFascicolo> = {}): SchedaDelFascicolo {
    const doc = (id: number, nome: string, pagine: number | null) => ({ id, nome, pagine });
    const riga = (r: Partial<RigaDelFascicolo> & Pick<RigaDelFascicolo, 'numero' | 'punto' | 'documento'>): RigaDelFascicolo => ({
        punto_id: null, documento_muto: false, sotto: null, stato: null, azione: null, prende: false, documenti: [], proposte: [], aggiungi: false, togli: false, ...r,
    });

    return {
        righe: [
            riga({ numero: '—', punto: 'Copertina e avviso', documento: 'sulla carta dello studio, con l\'ordine del giorno e la delega', documento_muto: true, stato: { parola: 'Pronti', colore: 'em' } }),
            riga({ numero: '1', punto_id: 11, punto: 'Rendiconto 2025 e stato di riparto', documento: 'Versione 2 fermata il 12/11 · impronta 7f3a…', stato: { parola: 'Fermato', colore: 'em' } }),
            riga({ numero: '2', punto_id: 12, punto: 'Preventivo 2027 e piano di riparto', documento: 'la distinta, il riparto per tabella, le rate per condòmino', documento_muto: true, azione: 'ferma_preventivo' }),
            riga({ numero: '3', punto_id: 13, punto: 'Ratifica della spesa oltre il preventivo di € 3.450,00 per il quadro di manovra dell\'ascensore', documento: 'Preventivo quadro di manovra.pdf',
                sotto: 'Fattura n. GUIDA-02 · Mario Rossi Impianti s.r.l. · € 3.450,00', prende: true, aggiungi: true, togli: true,
                documenti: [doc(31, 'Preventivo quadro di manovra.pdf', 3)],
                proposte: [{ da: 'Proposto dalla fattura n. GUIDA-02', breve: 'Dalla fattura', documenti: [doc(41, 'fattura-GUIDA-02.pdf', 2)], pagine: 2 }] }),
            riga({ numero: '4', punto_id: 14, punto: 'Sostituzione del portone d\'ingresso: preventivi, impresa, spesa di € 14.500,00', documento: 'Preventivo Ferri Serramenti.pdf', prende: true,
                aggiungi: true, togli: true,
                documenti: [doc(51, 'Preventivo Ferri Serramenti.pdf', 4), doc(52, 'Preventivo Portoni Bianchi.pdf', 3), doc(53, 'Preventivo Edil Nord.pdf', 5),
                    doc(54, 'Capitolato.pdf', 6), doc(55, 'Foto del portone.pdf', 2), doc(56, 'Relazione del tecnico.pdf', 8), doc(57, 'Planimetria.pdf', 1)],
                proposte: [{ da: 'Proposti dalla fattura n. 51', breve: 'Dalla fattura', documenti: [doc(61, 'a.pdf', 2), doc(62, 'b.pdf', 2), doc(63, 'c.pdf', 2), doc(64, 'd.pdf', 3)], pagine: 9 }] }),
            riga({ numero: '6', punto_id: 16, punto: 'Varie ed eventuali', documento: 'Nessun documento.', documento_muto: true, prende: true, aggiungi: true, togli: true }),
        ],
        frase: 'Il preventivo non è ancora fermato: lo fermi prima di spedire, così resta che cosa hanno ricevuto i condòmini.',
        riepilogo: { documenti: 9, pagine: 35, mb: 3, tetto: 40, dire: false, vicino: false },
        condominio: 'Via Roma 12',
        modificabile: true,
        componi: { quante: 5, attivo: true, perche: null },
        per_persona: { quante: 27, attivo: true, perche: null },
        ferma_preventivo: { quante: 1, attivo: true, perche: null },
        gestisce: true,
        rotte: { componi: '/a/1/fascicolo/componi', per_persona: '/a/1/fascicolo/per-persona', ferma_preventivo: '/a/1/fascicolo/preventivo',
            documenti: '/a/1/fascicolo/punti/__PUNTO__/documenti', aggiungi: '/a/1/fascicolo/punti/__PUNTO__/documenti',
            togli: '/a/1/fascicolo/punti/__PUNTO__/documenti/__DOCUMENTO__' },
        ...piu,
    };
}
