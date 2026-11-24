{{--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
--}}
{{--
  Il poco che le pagine d'errore fanno nel browser, dentro la pagina (niente file da Vite): «Copia» (anche dove il browser non dà gli appunti, su un indirizzo
  http della rete dello studio), «Avvisa lo studio» senza lasciare la pagina, «Torna indietro», «Ricarica la pagina» (la pagina intera, anche dalla finestra
  sopra una pagina), «Riprova» che si accende quando il tempo del 429 è passato. Senza JavaScript i pulsanti restano collegamenti e il modulo si manda lo
  stesso.
--}}
<script>
(function () {
    function copia(testo, fatto) {
        function ripiego() {
            var area = document.createElement('textarea');
            area.value = testo;
            area.setAttribute('readonly', '');
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            try { document.execCommand('copy'); fatto(); } catch (e) {}
            document.body.removeChild(area);
        }
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(testo).then(fatto, ripiego);
        } else {
            ripiego();
        }
    }
    document.querySelectorAll('[data-copia]').forEach(function (pulsante) {
        pulsante.addEventListener('click', function () {
            var da = pulsante.getAttribute('data-copia-da');
            var testo = da ? (document.getElementById(da) || {}).value || '' : pulsante.getAttribute('data-copia');
            copia(testo, function () {
                if (pulsante.getAttribute('data-giro')) return;
                var prima = pulsante.innerHTML;
                pulsante.setAttribute('data-giro', '1');
                pulsante.classList.add('fatto');
                pulsante.textContent = pulsante.getAttribute('data-copiato');
                setTimeout(function () { pulsante.innerHTML = prima; pulsante.classList.remove('fatto'); pulsante.removeAttribute('data-giro'); }, 2000);
            });
        });
    });
    document.querySelectorAll('[data-indietro]').forEach(function (a) {
        a.addEventListener('click', function (ev) {
            if (window.history.length > 1) { ev.preventDefault(); window.history.back(); }
        });
    });
    document.querySelectorAll('[data-ricarica]').forEach(function (a) {
        a.addEventListener('click', function (ev) {
            ev.preventDefault();
            try { (window.top || window).location.reload(); } catch (e) { window.location.reload(); }
        });
    });
    document.querySelectorAll('[data-riprova]').forEach(function (a) {
        a.addEventListener('click', function (ev) { ev.preventDefault(); window.location.reload(); });
    });
    document.querySelectorAll('[data-fra-secondi]').forEach(function (pulsante) {
        var resta = parseInt(pulsante.getAttribute('data-fra-secondi'), 10) || 0;
        var etichetta = pulsante.querySelector('[data-etichetta]');
        function giro() {
            if (resta <= 0) {
                pulsante.removeAttribute('disabled');
                if (etichetta) etichetta.textContent = pulsante.getAttribute('data-pronto');
                return;
            }
            var m = Math.floor(resta / 60), s = resta % 60;
            if (etichetta) etichetta.textContent = pulsante.getAttribute('data-attesa').replace(':tempo', m + ':' + (s < 10 ? '0' : '') + s);
            resta -= 1;
            setTimeout(giro, 1000);
        }
        giro();
    });
    var modulo = document.querySelector('[data-avviso]');
    if (!modulo) return;
    var nota = modulo.querySelector('textarea');
    var conta = modulo.querySelector('[data-conta]');
    if (nota && conta) nota.addEventListener('input', function () { conta.textContent = String(nota.value.length); });
    modulo.addEventListener('submit', function (ev) {
        if (!window.fetch || !window.FormData) return;
        ev.preventDefault();
        var invia = modulo.querySelector('button[type="submit"]');
        invia.setAttribute('disabled', '');
        fetch(modulo.action, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(modulo),
            credentials: 'same-origin'
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (dati) { return { stato: r.status, dati: dati }; });
        }).then(function (esito) {
            var riuscito = esito.stato >= 200 && esito.stato < 300;
            var gia = esito.stato === 409;
            var testo = esito.dati && esito.dati.messaggio ? esito.dati.messaggio
                : (esito.stato === 429 ? modulo.getAttribute('data-troppi') : modulo.getAttribute('data-non-riuscito'));
            mostra(riuscito || gia, riuscito ? modulo.getAttribute('data-fatto') : '', testo);
        }).catch(function () {
            mostra(false, '', modulo.getAttribute('data-non-riuscito'));
        });
    });
    function mostra(bene, titolo, testo) {
        var esito = document.querySelector('[data-esito]');
        var campi = modulo.querySelector('[data-campi]');
        esito.classList.toggle('am', !bene);
        esito.querySelector('[data-titolo]').textContent = titolo;
        esito.querySelector('[data-titolo]').hidden = !titolo;
        esito.querySelector('[data-testo]').textContent = testo || '';
        esito.hidden = false;
        if (campi) campi.hidden = true;
        // Il pulsante nero è uno solo: dopo l'avviso torna a essere «Torna alla pagina iniziale».
        var casa = document.querySelector('[data-pagina-iniziale]');
        if (casa) casa.classList.add('pri');
    }
})();
</script>
