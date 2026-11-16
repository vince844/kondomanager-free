<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Gestionale\Inbox;

use App\Helpers\DateHelper;
use App\Helpers\MoneyHelper;
use App\Models\Comunicazione;
use App\Models\Evento;
use App\Models\Segnalazione;
use App\Services\Treasury\DisponibilitaDiOggi;
use Illuminate\Support\Str;

/**
 * I tipi dei compiti dell'Action inbox, in una mappa sola: per ogni tipo (i casi di `EventoTipo` e i tipi che i listener scrivono solo in `meta.type`) il
 * nome nel grafico «Per tipo», il testo della prossima azione e se il tipo ha una pagina sua. Con la pagina, il pulsante porta al collegamento salvato nel
 * compito (`meta.action_url`, letto con {@see IndirizzoDelCompito}), alla pagina del condominio che il tipo dice (le ritenute: «Ritenute e F24»; il deficit
 * ereditato: un piano rate straordinario nell'esercizio aperto di oggi; l'eccedenza del già versato: il «Già versato» della sua voce, o l'elenco con più voci), o per un commento alla scheda
 * dell'elemento commentato; senza la pagina (una scadenza generica, ciò che si fa fuori dal programma) il pulsante è «Segna come fatto», che apre «Fatto»
 * del menu del compito.
 *
 * Qui anche il titolo a video (il titolo salvato senza il nome del condominio ripetuto: il titolo salvato non cambia) e la riga sotto il titolo, con la rata,
 * la cifra o la fattura quando il tipo le ha in `meta`.
 */
final class TipiDeiCompiti
{
    /** Il pulsante dei compiti senza una pagina loro: apre «Fatto» del menu del compito. */
    public const FATTO = 'Segna come fatto';

    /** @var array<string, array{nome: string, azione: string, pagina: bool, rotta?: string, della_voce?: string, nell_esercizio?: bool, parametri?: array<string, string>}> */
    public const TIPI = [
        'verifica_pagamento' => ['nome' => 'Bonifici segnalati', 'azione' => 'Registra l\'incasso', 'pagina' => true],
        'controllo_incassi' => ['nome' => 'Verifica incassi', 'azione' => 'Registra gli incassi', 'pagina' => true],
        'emissione_rata' => ['nome' => 'Emissione rate', 'azione' => 'Emetti la rata', 'pagina' => true],
        'emissione_rata_sopravvenienza' => ['nome' => 'Rate per una sopravvenienza', 'azione' => 'Crea il piano rate', 'pagina' => true],
        'pagamento_fornitore' => ['nome' => 'Fornitori da pagare', 'azione' => 'Paga la fattura', 'pagina' => true],
        'scoperto_documentato' => ['nome' => 'Quote non assegnate', 'azione' => 'Assegna le quote', 'pagina' => true],
        'gia_versato_acconto_dichiarato' => ['nome' => 'Acconti da registrare', 'azione' => 'Registra la fattura', 'pagina' => true],
        // Il debito ereditato verso un fornitore che i pregressi chiesti ai condòmini non coprono: lo scoperto è proprio la parte che nessuna rata chiede, e le rate
        // scadute non lo recuperano. Si ripiana con un piano rate straordinario del condominio, nell'esercizio aperto di oggi (la stessa pagina dell'azione
        // consigliata della tesoreria); senza un esercizio aperto, «Segna come fatto». Il collegamento salvato nel compito non conta.
        'pianifica_ripianamento_deficit' => ['nome' => 'Deficit da recuperare', 'azione' => 'Prepara il piano di ripianamento', 'pagina' => true,
            'rotta' => 'admin.gestionale.esercizi.piani-rate.create', 'nell_esercizio' => true, 'parametri' => ['tipo' => 'straordinario']],
        'segnalazione_guasto' => ['nome' => 'Segnalazioni', 'azione' => 'Apri la segnalazione', 'pagina' => true],
        'commento' => ['nome' => 'Commenti', 'azione' => 'Rispondi al commento', 'pagina' => true],
        'allegato' => ['nome' => 'Allegati da approvare', 'azione' => 'Approva gli allegati', 'pagina' => true],
        // La ritenuta d'acconto da versare con l'F24: la scrivono il pagamento di una fattura con la ritenuta (`versamento_ritenuta`) e il condominio di
        // prova (`f24`). È la stessa cosa: un nome solo, e la pagina «Ritenute e F24» del condominio, dove si prepara la delega e si registra il versamento.
        'versamento_ritenuta' => ['nome' => 'Ritenute da versare', 'azione' => 'Prepara l\'F24', 'pagina' => true, 'rotta' => 'admin.gestionale.f24.index'],
        'f24' => ['nome' => 'Ritenute da versare', 'azione' => 'Prepara l\'F24', 'pagina' => true, 'rotta' => 'admin.gestionale.f24.index'],
        'scadenza' => ['nome' => 'Scadenze', 'azione' => self::FATTO, 'pagina' => false],
        // Chi ha versato più della sua quota di una voce: la pagina «Già versato» della voce dice l'eccedenza di ogni persona («in eccesso») ed è dove il
        // versato si corregge; con le eccedenze su più voci, o senza il dettaglio, l'elenco del già versato. La pagina del piano, salvata nel compito, non la dice.
        'eccedenza_gia_versato_rilevata' => ['nome' => 'Eccedenze del già versato', 'azione' => 'Controlla il già versato', 'pagina' => true,
            'rotta' => 'admin.gestionale.contributi.index', 'della_voce' => 'admin.gestionale.contributi.edit'],
        'convocazione_urgenza' => ['nome' => 'Assemblee da convocare', 'azione' => self::FATTO, 'pagina' => false],
        'ratifica_sforo' => ['nome' => 'Ratifiche in assemblea', 'azione' => self::FATTO, 'pagina' => false],
        'agenda' => ['nome' => 'Agenda', 'azione' => self::FATTO, 'pagina' => false],
        'promemoria' => ['nome' => 'Promemoria', 'azione' => self::FATTO, 'pagina' => false],
        'scadenza_rata_condomino' => ['nome' => 'Rate dei condòmini', 'azione' => self::FATTO, 'pagina' => false],
    ];

    /** La prossima azione della segnalazione che aspetta l'approvazione (`meta.is_approved` falso). */
    public const AZIONE_DA_APPROVARE = 'Approva o rifiuta';

    /** I tipi che sono la stessa cosa scritta da due punti del programma: contano insieme, sotto il primo. */
    public const STESSO_TIPO = ['f24' => 'versamento_ritenuta'];

    /** Il tipo con cui un compito si conta e si filtra: lo stesso, o quello di cui è un sinonimo. */
    public static function canonico(?string $tipo): ?string
    {
        return $tipo === null ? null : (self::STESSO_TIPO[$tipo] ?? $tipo);
    }

    /**
     * I tipi scritti nei compiti che il filtro per un tipo deve trovare: il tipo e i suoi sinonimi.
     *
     * @return list<string>
     */
    public static function famiglia(string $tipo): array
    {
        $canonico = (string) self::canonico($tipo);

        return [$canonico, ...array_keys(array_filter(self::STESSO_TIPO, fn ($t) => $t === $canonico))];
    }

    /** Un tipo che la mappa non conosce: un compito scritto da una versione futura o a mano. */
    public const ALTRO = ['nome' => 'Altri compiti', 'azione' => self::FATTO, 'pagina' => false];

    /** @return array{nome: string, azione: string, pagina: bool} */
    public static function voce(?string $tipo): array
    {
        return self::TIPI[(string) $tipo] ?? self::ALTRO;
    }

    /** Il nome del tipo nel grafico «Per tipo» e nei filtri. */
    public static function nome(?string $tipo): string
    {
        return self::voce($tipo)['nome'];
    }

    /**
     * La prossima azione della riga: il testo e dove porta. Senza una pagina, o con la pagina ma senza un collegamento, «Segna come fatto» e nessun
     * indirizzo.
     *
     * @return array{testo: string, url: ?string}
     */
    public static function azione(?string $tipo, Evento $compito): array
    {
        $voce = self::voce($tipo);
        $url = ! $voce['pagina'] ? null : (isset($voce['rotta'])
            ? self::paginaDelCondominio($voce['rotta'], $compito, isset($voce['della_voce']) ? [$voce['della_voce'], self::vocePerSola($compito)] : null,
                (bool) ($voce['nell_esercizio'] ?? false), $voce['parametri'] ?? [])
            : (IndirizzoDelCompito::perIlLink($compito->meta['action_url'] ?? null) ?? self::schedaDellElemento($compito)));

        if ($url === null) {
            return ['testo' => self::FATTO, 'url' => null];
        }
        // La segnalazione dal portale aspetta l'approvazione: la prossima azione è approvarla o rifiutarla, nella sua pagina.
        $testo = $tipo === CompitoDellaSegnalazione::TIPO && ($compito->meta['is_approved'] ?? true) === false ? self::AZIONE_DA_APPROVARE : $voce['azione'];

        return ['testo' => $testo, 'url' => $url];
    }

    /**
     * La pagina del condominio del compito con quella rotta (le ritenute e l'F24, il già versato, il piano rate del deficit); senza condominio, nessuna. Con
     * `$dellaVoce` ([rotta, voce]) e una voce sola, la pagina di quella voce. Con `$nellEsercizio` la pagina è dell'esercizio aperto di oggi
     * ({@see DisponibilitaDiOggi::esercizioDiOggi()}), e senza un esercizio aperto nessuna. `$parametri` vanno nell'indirizzo.
     *
     * @param  array{0: string, 1: ?int}|null  $dellaVoce
     * @param  array<string, string>  $parametri
     */
    private static function paginaDelCondominio(string $rotta, Evento $compito, ?array $dellaVoce = null, bool $nellEsercizio = false, array $parametri = []): ?string
    {
        $condominio = $compito->relationLoaded('condomini')
            ? $compito->condomini->sortBy('id')->first()?->id
            : $compito->condomini()->orderBy('condomini.id')->value('condomini.id');
        if ($condominio === null) {
            return null;
        }
        if ($dellaVoce !== null && $dellaVoce[1] !== null) {
            return IndirizzoDelCompito::perIlLink(route($dellaVoce[0], ['condominio' => $condominio, 'conto' => $dellaVoce[1]], false));
        }

        if ($nellEsercizio) {
            $esercizio = DisponibilitaDiOggi::esercizioDiOggi((int) $condominio, DateHelper::oggiUtente());
            if ($esercizio === null) {
                return null;
            }

            return IndirizzoDelCompito::perIlLink(route($rotta, ['condominio' => $condominio, 'esercizio' => $esercizio] + $parametri, false));
        }

        return IndirizzoDelCompito::perIlLink(route($rotta, ['condominio' => $condominio] + $parametri, false));
    }

    /** La voce delle eccedenze del compito (`meta.context.eccedenze[].conto_id`), se è una sola; con più voci, o senza il dettaglio, nessuna. */
    private static function vocePerSola(Evento $compito): ?int
    {
        $eccedenze = $compito->meta['context']['eccedenze'] ?? null;
        $voci = is_array($eccedenze)
            ? array_values(array_unique(array_filter(array_map(fn ($e) => is_array($e) ? (int) ($e['conto_id'] ?? 0) : 0, $eccedenze))))
            : [];

        return count($voci) === 1 ? $voci[0] : null;
    }

    /** La scheda della segnalazione o della comunicazione a cui il compito appartiene (un commento è salvato senza collegamento). */
    private static function schedaDellElemento(Evento $compito): ?string
    {
        if ($compito->eventable_id === null || $compito->eventable_type === null) {
            return null;
        }
        $rotta = match ($compito->eventable_type) {
            (new Segnalazione())->getMorphClass(), Segnalazione::class => 'admin.segnalazioni.show',
            (new Comunicazione())->getMorphClass(), Comunicazione::class => 'admin.comunicazioni.show',
            default => null,
        };

        return $rotta === null ? null : IndirizzoDelCompito::perIlLink(route($rotta, $compito->eventable_id, false));
    }

    /**
     * Il titolo a video: il titolo salvato senza il nome del condominio ripetuto («Emettere rata 1 - Via Verdi 12», «Pagare Idraulica Rossi (Via Verdi 12)»).
     * Si tolgono il nome di oggi e quello che il compito ha salvato, se il condominio ha cambiato nome dopo; un titolo che resterebbe vuoto resta com'è.
     *
     * @param  list<string|null>  $nomi
     */
    public static function titolo(string $titolo, array $nomi): string
    {
        $aVideo = $titolo;
        foreach (array_unique(array_filter(array_map(fn ($n) => trim((string) $n), $nomi))) as $nome) {
            $n = preg_quote($nome, '/');
            $aVideo = (string) preg_replace('/\s*\(\s*' . $n . '\s*\)/u', '', $aVideo);
            $aVideo = (string) preg_replace('/\s*[-–—:·|]\s*' . $n . '\s*$/u', '', $aVideo);
            $aVideo = (string) preg_replace('/^' . $n . '\s*[-–—:·|]\s*/u', '', $aVideo);
        }
        $aVideo = trim($aVideo);

        return $aVideo === '' ? $titolo : $aVideo;
    }

    /** La prima riga non vuota della descrizione, fino a 140 caratteri con i puntini: la riga sotto il titolo di un compito senza dati. */
    public static function primaRigaDellaDescrizione(?string $descrizione): string
    {
        foreach (preg_split('/\R/u', (string) $descrizione) ?: [] as $linea) {
            if (trim($linea) !== '') {
                return Str::limit(trim($linea), 140, '…');
            }
        }

        return '';
    }

    /**
     * La riga sotto il titolo dai dati del compito (la rata e la cifra, la fattura, il bonifico, il piano); vuota se il tipo non ne ha, e allora la riga sotto
     * è la prima riga della descrizione ({@see primaRigaDellaDescrizione()}).
     */
    public static function sottoDaiDati(?string $tipo, Evento $compito, ?string $persona): string
    {
        $m = is_array($compito->meta) ? $compito->meta : [];
        $c = is_array($m['context'] ?? null) ? $m['context'] : [];
        $euro = fn ($v) => is_numeric($v) ? MoneyHelper::format((int) $v) : null;
        $insieme = fn (...$parti) => implode(' · ', array_values(array_filter($parti, fn ($p) => $p !== null && $p !== '')));

        $riga = match ($tipo) {
            'emissione_rata', 'controllo_incassi' => isset($m['numero_rata']) ? $insieme('Rata ' . $m['numero_rata'], $euro($m['totale_rata'] ?? null)) : '',
            'verifica_pagamento' => $insieme($persona, $euro($m['importo_dichiarato'] ?? null)),
            'pagamento_fornitore' => $insieme(! empty($m['numero_documento']) ? 'Fattura n. ' . $m['numero_documento'] : 'Fattura in scadenza', $euro($m['importo'] ?? null)),
            'versamento_ritenuta', 'f24' => $insieme(! empty($m['fornitore']) ? 'Ritenuta di ' . $m['fornitore'] : 'Ritenuta d\'acconto', $euro($m['importo'] ?? null)),
            'scoperto_documentato' => $insieme($c['piano_rate_nome'] ?? null, $euro($c['importo_cents'] ?? null)),
            'eccedenza_gia_versato_rilevata' => $insieme($c['piano_rate_nome'] ?? null, $euro($c['totale_cents'] ?? null)),
            'gia_versato_acconto_dichiarato' => $insieme($c['conto_nome'] ?? null, $euro($c['importo_cents'] ?? null)),
            'pianifica_ripianamento_deficit' => isset($m['importo_buco']) ? 'Scoperto di ' . $euro($m['importo_buco']) : '',
            'emissione_rata_sopravvenienza' => $insieme('Sopravvenienza', $m['fornitore'] ?? null),
            'commento' => isset($m['contatore']) ? $insieme(((int) $m['contatore'] === 1 ? '1 commento nuovo' : (int) $m['contatore'] . ' commenti nuovi'), $m['ultimo_autore'] ?? null) : '',
            'allegato' => isset($m['contatore']) ? $insieme(((int) $m['contatore'] === 1 ? '1 allegato da approvare' : (int) $m['contatore'] . ' allegati da approvare'), $m['ultimo_autore'] ?? null) : '',
            default => '',
        };

        return $riga;
    }
}
