<?php

/**
 * Cosa resta scoperto: la traduzione spagnola è la copia dell'inglese, come per i testi vicini di `lang/` (le righe di `es` qui sotto
 * cercano il testo inglese: se si traduce, cambiano insieme); i tre modali scrivono le etichette dei campi con le chiavi `labels` (`owner_percent`,
 * `tenant_percent`, `usufructuary_percent`) e questo test confronta il testo con quelle chiavi, lingua per lingua: che i modali le leggano, e che il
 * testo compaia solo con «Entrata», è in `SuggerimentoEntrataUsufrutto.test.ts`.
 *
 * Il testo che i tre modali di una voce di entrata mostrano sull'art. 984 c.c.: un'unica chiave in `lang/`, letta dai tre punti dove si
 * scrivono le percentuali (nuova voce, modifica, tabella collegata) e solo con il tipo «Entrata».
 *
 * Dice, in quest'ordine: che un affitto o un canone sono frutti civili e spettano all'usufruttuario (art. 984 c.c.), e che se il titolo dell'usufrutto dice altro vale fra le parti;
 * che sulle unità in usufrutto decide la voce («Usufruttuario %» a 100 all'usufruttuario, «Proprietario %» a 100 al nudo proprietario); che con
 * l'usufruttuario inserito a mano una data di inizio a metà anno non divide l'entrata giorno per giorno, salvo una riga di usufrutto chiusa il
 * giorno prima, una data di fine sì, e che i giorni li divide «Registra passaggio».
 *
 * Ogni affermazione è tenuta ferma dal motore (`Beta52UsufruttoVoceDiEntrataTest.php`): chi riceve con «Usufruttuario %» a 100 e con «Proprietario %»
 * a 100, l'usufrutto a mano che inizia senza predecessore (tutto l'anno), con un predecessore chiuso il giorno prima (giorno per giorno) e
 * che finisce a metà anno (giorno per giorno). Il testo non cita i commi dell'art. 984 c.c. e non riporta il testo degli articoli.
 *
 * L'abbinamento fra l'etichetta e il destinatario è provato in ogni lingua: con i destinatari scambiati il testo direbbe il contrario di
 * quello che il motore fa.
 *
 * L'art. 984 c.c. dà i frutti civili all'usufruttuario e non nomina il titolo: che il titolo dell'usufrutto possa dire altro, e valga fra le parti, è
 * un'osservazione a parte, e nessun testo del prodotto attribuisce all'articolo la riserva «salvo diverso titolo».
 */

const B52_CHIAVE_984 = 'gestionale.list_pages.piani_conti.show.new_entry_modal.help.income_usufruct';

/** I pezzi che il testo di ogni lingua deve contenere, nell'ordine in cui li dice; `es` è la copia dell'inglese. */
function b52SuggerimentoPezzi(): array
{
    $inglese = [
        'Rent or a fee collected by the condominium is a civil fruit and belongs to the usufructuary (art. 984 of the Italian Civil Code); if the title of the usufruct says otherwise, that applies between the parties',
        'On units under usufruct this entry decides: with «Usufructuary %» at 100 the income goes to the usufructuary, with «Owner %» at 100 to the bare owner',
        'a mid-year start date does not split the income day by day, unless a usufruct row ends the day before; an end date does',
        '«Registra passaggio» splits the days',
    ];

    return [
        'it' => [
            'Un affitto o un canone che il condominio incassa è un frutto civile',
            'e spetta all\'usufruttuario (art. 984 c.c.); se il titolo dell\'usufrutto dice altro, vale fra le parti',
            'Sulle unità in usufrutto decide questa voce',
            'con «Usufruttuario %» a 100 l\'entrata va all\'usufruttuario, con «Proprietario %» a 100 al nudo proprietario',
            'una data di inizio a metà anno non divide l\'entrata giorno per giorno, salvo una riga di usufrutto chiusa il giorno prima; una data di fine sì',
            '«Registra passaggio» divide i giorni',
        ],
        'en' => $inglese,
        'pt' => [
            'Uma renda ou taxa que o condomínio recebe é um fruto civil e cabe ao usufrutuário (art. 984 do Código Civil italiano); se o título do usufruto disser outra coisa, vale entre as partes',
            'Nas unidades em usufruto decide esta rubrica: com «Usufrutuário %» a 100 a receita vai para o usufrutuário, com «Proprietário %» a 100 para o nu-proprietário',
            'uma data de início a meio do ano não divide a receita dia a dia, salvo uma linha de usufruto terminada na véspera; uma data de fim divide',
            '«Registra passaggio» divide os dias',
        ],
        'es' => $inglese,
    ];
}

it('il suggerimento sull\'art. 984 c.c. esiste nelle quattro lingue e non è la chiave stessa', function (string $lingua) {
    $testo = trans(B52_CHIAVE_984, [], $lingua);

    expect($testo)->not->toBe(B52_CHIAVE_984)
        ->and(str_contains($testo, '984'))->toBeTrue($testo);
})->with(['it', 'en', 'pt', 'es']);

it('dice la legge, che decide la voce, quando l\'usufrutto a mano divide l\'entrata e che il giorno per giorno lo fa «Registra passaggio»', function (string $lingua) {
    $testo = trans(B52_CHIAVE_984, [], $lingua);

    foreach (b52SuggerimentoPezzi()[$lingua] as $pezzo) {
        expect(str_contains($testo, $pezzo))->toBeTrue("Manca «{$pezzo}» in ({$lingua}): {$testo}");
    }
})->with(['it', 'en', 'pt', 'es']);

it('le etichette del testo sono quelle delle chiavi `labels` della stessa lingua', function () {
    foreach (['it' => ['Proprietario %', 'Usufruttuario %'], 'en' => ['Owner %', 'Usufructuary %'], 'pt' => ['Proprietário %', 'Usufrutuário %'], 'es' => ['Owner %', 'Usufructuary %']] as $lingua => $etichette) {
        $testo = trans(B52_CHIAVE_984, [], $lingua);
        foreach ($etichette as $etichetta) {
            expect(str_contains($testo, $etichetta))->toBeTrue("Manca «{$etichetta}» in ({$lingua}): {$testo}");
            expect(trans('gestionale.list_pages.piani_conti.show.new_entry_modal.labels.' . (str_starts_with($etichetta, 'Usuf') ? 'usufructuary_percent' : 'owner_percent'), [], $lingua))->toBe($etichetta);
        }
    }
});

it('nessun testo del prodotto attribuisce all\'art. 984 c.c. la riserva «salvo diverso titolo», in nessuna lingua', function () {
    $clausola = '/984.{0,200}?(salvo diverso titolo|unless the title provides otherwise|salvo título em contrário)/su';
    $trovati = [];
    foreach (['app', 'lang', 'resources/js', 'resources/views'] as $cartella) {
        foreach (\Illuminate\Support\Facades\File::allFiles(base_path($cartella)) as $file) {
            $percorso = $file->getPathname();
            if (! preg_match('/\.(php|vue|ts|json)$/', $percorso) || str_ends_with($percorso, '.test.ts')) {
                continue;
            }
            $testo = preg_replace('/\s+/u', ' ', str_replace("\\'", "'", \Illuminate\Support\Facades\File::get($percorso)));
            if (preg_match($clausola, $testo, $m)) {
                $trovati[] = str_replace(base_path() . '/', '', $percorso) . ': …' . mb_substr($m[0], 0, 140) . '…';
            }
        }
    }

    expect($trovati)->toBe([], count($trovati) . " testi attribuiscono all'art. 984 c.c. la riserva del titolo:\n" . implode("\n", $trovati));
});

it('controprova — la guardia riconosce la riserva attribuita all\'art. 984 c.c. nelle forme di prima, e non la frase che la tiene fuori dalle parentesi dell\'articolo', function (string $testo, bool $segnalata) {
    expect(preg_match('/984.{0,200}?(salvo diverso titolo|unless the title provides otherwise|salvo título em contrário)/su', $testo) === 1)->toBe($segnalata);
})->with([
    'it, di prima' => ['è un frutto civile: per l\'art. 984 c.c. spetta all\'usufruttuario, salvo diverso titolo.', true],
    'en, di prima' => ['under art. 984 of the Italian Civil Code it belongs to the usufructuary, unless the title provides otherwise.', true],
    'pt, di prima' => ['pelo art. 984 do Código Civil italiano cabe ao usufrutuário, salvo título em contrário.', true],
    'it, di adesso' => ['spetta all\'usufruttuario (art. 984 c.c.); se il titolo dell\'usufrutto dice altro, vale fra le parti.', false],
]);
