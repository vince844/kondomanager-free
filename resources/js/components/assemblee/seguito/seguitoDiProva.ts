// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il seguito delle delibere dell'ordinaria di Via Roma 12 del 15/12/2026 (verbale n. 42), nella forma della prop `seguito`, per i test delle pagine (non è
 * un test): il riepilogo da confermare con il portone (la gestione legata, «Crea il piano straordinario», «Avvisa i condòmini»), la delega, il piano che resta
 * fuori; poi la tabella con il rendiconto eseguito e il portone da eseguire.
 */
import type { CosaDaFare, PaginaDelSeguito, PuntoDelRiepilogo, RigaDelPassoSei, RigaDelSeguito } from '@/types/assemblee';

export const riga = (r: Partial<RigaDelSeguito>): RigaDelSeguito => ({
    chiave: '4:gestione:7:delibera', delibera_id: 4, punto: 4, tipo: 'applica', catena: 'lavori', titolo: 'Portone d\'ingresso 2027: legata alla delibera',
    frase: 'Si applica adesso: le fatture e i piani della gestione trovano la delibera proposta.', spuntata: true, si_applica: true, applicata: false, perche_no: null,
    scheda: { tipo: 'gestione', id: 7, nome: 'Gestione «Portone d\'ingresso 2027»' }, ruolo: 'delibera', cosa: null, conto: 'cambi', dati: {}, ...r,
});

export const cosa = (c: Partial<CosaDaFare>): CosaDaFare => ({
    id: 31, delibera_id: 4, tipo: 'crea_piano_straordinario', tipo_parola: 'Crea il piano straordinario', testo: 'Crea il piano straordinario (€ 14.500,00)',
    entro_il: '2027-01-15', a_chi: 'me', assegnato_a: null, chi: 'Andrea Costa', stato: 'da_fare', fatta_il: null, fatta_da: null, nota: null, chiusa_motivo: null,
    documento: null, oggetto: null, compito_id: 90, azione: { testo: 'Crea il piano straordinario', url: '/admin/gestionale/3/assemblee/9?scheda=delibere&cosa=31' }, ...c,
});

const piano = riga({ chiave: '4:cosa:crea_piano_straordinario', tipo: 'cosa', titolo: 'Approvata, ma resta da fare', scheda: null, ruolo: null, conto: 'cose',
    frase: 'La delibera è «Da eseguire» finché il piano non c\'è.', cosa: { tipo: 'crea_piano_straordinario', parola: 'Crea il piano straordinario',
        testo: 'Crea il piano straordinario (€ 14.500,00)' } });
const avviso = riga({ chiave: '4:cosa:avvisa_condomini', tipo: 'cosa', catena: 'avviso', titolo: 'Avvisa i condòmini', scheda: null, ruolo: null, conto: 'comunicazioni',
    frase: 'Preparo la comunicazione in bacheca.', cosa: { tipo: 'avvisa_condomini', parola: 'Avvisa i condòmini', testo: 'Avvisa i condòmini: il portone' } });
const delega = riga({ chiave: '9:delega', delibera_id: 9, punto: 9, tipo: 'delega', catena: 'delega', titolo: 'Delega con tetto: € 2.000,00 fino al 31/12/2027',
    frase: 'Nessun effetto nei conti adesso: la delega è la delibera stessa.', spuntata: false, si_applica: false, applicata: true, scheda: null, ruolo: null,
    conto: 'deleghe', dati: { delega: { tetto: 200000, ambito: 'accordi_fornitori', ambito_parola: 'Accordi con i fornitori', fino_al: '2027-12-31', in_vigore: true,
        frase: '€ 2.000,00 fino al 31/12/2027' } } });
const fuori = riga({ chiave: '2:piano_rate:5:delibera', delibera_id: 2, punto: 2, tipo: 'fuori', catena: 'piano', titolo: 'Piano rate «Ordinario 2027»: resta fuori',
    frase: 'Rimetti il piano come il preventivo approvato, poi «Applica» nel passo 6.', spuntata: false, si_applica: false, scheda: { tipo: 'piano_rate', id: 5,
        nome: 'Piano «Ordinario 2027»' }, conto: 'fuori', dati: { aspetta: true, differenza: { frase: 'Il piano chiede € 49.200,00, l\'assemblea ha approvato € 48.000,00.',
        fermato: 4800000, oggi: 4920000, voci: [{ nome: 'Manutenzione dell\'ascensore', prima: 380000, dopo: 500000,
            frase: 'Il 05/12/2026 la voce «Manutenzione dell\'ascensore» è passata da € 3.800,00 a € 5.000,00.' }], tabelle: [], rate: null } } });

const punto = (p: Partial<PuntoDelRiepilogo>): PuntoDelRiepilogo => ({
    delibera_id: 4, punto_id: 40, numero: 4, testo: 'Portone: € 14.500,00 con Serramenti Marino, fondo speciale', votazione: 1, oggetto: null, esito: 'approvata',
    esito_parola: 'Approvata', confermata: false, modifiche: null, righe: [], cose: [],
    seguito: { stato: 'da_confermare', parola: 'Da confermare', eseguita_il: null, fatte: 0, quante: 0 }, ...p,
});

export const rigaDelPasso = (r: Partial<RigaDelPassoSei>): RigaDelPassoSei => ({
    delibera_id: 1, punto: 1, punto_id: 10, testo: 'Rendiconto 2025 e stato di riparto', esito: 'approvata', esito_parola: 'Approvata',
    seguito: { stato: 'eseguita', parola: 'Eseguita', eseguita_il: '2026-12-16', fatte: 0, quante: 0 }, cose: [], righe_da_applicare: [], verbale_annullato: false,
    segni: [], fondo_speciale: null, trenta_giorni: { per_tutti: '2027-01-21', dal: '2027-01-22', frase: 'Passati per tutti dal 22/01/2027. Resta la nullità.' },
    chi_puo_impugnare: [{ nome: 'Ricci Elena', perche: 'astenuto' }],
    posti: [{ oggetto_tipo: 'rendiconto_versione', oggetto_id: 2, ruolo: 'approvazione', parola: 'Approvazione', nome: 'Rendiconto, versione 2', data: '2026-12-15',
        nota: null, esiste: true }], ...r,
});

/** Il riepilogo da confermare, il 16/12/2026: il portone, il preventivo con il piano fuori, la delega. */
export function daConfermare(): PaginaDelSeguito {
    return {
        riepilogo: { aperto: true, perche_chiuso: null, su_carta: false, verbale_da_caricare: false, confermato_il: null, da_confermare: 3,
            punti: [punto({ righe: [riga({}), piano, avviso] }), punto({ delibera_id: 2, punto_id: 20, numero: 2, testo: 'Preventivo 2027 e piano di riparto', righe: [fuori] }),
                punto({ delibera_id: 9, punto_id: 90, numero: 9, testo: 'Delega all\'amministratore', righe: [delega] })],
            conto: { cambi: 1, cose: 1, comunicazioni: 1, deleghe: 1, punti: 0, fuori: 1, respinte: 0 },
            frase_del_conto: '1 cambio nei conti · 1 resta fuori · 1 cosa da fare · 1 comunicazione da rileggere · 1 delega in vigore' },
        tabella: [], da_eseguire: [], trenta_giorni: { per_tutti: null, dal: null, frase: 'La data non c\'è finché il verbale non è consegnato a tutti. Resta la nullità.' },
        passo: { stato: 'adesso', riga: 'da confermare: «Che cosa cambia nel gestionale»', perche: null, piede: 'Niente cambia nei moduli finché non premi «Conferma».' },
        puo: { gestire: true },
    };
}

/** Il 10/01/2027, dopo la conferma: il rendiconto eseguito, il portone da eseguire con la firma fatta e il piano da creare, il preventivo da correggere. */
export function confermato(): PaginaDelSeguito {
    const portone = rigaDelPasso({ delibera_id: 4, punto: 4, punto_id: 40, testo: 'Portone: Serramenti Marino, € 14.500,00', posti: [],
        seguito: { stato: 'da_eseguire', parola: 'Da eseguire · 2 di 3', eseguita_il: null, fatte: 2, quante: 3 },
        cose: [cosa({ id: 30, tipo: 'avvisa_condomini', testo: 'Avvisa i condòmini: il portone', stato: 'fatta', fatta_il: '2026-12-17', azione: null }),
            cosa({ id: 32, tipo: 'fuori_dal_programma', testo: 'Firma con Serramenti Marino', stato: 'fatta', fatta_il: '2027-01-08', fatta_da: 'Marco Galli', azione: null }),
            cosa({})],
        righe_da_applicare: [riga({})],
        fondo_speciale: { chiesti: 900000, deliberati: 1450000, pari: false, frase: 'Fondo speciale: € 9.000,00 chiesti su € 14.500,00 deliberati.',
            legge: 'La legge vuole un fondo «di importo pari all\'ammontare dei lavori» (art. 1135 n. 4 c.c.). Non blocca niente: decidi tu.' } });
    const preventivo = rigaDelPasso({ delibera_id: 2, punto: 2, punto_id: 20, testo: 'Preventivo 2027 e piano di riparto', esito: 'approvata_con_modifiche',
        esito_parola: 'Approvata con modifiche', posti: [], seguito: { stato: 'da_eseguire', parola: 'Da eseguire', eseguita_il: null, fatte: 0, quante: 1 },
        cose: [cosa({ id: 33, delibera_id: 2, tipo: 'rifai_versione', testo: 'Correggi il preventivo 2027 e fermalo di nuovo', entro_il: '2026-12-22', azione: null })] });

    return {
        ...daConfermare(),
        riepilogo: { ...daConfermare().riepilogo, da_confermare: 0, confermato_il: '2026-12-16T09:00:00+01:00', punti: [] },
        tabella: [rigaDelPasso({}), preventivo, portone],
        da_eseguire: [{ ...cosa({}), punto: 4 }],
        trenta_giorni: { per_tutti: '2027-01-21', dal: '2027-01-22', frase: 'Passati per tutti dal 22/01/2027. Resta la nullità.' },
        passo: { stato: 'adesso', riga: '1 su 3 · 2 da eseguire', perche: null, piede: 'Il passo è fatto quando ogni delibera è eseguita, o le sue cose da fare non servono più.' },
    };
}
