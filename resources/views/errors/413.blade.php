{{--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
--}}
{{--
    Il file è troppo grande, e questa pagina esiste perché il messaggio arrivi davvero.

    ⚠️ La prima stesura rispondeva con `back()->withErrors(['file' => …])` e **non mostrava niente**:
    `ValidatePostSize` sta nello stack globale, `StartSession` nel gruppo web — quando la dimensione
    viene rifiutata la sessione non è ancora partita, quindi il flash finisce in un archivio mai
    salvato e l'utente riceve un 302 verso il modulo senza alcun avviso. Trovato dal ripasso della
    beta.58 leggendo l'ordine dei middleware. Una vista non ha bisogno della sessione: è l'unica
    forma che regge in questo punto della catena. Nella veste comune delle pagine d'errore dalla 1.12.0-beta.14.
--}}
@extends('errors.minimal')

@section('title', __('errors.413_title'))
@section('code', '413')
@section('tono', 'sl')
@section('message', __('errors.413_title'))
@section('icona')
    <svg class="i l" viewBox="0 0 24 24"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M12 11v3"/><path d="M12 18h.01"/></svg>
@endsection
@section('corpo')
    <p class="lead">{{ __('validation.corpo_troppo_grande', ['limite' => \App\Support\LimiteCaricamento::etichettaPost()]) }}</p>
@endsection
