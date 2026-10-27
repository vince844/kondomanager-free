// @vitest-environment jsdom
import fs from 'node:fs';
import { afterEach, describe, expect, it, vi } from 'vitest';

/**
 * La pagina 503 di un ripristino fermo (`resources/views/restore/in-progress.blade.php`) non stampa versione ed errore:
 * la vede chiunque apra il gestionale. Li scrive nel log, da copiare per l'assistenza, solo dopo «Riprendi» con la
 * password giusta, se il ripristino si ferma di nuovo: la risposta di chi ha il token o la password porta lo stato
 * completo (Coda 343, decisione 127.5).
 *
 * La pagina è statica, senza la build: lo script vive nel template. Il test monta il ramo del recupero e il suo script
 * come li scrive il template, con i valori di Blade al posto delle espressioni, e guida i pulsanti con le risposte del
 * server finte.
 */

const TESTI = {
    copied: 'Copiato', copy: 'Copia log', failed_again: 'Il ripristino si è fermato di nuovo.', resume_progress: 'Ripristino in corso…',
    need_password: 'Inserisci la password.', working: 'Attendere…', too_many: 'Troppi tentativi.', auth_failed: 'Password non corretta.',
    auth_unavailable: 'La password non si può verificare.', generic_error: 'Errore.',
    failed_body: 'Il database potrebbe essere incompleto.', failed_body_untouched: 'Fermo nel backup di sicurezza: database non toccato.',
    abort_note: 'Il database potrebbe restare incompleto.', abort_note_untouched: 'Il ripristino non ha toccato il database.',
};

const LOG_INIZIALE = 'KondoManager restore log\nuuid: r1\nfailed_phase: importing_database\nfailed_at: —';

function montaLaPagina(): void {
    const sorgente = fs.readFileSync('resources/views/restore/in-progress.blade.php', 'utf8');

    const inizio = sorgente.indexOf('@if($stuck)');
    const markup = sorgente.slice(inizio + '@if($stuck)'.length, sorgente.indexOf('@else', inizio)).replace(/\{\{[\s\S]*?\}\}/g, '');
    const script = sorgente
        .slice(sorgente.indexOf('<script>') + '<script>'.length, sorgente.indexOf('</script>'))
        .replace('@json($t)', JSON.stringify(TESTI))
        .replace(/@json\(url\('([^']+)'\)\)/g, (_, percorso: string) => JSON.stringify('/' + percorso));

    document.body.innerHTML = `<div class="card">${markup}</div>`;
    (document.getElementById('restoreLog') as HTMLElement).textContent = LOG_INIZIALE;

    new Function(script)();
}

type Risposta = { status: number; corpo?: unknown };

function serverFinto(risposte: Record<string, Risposta>) {
    const chiamate: Array<{ url: string; headers: Record<string, string>; corpo: unknown }> = [];

    const finto = vi.fn(async (url: string, opzioni: RequestInit) => {
        chiamate.push({ url, headers: opzioni.headers as Record<string, string>, corpo: JSON.parse(String(opzioni.body)) });
        const r = risposte[url] ?? { status: 500 };

        return { status: r.status, ok: r.status >= 200 && r.status < 300, json: async () => r.corpo };
    });
    vi.stubGlobal('fetch', finto);

    return chiamate;
}

async function attendi(): Promise<void> {
    for (let i = 0; i < 10; i++) {
        await new Promise((risolvi) => setTimeout(risolvi, 0));
    }
}

function premiRiprendi(password: string): void {
    (document.getElementById('accpwd') as HTMLInputElement).value = password;
    (document.getElementById('resumeBtn') as HTMLButtonElement).click();
}

const STATO_FERMO_DI_NUOVO = {
    phase: 'failed', uuid: 'r1', app_version: '1.11.0-beta.57', failed_phase: 'importing_database', failed_at: 1761553200,
    error: 'SQLSTATE[23000]: chiave doppia',
};

afterEach(() => {
    vi.unstubAllGlobals();
    document.body.innerHTML = '';
});

describe('la pagina 503 di un ripristino fermo', () => {
    it('dopo «Riprendi» con la password giusta, se il ripristino si ferma di nuovo, il log porta versione ed errore', async () => {
        montaLaPagina();
        const chiamate = serverFinto({
            '/ripristino/riprendi': { status: 200, corpo: { token: 'token-nuovo', restore: { phase: 'importing_database' } } },
            '/ripristino/step': { status: 200, corpo: { restore: STATO_FERMO_DI_NUOVO } },
        });

        premiRiprendi('la-mia-password');
        await attendi();

        const log = (document.getElementById('restoreLog') as HTMLElement).textContent ?? '';
        expect(log).toContain('version: 1.11.0-beta.57');
        expect(log).toContain('error: SQLSTATE[23000]: chiave doppia');
        expect(log).toContain('failed_phase: importing_database');
        expect(log).toContain('failed_at: 2025-10-27 08:20:00');
        expect((document.querySelector('details') as HTMLDetailsElement).open).toBe(true);
        expect(document.getElementById('msg')?.textContent).toBe(TESTI.failed_again);

        // «Riprendi» con la password, poi lo step con il token che «Riprendi» ha dato.
        expect(chiamate.map((c) => c.url)).toEqual(['/ripristino/riprendi', '/ripristino/step']);
        expect(chiamate[0].corpo).toEqual({ account_password: 'la-mia-password' });
        expect(chiamate[1].headers['X-Restore-Token']).toBe('token-nuovo');
    });

    it('con la password sbagliata il log resta senza versione ed errore', async () => {
        montaLaPagina();
        serverFinto({ '/ripristino/riprendi': { status: 422, corpo: { message: 'Password non corretta.' } } });

        premiRiprendi('sbagliata');
        await attendi();

        const log = (document.getElementById('restoreLog') as HTMLElement).textContent ?? '';
        expect(log).toBe(LOG_INIZIALE);
        expect(log).not.toContain('version:');
        expect(log).not.toContain('error:');
        expect(document.getElementById('msg')?.textContent).toBe(TESTI.auth_failed);
    });

    it.each([
        ['Riprendi', 'resumeBtn', '/ripristino/riprendi'],
        ['Sblocca', 'abortBtn', '/ripristino/annulla'],
    ])('quando il server risponde che la password non si può verificare (409), «%s» lo dice invece di «Password non corretta.»', async (_, pulsante, url) => {
        montaLaPagina();
        serverFinto({ [url]: { status: 409, corpo: { message: 'La password non si può verificare.' } } });

        (document.getElementById('accpwd') as HTMLInputElement).value = 'la-mia-password';
        (document.getElementById(pulsante) as HTMLButtonElement).click();
        await attendi();

        expect(document.getElementById('msg')?.textContent).toBe(TESTI.auth_unavailable);
        expect((document.getElementById('resumeBtn') as HTMLButtonElement).disabled).toBe(false);
        expect((document.getElementById('restoreLog') as HTMLElement).textContent).toBe(LOG_INIZIALE);
    });

    // Fermo nel backup di sicurezza la pagina dice che il database non è stato toccato (lo scrive Blade). Dopo «Riprendi»
    // il ripristino può andare oltre e toccarlo: se si ferma di nuovo, la frase e la nota seguono la fase nuova.
    function fermoNelBackupDiSicurezza(): void {
        montaLaPagina();
        (document.getElementById('failedBody') as HTMLElement).textContent = TESTI.failed_body_untouched;
        (document.getElementById('abortNote') as HTMLElement).textContent = TESTI.abort_note_untouched;
    }

    it('fermo nel backup di sicurezza, dopo «Riprendi» con la password il ripristino si ferma di nuovo nell\'import: la frase e la nota tornano a dire che il database potrebbe essere incompleto', async () => {
        fermoNelBackupDiSicurezza();
        serverFinto({
            '/ripristino/riprendi': { status: 200, corpo: { token: 'token-nuovo', restore: { phase: 'importing_database', failed_phase: null } } },
            '/ripristino/step': { status: 200, corpo: { restore: STATO_FERMO_DI_NUOVO } },
        });

        premiRiprendi('la-mia-password');
        await attendi();

        expect(document.getElementById('msg')?.textContent).toBe(TESTI.failed_again);
        expect(document.getElementById('failedBody')?.textContent).toBe(TESTI.failed_body);
        expect(document.getElementById('abortNote')?.textContent).toBe(TESTI.abort_note);
    });

    it('fermo nel backup di sicurezza, dopo «Riprendi» con la password il backup di sicurezza riparte e si ferma di nuovo: la frase e la nota tornano a dire che il database non è stato toccato', async () => {
        fermoNelBackupDiSicurezza();
        serverFinto({
            '/ripristino/riprendi': { status: 200, corpo: { token: 'token-nuovo', restore: { phase: 'safety_backup', failed_phase: null } } },
            '/ripristino/step': { status: 200, corpo: { restore: { ...STATO_FERMO_DI_NUOVO, failed_phase: 'safety_backup', error: 'Il backup di sicurezza non è riuscito.' } } },
        });

        premiRiprendi('la-mia-password');
        await attendi();

        expect(document.getElementById('msg')?.textContent).toBe(TESTI.failed_again);
        expect(document.getElementById('failedBody')?.textContent).toBe(TESTI.failed_body_untouched);
        expect(document.getElementById('abortNote')?.textContent).toBe(TESTI.abort_note_untouched);
    });

    it('fermo nel backup di sicurezza, mentre «Riprendi» con la password fa andare avanti il ripristino la frase e la nota non dicono più che il database non è stato toccato', async () => {
        fermoNelBackupDiSicurezza();
        // Il passo dopo «Riprendi» è ancora in corso: la risposta non arriva. La risposta di «Riprendi» porta la fase del
        // fallimento di prima, com'era scritta negli stati di prima: in corso non conta.
        vi.stubGlobal('fetch', vi.fn(async (url: string) => (url === '/ripristino/riprendi'
            ? { status: 200, ok: true, json: async () => ({ token: 'token-nuovo', restore: { phase: 'importing_database', failed_phase: 'safety_backup' } }) }
            : new Promise(() => {}))));

        premiRiprendi('la-mia-password');
        await attendi();

        expect(document.getElementById('msg')?.textContent).toBe(TESTI.resume_progress);
        expect(document.getElementById('failedBody')?.textContent).toBe(TESTI.failed_body);
        expect(document.getElementById('abortNote')?.textContent).toBe(TESTI.abort_note);
    });

    // Una sola richiesta di «Riprendi» rifà il backup di sicurezza, estrae, verifica e toglie il database. Se la risposta non
    // arriva, o arriva un errore del server, il ripristino può essere andato oltre: la pagina non lo sa, e torna alle frasi
    // generiche accanto a «Sblocca», di nuovo attivo.
    function laFraseELaNotaSonoGeneriche(): void {
        expect(document.getElementById('msg')?.textContent).toBe(TESTI.generic_error);
        expect(document.getElementById('failedBody')?.textContent).toBe(TESTI.failed_body);
        expect(document.getElementById('abortNote')?.textContent).toBe(TESTI.abort_note);
        expect((document.getElementById('abortBtn') as HTMLButtonElement).disabled).toBe(false);
        expect((document.getElementById('restoreLog') as HTMLElement).textContent).toBe(LOG_INIZIALE);
    }

    it.each([500, 502, 503, 504])('fermo nel backup di sicurezza, «Riprendi» con la password riceve un errore del server (%s): la frase e la nota non dicono più che il database non è stato toccato', async (status) => {
        fermoNelBackupDiSicurezza();
        serverFinto({ '/ripristino/riprendi': { status, corpo: { message: 'Server Error' } } });

        premiRiprendi('la-mia-password');
        await attendi();

        laFraseELaNotaSonoGeneriche();
    });

    it.each([
        ['la rete che cade', async () => { throw new TypeError('Failed to fetch'); }],
        ['una risposta che non si legge', async () => ({ status: 200, ok: true, json: async () => { throw new SyntaxError('Unexpected token <'); } })],
    ])('fermo nel backup di sicurezza, «Riprendi» con la password senza una risposta leggibile (%s): la frase e la nota non dicono più che il database non è stato toccato', async (_, risposta) => {
        fermoNelBackupDiSicurezza();
        vi.stubGlobal('fetch', vi.fn(risposta));

        premiRiprendi('la-mia-password');
        await attendi();

        laFraseELaNotaSonoGeneriche();
    });

    // Un 4xx di «Riprendi» viene dall'autorizzazione, prima di ogni azione: il ripristino è fermo dove era.
    it.each([
        [422, TESTI.auth_failed],
        [409, TESTI.auth_unavailable],
        [429, TESTI.too_many],
        [404, TESTI.generic_error],
    ])('fermo nel backup di sicurezza, «Riprendi» con la password rifiutato prima di agire (%s): la frase e la nota del database non toccato restano', async (status, messaggio) => {
        fermoNelBackupDiSicurezza();
        serverFinto({ '/ripristino/riprendi': { status, corpo: { message: 'x' } } });

        premiRiprendi('la-mia-password');
        await attendi();

        expect(document.getElementById('msg')?.textContent).toBe(messaggio);
        expect(document.getElementById('failedBody')?.textContent).toBe(TESTI.failed_body_untouched);
        expect(document.getElementById('abortNote')?.textContent).toBe(TESTI.abort_note_untouched);
    });
});
