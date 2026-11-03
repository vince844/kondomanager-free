/**
 * Code 304 e 306 — ciò che le schede dicono sulla privacy di una segnalazione.
 *
 * - La guida «Chi la vede» della scheda del condòmino segue `is_private`: dice che la segnalazione
 *   è privata solo su una privata.
 * - «Rendi pubblica» si offre solo su una privata e a chi può approvare (decisione 127.4); la
 *   conferma dice il condominio e il nome dell'autore (decisione 129.2), lo stesso nome che i
 *   condòmini vedranno: quello dell'anagrafica, e se manca quello dell'utente.
 * - Lo stato di pubblicazione: «In attesa di approvazione», «Bozza», «Pubblicata», da `is_approved`
 *   e `is_published`; la privacy è un'indicazione a parte.
 */

import { describe, expect, test } from 'vitest';
import { argomentiConfermaRendiPubblica, chiaveAutorePerdeLaScheda, chiaveConfermaRendiPubblica, guidaChiLaVede, guidaPrivacy, nomeAutore, offreRendiPubblica } from '@/lib/segnalazioni/privacy';
import { etichettaPrivacy, etichettaStatoPubblicazione, statoPubblicazione } from '@/lib/pubblicazione';

describe('la guida «Chi la vede» della scheda del condòmino', () => {
    test('su una privata dice che è privata', () => {
        expect(guidaPrivacy(true).description).toBe('segnalazioni.privacy.guide_private');
        expect(guidaPrivacy(1).description).toBe('segnalazioni.privacy.guide_private');
    });

    test('su una non privata non dice che è privata', () => {
        expect(guidaPrivacy(false).description).toBe('segnalazioni.privacy.guide_not_private');
        expect(guidaPrivacy(0).description).toBe('segnalazioni.privacy.guide_not_private');
        expect(guidaPrivacy(undefined).description).toBe('segnalazioni.privacy.guide_not_private');
    });
});

describe('«Rendi pubblica»', () => {
    test('si offre solo su una privata, e solo a chi approva', () => {
        expect(offreRendiPubblica({ is_private: true }, true)).toBe(true);
        expect(offreRendiPubblica({ is_private: 1 }, true)).toBe(true);
        expect(offreRendiPubblica({ is_private: true }, false)).toBe(false);
        expect(offreRendiPubblica({ is_private: false }, true)).toBe(false);
        expect(offreRendiPubblica({ is_private: 0 }, true)).toBe(false);
        expect(offreRendiPubblica(null, true)).toBe(false);
    });

    test('la conferma dice il condominio e il nome dell\'anagrafica dell\'autore', () => {
        const argomenti = argomentiConfermaRendiPubblica({
            created_by: { user: { name: 'mrossi' }, anagrafica: { nome: 'Mario Rossi' } },
            condominio: { option: { label: 'Condominio Aurora' }, full: { nome: 'Condominio Aurora' } },
        }, { condominio: 'il condominio', autore: 'l\'autore' });

        expect(argomenti).toEqual({ condominio: 'Condominio Aurora', autore: 'Mario Rossi' });
    });

    test('senza anagrafica il nome è quello dell\'utente; senza nessuno dei due, il ripiego', () => {
        expect(nomeAutore({ user: { name: 'mrossi' }, anagrafica: null })).toBe('mrossi');
        expect(nomeAutore({ user: { name: 'mrossi' }, anagrafica: { nome: '' } })).toBe('mrossi');
        expect(nomeAutore(null)).toBeNull();

        const argomenti = argomentiConfermaRendiPubblica({ created_by: null, condominio: null }, { condominio: 'il condominio', autore: 'l\'autore' });
        expect(argomenti).toEqual({ condominio: 'il condominio', autore: 'l\'autore' });
    });
});

describe('«Rendi pubblica» su una privata che i condòmini non vedono ancora', () => {
    // Il comando toglie la privacy, non approva e non pubblica (decisione 127.2): «la vedranno tutti»
    // solo se è già pubblicata e approvata, altrimenti «quando sarà pubblicata e approvata».
    test('pubblicata e approvata: la conferma dice che la vedranno tutti', () => {
        expect(chiaveConfermaRendiPubblica({ is_published: true, is_approved: true })).toBe('segnalazioni.privacy.make_public_description');
        expect(chiaveConfermaRendiPubblica({ is_published: 1, is_approved: 1 })).toBe('segnalazioni.privacy.make_public_description');
    });

    test.each([
        [false, false, 'in attesa di approvazione, come la apre dal portale un condòmino di serie'],
        [true, false, 'pubblicata ma non approvata'],
        [false, true, 'approvata e rimessa in bozza'],
        [0, 1, 'in bozza, con i flag come numeri'],
    ])('is_published=%s, is_approved=%s (%s): la conferma dice «quando sarà pubblicata e approvata»', (pubblicata, approvata, _caso) => {
        expect(chiaveConfermaRendiPubblica({ is_published: pubblicata, is_approved: approvata })).toBe('segnalazioni.privacy.make_public_description_pending');
    });

    test('senza i due flag non promette che la vedono già', () => {
        expect(chiaveConfermaRendiPubblica({})).toBe('segnalazioni.privacy.make_public_description_pending');
        expect(chiaveConfermaRendiPubblica(null)).toBe('segnalazioni.privacy.make_public_description_pending');
    });
});

describe('lo stato di pubblicazione e la privacy', () => {
    test.each([
        [false, false, 'pending'],
        [true, false, 'pending'],
        [false, true, 'draft'],
        [true, true, 'published'],
        [0, 1, 'draft'],
        [1, 1, 'published'],
    ])('is_published=%s, is_approved=%s → %s', (pubblicata, approvata, atteso) => {
        expect(statoPubblicazione(pubblicata, approvata)).toBe(atteso);
    });

    test('le chiavi seguono l\'entità', () => {
        expect(etichettaStatoPubblicazione('comunicazioni', true, false)).toBe('comunicazioni.publication.pending');
        expect(etichettaStatoPubblicazione('segnalazioni', true, true)).toBe('segnalazioni.publication.published');
        expect(etichettaPrivacy('segnalazioni', true)).toBe('segnalazioni.privacy.private');
        expect(etichettaPrivacy('comunicazioni', 0)).toBeNull();
    });
});

describe('«Rendi pubblica»: l\'autore che dopo non la vedrà più (Coda 304)', () => {
    test('la frase in più c\'è solo quando il server dice che l\'autore perde la scheda', () => {
        expect(chiaveAutorePerdeLaScheda(true)).toBe('segnalazioni.privacy.make_public_author_loses_access');
        expect(chiaveAutorePerdeLaScheda(1)).toBe('segnalazioni.privacy.make_public_author_loses_access');
        expect(chiaveAutorePerdeLaScheda(false)).toBeNull();
        expect(chiaveAutorePerdeLaScheda(undefined)).toBeNull();
    });
});

describe('la guida «Chi la vede» secondo lo stato (Code 306 e 198)', () => {
    test('finché non è pubblicata e approvata dice che per ora non la vede nessun altro', () => {
        for (const [pubblicata, approvata] of [[false, false], [true, false], [false, true], [0, 1], [1, 0], [undefined, undefined]]) {
            expect(guidaChiLaVede({ is_private: false, is_published: pubblicata, is_approved: approvata }).description)
                .toBe('segnalazioni.privacy.guide_not_private_pending');
            expect(guidaChiLaVede({ is_private: true, is_published: pubblicata, is_approved: approvata }).description)
                .toBe('segnalazioni.privacy.guide_private_pending');
        }
    });

    test('pubblicata e approvata segue `is_private`, come prima', () => {
        expect(guidaChiLaVede({ is_private: false, is_published: true, is_approved: true }).description).toBe('segnalazioni.privacy.guide_not_private');
        expect(guidaChiLaVede({ is_private: 1, is_published: 1, is_approved: 1 }).description).toBe('segnalazioni.privacy.guide_private');
        expect(guidaChiLaVede({ is_private: true, is_published: true, is_approved: true }).title).toBe('segnalazioni.privacy.guide_title');
    });
});
