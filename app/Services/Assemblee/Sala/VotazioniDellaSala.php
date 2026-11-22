<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Sala;

use App\Enums\Assemblee\AmministrazionePunto;
use App\Enums\Assemblee\EsitoDelGesto;
use App\Enums\Assemblee\EsitoDelibera;
use App\Enums\Assemblee\Maggioranza;
use App\Enums\Assemblee\PercheVotaLUsufruttuario;
use App\Enums\Assemblee\SceltaDiVoto;
use App\Enums\Assemblee\StatoAssemblea;
use App\Models\Assemblee\Delibera;
use App\Models\Assemblee\DichiarazioneAVerbale;
use App\Models\Assemblee\GestoDellaSala;
use App\Models\Assemblee\PuntoDellOrdineDelGiorno;
use App\Models\Assemblee\SedutaDellAssemblea;
use App\Models\Assemblee\VotazioneDelPunto;
use App\Models\Assemblee\VotoInSala;
use App\Services\Assemblee\StatiDellAssemblea;
use App\Services\RegistroModifiche\GestiDelRegistro;
use App\Services\RegistroModifiche\RegistroModifiche;
use App\Settings\AssembleeSettings;
use Illuminate\Validation\ValidationException;

/**
 * Il voto dei punti (R8).
 *
 * - **«Si vota»** ferma il momento (`votata_il`) e gli intervenuti: le teste del punto presenti in quel momento, ognuna con chi la porta e i millesimi
 *   della tabella del punto. Chi entra dopo non conta, chi è uscito o caduto nemmeno. Con le scelte del presidente: l'usufruttuario che vota sul punto
 *   straordinario (art. 67 c. 7 disp. att.). Il punto della sola scala ha il conto della scala, che **avvisa e non blocca**.
 * - **«Tutti i presenti favorevoli»** è un gesto solo; poi i contrari e gli astenuti, una testa per volta. Il voto discorde dei comproprietari conta
 *   astenuto, o come lo decide il presidente (l'impostazione dello studio).
 * - **Il conflitto d'interessi** si segna con la nota: non toglie nessuno dal conto, e se è determinante la sala lo dice. Su un voto già confermato vale come
 *   una correzione: con il perché, una riga, e il voto torna da confermare.
 * - **L'esito proposto** lo calcola il server da sé ({@see ContiDellaSala}), secondo la maggioranza del punto e la convocazione della seduta.
 * - **«Conferma»** scrive la delibera sul punto con il giorno della seduta ({@see StatiDellAssemblea::deliberaSulPunto()}) e la lega al voto; un esito diverso
 *   da quello proposto vuole il motivo (senza, solo «Approvata con modifiche» quando la proposta è «Approvata» e il conflitto non è determinante). Un voto si
 *   corregge fino alla chiusura, con il perché: il voto torna da confermare, e la conferma riallinea la stessa delibera.
 * - **Il punto senza voto** ha solo «Trattato». **La dichiarazione a verbale** (art. 1130 n. 7 c.c.).
 * - **Le note del presidente** ({@see nota()}), che vanno nel verbale: sul voto del punto (anche quando si vota senza che l'assemblea risulti costituita) e
 *   su chi non ha votato. Prima della conferma non sono righe del registro, e la riga della conferma le porta nel perché; dopo, il cambio è una riga.
 *
 * Il voto prima della conferma non è una riga del registro: la riga è quella della conferma, con i voti nel lotto. La correzione dopo la conferma è una riga.
 */
final class VotazioniDellaSala
{
    public function siVota(GestoInArrivo $g): array
    {
        $a = $g->assemblea;
        $seduta = StatiDellaSala::sedutaApertaDelGesto($g);
        $punto = self::ilPunto($g);
        if ($punto->maggioranza === Maggioranza::SENZA_VOTO) {
            throw ValidationException::withMessages(['gesto' => 'Il punto ' . $punto->numero . ' è senza voto: segnalo «Trattato».']);
        }
        $numero = (int) $g->dato('numero', 1);
        if (! in_array($numero, [1, 2], true)) {
            throw ValidationException::withMessages(['gesto' => 'Un punto si vota una volta, o due per il rendiconto e il riparto.']);
        }
        $gia = VotazioneDelPunto::query()->whereIn('seduta_id', $a->sedute()->pluck('id'))->where('punto_id', $punto->id)->where('numero', $numero)->first();
        if ($gia !== null) {
            throw ValidationException::withMessages(['gesto' => 'Il punto ' . $punto->numero . ($gia->eConfermata() ? ' è già votato.' : ' si sta già votando.')]);
        }
        $oggetto = $g->dato('oggetto') ?? ($numero === 2 ? 'riparto' : null);
        if ($oggetto !== null && ! in_array($oggetto, ['rendiconto', 'riparto'], true)) {
            throw ValidationException::withMessages(['gesto' => 'Le due votazioni del rendiconto sono «rendiconto» e «riparto».']);
        }
        $usufruttuari = $this->usufruttuari($g, $seduta, $punto);
        $intervenuti = self::intervenuti($seduta, $punto, array_map(fn ($u) => $u['perche'], $usufruttuari), $g->segnatoIl);

        $votazione = RegistroModifiche::durante(GestiDelRegistro::dellaSalaSenzaRiga($a), function () use ($seduta, $punto, $numero, $oggetto, $intervenuti,
            $usufruttuari, $g) {
            $v = VotazioneDelPunto::create(['seduta_id' => $seduta->id, 'punto_id' => $punto->id, 'numero' => $numero, 'oggetto' => $oggetto,
                'maggioranza' => $punto->maggioranza?->value, 'maggioranza_libera' => $punto->maggioranza_libera, 'votata_il' => $g->segnatoIl,
                'intervenuti' => $intervenuti, 'scelte' => $usufruttuari === [] ? null : ['usufruttuari' => array_values($usufruttuari)], 'versione' => 1,
                'gesto' => $g->numero]);
            $this->ricalcola($v, false);

            return $v;
        });
        $costituzione = ContiDellaSala::costituzione((string) $seduta->fotografia['convocazione'], (int) $seduta->fotografia['costituzione']['totale_teste'],
            (int) $seduta->fotografia['costituzione']['totale_valore'], ...array_values(PresenzeDellaSala::conteggio($seduta->fotografia['costituzione']['teste'],
                PresenzeDellaSala::presenti($seduta, $g->segnatoIl))));

        return ['versione' => (int) $votazione->versione, 'votazione_id' => (int) $votazione->id,
            'messaggio' => $costituzione['costituita'] ? ($intervenuti['perimetro']['avviso'] ?? null)
                // Il messaggio dice il punto (rilievo C52): con due punti votati a sala non costituita non sono due messaggi uguali.
                : 'Punto ' . $punto->numero . ': l\'assemblea non risulta costituita (' . $costituzione['barra'] . '; ' . $costituzione['servono'] . '). Decide il presidente.'];
    }

    public function tuttiFavorevoli(GestoInArrivo $g): array
    {
        $v = $this->laVotazione($g);
        if ($v->eConfermata()) {
            throw ValidationException::withMessages(['gesto' => 'Il voto del punto ' . $v->punto->numero . ' è confermato: per correggerlo cambia un voto con il perché.']);
        }
        $gia = $v->voti()->pluck('testa')->all();
        RegistroModifiche::durante(GestiDelRegistro::dellaSalaSenzaRiga($g->assemblea), function () use ($v, $gia, $g) {
            foreach ((array) $v->intervenuti['teste'] as $t) {
                if (in_array($t['chiave'], $gia, true)) {
                    continue;
                }
                VotoInSala::create(['votazione_id' => $v->id, 'testa' => $t['chiave'], 'anagrafica_id' => $t['chi']['anagrafica_id'] ?? null,
                    'nome' => (string) $t['chi']['nome'], 'ruolo' => (string) $t['chi']['ruolo'], 'millesimi' => (int) $t['millesimi'],
                    'voto' => SceltaDiVoto::FAVOREVOLE->value, 'versione' => 1, 'gesto' => $g->numero]);
            }
            $this->ricalcola($v);
        });

        return ['versione' => (int) $v->refresh()->versione, 'votazione_id' => (int) $v->id, 'messaggio' => null];
    }

    public function voto(GestoInArrivo $g): array
    {
        $v = $this->laVotazione($g);
        $testa = (string) $g->dato('testa', '');
        $intervenuto = collect((array) $v->intervenuti['teste'])->firstWhere('chiave', $testa);
        if ($intervenuto === null) {
            $delPunto = collect(FotografiaDellaSeduta::testeDelPunto((array) $v->seduta->fotografia, (int) $v->punto_id)['teste'])->firstWhere('chiave', $testa);
            throw ValidationException::withMessages(['gesto' => $delPunto === null ? 'Questa testa non è del punto ' . $v->punto->numero . '.'
                : $delPunto['nome'] . ' non era presente quando si è votato il punto ' . $v->punto->numero . ': il suo voto non conta.']);
        }
        $scelta = SceltaDiVoto::tryFrom((string) $g->dato('voto')) ?? throw ValidationException::withMessages(['gesto' => 'Il voto è favorevole, contrario o astenuto.']);
        $decisione = null;
        if ($scelta === SceltaDiVoto::DISCORDE) {
            if (count((array) $intervenuto['persone']) < 2) {
                throw ValidationException::withMessages(['gesto' => 'Il voto discorde è dei comproprietari: questa testa ha una persona sola.']);
            }
            if (ImpostazioniDellaSala::dellaFotografia((array) $v->seduta->fotografia)['voto_discorde_comproprietari'] === AssembleeSettings::DECIDE_IL_PRESIDENTE) {
                $decisione = SceltaDiVoto::tryFrom((string) $g->dato('decisione'));
                if ($decisione === null || $decisione === SceltaDiVoto::DISCORDE) {
                    throw ValidationException::withMessages(['gesto' => 'I comproprietari votano in modo diverso: decide il presidente, scegli come conta il voto.']);
                }
            }
        }
        $esistente = $v->voti()->where('testa', $testa)->first();
        $nome = (string) $intervenuto['nome'];
        $base = $g->baseDaControllare();
        if ($esistente !== null && $esistente->voto === $scelta && $esistente->decisione === $decisione) {
            return ['versione' => (int) $esistente->versione, 'versione_votazione' => (int) $v->versione, 'votazione_id' => (int) $v->id,
                'messaggio' => 'Era già così: niente da cambiare.'];
        }
        if ($base !== null && (int) ($esistente?->versione ?? 0) !== $base) {
            throw new DueVersioni($nome . ': ' . $scelta->parola(), $nome . ': ' . ($esistente?->voto?->parola() ?? 'nessun voto'), 'votazione:' . $v->id,
                $esistente?->gesto);
        }
        $perche = $g->testo('perche');
        $confermata = $v->eConfermata();
        if ($confermata && $perche === '') {
            throw ValidationException::withMessages(['gesto' => 'Il voto del punto ' . $v->punto->numero . ' è confermato: per correggerlo scrivi il perché.']);
        }
        $lavoro = function () use ($v, $esistente, $intervenuto, $testa, $scelta, $decisione, $g, $confermata) {
            $voto = $esistente ?? new VotoInSala(['votazione_id' => $v->id, 'testa' => $testa, 'versione' => 0]);
            $voto->fill(['anagrafica_id' => $intervenuto['chi']['anagrafica_id'] ?? null, 'nome' => (string) $intervenuto['chi']['nome'],
                'ruolo' => (string) $intervenuto['chi']['ruolo'], 'millesimi' => (int) $intervenuto['millesimi'], 'voto' => $scelta->value,
                'decisione' => $decisione?->value, 'versione' => (int) $voto->versione + 1, 'gesto' => $g->numero])->save();
            if ($confermata) {
                $v->update(['confermata_il' => null, 'confermata_da' => null]);
            }
            $this->ricalcola($v);

            return $voto;
        };
        $gesto = $confermata
            ? GestiDelRegistro::dellaSala($g->assemblea, 'voto di ' . $nome . ' sul punto ' . $v->punto->numero . ' corretto: '
                . ($esistente?->voto?->parola() ?? 'nessun voto') . ' → ' . $scelta->parola() . '; il voto è da confermare di nuovo', $g->segnatoIl,
                $g->dalFoglio($v->seduta), $perche, array_filter([$intervenuto['chi']['anagrafica_id'] ?? null]))
            : GestiDelRegistro::dellaSalaSenzaRiga($g->assemblea);
        $voto = RegistroModifiche::durante($gesto, $lavoro);

        return ['versione' => (int) $voto->versione, 'versione_votazione' => (int) $v->refresh()->versione, 'votazione_id' => (int) $v->id, 'messaggio' => null];
    }

    /**
     * Il segno «In conflitto» su chi ha votato, con la nota. Su un voto già confermato (un gesto in coda da un altro dispositivo) vale come una correzione: senza
     * il perché si rifiuta; con il perché è una riga del registro, e il voto torna da confermare.
     */
    public function conflitto(GestoInArrivo $g): array
    {
        $v = $this->laVotazione($g);
        $voto = $v->voti()->where('testa', (string) $g->dato('testa', ''))->first()
            ?? throw ValidationException::withMessages(['gesto' => 'Prima segna il voto di questa testa, poi il conflitto.']);
        $in = (bool) $g->dato('in_conflitto', true);
        $confermata = $v->eConfermata();
        $perche = $g->testo('perche');
        if ($confermata && (bool) $voto->in_conflitto === $in) {
            return ['versione' => (int) $v->versione, 'votazione_id' => (int) $v->id, 'messaggio' => 'Era già così: niente da cambiare.'];
        }
        if ($confermata && $perche === '') {
            throw ValidationException::withMessages(['gesto' => 'Il voto del punto ' . $v->punto->numero . ' è confermato: per segnare il conflitto d\'interessi scrivi '
                . 'il perché.']);
        }
        $gesto = $confermata
            ? GestiDelRegistro::dellaSala($g->assemblea, 'conflitto d\'interessi di ' . $voto->nome . ' sul punto ' . $v->punto->numero . ($in ? ' segnato' : ' tolto')
                . ' dopo la conferma; il voto è da confermare di nuovo', $g->segnatoIl, $g->dalFoglio($v->seduta), $perche, array_filter([$voto->anagrafica_id]))
            : GestiDelRegistro::dellaSalaSenzaRiga($g->assemblea);
        RegistroModifiche::durante($gesto, function () use ($v, $voto, $in, $g, $confermata) {
            $voto->update(['in_conflitto' => $in, 'nota_conflitto' => $in ? ($g->testo('nota') ?: null) : null]);
            if ($confermata) {
                $v->update(['confermata_il' => null, 'confermata_da' => null]);
            }
            $this->ricalcola($v);
        });

        return ['versione' => (int) $v->refresh()->versione, 'votazione_id' => (int) $v->id, 'messaggio' => $v->conflitto_determinante ? $v->conteggi['conflitto'] : null];
    }

    public function conferma(GestoInArrivo $g): array
    {
        $v = $this->laVotazione($g);
        $punto = $v->punto;
        if (self::sceltaAperta($v)) {
            throw ValidationException::withMessages(['gesto' => 'Il voto del punto ' . $punto->numero . ' ha due versioni da scegliere: scegli prima di confermare.']);
        }
        $esito = EsitoDelibera::tryFrom((string) $g->dato('esito'));
        if ($esito === null || $esito === EsitoDelibera::SENZA_VOTO) {
            throw ValidationException::withMessages(['gesto' => 'L\'esito è approvata, approvata con modifiche, respinta o rinviata.']);
        }
        $base = $g->baseDaControllare();
        if ($base !== null && $base !== (int) $v->versione) {
            if ($v->eConfermata() && $v->esito === $esito) {
                return ['versione' => (int) $v->versione, 'votazione_id' => (int) $v->id, 'messaggio' => 'Era già così: niente da cambiare.'];
            }
            throw new DueVersioni('Punto ' . $punto->numero . ': ' . $esito->parola(), 'Punto ' . $punto->numero . ': i voti sono cambiati, esito proposto '
                . ($v->esito_proposto?->parola() ?? 'nessuno'), 'votazione:' . $v->id, $v->voti()->orderByDesc('updated_at')->orderByDesc('id')->value('gesto'));
        }
        $motivo = $g->testo('motivo');
        if ($motivo === '' && self::serveIlMotivo($v, $esito)) {
            throw ValidationException::withMessages(['gesto' => ($v->conflitto_determinante ? 'Il voto in conflitto è determinante'
                : 'L\'esito proposto è «' . $v->esito_proposto?->parola() . '»') . ': per confermare «' . $esito->parola() . '» scrivi il motivo.']);
        }
        $c = (array) $v->conteggi;
        $di = fn (string $k) => (int) ($c[$k]['teste'] ?? 0);
        $di_nuovo = $v->delibera_id !== null;
        $frase = 'punto ' . $punto->numero . ($v->oggetto !== null ? ' (' . $v->oggetto . ')' : '') . ($di_nuovo ? ' confermato di nuovo: ' : ' ')
            . mb_strtolower($esito->parola()) . ', ' . $di('favorevoli') . ' favorevoli con ' . ContiDellaSala::millesimi((int) ($c['favorevoli']['valore'] ?? 0))
            . ' millesimi, ' . $di('contrari') . ' contrari, ' . $di('astenuti') . ' astenuti';
        $perche = array_values(array_filter([
            $motivo !== '' ? 'Motivo: ' . $motivo : null,
            $v->conflitto_determinante ? (string) ($c['conflitto'] ?? '') : null,
            ...array_map(fn ($u) => (string) $u['frase'], (array) ($v->scelte['usufruttuari'] ?? [])),
            $v->intervenuti['perimetro']['avviso'] ?? null,
            $v->nota !== null ? 'Nota del presidente: ' . $v->nota : null,
            $v->nota_non_votato !== null ? 'Su chi non ha votato: ' . $v->nota_non_votato : null,
        ]));
        $gesto = GestiDelRegistro::dellaSala($g->assemblea, $frase, $g->segnatoIl, $g->dalFoglio($v->seduta), $perche === [] ? null : implode(' ', $perche),
            $v->voti()->pluck('anagrafica_id')->filter()->all())
            ->lotto(['voti' => $v->voti()->get()->map(fn (VotoInSala $x) => ['nome' => $x->nome, 'millesimi' => (int) $x->millesimi, 'voto' => $x->voto?->value,
                'in_conflitto' => (bool) $x->in_conflitto])->values()->all()]);
        $giorno = $v->seduta->fissata_il->format('Y-m-d');
        $testo = $g->testo('testo');
        // Un punto di nomina: il nome del nuovo amministratore detto in sala, che la riga proposta del registro di nomina e revoca porterà.
        $nominato = $punto->materia === \App\Enums\Assemblee\MateriaPunto::NOMINA ? mb_substr($g->testo('amministratore'), 0, 255) : '';
        $scelte = $nominato !== '' ? ['scelte' => ['nomina' => ['amministratore' => $nominato]] + (array) $v->scelte] : [];

        RegistroModifiche::durante($gesto, function () use ($v, $punto, $esito, $giorno, $g, $motivo, $testo, $scelte) {
            if ($v->delibera_id === null) {
                $delibera = StatiDellAssemblea::deliberaSulPunto($punto, ['esito' => $esito->value, 'testo' => $testo !== '' ? $testo : null,
                    'votazione' => (int) $v->numero, 'oggetto' => $v->oggetto], $giorno, $g->utente);
            } else {
                $delibera = Delibera::query()->findOrFail($v->delibera_id);
                $prima = $delibera->esito;
                $delibera->update(['esito' => $esito->value] + ($testo !== '' ? ['testo' => $testo] : []));
                // Il voto corretto dopo la conferma: i memo del punto seguono il nuovo esito, come alla prima scrittura (revisione del 22/11).
                StatiDellAssemblea::esitoCambiato($punto, $prima, $esito, $g->utente);
            }
            $v->update(['esito' => $esito->value, 'motivo' => $motivo !== '' ? $motivo : null, 'confermata_il' => $g->segnatoIl, 'confermata_da' => $g->utente->id,
                'delibera_id' => $delibera->id, 'versione' => (int) $v->versione + 1] + $scelte);
        });

        return ['versione' => (int) $v->refresh()->versione, 'votazione_id' => (int) $v->id, 'delibera_id' => (int) $v->delibera_id, 'messaggio' => null];
    }

    /**
     * Un esito confermato vuole il motivo quando è diverso da quello proposto; «Approvata con modifiche» no solo se la proposta è «Approvata» e il voto in
     * conflitto non è determinante. Senza una proposta (un'altra maggioranza) decide il presidente, senza motivo. Le stesse regole nella finestra della
     * conferma (`FinestraDellaConferma.vue`).
     */
    public static function serveIlMotivo(VotazioneDelPunto $v, EsitoDelibera $esito): bool
    {
        if ($v->esito_proposto === null || $v->esito_proposto === $esito) {
            return false;
        }

        return ! ($esito === EsitoDelibera::APPROVATA_CON_MODIFICHE && $v->esito_proposto === EsitoDelibera::APPROVATA && ! $v->conflitto_determinante);
    }

    /** «Trattato»: il punto senza voto, con chi c'era e la delibera «senza voto». */
    public function trattato(GestoInArrivo $g): array
    {
        $seduta = StatiDellaSala::sedutaApertaDelGesto($g);
        $punto = self::ilPunto($g);
        if ($punto->maggioranza !== Maggioranza::SENZA_VOTO) {
            throw ValidationException::withMessages(['gesto' => 'Il punto ' . $punto->numero . ' ha un voto: «Si vota».']);
        }
        if (VotazioneDelPunto::query()->whereIn('seduta_id', $g->assemblea->sedute()->pluck('id'))->where('punto_id', $punto->id)->exists()) {
            throw ValidationException::withMessages(['gesto' => 'Il punto ' . $punto->numero . ' è già trattato.']);
        }
        $intervenuti = self::intervenuti($seduta, $punto, [], $g->segnatoIl);
        $giorno = $seduta->fissata_il->format('Y-m-d');
        $v = RegistroModifiche::durante(GestiDelRegistro::dellaSala($g->assemblea, 'punto ' . $punto->numero . ' trattato, senza voto', $g->segnatoIl,
            $g->dalFoglio($seduta)), function () use ($seduta, $punto, $intervenuti, $giorno, $g) {
                $delibera = StatiDellAssemblea::deliberaSulPunto($punto, ['esito' => EsitoDelibera::SENZA_VOTO->value], $giorno, $g->utente);

                return VotazioneDelPunto::create(['seduta_id' => $seduta->id, 'punto_id' => $punto->id, 'numero' => 1, 'maggioranza' => Maggioranza::SENZA_VOTO->value,
                    'votata_il' => $g->segnatoIl, 'intervenuti' => $intervenuti, 'esito_proposto' => EsitoDelibera::SENZA_VOTO->value,
                    'esito' => EsitoDelibera::SENZA_VOTO->value, 'confermata_il' => $g->segnatoIl, 'confermata_da' => $g->utente->id, 'delibera_id' => $delibera->id,
                    'versione' => 1, 'gesto' => $g->numero]);
            });

        return ['versione' => (int) $v->versione, 'votazione_id' => (int) $v->id, 'messaggio' => null];
    }

    /** La breve dichiarazione a verbale (art. 1130 n. 7 c.c.), su un punto o sulla seduta. */
    public function dichiarazione(GestoInArrivo $g): array
    {
        $seduta = StatiDellaSala::sedutaApertaDelGesto($g);
        $punto = $g->dato('punto_id') !== null ? self::ilPunto($g) : null;
        $testo = $g->testo('testo');
        if ($testo === '' || mb_strlen($testo) > 5000) {
            throw ValidationException::withMessages(['gesto' => 'Scrivi la dichiarazione, in poche righe.']);
        }
        $id = $g->dato('anagrafica_id') !== null ? (int) $g->dato('anagrafica_id') : null;
        $persone = (array) ($seduta->fotografia['persone'] ?? []);
        if ($id !== null && ! isset($persone[$id])) {
            throw ValidationException::withMessages(['gesto' => PresenzeDellaSala::FRASE_CHI]);
        }
        $nome = $id !== null ? (string) $persone[$id]['nome'] : $g->testo('nome');
        if ($nome === '') {
            throw ValidationException::withMessages(['gesto' => 'Scrivi chi fa la dichiarazione.']);
        }
        $d = RegistroModifiche::durante(GestiDelRegistro::dellaSalaSenzaRiga($g->assemblea), fn () => DichiarazioneAVerbale::create(['seduta_id' => $seduta->id,
            'punto_id' => $punto?->id, 'anagrafica_id' => $id, 'nome' => $nome, 'testo' => $testo, 'gesto' => $g->numero, 'created_by' => $g->utente->id]));

        return ['versione' => null, 'dichiarazione_id' => (int) $d->id, 'messaggio' => null];
    }

    /**
     * La dichiarazione a verbale corretta (decisione 664 (7), rilievo C53): il testo nuovo, finché il voto del punto non è confermato (senza punto, finché la
     * seduta è aperta). Una riga nel diario dell'assemblea; il verbale la riporta com'è.
     */
    public function cambiaLaDichiarazione(GestoInArrivo $g): array
    {
        [$d, $seduta] = $this->laDichiarazione($g);
        $testo = $g->testo('testo');
        if ($testo === '' || mb_strlen($testo) > 5000) {
            throw ValidationException::withMessages(['gesto' => 'Scrivi la dichiarazione, in poche righe.']);
        }
        if ($testo === (string) $d->testo) {
            return ['versione' => null, 'dichiarazione_id' => (int) $d->id, 'messaggio' => 'Era già così: niente da cambiare.'];
        }
        RegistroModifiche::durante(GestiDelRegistro::dellaSala($g->assemblea, self::fraseDellaDichiarazione($d) . ' corretta', $g->segnatoIl, $g->dalFoglio($seduta),
            null, array_filter([$d->anagrafica_id])), fn () => $d->update(['testo' => $testo]));

        return ['versione' => null, 'dichiarazione_id' => (int) $d->id, 'messaggio' => null];
    }

    /** La dichiarazione a verbale tolta (rilievo C53), con le stesse regole della correzione: una riga nel diario, e il verbale non la riporta più. */
    public function togliLaDichiarazione(GestoInArrivo $g): array
    {
        [$d, $seduta] = $this->laDichiarazione($g);
        $id = (int) $d->id;
        RegistroModifiche::durante(GestiDelRegistro::dellaSala($g->assemblea, self::fraseDellaDichiarazione($d) . ' tolta', $g->segnatoIl, $g->dalFoglio($seduta),
            null, array_filter([$d->anagrafica_id])), fn () => $d->delete());

        return ['versione' => null, 'dichiarazione_id' => $id, 'messaggio' => null];
    }

    /** «dichiarazione a verbale di Colombo Rita sul punto 1». */
    private static function fraseDellaDichiarazione(DichiarazioneAVerbale $d): string
    {
        return 'dichiarazione a verbale di ' . $d->nome . ($d->punto !== null ? ' sul punto ' . $d->punto->numero : '');
    }

    /**
     * La dichiarazione del gesto: della seduta aperta di questa assemblea; si corregge finché il voto del suo punto non è confermato (una dichiarazione senza
     * punto, finché la seduta è aperta). @return array{0: DichiarazioneAVerbale, 1: \App\Models\Assemblee\SedutaDellAssemblea}
     */
    private function laDichiarazione(GestoInArrivo $g): array
    {
        $seduta = StatiDellaSala::sedutaApertaDelGesto($g);
        $d = DichiarazioneAVerbale::query()->whereKey($g->id('dichiarazione_id'))->whereIn('seduta_id', $g->assemblea->sedute()->pluck('id'))->with('punto')->first()
            ?? throw ValidationException::withMessages(['gesto' => 'La dichiarazione non è di questa assemblea, o è già tolta.']);
        if ((int) $d->seduta_id !== (int) $seduta->id) {
            throw ValidationException::withMessages(['gesto' => 'La dichiarazione è di una riunione chiusa: resta com\'è.']);
        }
        if ($d->punto !== null) {
            $votazioni = VotazioneDelPunto::query()->where('seduta_id', $seduta->id)->where('punto_id', $d->punto_id)->get();
            if ($votazioni->isNotEmpty() && $votazioni->every(fn (VotazioneDelPunto $v) => $v->eConfermata())) {
                throw ValidationException::withMessages(['gesto' => 'Il voto del punto ' . $d->punto->numero . ' è confermato: la dichiarazione resta com\'è.']);
            }
        }

        return [$d, $seduta];
    }

    /**
     * La nota del presidente sul voto del punto (`su: voto`) o su chi non ha votato (`su: non_votato`): va nel verbale. Il testo vuoto la toglie. Non cambia
     * la versione del voto. Prima della conferma si conta nel lotto senza riga (la riga della conferma la porta nel perché); dopo, il cambio è una riga.
     */
    public function nota(GestoInArrivo $g): array
    {
        $v = $this->laVotazione($g);
        $colonna = match ((string) $g->dato('su', '')) {
            'voto' => 'nota',
            'non_votato' => 'nota_non_votato',
            default => throw ValidationException::withMessages(['gesto' => 'La nota è sul voto o su chi non ha votato.']),
        };
        $testo = $g->testo('testo');
        if (mb_strlen($testo) > 2000) {
            throw ValidationException::withMessages(['gesto' => 'La nota sta in poche righe: al più 2.000 caratteri.']);
        }
        $nuova = $testo === '' ? null : $testo;
        if ($v->{$colonna} === $nuova) {
            return ['versione' => (int) $v->versione, 'votazione_id' => (int) $v->id, 'messaggio' => 'Era già così: niente da cambiare.'];
        }
        $su = $colonna === 'nota' ? 'sul voto' : 'su chi non ha votato';
        $gesto = $v->eConfermata()
            ? GestiDelRegistro::dellaSala($g->assemblea, 'nota del presidente ' . $su . ' del punto ' . $v->punto->numero . ($nuova === null ? ' tolta' : ' scritta'),
                $g->segnatoIl, $g->dalFoglio($v->seduta), $nuova)
            : GestiDelRegistro::dellaSalaSenzaRiga($g->assemblea);
        RegistroModifiche::durante($gesto, fn () => $v->update([$colonna => $nuova]));

        return ['versione' => (int) $v->refresh()->versione, 'votazione_id' => (int) $v->id, 'messaggio' => null];
    }

    /**
     * Dopo un'entrata o un'uscita segnata prima di un voto non ancora confermato (un gesto arrivato tardi, da un dispositivo senza rete): gli intervenuti di
     * quel voto si ricontano al suo momento. Il voto di chi non c'era più si toglie solo per una scelta fra due versioni (l'uscita tarda che toglie un voto
     * già segnato è «Due versioni», {@see PresenzeDellaSala::esce()}), e chi chiama lo dice nella riga del registro e nel messaggio: rende i voti tolti.
     *
     * @return list<array{nome: string, punto: string}>
     */
    public function dopoUnaPresenza(SedutaDellAssemblea $seduta, \DateTimeInterface $quando): array
    {
        $tolti = [];
        foreach (VotazioneDelPunto::query()->where('seduta_id', $seduta->id)->whereNull('confermata_il')->where('votata_il', '>=', $quando)
            ->where('maggioranza', '!=', Maggioranza::SENZA_VOTO->value)->with('punto')->get() as $v) {
            $usufruttuari = [];
            foreach ((array) ($v->scelte['usufruttuari'] ?? []) as $u) {
                $usufruttuari[(int) $u['immobile_id']] = (string) $u['perche'];
            }
            $intervenuti = self::intervenuti($seduta, $v->punto, $usufruttuari, $v->votata_il);
            $chiavi = array_column($intervenuti['teste'], 'chiave');
            foreach ($v->voti()->get() as $voto) {
                if (! in_array($voto->testa, $chiavi, true)) {
                    $tolti[] = ['nome' => (string) $voto->nome, 'punto' => (string) $v->punto?->numero];
                    $voto->delete();
                }
            }
            $v->update(['intervenuti' => $intervenuti]);
            $this->ricalcola($v);
        }

        return $tolti;
    }

    /** L'avviso quando una presenza tarda tocca un voto già confermato: si corregge con il perché, se serve. */
    public function avvisoDelleConfermate(SedutaDellAssemblea $seduta, \DateTimeInterface $quando): ?string
    {
        $numeri = VotazioneDelPunto::query()->where('seduta_id', $seduta->id)->whereNotNull('confermata_il')->where('votata_il', '>=', $quando)
            ->where('maggioranza', '!=', Maggioranza::SENZA_VOTO->value)->with('punto')->get()->map(fn ($v) => (string) $v->punto?->numero)->unique()->values()->all();
        if ($numeri === []) {
            return null;
        }

        return (count($numeri) === 1 ? 'Il voto del punto ' . $numeri[0] . ', già confermato, è stato contato' : 'I voti dei punti ' . TesteDellaSala::elenco($numeri)
            . ', già confermati, sono stati contati') . ' senza questa presenza: se serve, correggi con il perché.';
    }

    /**
     * Gli intervenuti di un punto in un momento: le teste del punto (con le scelte del presidente sull'usufrutto) portate da qualcuno presente in quel
     * momento; i totali del punto; per il punto di una parte del condominio, il conto della parte che avvisa e non blocca.
     *
     * @param array<int, string> $usufruttuari unità => il perché
     * @param array<string, \DateTimeInterface> $usciteDaProvare chiave => ora: le uscite da contare senza scriverle (vedi {@see PresenzeDellaSala::presenti()})
     * @return array<string, mixed>
     */
    public static function intervenuti(SedutaDellAssemblea $seduta, PuntoDellOrdineDelGiorno $punto, array $usufruttuari, \DateTimeInterface $al,
        array $usciteDaProvare = []): array
    {
        $fotografia = (array) $seduta->fotografia;
        $delPunto = FotografiaDellaSeduta::testeDelPunto($fotografia, (int) $punto->id, $usufruttuari);
        $presenti = PresenzeDellaSala::presenti($seduta, $al, (int) $punto->id, $usciteDaProvare);
        $teste = [];
        $valore = 0;
        foreach ($delPunto['teste'] as $t) {
            $chi = PresenzeDellaSala::chiPorta($t, $presenti, $delPunto['ruoli']);
            if ($chi === null) {
                continue;
            }
            $teste[] = $t + ['chi' => $chi];
            $valore += (int) $t['millesimi'];
        }
        $voce = collect((array) $fotografia['punti'])->firstWhere('punto_id', (int) $punto->id);
        $perimetro = null;
        if (($voce['perimetro'] ?? null) !== null) {
            $conto = ContiDellaSala::costituzione((string) $fotografia['convocazione'], $delPunto['totale_teste'], $delPunto['totale_valore'], count($teste), $valore);
            $perimetro = ['nome' => (string) $voce['perimetro'], 'costituzione' => $conto, 'avviso' => $conto['costituita'] ? null
                : $voce['perimetro'] . ': ' . $conto['barra'] . '; ' . $conto['servono'] . '. Il conto della parte non basta: decide il presidente, e il verbale lo scrive.'];
        }

        return ['teste' => $teste, 'numero' => count($teste), 'valore' => $valore, 'totale_teste' => $delPunto['totale_teste'],
            'totale_valore' => $delPunto['totale_valore'], 'convocazione' => (string) $fotografia['convocazione'], 'perimetro' => $perimetro];
    }

    /** I conteggi, l'esito proposto, il conflitto determinante e la versione, dai voti: il server ricalcola da sé. */
    public function ricalcola(VotazioneDelPunto $v, bool $nuovaVersione = true): void
    {
        $voti = $v->voti()->get();
        $discordeAstenuto = ImpostazioniDellaSala::dellaFotografia((array) $v->seduta?->fotografia)['voto_discorde_comproprietari'] !== AssembleeSettings::DECIDE_IL_PRESIDENTE;
        $vuoto = fn () => ['teste' => 0, 'valore' => 0, 'nomi' => []];
        $c = ['favorevoli' => $vuoto(), 'contrari' => $vuoto(), 'astenuti' => $vuoto()];
        $conflittoTeste = 0;
        $conflittoValore = 0;
        foreach ($voti as $voto) {
            $conta = $voto->voto === SceltaDiVoto::DISCORDE ? ($discordeAstenuto || $voto->decisione === null ? SceltaDiVoto::ASTENUTO : $voto->decisione) : $voto->voto;
            $chiave = match ($conta) {
                SceltaDiVoto::FAVOREVOLE => 'favorevoli',
                SceltaDiVoto::CONTRARIO => 'contrari',
                default => 'astenuti',
            };
            $c[$chiave]['teste']++;
            $c[$chiave]['valore'] += (int) $voto->millesimi;
            $c[$chiave]['nomi'][] = ['nome' => $voto->nome, 'millesimi' => (int) $voto->millesimi];
            if ($voto->in_conflitto && $chiave === 'favorevoli') {
                $conflittoTeste++;
                $conflittoValore += (int) $voto->millesimi;
            }
        }
        $intervenuti = (array) $v->intervenuti;
        $votate = array_column($voti->all(), 'testa');
        $senza = array_values(array_filter((array) $intervenuti['teste'], fn ($t) => ! in_array($t['chiave'], $votate, true)));
        $c['non_votato'] = ['teste' => count($senza), 'valore' => array_sum(array_map(fn ($t) => (int) $t['millesimi'], $senza)), 'nomi' => array_map(fn ($t) => [
            'nome' => (string) $t['nome'], 'millesimi' => (int) $t['millesimi']], $senza)];
        $maggioranza = $v->maggioranza?->value;
        $convocazione = (string) ($intervenuti['convocazione'] ?? 'seconda');
        $totTeste = (int) $intervenuti['totale_teste'];
        $totValore = (int) $intervenuti['totale_valore'];
        $conflitto = ContiDellaSala::conflitto($maggioranza, $convocazione, $totTeste, $totValore, (int) $intervenuti['numero'], $c['favorevoli']['teste'],
            $c['favorevoli']['valore'], $conflittoTeste, $conflittoValore);
        $c['conflitto'] = $conflitto['frase'];
        $c['frase'] = self::fraseDellEsito($conflitto['esito'], $maggioranza, (array) $v->maggioranza_libera, $convocazione, $totTeste, $totValore,
            (int) $intervenuti['numero'], $c['favorevoli']['teste'], $c['favorevoli']['valore']);
        // Per la pagina del voto, le due condizioni una per una; il verbale non le legge (dice la regola, non i conti che mancano).
        $c['condizioni'] = ContiDellaSala::condizioni($maggioranza, $convocazione, $totTeste, $totValore, (int) $intervenuti['numero'], $c['favorevoli']['teste'],
            $c['favorevoli']['valore']);
        $v->update(['conteggi' => $c, 'esito_proposto' => $conflitto['esito'], 'conflitto_determinante' => $conflitto['determinante']]
            + ($nuovaVersione ? ['versione' => (int) $v->versione + 1] : []));
    }

    /** «Respinta: 9 favorevoli su 11 intervenuti, 292,00 millesimi; in seconda convocazione servono la maggioranza degli intervenuti e 333,34 millesimi.» */
    public static function fraseDellEsito(?string $esito, ?string $maggioranza, array $libera, string $convocazione, int $totTeste, int $totValore, int $intervenuti,
        int $favorevoli, int $valore): string
    {
        $m = Maggioranza::tryFrom((string) $maggioranza);
        $regola = self::regolaDellaMaggioranza($maggioranza, $convocazione, $totTeste, $totValore);
        if ($regola === null) {
            return $m === Maggioranza::LIBERA
                ? 'Un\'altra maggioranza' . (trim((string) ($libera['soglia'] ?? '')) !== '' ? ' (' . trim((string) $libera['soglia'])
                    . (trim((string) ($libera['norma'] ?? '')) !== '' ? ', ' . trim((string) $libera['norma']) : '') . ')' : '') . ': decide il presidente.'
                : 'La maggioranza del punto non è scelta: decide il presidente.';
        }

        return ($esito === EsitoDelibera::APPROVATA->value ? 'Approvata' : 'Respinta') . ': ' . $favorevoli . ' favorevoli su ' . $intervenuti . ' intervenuti, '
            . ContiDellaSala::millesimi($valore) . ' millesimi; ' . $regola . '.';
    }

    /**
     * Che cosa serve per approvare, con l'articolo: «in seconda convocazione servono la maggioranza degli intervenuti e 333,34 millesimi», «servono la
     * maggioranza degli intervenuti e 500,00 millesimi (art. 1136 c. 4 c.c.)». Null per un'altra maggioranza, per quella non scelta e per il punto senza voto.
     * La legge anche il verbale, per la frase del voto.
     */
    public static function regolaDellaMaggioranza(?string $maggioranza, string $convocazione, int $totTeste, int $totValore): ?string
    {
        $m = Maggioranza::tryFrom((string) $maggioranza);
        $perEccesso = fn (int $a, int $b) => ContiDellaSala::millesimiPerEccesso(intdiv($a + $b - 1, $b));

        return match ($m) {
            Maggioranza::ORDINARIA => $convocazione === 'prima'
                ? 'in prima convocazione servono la maggioranza degli intervenuti e ' . $perEccesso($totValore, 2) . ' millesimi'
                : 'in seconda convocazione servono la maggioranza degli intervenuti e ' . $perEccesso($totValore, 3) . ' millesimi',
            Maggioranza::META_DEL_VALORE => 'servono la maggioranza degli intervenuti e ' . $perEccesso($totValore, 2) . ' millesimi (art. 1136 c. 4 c.c.)',
            Maggioranza::DUE_TERZI => 'servono la maggioranza degli intervenuti e ' . $perEccesso(2 * $totValore, 3) . ' millesimi (art. 1136 c. 5 c.c.)',
            Maggioranza::QUATTRO_QUINTI => 'servono ' . ContiDellaSala::condomini(intdiv(4 * $totTeste + 4, 5)) . ' e ' . $perEccesso(4 * $totValore, 5) . ' millesimi (art. 1117-ter c.c.)',
            Maggioranza::UNANIMITA => 'serve il voto favorevole di tutti i condòmini',
            default => null,
        };
    }

    /** Una scelta fra due versioni ancora aperta su questa votazione. */
    public static function sceltaAperta(VotazioneDelPunto $v): bool
    {
        $assemblea = SedutaDellAssemblea::query()->whereKey($v->seduta_id)->value('assemblea_id');

        return GestoDellaSala::query()->where('assemblea_id', $assemblea)->where('esito', EsitoDelGesto::IN_CONFLITTO->value)->whereNull('scelto_il')->get(['conflitto'])
            ->contains(fn (GestoDellaSala $x) => ($x->conflitto['su'] ?? null) === 'votazione:' . $v->id);
    }

    /** Il punto del gesto: di questa assemblea, o il gesto si rifiuta. */
    private static function ilPunto(GestoInArrivo $g): PuntoDellOrdineDelGiorno
    {
        return PuntoDellOrdineDelGiorno::query()->where('assemblea_id', $g->assemblea->id)->whereKey($g->id('punto_id'))->first()
            ?? throw ValidationException::withMessages(['gesto' => 'Il punto non è di questa assemblea.']);
    }

    /**
     * La votazione del gesto: di una seduta di questa assemblea, con l'assemblea in corso o chiusa e il verbale della riunione non ancora registrato. Il gesto la dice con il suo numero (`votazione_id`), o,
     * da un dispositivo che ha aperto il voto senza rete e il numero non lo ha, con la seduta, il punto e il numero della votazione (1, o 2 per il riparto).
     * Con `aperta_dal_gesto` (il gesto «Si vota» di quel dispositivo) la votazione dev'essere quella che quel gesto ha aperto: se il punto si votava già da un
     * altro dispositivo, i voti segnati senza rete non si mescolano con i suoi.
     */
    private function laVotazione(GestoInArrivo $g): VotazioneDelPunto
    {
        $sedute = $g->assemblea->sedute()->pluck('id');
        if ($g->dato('votazione_id') !== null) {
            $v = VotazioneDelPunto::query()->whereIn('seduta_id', $sedute)->whereKey($g->id('votazione_id'))->with(['punto', 'seduta'])->first()
                ?? throw ValidationException::withMessages(['gesto' => 'La votazione non è di questa assemblea.']);
        } else {
            $punto = self::ilPunto($g);
            $v = VotazioneDelPunto::query()->whereIn('seduta_id', $sedute)->where('seduta_id', $g->id('seduta_id'))->where('punto_id', $punto->id)
                ->where('numero', (int) $g->dato('numero', 1))->with(['punto', 'seduta'])->first()
                ?? throw ValidationException::withMessages(['gesto' => 'Il punto ' . $punto->numero . ' non si sta votando: prima «Si vota».']);
            $aperta = strtolower(trim((string) $g->dato('aperta_dal_gesto', '')));
            if ($aperta !== '' && strtolower((string) $v->gesto) !== $aperta) {
                throw ValidationException::withMessages(['gesto' => 'Il punto ' . $punto->numero . ' si votava già da un altro dispositivo: i voti segnati qui non si '
                    . 'mescolano con quelli. Segnali di nuovo sul voto di adesso.']);
            }
        }
        // Il voto si corregge in sala fino alla registrazione del verbale della sua riunione, non solo fino alla chiusura (§0.4 (5) del progetto del
        // verbale: la bozza propone «correggi il voto in sala» quando una cifra non torna).
        if (! in_array($g->assemblea->stato, [StatoAssemblea::IN_CORSO, StatoAssemblea::CHIUSA], true)) {
            throw ValidationException::withMessages(['gesto' => 'L\'assemblea è ' . mb_strtolower((string) $g->assemblea->stato?->parola()) . ': il voto non si cambia più.']);
        }
        $registrato = \App\Services\Assemblee\Verbale\VerbaliDellAssemblea::registratoDellaSeduta((int) $v->seduta_id);
        if ($registrato !== null) {
            throw ValidationException::withMessages(['gesto' => 'Il verbale n. ' . $registrato->numero . ' è registrato: il voto non si cambia più.']);
        }

        return $v;
    }

    /**
     * Le scelte del presidente sull'usufrutto per il punto: le unità con usufruttuario e nudo proprietario, su un punto straordinario, con uno dei tre perché
     * dell'art. 67 c. 7 disp. att.
     *
     * @return array<int, array{immobile_id: int, unita: string, perche: string, frase: string}>
     */
    private function usufruttuari(GestoInArrivo $g, SedutaDellAssemblea $seduta, PuntoDellOrdineDelGiorno $punto): array
    {
        $scelte = (array) $g->dato('usufruttuari', []);
        if ($scelte === []) {
            return [];
        }
        if ($punto->amministrazione !== AmministrazionePunto::STRAORDINARIA) {
            throw ValidationException::withMessages(['gesto' => 'Sul punto ' . $punto->numero . ', di ordinaria amministrazione, vota già l\'usufruttuario.']);
        }
        $voce = collect((array) $seduta->fotografia['punti'])->firstWhere('punto_id', (int) $punto->id);
        $conUsufrutto = array_map('intval', (array) ($voce['usufrutto'] ?? []));
        $unita = (array) $seduta->fotografia['unita'];
        $persone = (array) $seduta->fotografia['persone'];
        $esito = [];
        foreach ($scelte as $s) {
            $id = (int) ($s['immobile_id'] ?? 0);
            $perche = PercheVotaLUsufruttuario::tryFrom((string) ($s['perche'] ?? ''));
            if (! in_array($id, $conUsufrutto, true) || $perche === null) {
                throw ValidationException::withMessages(['gesto' => 'L\'usufruttuario vota su un\'unità con l\'usufrutto, con uno dei tre perché dell\'art. 67 c. 7 disp. att.']);
            }
            $usufruttuari = array_map(fn ($t) => (string) ($persone[(int) $t['anagrafica_id']]['nome'] ?? ''),
                array_filter((array) $unita[$id]['titolari'], fn ($t) => $t['ruolo'] === 'usufruttuario'));
            $nome = (string) $unita[$id]['nome'];
            $esito[$id] = ['immobile_id' => $id, 'unita' => $nome, 'perche' => $perche->value, 'frase' => $nome . ': vota ' . TesteDellaSala::elenco(array_values($usufruttuari))
                . ', che ha l\'usufrutto, perché ' . match ($perche) {
                    PercheVotaLUsufruttuario::ART_1006 => 'si avvale dell\'art. 1006 c.c.',
                    PercheVotaLUsufruttuario::LAVORI_985_986 => 'si tratta di lavori od opere degli artt. 985 e 986 c.c.',
                    PercheVotaLUsufruttuario::SEMPLICE_GODIMENTO => 'è un affare di semplice godimento delle cose comuni',
                }];
        }

        return $esito;
    }
}
