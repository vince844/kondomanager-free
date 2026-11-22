<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Seguito;

use App\Enums\Assemblee\RuoloCollegamento;
use App\Enums\Assemblee\TipoCosaDaFare;
use App\Helpers\DateHelper;
use App\Models\Assemblee\CollegamentoAllaDelibera;
use App\Models\Assemblee\CosaDaFareDellaDelibera;
use App\Models\Assemblee\Delibera;
use App\Models\Assemblee\PuntoDellOrdineDelGiorno;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Fornitore;
use App\Models\Gestione;
use App\Models\Palazzina;
use App\Models\Scala;
use App\Models\Tabella;
use App\Models\User;
use App\Services\Assemblee\CollegamentiDelleDelibere;
use App\Services\Assemblee\DeliberaInParole;
use App\Services\Gestionale\CreazioneDellaGestione;
use App\Services\Gestionale\CreazioneDellaVoce;
use App\Services\RegistroModifiche\FormatoDelValore;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Crea il piano straordinario» dalla cosa da fare di una delibera di lavori (decisione 622 (1), la scena 2 della bozza approvata con la decisione 623):
 * **prepara tutto, salvi tu**.
 *
 * - {@see proposta()}: ciò che la finestra mostra, preso dalla delibera: il nome della gestione, l'esercizio e il periodo (quello aperto che contiene il
 *   giorno «entro il» della cosa), la voce, l'importo deliberato, la tabella del punto (o «Proprietà»), il fornitore facoltativo. Con l'esito «approvata
 *   con modifiche» (decisione 637 (1)) in testa «Che cosa ha cambiato l'assemblea», e l'importo da scrivere: la cifra di prima non è deliberata;
 * - {@see prepara()}: confermata la finestra, nascono **la gestione straordinaria, il suo piano dei conti e la voce** per le strade di oggi
 *   (`CreazioneDellaGestione`, il corpo di «Nuova gestione»; `CreazioneDellaVoce`, il corpo di «Nuova voce»), e la gestione si lega alla delibera (ruolo
 *   `delibera`): le fatture e gli stati di avanzamento che verranno la trovano proposta in «Scegli la delibera». **Il piano no**: la risposta è l'indirizzo
 *   del nuovo piano già compilato con la gestione, la voce e la delibera; il piano nasce con «Salva il piano», e allora la cosa si chiude da sola (R5).
 * - Una gestione già legata alla delibera (quella da cui il punto è nato, o una creata prima da questa finestra) non si crea di nuovo: la finestra porta al
 *   piano di quella. Il controllo sta nella transazione, con la cosa bloccata: due «Crea e apri il piano» quasi insieme non fanno due gestioni.
 *
 * **Il denaro**: l'importo arriva in centesimi (lo converte il controllore, al confine); quello della delibera è già in centesimi. Nessuna scrittura sui moduli
 * fuori dai loro servizi (R3).
 */
final class PianoStraordinario
{
    /** La frase della finestra sotto la voce: la regola delle spese straordinarie. */
    public const A_CARICO = 'A carico del proprietario, come ogni spesa straordinaria. Se cambi l\'importo, il riepilogo dice la differenza dalla delibera.';

    /** La stessa frase con le modifiche dell'assemblea: non c'è una cifra deliberata con cui confrontare. */
    public const A_CARICO_CON_MODIFICHE = 'A carico del proprietario, come ogni spesa straordinaria.';

    /**
     * Ciò che la finestra mostra.
     *
     * @return array<string, mixed>
     */
    public static function proposta(CosaDaFareDellaDelibera $cosa): array
    {
        $delibera = self::deliberaDellaCosa($cosa);
        $condominio = Condominio::query()->findOrFail($delibera->condominio_id);
        $punto = $delibera->punto;
        $conModifiche = $delibera->esito === \App\Enums\Assemblee\EsitoDelibera::APPROVATA_CON_MODIFICHE;
        // Con le modifiche la cifra di prima non è deliberata: l'importo si scrive nella finestra.
        $importo = $conModifiche ? null : (int) ($delibera->importo ?? $punto?->importo ?? 0);
        $esercizi = self::esercizi($condominio);
        $scelto = self::esercizioProposto($esercizi, $cosa->entro_il?->format('Y-m-d'));
        $esistente = self::gestioneDellaDelibera($delibera);
        $nome = self::nomeDalPunto((string) ($punto?->testo ?? $delibera->testo));

        return [
            'cosa' => ['id' => (int) $cosa->id, 'testo' => $cosa->testo, 'da_fare' => $cosa->eDaFare()],
            'delibera' => ['id' => (int) $delibera->id, 'frase' => self::fraseDellaDelibera($delibera), 'importo' => $importo],
            // «Che cosa ha cambiato l'assemblea», scritto alla conferma; null senza le modifiche.
            'modifiche' => $conModifiche ? (trim((string) (((array) ($delibera->dati ?? []))['modifiche'] ?? '')) ?: 'L\'assemblea l\'ha approvata con modifiche.') : null,
            'gestione_esistente' => $esistente === null ? null : ['id' => (int) $esistente->id, 'nome' => $esistente->nome,
                'url' => self::indirizzoDelPiano($condominio, $esistente, $delibera)],
            'gestione' => [
                'nome' => $nome . ($scelto !== null ? ' ' . substr((string) $scelto->data_fine?->format('Y-m-d'), 0, 4) : ''),
                'esercizio_id' => $scelto?->id,
                'data_inizio' => $scelto?->data_inizio?->format('Y-m-d'),
                'data_fine' => $scelto?->data_fine?->format('Y-m-d'),
            ],
            'esercizi' => $esercizi->map(fn (Esercizio $e) => ['id' => (int) $e->id, 'nome' => $e->nome, 'data_inizio' => $e->data_inizio?->format('Y-m-d'),
                'data_fine' => $e->data_fine?->format('Y-m-d')])->values()->all(),
            'voce' => ['nome' => $nome, 'importo' => $importo, 'tabella_id' => self::tabellaProposta($condominio, $punto), 'fornitore_id' => null],
            'tabelle' => Tabella::query()->where('condominio_id', $condominio->id)->orderBy('nome')->get(['id', 'nome'])
                ->map(fn (Tabella $t) => ['id' => (int) $t->id, 'nome' => $t->nome])->values()->all(),
            'fornitori' => Fornitore::attivi()->orderBy('ragione_sociale')->get(['id', 'ragione_sociale'])
                ->map(fn (Fornitore $f) => ['id' => (int) $f->id, 'nome' => $f->ragione_sociale])->values()->all(),
            'a_carico' => $conModifiche ? self::A_CARICO_CON_MODIFICHE : self::A_CARICO,
        ];
    }

    /**
     * La finestra confermata: la gestione, il suo piano dei conti e la voce, la gestione legata alla delibera; la risposta è l'indirizzo del nuovo piano già
     * compilato. Con una gestione già legata alla delibera niente nasce: l'indirizzo è quello del piano di quella.
     *
     * @param array{gestione_nome: string, esercizio_id: int, data_inizio: string, data_fine: string, voce_nome: string, importo: int, tabella_id: int, fornitore_id: ?int} $dati
     * @return array{url: string, gestione_id: int, creata: bool, frase: string}
     */
    public static function prepara(CosaDaFareDellaDelibera $cosa, array $dati, User $utente): array
    {
        $delibera = self::deliberaDellaCosa($cosa);
        $condominio = Condominio::query()->findOrFail($delibera->condominio_id);
        // La descrizione resta scritta nella gestione: il giorno e il punto, senza lo stato del verbale (decisione 637 (2)).
        $descrizione = 'Dalla ' . lcfirst(DeliberaInParole::breve($delibera, scritta: true)) . '.';

        return DB::transaction(function () use ($cosa, $condominio, $dati, $delibera, $descrizione, $utente) {
            // La cosa bloccata fino alla fine: una seconda richiesta quasi insieme aspetta qui, e trova la gestione già legata.
            $cosa = CosaDaFareDellaDelibera::query()->lockForUpdate()->findOrFail($cosa->id);
            if (! $cosa->eDaFare()) {
                throw ValidationException::withMessages(['cosa' => 'Questa cosa da fare è già chiusa: il piano c\'è, o non serve più.']);
            }
            $esistente = self::gestioneDellaDelibera($delibera);
            if ($esistente !== null) {
                return ['url' => self::indirizzoDelPiano($condominio, $esistente, $delibera), 'gestione_id' => (int) $esistente->id, 'creata' => false,
                    'frase' => 'La gestione «' . $esistente->nome . '» è già legata alla delibera: si apre il suo piano.'];
            }
            $esercizio = Esercizio::query()->where('condominio_id', $condominio->id)->whereKey($dati['esercizio_id'])->first()
                ?? throw ValidationException::withMessages(['esercizio_id' => 'Scegli un esercizio del condominio.']);
            $tabella = Tabella::query()->where('condominio_id', $condominio->id)->whereKey($dati['tabella_id'])->first()
                ?? throw ValidationException::withMessages(['tabella_id' => 'Scegli una tabella del condominio.']);
            if ((int) $dati['importo'] <= 0) {
                throw ValidationException::withMessages(['importo' => 'Scrivi l\'importo della voce.']);
            }
            $gestione = CreazioneDellaGestione::crea($condominio, $esercizio, ['nome' => trim($dati['gestione_nome']), 'descrizione' => $descrizione,
                'data_inizio' => $dati['data_inizio'], 'data_fine' => $dati['data_fine'], 'tipo' => 'straordinaria']);
            $pianoDeiConti = CreazioneDellaGestione::pianoDeiConti($gestione, $descrizione);
            $voce = CreazioneDellaVoce::crea($condominio, $pianoDeiConti, ['nome' => trim($dati['voce_nome']), 'tipo' => 'spesa', 'tipo_spesa' => 'lavori',
                'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false, 'importo' => (int) $dati['importo'],
                'tabella_millesimale_id' => $tabella->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
                'default_fornitore_id' => $dati['fornitore_id'] ?? null]);
            CollegamentiDelleDelibere::collega($gestione, RuoloCollegamento::DELIBERA->value, $delibera, null, $utente);

            return ['url' => self::indirizzoDelPiano($condominio, $gestione, $delibera, $esercizio, (int) $voce->id), 'gestione_id' => (int) $gestione->id,
                'creata' => true, 'frase' => 'Nate la gestione «' . $gestione->nome . '», il suo piano dei conti e la voce «' . $voce->nome . '» da '
                    . FormatoDelValore::testo((int) $voce->importo, 'euro') . '. Il piano nasce quando lo salvi.'];
        });
    }

    /** La delibera della cosa, solo per «Crea il piano straordinario» di una delibera approvata. */
    private static function deliberaDellaCosa(CosaDaFareDellaDelibera $cosa): Delibera
    {
        if ($cosa->tipo !== TipoCosaDaFare::CREA_PIANO_STRAORDINARIO) {
            throw ValidationException::withMessages(['cosa' => 'Questa cosa da fare non crea un piano straordinario.']);
        }
        $delibera = $cosa->delibera()->with(['assemblea', 'punto'])->firstOrFail();
        if (! $delibera->eApprovata()) {
            throw ValidationException::withMessages(['cosa' => 'La delibera non è approvata: il piano straordinario non si crea.']);
        }

        return $delibera;
    }

    /** «Dalla delibera del 15/12/2026, punto 4 (verbale n. 42): «Portone: € 14.500,00 con Serramenti Marino».» */
    public static function fraseDellaDelibera(Delibera $delibera): string
    {
        $testo = trim(preg_replace('/\s+/u', ' ', (string) ($delibera->punto?->testo ?? $delibera->testo)) ?? '');

        return 'Dalla ' . lcfirst(DeliberaInParole::breve($delibera)) . ($testo !== '' ? ': «' . rtrim($testo, '.') . '»' : '') . '.';
    }

    /** La gestione già legata alla delibera con il ruolo `delibera`, se c'è. */
    private static function gestioneDellaDelibera(Delibera $delibera): ?Gestione
    {
        $id = CollegamentoAllaDelibera::query()->where('delibera_id', $delibera->id)->where('oggetto_tipo', 'gestione')
            ->where('ruolo', RuoloCollegamento::DELIBERA->value)->orderBy('id')->value('oggetto_id');

        return $id === null ? null : Gestione::query()->where('condominio_id', $delibera->condominio_id)->find((int) $id);
    }

    /** Gli esercizi aperti del condominio, dal più vecchio. @return \Illuminate\Support\Collection<int, Esercizio> */
    private static function esercizi(Condominio $condominio): \Illuminate\Support\Collection
    {
        return Esercizio::query()->where('condominio_id', $condominio->id)->where('stato', 'aperto')->orderBy('data_inizio')->get();
    }

    /**
     * L'esercizio proposto: quello aperto che contiene il giorno «entro il» della cosa (il portone deliberato il 15/12/2026 per il 2027, da fare entro il
     * 15/01/2027: il 2027, non il 2026 che si chiude fra quindici giorni); senza quel giorno, o senza un esercizio aperto che lo contiene, quello di oggi se
     * è aperto; altrimenti l'ultimo aperto.
     *
     * @param \Illuminate\Support\Collection<int, Esercizio> $esercizi
     */
    private static function esercizioProposto(\Illuminate\Support\Collection $esercizi, ?string $entroIl = null): ?Esercizio
    {
        $contiene = fn (string $giorno) => $esercizi->first(fn (Esercizio $e) => $e->data_inizio?->format('Y-m-d') <= $giorno
            && $e->data_fine?->format('Y-m-d') >= $giorno);

        return ($entroIl !== null ? $contiene($entroIl) : null) ?? $contiene(DateHelper::oggiUtente()) ?? $esercizi->last();
    }

    /**
     * La tabella proposta: quella del punto; poi quella segnata come di proprietà generale (la scala o la palazzina del punto, poi il condominio: decisione 609);
     * poi quella che si chiama «Proprietà…» o «Generale»; altrimenti nessuna, e la finestra chiede di sceglierla: mai la prima a caso, che a volte è quella
     * dei consumi d'acqua.
     */
    private static function tabellaProposta(Condominio $condominio, ?PuntoDellOrdineDelGiorno $punto): ?int
    {
        $tabelle = Tabella::query()->where('condominio_id', $condominio->id)->orderBy('id')->get(['id', 'nome']);
        $delPunto = $punto?->tabella_id === null ? null : $tabelle->firstWhere('id', $punto->tabella_id);
        $perimetri = array_filter([$punto?->scala_id !== null ? Scala::query()->find($punto->scala_id) : null,
            $punto?->palazzina_id !== null ? Palazzina::query()->find($punto->palazzina_id) : null, $condominio]);
        $segnata = null;
        foreach ($perimetri as $perimetro) {
            $segnata = $perimetro->getAttribute('tabella_proprieta_id');
            if ($segnata !== null) {
                $segnata = $tabelle->firstWhere('id', $segnata);
                if ($segnata !== null) {
                    break;
                }
            }
        }
        $dalNome = $tabelle->first(function (Tabella $t) {
            $nome = mb_strtolower(trim((string) $t->nome));

            return str_starts_with($nome, 'propriet') || str_starts_with($nome, 'generale');
        });

        return ($delPunto ?? $segnata ?? $dalNome)?->id;
    }

    /** «Portone: € 14.500,00 con Serramenti Marino, fondo speciale» → «Portone». */
    public static function nomeDalPunto(string $testo): string
    {
        $testo = trim(preg_replace('/\s+/u', ' ', $testo) ?? '');
        $pezzo = trim((string) preg_split('/[:,;(]/u', $testo, 2)[0]);
        if ($pezzo === '') {
            $pezzo = 'Lavori straordinari';
        }

        return mb_strlen($pezzo) > 120 ? rtrim(mb_substr($pezzo, 0, 119)) . '…' : $pezzo;
    }

    /**
     * Il nuovo piano già compilato: la gestione, la voce (se è una sola), la delibera. L'esercizio è quello dato, altrimenti quello della gestione che
     * contiene oggi, altrimenti l'ultimo della gestione.
     */
    private static function indirizzoDelPiano(Condominio $condominio, Gestione $gestione, Delibera $delibera, ?Esercizio $esercizio = null, ?int $voce = null): string
    {
        if ($esercizio === null) {
            $suoi = $gestione->esercizi()->orderBy('esercizi.data_inizio')->get();
            $esercizio = self::esercizioProposto($suoi) ?? $suoi->last() ?? Esercizio::query()->where('condominio_id', $condominio->id)->orderByDesc('data_inizio')->first();
        }
        if ($voce === null) {
            $voci = DB::table('conti')->join('piani_conti', 'piani_conti.id', '=', 'conti.piano_conto_id')->where('piani_conti.gestione_id', $gestione->id)
                ->whereNull('conti.parent_id')->where('conti.tipo', 'spesa')->pluck('conti.id');
            $voce = $voci->count() === 1 ? (int) $voci->first() : null;
        }

        return route('admin.gestionale.esercizi.piani-rate.create', array_filter(['condominio' => $condominio->id, 'esercizio' => $esercizio?->id,
            'gestione_id' => $gestione->id, 'conto_id' => $voce, 'delibera_id' => $delibera->id], fn ($v) => $v !== null), false);
    }
}
