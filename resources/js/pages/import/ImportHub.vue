<script setup lang="ts">
/**
 * S1 — «Da dove arrivi?»
 *
 * La schermata d'ingresso dell'importazione (§14.1 di docs/import_migrazione_dati.md).
 *
 * Due scelte di disegno che non sono estetiche:
 *
 * 1. **Una dropzone sola, non dodici cicli.** Il driver lavora su un insieme di file, quindi
 *    l'amministratore li trascina tutti insieme e la classificazione la fa il sistema. Chi fa
 *    scegliere il livello *prima* di caricare costringe a ripetere il giro per ogni livello.
 * 2. **La promessa sta sotto ogni schermata, non solo qui.** «Niente viene scritto finché non
 *    confermi» è l'unica cosa che riduce la paura di chi sta per versare in un software
 *    sconosciuto anni di contabilità che non è sua.
 */
import { ref, computed } from 'vue';
import { Head, useForm, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import ImportGuide from '@/components/guides/ImportGuide.vue';
import { guideImport } from '@/pages/import/intestazione';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import Alert from '@/components/Alert.vue';
import InputError from '@/components/InputError.vue';
import { UploadCloud, FileSpreadsheet, PencilRuler, Sparkles, LoaderCircle, Info, ShieldCheck, Building2 } from 'lucide-vue-next';
import type { Flash } from '@/types/flash';

const props = defineProps<{
  /** Tutte le importazioni lasciate a metà, non solo l'ultima: uno studio ne apre più d'una. */
  interrotte: {
    uuid: string;
    condominio: string | null;
    file_nomi: string[];
    livello_corrente: string | null;
    iniziata_il: string | null;
    file: number;
    posizione: number | null;
    livelli_totali: number;
    ha_scritto: boolean;
  }[];
  formati: string[];
  /** Limite già scritto per l'utente («2 MB»), calcolato dal server: non è più il nostro tetto. */
  dimensione_massima: string;
}>();

// Lo scarto chiede conferma in linea invece di un dialogo: la domanda è breve, la risposta è
// una sola riga di testo, e un modale per due parole è un ostacolo in più su una cosa che
// l'utente ha già deciso.
//
// ⚠️ Con più importazioni a metà la conferma non può essere un booleano: sarebbe **una sola**,
// aperta su tutte le schede insieme, e un «Sì, scartala» armato su una riga diversa da quella
// che si sta guardando è il modo più diretto di far cancellare la cosa sbagliata. Si tiene lo
// uuid di quella in questione, e null quando non se ne sta scartando nessuna.
const scartando = ref<string | null>(null);
const scarto = useForm({});

function scarta(uuid: string) {
  // Lo stato va richiuso a mano: Inertia riusa il componente dopo il redirect, quindi senza
  // questo la conferma resta aperta — puntata sul lotto **successivo**, che l'utente non ha
  // mai chiesto di scartare.
  scarto.delete(route('import.scarta', uuid), {
    onFinish: () => { scartando.value = null; },
  });
}

/**
 * Come si riconosce un'importazione a metà, quando il condominio non si sa ancora.
 *
 * ⚠️ **Il nome del condominio esiste solo dal secondo livello in poi.** Un lotto fermo prima non
 * ce l'ha, ed è proprio quello che va distinto dagli altri: con tre migrazioni aperte le schede
 * erano identiche a meno dell'ora. Allora si dice quali file contiene — che è l'unica cosa che
 * l'amministratore riconosce, perché li ha trascinati lui.
 *
 * Due nomi e poi il conto: l'elenco intero su quattro file lungo una riga la spezzerebbe, e la
 * scheda serve a riconoscere, non a inventariare.
 */
function comeSiRiconosce(b: { condominio: string | null; file_nomi: string[] }): string {
  if (b.condominio) {
    return b.condominio;
  }

  if (b.file_nomi.length === 0) {
    return 'nessun file';
  }

  const primi = b.file_nomi.slice(0, 2).join(', ');
  const altri = b.file_nomi.length - 2;

  return altri > 0 ? `${primi} e altri ${altri}` : primi;
}

// Il flash si legge dai props di pagina: `<Alert />` senza props rende una scatola vuota
// con la sua × — un avviso che avvisa di niente, e in cima alla schermata più delicata.
const page = usePage<{ flash: { message?: Flash } }>();
const flashMessage = computed(() => page.props.flash.message);

const mostraGuida = ref(false);

const headerBreadcrumbs = [{ title: 'Importa dati' }];

const form = useForm<{ file: File[] }>({ file: [] });
const trascinando = ref(false);
const inputFile = ref<HTMLInputElement | null>(null);

const nomiFile = computed(() => form.file.map((f) => f.name));

function raccogli(lista: FileList | null) {
  if (!lista) return;
  form.file = [...form.file, ...Array.from(lista)];
}

function suDrop(e: DragEvent) {
  trascinando.value = false;
  raccogli(e.dataTransfer?.files ?? null);
}

function togli(indice: number) {
  form.file = form.file.filter((_, i) => i !== indice);
}

function invia() {
  form.post(route('import.store'), { forceFormData: true });
}

const etichettaLivello = (chiave: string | null) => ({
  condominio: 'Condominio',
  esercizi: 'Esercizi',
  soggetti: 'Persone',
  unita: 'Unità immobiliari',
  titolarita: 'Chi possiede cosa',
  tabelle: 'Tabelle millesimali',
  saldi: 'Saldi di apertura',
}[chiave ?? ''] ?? chiave);
</script>

<template>
  <Head title="Importa dati" />

  <AppLayout :breadcrumbs="[]">
    <div class="space-y-6 px-4 py-6">
      <PageHeaderGuide
        page-title="Importa dati"
        page-subtitle="Porta in Kondomanager un condominio che gestisci già altrove."
        :guides="guideImport"
        :breadcrumbs="headerBreadcrumbs"
        has-text-guide
        text-guide-title="Guida"
        @open-text-guide="mostraGuida = true"
      />

      <Alert v-if="flashMessage" :message="flashMessage.message" :type="flashMessage.type" />

      <!-- Il lavoro a metà viene per primo: è l'unica cosa che nessun altro gli ricorderà -->
      <Card
        v-for="b in props.interrotte"
        :key="b.uuid"
        class="border-amber-300 bg-amber-50 dark:border-amber-900/60 dark:bg-amber-950/30"
      >
        <CardContent class="flex flex-wrap items-start justify-between gap-4 pt-6">
          <div>
            <h2 class="font-semibold">
              {{ props.interrotte.length > 1 ? 'Importazione ferma a metà' : 'Hai un\'importazione ferma a metà' }}
            </h2>
            <p class="mt-1 text-sm font-medium text-foreground">{{ comeSiRiconosce(b) }}</p>
            <p class="mt-0.5 text-sm text-muted-foreground">
              <template v-if="b.iniziata_il">iniziata il {{ b.iniziata_il }} · </template>
              {{ b.file }} file caricat{{ b.file === 1 ? 'o' : 'i' }}<template v-if="b.livello_corrente">,
                arrivata a <strong>{{ etichettaLivello(b.livello_corrente) }}</strong><template
                  v-if="b.posizione"
                > ({{ b.posizione }} livelli su {{ b.livelli_totali }})</template></template>
            </p>

            <!--
              Chi ha già scritto qualcosa deve saperlo prima di premere «Scarta», non dopo: qui
              si chiude la sessione, non si disfa l'importazione.
            -->
            <p v-if="scartando === b.uuid" class="mt-2 text-sm">
              <template v-if="b.ha_scritto">
                Scartandola chiudi questa sessione e cancelli i file caricati.
                <strong>Quello che è già entrato in archivio resta</strong> — per toglierlo, oggi,
                si va nelle schermate del condominio.
              </template>
              <template v-else>
                Scartandola cancelli i file caricati. Niente è ancora entrato in archivio, quindi
                non si perde altro.
              </template>
            </p>
          </div>

          <div class="flex flex-wrap items-center gap-2">
            <!-- La distruttiva è la più smorzata delle due, ma c'è -->
            <template v-if="scartando === b.uuid">
              <!--
                ⚠️ **Il gemello di quello dell'esito, corretto insieme.** Era `ghost` da prima
                dell'annullamento, e non se n'era accorto nessuno finché la stessa coppia non è
                comparsa una seconda volta. Correggerne uno solo è la forma che questo progetto ha
                pagato tre volte — le rotte morte in un file su cinque, il download senza estensione
                su uno dei due controller: si chiudono i due insieme.
              -->
              <Button variant="outline" size="sm" @click="scartando = null">Lascia stare</Button>
              <Button variant="destructive" size="sm" :disabled="scarto.processing" @click="scarta(b.uuid)">
                Sì, scartala
              </Button>
            </template>
            <template v-else>
              <!--
                Terza occorrenza della stessa forma, nella stessa card: `ghost` accanto al pulsante
                pieno «Riprendi» faceva leggere l'azione distruttiva come un'etichetta. Resta
                secondaria — `outline` contro il primario — ma si vede che è premibile.
              -->
              <Button variant="outline" @click="scartando = b.uuid">Scarta</Button>
              <Link :href="route('import.riconoscimento', b.uuid)">
                <Button>Riprendi</Button>
              </Link>
            </template>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Da dove arrivi?</CardTitle>
          <CardDescription>Scegli una strada. Puoi cambiarla in qualsiasi momento.</CardDescription>
        </CardHeader>

        <CardContent class="space-y-4">
          <!--
            **Il criterio è generale, Danea è solo l'esempio più frequente.**

            La prima versione di questo riquadro parlava di «stampe» e di «Import/Export tramite
            Excel» come se fossero categorie universali: sono due voci di menù **di Danea**. Ma
            l'importatore non è il lettore di un gestionale solo — i modelli Excel nostri arrivano,
            e altri export arriveranno — e la domanda che conta non cambia mai: **il file dice a
            quale condominio appartiene?** Da quella discende tutto il resto.

            **Sta sopra la zona di trascinamento, non sotto.** Fino alla 1.11.0-beta.3 la
            differenza si scopriva alla **terza** schermata, con i file ormai caricati e
            l'esportazione da rifare; messa sotto il riquadro di caricamento sarebbe arrivata
            comunque dopo il gesto che informa. Una spiegazione che si legge dopo aver agito non
            è una spiegazione.

            Restano invece **in fondo** «Parto da zero» e «In arrivo»: la prima è l'uscita
            dall'importatore, la seconda descrive due cose che non esistono ancora. Aprire con
            loro una pagina che si chiama «Importa dati» vorrebbe dire cominciare da ciò che non
            si può fare.
          -->
          <div class="rounded-md border bg-muted/40 p-4 text-sm">
            <p class="flex items-center gap-2 font-medium">
              <Info class="h-4 w-4 shrink-0 text-muted-foreground" />
              Il file dice a quale condominio appartiene?
            </p>
            <p class="mt-2 text-muted-foreground">
              È l'unica cosa che cambia il modo di lavorare. Alcuni file lo <strong>dichiarano in
              testa</strong> — nome del condominio, codice fiscale e periodo dell'esercizio: da
              quelli creo tutto io, con le date esatte, anche quando l'anno non è solare.
              <span class="text-foreground/70">In Danea sono le <strong>stampe</strong> esportate in
              Excel, e la più completa è il «Consuntivo ripartizioni per unità», che porta anche i
              saldi.</span>
            </p>
            <p class="mt-2 text-muted-foreground">
              Altri sono <strong>elenchi puri</strong>, che cominciano dalle intestazioni di colonna:
              vanno benissimo, ma non dicono di chi sono i dati. In quel caso il condominio deve
              <strong>essere già in archivio con un esercizio aperto</strong>, e te lo chiederò nella
              schermata di verifica.
              <span class="text-foreground/70">In Danea è l'export «Import/Export tramite Excel».</span>
            </p>
            <!--
              Il modello va nominato qui e non solo nella card più in basso: questo riquadro
              risponde a «di quale condominio sono questi dati?», ed è la domanda su cui il
              modello si comporta come il primo dei due casi — non come il secondo, che è quello
              che costringe a scegliere la destinazione a mano.
            -->
            <p class="mt-2 text-muted-foreground">
              <strong>Il modello Kondomanager sta nel primo gruppo</strong>: la sua copertina porta
              il nome del condominio e le date dell'esercizio, quindi da lì creo tutto io. Se lasci
              vuoto il nome, ricadi nel secondo — e ti chiederò dove metterli.
            </p>
          </div>

          <div
            class="rounded-lg border-2 border-dashed bg-muted/40 p-8 text-center transition-colors"
            :class="trascinando ? 'border-primary bg-muted' : 'border-input'"
            @dragover.prevent="trascinando = true"
            @dragleave.prevent="trascinando = false"
            @drop.prevent="suDrop"
          >
            <UploadCloud class="mx-auto h-8 w-8 text-muted-foreground" />
            <!--
              ⚠️ Diceva «i file del tuo vecchio gestionale», ed era vero finché l'unica strada era
              Danea. Da questa beta si carica **qui** anche il modello compilato a mano, che dal
              vecchio gestionale non esce affatto: chi lo ha appena riempito si fermerebbe a
              chiedersi se è il posto giusto.
            -->
            <p class="mt-3 font-medium">Trascina qui i file da importare</p>
            <p class="mt-1 text-sm text-muted-foreground">
              Gli export del vecchio gestionale, o il modello che hai compilato a mano. Tutti
              insieme — ci pensiamo noi a capire cos'è ciascuno.
            </p>
            <p class="mt-1 text-xs text-muted-foreground">
              {{ props.formati.join(', ').toUpperCase() }} · massimo {{ props.dimensione_massima }} per file
            </p>

            <input
              ref="inputFile"
              type="file"
              multiple
              class="hidden"
              :accept="props.formati.map((f) => '.' + f).join(',')"
              @change="raccogli(($event.target as HTMLInputElement).files)"
            />

            <Button type="button" variant="outline" class="mt-4" @click="inputFile?.click()">
              Scegli i file
            </Button>
          </div>


          <div v-if="nomiFile.length" class="space-y-2">
            <div
              v-for="(nome, i) in nomiFile"
              :key="nome + i"
              class="flex items-center justify-between rounded-md border px-3 py-2 text-sm"
            >
              <span class="flex items-center gap-2">
                <FileSpreadsheet class="h-4 w-4 text-muted-foreground" />
                {{ nome }}
              </span>
              <!--
                Quarta e ultima della famiglia in questo percorso. Qui è meno vistoso — la riga ha
                già un bordo suo — ma la regola è la stessa: un'azione che toglie qualcosa non si
                scrive come un'etichetta.
              -->
              <Button type="button" variant="outline" size="sm" @click="togli(i)">Togli</Button>
            </div>

            <InputError :message="form.errors['file.0'] ?? form.errors.file" />

            <Button class="w-full" :disabled="form.processing" @click="invia">
              <LoaderCircle v-if="form.processing" class="mr-2 h-4 w-4 animate-spin" />
              Carica {{ nomiFile.length }} file
            </Button>
          </div>
        </CardContent>
      </Card>

      <div class="grid gap-4 md:grid-cols-2">
        <Card>
          <CardContent class="space-y-2 pt-6">
            <h3 class="flex items-center gap-2 font-medium">
              <Sparkles class="h-5 w-5 shrink-0 text-muted-foreground" />
              Parto da zero
            </h3>
            <p class="text-sm text-muted-foreground">
              Nessun dato da importare, o troppo pochi perché valga la pena: creo il condominio a
              mano e ci aggiungo unità e persone dalle sue schede.
            </p>
            <Link :href="route('condomini.create')">
              <Button variant="outline" size="sm" class="mt-1">
                <Building2 class="mr-2 h-4 w-4" />
                Crea un condominio
              </Button>
            </Link>
          </CardContent>
        </Card>

        <!--
          ⚠️ **Questa carta è nata dentro «In arrivo», e ci è rimasta finché non c'è stato il
          parser.** Il commento che la sostituisce diceva: «lasciarle con l'aria di un'azione
          sarebbe la versione piccola del difetto che questo importatore esiste per non
          commettere — qualcosa che sembra cliccabile e non fa niente». Vale ancora, al
          contrario: il pulsante si accende **insieme** alla strada di ritorno, non prima. Un
          modello che si scarica e non si può ricaricare sarebbe la stessa promessa mancata,
          scoperta però dopo aver compilato quattro fogli.

          Il file non è un allegato statico: `import.modello` lo **genera** a ogni richiesta con
          lo stesso servizio che il parser sa rileggere.
        -->
        <Card>
          <CardContent class="space-y-2 pt-6">
            <h3 class="flex items-center gap-2 font-medium">
              <PencilRuler class="h-5 w-5 shrink-0 text-muted-foreground" />
              Compilo a mano
            </h3>
            <p class="text-sm text-muted-foreground">
              Dal vecchio gestionale non esce un export usabile: scarico il modello, lo compilo e
              lo ricarico qui sopra.
            </p>
            <a :href="route('import.modello')" download>
              <Button variant="outline" size="sm" class="mt-1">
                <FileSpreadsheet class="mr-2 h-4 w-4" />
                Scarica il modello
              </Button>
            </a>
          </CardContent>
        </Card>

        <!--
          ⚠️ **Qui c'era «In arrivo», con dentro la migrazione assistita. È stata tolta il
          30/08/2026, e non per ragioni di spazio.**

          Quella carta annunciava un servizio — «ci mandi i file e te li consegniamo verificati» —
          di cui non è ancora deciso nemmeno se sarà gratuito o a pagamento. Annunciarlo in
          prodotto significa deciderlo: chi lo legge lo dà per compreso, e cambiare idea dopo
          diventa una promessa ritirata invece di una scelta mai fatta.

          Torna quando la decisione c'è, e allora dirà anche a che condizioni.
        -->
      </div>

      <!--
        ⚠️ **Qui c'era «Niente viene scritto finché non confermi. Puoi ricaricare i file quante
        volte vuoi»: la stessa frase della prima card dell'intestazione, parola per parola.**

        Ripetere la promessa su ogni schermata è voluto (`intestazione.ts`), ma ripeterla **due
        volte nella stessa** la svaluta: la seconda non si legge più, e le due insieme fanno
        sembrare che si stia insistendo.

        Al suo posto va la rassicurazione che manca, ed è quella che conta davvero quando si sta
        per caricare l'anagrafe di un condominio intero: **dove finiscono quei file**.
        Verificato: `ImportUploadService` li salva sul disco **privato** e `scarta` li cancella —
        due test lo presidiano, «conserva i file nel disco privato, non fra quelli pubblici» e
        «cancella i file caricati, che sono dati altrui su un disco privato».
      -->
      <div class="flex items-start gap-3 rounded-lg border bg-muted/40 p-4">
        <ShieldCheck class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
        <p class="text-sm text-muted-foreground">
          I file restano su <strong>questo</strong> server, in una cartella privata: contengono i
          dati personali dei tuoi condòmini — nomi, codici fiscali, indirizzi — e non vanno da
          nessun'altra parte. Se chiudi un'importazione a metà, vengono cancellati.
        </p>
      </div>
    </div>

    <ImportGuide v-model:open="mostraGuida" />
  </AppLayout>
</template>
