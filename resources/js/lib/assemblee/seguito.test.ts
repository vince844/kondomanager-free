// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le parole e i conti del seguito delle delibere nelle pagine, con l'ordinaria di Via Roma 12 del 15/12/2026:
 *
 * - il conto in fondo al riepilogo segue le spunte (la stessa regola del server: delega, memo e ciò che resta fuori contano sempre);
 * - il corpo di «Conferma»: le chiavi spuntate delle delibere da confermare, la data e la persona delle cose, le cose scritte, che cosa ha cambiato
 *   l'assemblea;
 * - la riga di una cosa nel passo 6 («Firma con Serramenti Marino: il 08/01/2027, segnata da Marco Galli»), il dettaglio di una delibera eseguita, l'ordine
 *   al telefono (le delibere da eseguire in cima), le voci del menu di riga con la riga che dice a che cosa servono, gli esiti dopo «Conferma».
 */
import { describe, expect, test } from 'vitest';
import {
    contoDelRiepilogo, contiDelleDelibere, corpoDellaConferma, dettaglioDelSeguito, esitiDaMostrare, fraseDelConto, nomeDelPulsante, ordinePerIlTelefono, rigaBreveDellaCosa,
    rigaDellaCosa, righeDelPunto, vociDelMenu,
} from './seguito';
import type { CosaDaFare, PuntoDelRiepilogo, RigaDelPassoSei, RigaDelSeguito } from '@/types/assemblee';

const riga = (r: Partial<RigaDelSeguito>): RigaDelSeguito => ({
    chiave: 'x', delibera_id: 4, punto: 4, tipo: 'applica', catena: 'lavori', titolo: '', frase: '', spuntata: true, si_applica: true, applicata: false,
    perche_no: null, scheda: null, ruolo: null, cosa: null, conto: 'cambi', dati: {}, ...r,
});
const cosa = (c: Partial<CosaDaFare>): CosaDaFare => ({
    id: 1, delibera_id: 4, tipo: 'fuori_dal_programma', tipo_parola: 'Fuori dal programma', testo: 'Firma con Serramenti Marino', entro_il: null, a_chi: 'me',
    assegnato_a: null, chi: 'Andrea Costa', stato: 'da_fare', fatta_il: null, fatta_da: null, nota: null, chiusa_motivo: null, documento: null, oggetto: null,
    compito_id: null, azione: null, ...c,
});
const punto = (righe: RigaDelSeguito[], piu: Partial<PuntoDelRiepilogo> = {}): PuntoDelRiepilogo => ({
    delibera_id: 4, punto_id: 40, numero: 4, testo: 'Portone: € 14.500,00 con Serramenti Marino', votazione: 1, oggetto: null, esito: 'approvata',
    esito_parola: 'Approvata', confermata: false, modifiche: null, righe, cose: [],
    seguito: { stato: 'da_confermare', parola: 'Da confermare', eseguita_il: null, fatte: 0, quante: 0 }, ...piu,
});
const rigaDelPasso = (r: Partial<RigaDelPassoSei>): RigaDelPassoSei => ({
    delibera_id: 4, punto: 4, punto_id: 40, testo: 'Portone', esito: 'approvata', esito_parola: 'Approvata',
    seguito: { stato: 'eseguita', parola: 'Eseguita', eseguita_il: '2026-12-16', fatte: 0, quante: 0 }, cose: [], righe_da_applicare: [], verbale_annullato: false,
    segni: [], fondo_speciale: null, trenta_giorni: { per_tutti: null, dal: null, frase: '' }, chi_puo_impugnare: [], posti: [], ...r,
});

const gestione = riga({ chiave: '4:gestione:7:delibera', titolo: 'Portone 2027: legata alla delibera' });
const piano = riga({ chiave: '4:cosa:crea_piano_straordinario', tipo: 'cosa', conto: 'cose',
    cosa: { tipo: 'crea_piano_straordinario', parola: 'Crea il piano straordinario', testo: 'Crea il piano straordinario (€ 14.500,00)' } });
const avviso = riga({ chiave: '4:cosa:avvisa_condomini', tipo: 'cosa', conto: 'comunicazioni', catena: 'avviso',
    cosa: { tipo: 'avvisa_condomini', parola: 'Avvisa i condòmini', testo: 'Avvisa i condòmini: il portone' } });
const delega = riga({ chiave: '9:delega', tipo: 'delega', spuntata: false, si_applica: false, conto: 'deleghe', delibera_id: 9 });

describe('il conto in fondo al riepilogo', () => {
    test('segue le spunte; la delega conta sempre, e le cose scritte si aggiungono alle cose da fare', () => {
        const punti = [punto([gestione, piano, avviso]), punto([delega], { delibera_id: 9, numero: 9 })];

        expect(fraseDelConto(contoDelRiepilogo(punti, {}, 0))).toBe('1 cambio nei conti · 1 cosa da fare · 1 comunicazione da rileggere · 1 delega in vigore');
        expect(fraseDelConto(contoDelRiepilogo(punti, { '4:gestione:7:delibera': false, '4:cosa:avvisa_condomini': false }, 1)))
            .toBe('0 cambi nei conti · 2 cose da fare · 1 delega in vigore');
    });

    test('una delibera già confermata non conta, e una riga già applicata non si rimanda', () => {
        const punti = [punto([gestione], { confermata: true }), punto([riga({ chiave: '5:x', applicata: true })], { delibera_id: 5 })];

        expect(contoDelRiepilogo(punti, {}, 0).cambi).toBe(1);
        expect(corpoDellaConferma(punti, {}, {}, [], {}).chiavi).toEqual([]);
    });
});

describe('il corpo di «Conferma»', () => {
    test('le chiavi spuntate, la data e la persona delle cose, le cose scritte, che cosa ha cambiato l\'assemblea', () => {
        const punti = [punto([gestione, piano, avviso]), punto([], { delibera_id: 2, numero: 2, esito: 'approvata_con_modifiche' })];
        const corpo = corpoDellaConferma(punti, { '4:cosa:avvisa_condomini': false },
            { '4:cosa:crea_piano_straordinario': { entro_il: '2027-01-15', a_chi: 'collaboratore', assegnato_a: 7 } },
            [{ delibera_id: 4, testo: 'Firma con Serramenti Marino', entro_il: '', a_chi: 'me', assegnato_a: null }, { delibera_id: 4, testo: '  ', entro_il: '', a_chi: 'me', assegnato_a: null }],
            { 2: 'Pulizia delle scale a € 5.400,00', 4: 'non si manda: non è con modifiche' });

        expect(corpo).toEqual({
            chiavi: ['4:gestione:7:delibera', '4:cosa:crea_piano_straordinario'],
            cose: { '4:cosa:crea_piano_straordinario': { entro_il: '2027-01-15', a_chi: 'collaboratore', assegnato_a: 7 } },
            altre: [{ delibera_id: 4, testo: 'Firma con Serramenti Marino', entro_il: null, a_chi: 'me', assegnato_a: null }],
            modifiche: { 2: 'Pulizia delle scale a € 5.400,00' },
        });
    });
});

describe('le righe di un punto', () => {
    test('i cambi, le cose da fare, «Avvisa i condòmini» a parte', () => {
        const g = righeDelPunto(punto([gestione, piano, avviso, delega]));

        expect(g.cambi.map((r) => r.chiave)).toEqual(['4:gestione:7:delibera', '9:delega']);
        expect(g.cose.map((r) => r.chiave)).toEqual(['4:cosa:crea_piano_straordinario']);
        expect(g.avviso?.chiave).toBe('4:cosa:avvisa_condomini');
    });
});

describe('il passo 6', () => {
    test('la riga di una cosa: fatta, da fare, l\'avviso pubblicato, non serve più', () => {
        expect(rigaDellaCosa(cosa({ stato: 'fatta', fatta_il: '2027-01-08', fatta_da: 'Marco Galli' }))).toBe('Firma con Serramenti Marino: il 08/01/2027, segnata da Marco Galli');
        expect(rigaDellaCosa(cosa({ testo: 'Crea il piano straordinario', entro_il: '2027-01-15' }))).toBe('Crea il piano straordinario: entro il 15/01/2027, Andrea Costa');
        expect(rigaDellaCosa(cosa({ tipo: 'avvisa_condomini', stato: 'fatta', fatta_il: '2026-12-17' }))).toBe('Avviso in bacheca: pubblicato il 17/12/2026');
        expect(rigaDellaCosa(cosa({ stato: 'non_serve', fatta_il: '2026-12-20' }))).toBe('Firma con Serramenti Marino: non serve più dal 20/12/2026');
        expect(rigaDellaCosa(cosa({ stato: 'fatta', fatta_il: '2027-01-12', oggetto: { tipo: 'piano_rate', id: 3, nome: 'Piano «Portone d\'ingresso 2027»' } })))
            .toBe('Firma con Serramenti Marino: il 12/01/2027 · Piano «Portone d\'ingresso 2027»');
    });

    test('il dettaglio di una delibera eseguita: il giorno e la scheda', () => {
        const posti = [{ oggetto_tipo: 'piano_rate', oggetto_id: 3, ruolo: 'delibera', parola: 'Delibera', nome: 'Piano «Ordinario 2027»', data: null, nota: null, esiste: true }];

        expect(dettaglioDelSeguito(rigaDelPasso({ posti }))).toBe('il 16/12 · Piano «Ordinario 2027»');
        expect(dettaglioDelSeguito(rigaDelPasso({}))).toBe('il 16/12');
        expect(dettaglioDelSeguito(rigaDelPasso({ seguito: { stato: 'da_eseguire', parola: 'Da eseguire · 1 di 2', eseguita_il: null, fatte: 1, quante: 2 } }))).toBe('');
    });

    test('al telefono le delibere da eseguire stanno in cima, le altre nell\'ordine dei punti', () => {
        const da = rigaDelPasso({ delibera_id: 4, punto: 4, seguito: { stato: 'da_eseguire', parola: 'Da eseguire', eseguita_il: null, fatte: 0, quante: 1 } });

        expect(ordinePerIlTelefono([rigaDelPasso({ delibera_id: 1, punto: 1 }), da, rigaDelPasso({ delibera_id: 5, punto: 5 })]).map((r) => r.delibera_id)).toEqual([4, 1, 5]);
    });

    test('il menu di riga: ogni voce con la riga che dice a che cosa serve; «Applica» solo con una riga tolta, i segni solo sulle approvate', () => {
        const conTolta = rigaDelPasso({ righe_da_applicare: [gestione], cose: [cosa({})],
            seguito: { stato: 'da_eseguire', parola: 'Da eseguire', eseguita_il: null, fatte: 0, quante: 1 } });
        const voci = vociDelMenu(conTolta, 'Via Roma 12');

        expect(voci.map((v) => v.voce)).toEqual(['applica', 'eseguita', 'non_serve', 'nuova', 'impugnata', 'annullata', 'revocata']);
        expect(voci.find((v) => v.voce === 'applica')?.riga).toBe('Solo per una riga tolta dal riepilogo');
        expect(voci.find((v) => v.voce === 'eseguita')?.riga).toBe('Per ciò che fai fuori dal programma');
        expect(voci.find((v) => v.voce === 'impugnata')?.riga).toBe('Qualcuno ha fatto causa: non ferma niente');
        expect(voci.find((v) => v.voce === 'revocata')?.riga).toBe('Da una delibera successiva di Via Roma 12');
        expect(vociDelMenu(rigaDelPasso({ esito: 'respinta', esito_parola: 'Respinta' }), 'Via Roma 12').map((v) => v.voce)).toEqual([]);
        expect(vociDelMenu(rigaDelPasso({ segni: [{ tipo: 'annullata', frase: 'Annullata dal giudice il 14/03/2028' }] }), 'Via Roma 12').map((v) => v.voce))
            .toEqual(['nuova', 'impugnata']);
    });

    test('gli esiti dopo «Conferma»: quante applicate, e il perché di ognuna non applicata', () => {
        expect(esitiDaMostrare({ a: { esito: 'applicata', frase: 'ok' }, b: { esito: 'non_applicata', frase: 'Il piano è già approvato dal 10/12.' },
            c: { esito: 'gia_applicata', frase: 'Già applicata.' } })).toEqual({ applicate: 2, non: ['Il piano è già approvato dal 10/12.'] });
    });
});

describe('le tre card in testa al passo 6 e le righe brevi (decisione 645)', () => {
    const delibera = (r: Partial<RigaDelPassoSei>): RigaDelPassoSei => ({ delibera_id: 1, punto: 1, esito: 'approvata', seguito: { stato: 'eseguita' } , cose: [], ...r }) as RigaDelPassoSei;

    test('«Le delibere»: i conti del badge giallo e le altre cose, solo i numeri che non sono zero', () => {
        const tabella = [
            delibera({ seguito: { stato: 'da_eseguire' } as RigaDelPassoSei['seguito'] }),
            delibera({}), delibera({}), delibera({}),
            delibera({ esito: 'respinta', seguito: { stato: 'senza_seguito' } as RigaDelPassoSei['seguito'] }),
            delibera({ esito: 'senza_voto', seguito: { stato: 'senza_seguito' } as RigaDelPassoSei['seguito'] }),
        ];
        expect(contiDelleDelibere(tabella, 2)).toEqual({ principale: '1 da eseguire · 3 eseguite', altre: '1 respinta · 1 senza voto · 2 cose da fare aperte' });
        expect(contiDelleDelibere([delibera({})], 0)).toEqual({ principale: '1 eseguita', altre: '' });
        expect(contiDelleDelibere([], 0).principale).toBe('nessuna delibera');
    });

    test('la riga breve di una cosa: il nome e a chi, senza il titolo del punto né i due punti; la data se c\'è', () => {
        expect(rigaBreveDellaCosa(cosa({ tipo: 'crea_piano_straordinario', tipo_parola: 'Crea il piano straordinario', testo: 'Crea il piano straordinario (€ 14.500,00)',
            chi: 'Vincenzo Rossi' }))).toBe('Crea il piano straordinario · a Vincenzo Rossi');
        expect(rigaBreveDellaCosa(cosa({ tipo: 'avvisa_condomini', tipo_parola: 'Avvisa i condòmini', testo: 'Avvisa i condòmini: Ascensore scala A', chi: 'Vincenzo Rossi' })))
            .toBe('Avvisa i condòmini · a Vincenzo Rossi');
        expect(rigaBreveDellaCosa(cosa({ tipo: 'crea_piano_straordinario', tipo_parola: 'Crea il piano straordinario', chi: null, entro_il: '2027-01-15' })))
            .toBe('Crea il piano straordinario · entro il 15/01/2027');
        // Una cosa scritta a mano dice quello che c'è scritto.
        expect(rigaBreveDellaCosa(cosa({ chi: 'Andrea Costa' }))).toBe('Firma con Serramenti Marino · a Andrea Costa');
    });

    test('il pulsante nella riga è corto: «Crea il piano»; il nome intero resta nella card', () => {
        expect(nomeDelPulsante('Crea il piano straordinario')).toBe('Crea il piano');
        expect(nomeDelPulsante('Prepara il piano di ripianamento')).toBe('Prepara il piano');
        expect(nomeDelPulsante('Avvisa i condòmini')).toBe('Avvisa i condòmini');
    });
});
