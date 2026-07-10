<?php

/**
 * Regressione per un bug reale osservato in produzione: @livewire($step['component'], ...)
 * nel wizard, senza una key esplicita, poteva far perdere a Livewire l'identità del
 * componente figlio (es. CreateAdmin) quando il wizard si ri-renderizzava per processare
 * il click su "Avanti" — il figlio veniva rimontato da zero con proprietà a null invece di
 * riutilizzare l'istanza esistente con i dati digitati dall'utente. Riprodotto e verificato
 * dal vivo nel browser (non riproducibile via Livewire::test(), che non esercita il morphing
 * DOM lato client dove il bug si manifesta davvero): stesso wire:id prima del click su
 * Avanti, wire:id diverso e tutti i campi tornati null subito dopo. Il fix è una key()
 * esplicita e stabile per step — questo test guarda che non venga rimossa per errore.
 */
it('keeps an explicit stable key on the nested step component', function () {
    expect(file_get_contents(resource_path('views/installer/installer-wizard.blade.php')))
        ->toContain("key(\$step['key'])");
});
