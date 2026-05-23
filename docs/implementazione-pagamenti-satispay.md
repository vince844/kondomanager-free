# Integrazione Pagamenti Satispay (v1.9.1)

Implementazione del sistema di pagamenti diretti tramite Satispay per le rate condominiali. Il sistema prevede un'attivazione globale dalle impostazioni generali e configurazioni specifiche per ogni condominio (che avrà un proprio account Satispay Business separato).

## User Review Required

> [!IMPORTANT]
> **Scelta del Pacchetto Satispay:**
> L'implementazione suggerisce di usare il pacchetto ufficiale PHP di Satispay (`satispay/gbusiness-api-php`) o direttamente le chiamate HTTP di Laravel. Essendo le API REST di Satispay molto semplici, spesso usare `Http::` di Laravel è più pulito e mantenibile rispetto al pacchetto ufficiale.
> Procederemo con `Http::` nativo di Laravel per minimizzare le dipendenze esterne?

> [!WARNING]
> **Limiti di Satispay per Multi-Condominio:**
> Come giustamente evidenziato, Satispay richiede un account Business separato (e diverso "Activation Code") per ogni Partita IVA / IBAN. Questo significa che se l'amministratore gestisce 20 condomini, dovrà generare 20 codici di attivazione diversi dal pannello Satispay e inserirli uno ad uno nelle impostazioni dei singoli condomini su Kondomanager. 

## Open Questions

> [!CAUTION]
> **Satispay Webhooks (Ping Pong):**
> Satispay invierà un webhook a Kondomanager quando il pagamento verrà accettato. Kondomanager dovrà registrare in automatico la `ScritturaContabile`. Volete che la data della scrittura contabile sia la data di accettazione del webhook, o c'è una logica particolare per la data valuta?

## Proposed Changes

---

### Database / Migrations

Aggiungeremo i campi necessari per abilitare globalmente Satispay e configurarlo a livello di condominio. Seguendo le direttive del pattern di cleanup per le migration multi-colonna (`KI: Kondomanager — Pattern Cleanup per Migration ALTER TABLE Multi-Colonna`).

#### [NEW] [database/migrations/2026_05_XX_add_satispay_to_condomini_table.php](file:///Users/vincenzo/Desktop/kondomanager-free/database/migrations/2026_05_XX_add_satispay_to_condomini_table.php)
- Aggiunta colonne: `satispay_key_id`, `satispay_private_key`, `satispay_public_key`, `satispay_sandbox` alla tabella `condomini`.
- Inserimento dei controlli `hasColumn` e fallback per garantire idempotenza in caso di fail parziali.

#### [MODIFY] [database/seeders/SettingsSeeder.php](file:///Users/vincenzo/Desktop/kondomanager-free/database/seeders/SettingsSeeder.php) (o dove vengono generati i setting)
- Creazione della chiave `satispay_enabled` nel database dei setting globali (default: `false`).

---

### Backend Logic (API Satispay)

#### [NEW] [app/Services/SatispayService.php](file:///Users/vincenzo/Desktop/kondomanager-free/app/Services/SatispayService.php)
- Servizio che gestisce:
  - Scambio dell'`Activation Code` fornito dall'admin per ottenere il `key_id`.
  - Generazione della coppia di chiavi RSA necessaria per Satispay e salvataggio nel database (`condomini`).
  - Generazione dei link di pagamento (Checkout Satispay).
  - Validazione delle firme (Signature) dei Webhook in entrata.

#### [NEW] [app/Http/Controllers/Satispay/SatispayController.php](file:///Users/vincenzo/Desktop/kondomanager-free/app/Http/Controllers/Satispay/SatispayController.php)
- `POST /satispay/activate/{condominio_id}`: rotta per attivare Satispay su uno specifico condominio inserendo l'Activation Code.
- `POST /satispay/checkout/{rata_id}`: rotta che chiama Satispay e restituisce il link di pagamento al frontend.

#### [NEW] [app/Http/Controllers/Satispay/SatispayWebhookController.php](file:///Users/vincenzo/Desktop/kondomanager-free/app/Http/Controllers/Satispay/SatispayWebhookController.php)
- `POST /webhooks/satispay`: Endpoint pubblico chiamato dal server Satispay. 
- Gestisce la logica di business (il "Pong"): marca la Rata come pagata e registra la Scrittura Contabile associata.

#### [MODIFY] [routes/web.php](file:///Users/vincenzo/Desktop/kondomanager-free/routes/web.php) & [routes/api.php](file:///Users/vincenzo/Desktop/kondomanager-free/routes/api.php)
- Registrazione delle nuove rotte web e webhooks.

---

### Frontend / UI

#### [MODIFY] [resources/js/pages/impostazioni/impostazioniGenerali.vue](file:///Users/vincenzo/Desktop/kondomanager-free/resources/js/pages/impostazioni/impostazioniGenerali.vue)
- Aggiunta del toggle (switch) **"Abilita Pagamenti Satispay"**.
- Gestione della properties Inertia e invio form per salvare il setting.

#### [MODIFY] [resources/js/pages/condomini/ModificaCondominio.vue](file:///Users/vincenzo/Desktop/kondomanager-free/resources/js/pages/condomini/ModificaCondominio.vue) (o componente equivalente per i settings del condominio)
- Nuovo blocco UI visibile solo se Satispay è abilitato globalmente.
- Form per inserire l'`Activation Code` di Satispay.
- Indicatore di stato (Connesso / Non Connesso).

#### [MODIFY] [resources/js/pages/gestionale/movimenti/fatture/FatturaRegisterList.vue](file:///Users/vincenzo/Desktop/kondomanager-free/resources/js/pages/gestionale/movimenti/fatture/FatturaRegisterList.vue) (e componente Scadenzario Rate)
- Aggiunta del pulsante **"Paga con Satispay"** per i condòmini (condizionato all'abilitazione globale e alla corretta configurazione del singolo condominio).
- Cliccando, l'utente verrà reindirizzato alla pagina Satispay per il check-out.

## Verification Plan

### Automated Tests
- Test di generazione RSA Key e mock della risposta di Satispay API per l'attivazione.
- Test sull'endpoint Webhook che validi una firma fittizia e simuli il salvataggio corretto della `ScritturaContabile`.

### Manual Verification
- Utilizzeremo il **Sandbox** mode di Satispay.
- Attiveremo globalmente Satispay.
- Inseriremo un token Sandbox di un account Satispay Business fittizio.
- Genereremo una Rata, cliccheremo "Paga con Satispay" (Sandox).
- Simuleremo o riceveremo il Webhook per verificare l'effettiva chiusura della rata nello Scadenzario.
