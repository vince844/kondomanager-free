/**
 * La schermata «Aggiornamento di sistema» dice quello che l'installatore fa davvero.
 *
 * Diceva che il processo «eseguirà un backup automatico dei file critici». L'installatore
 * (`resources/installer/index.php`, fase 3) copia in `BACKUP_DIR` il solo `.env`: il suo ripristino, se
 * l'aggiornamento fallisce, rimette a posto quel file e niente altro. La copia del database è un'altra cosa,
 * un'altra schermata (`Confirm.vue`), e solo dove l'infrastruttura di backup c'è già.
 *
 * Si leggono i sorgenti, come nei test delle guide: il primo controllo fissa il fatto (che cosa copia la
 * fase 3 dell'installatore), il secondo la frase. Se la fase 3 cominciasse a copiare altro, si cambia il fatto
 * qui e la frase nella schermata.
 */
import { describe, expect, test } from 'vitest';
import fs from 'node:fs';

const installatore = fs.readFileSync('resources/installer/index.php', 'utf8');
const schermata = fs.readFileSync('resources/js/pages/system/upgrade/Index.vue', 'utf8').replace(/\s+/g, ' ');

describe('Aggiornamento di sistema — il backup che la schermata promette', () => {
    test('la fase 3 dell\'installatore copia in BACKUP_DIR il solo .env', () => {
        const inizio = installatore.indexOf('PHASE 3: BACKUP');
        const fine = installatore.indexOf('PHASE 4: EXTRACTION');
        expect(inizio).toBeGreaterThan(0);
        expect(fine).toBeGreaterThan(inizio);

        const fase = installatore.slice(inizio, fine);
        const copie = [...fase.matchAll(/@?copy\(([^,]+),\s*([^)]+)\)/g)];

        expect(copie).toHaveLength(1);
        expect(copie[0][1]).toContain("'/.env'");
        expect(copie[0][2]).toContain("'/.env'");
    });

    test('la frase non promette un backup dei «file critici» ma dice che si tiene da parte il .env', () => {
        expect(schermata).not.toContain('backup automatico dei file critici');
        expect(schermata).toContain('terrà da parte una copia del file .env');
    });

    test('la frase manda il backup del database alla schermata dopo', () => {
        expect(schermata).toContain('Il backup del database, quando è disponibile, si fa nella schermata successiva');
    });
});
