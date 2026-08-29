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
import { UploadCloud, FileSpreadsheet, PencilRuler, Sparkles, LoaderCircle, Info, ShieldCheck } from 'lucide-vue-next';
import type { Flash } from '@/types/flash';

const props = defineProps<{
  interrotto: null | {
    uuid: string;
    condominio: string | null;
    livello_corrente: string | null;
    iniziata_il: string | null;
    file: number;
    posizione: number | null;
    livelli_totali: number;
    ha_scritto: boolean;
  };
  formati: string[];
  /** Limite già scritto per l'utente («2 MB»), calcolato dal server: non è più il nostro tetto. */
  dimensione_massima: string;
}>();

// Lo scarto chiede conferma in linea invece di un dialogo: la domanda è breve, la risposta è
// una sola riga di testo, e un modale per due parole è un ostacolo in più su una cosa che
// l'utente ha già deciso.
const scartando = ref(false);
const scarto = useForm({});

function scarta() {
  if (!props.interrotto) return;

  // Lo stato va richiuso a mano: Inertia riusa il componente dopo il redirect, quindi senza
  // questo la conferma resta aperta — puntata sul lotto **successivo**, che l'utente non ha
  // mai chiesto di scartare. Un «Sì, scartala» già armato su una cosa diversa.
  scarto.delete(route('import.scarta', props.interrotto.uuid), {
    onFinish: () => { scartando.value = false; },
  });
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
      <Card v-if="props.interrotto" class="border-amber-300 bg-amber-50 dark:border-amber-900/60 dark:bg-amber-950/30">
        <CardContent class="flex flex-wrap items-start justify-between gap-4 pt-6">
          <div>
            <h2 class="font-semibold">Hai un'importazione ferma a metà</h2>
            <p class="mt-1 text-sm text-muted-foreground">
              <template v-if="props.interrotto.condominio">{{ props.interrotto.condominio }} · </template>
              <template v-if="props.interrotto.iniziata_il">iniziata il {{ props.interrotto.iniziata_il }} · </template>
              {{ props.interrotto.file }} file caricat{{ props.interrotto.file === 1 ? 'o' : 'i' }}<template v-if="props.interrotto.livello_corrente">,
                arrivata a <strong>{{ etichettaLivello(props.interrotto.livello_corrente) }}</strong><template
                  v-if="props.interrotto.posizione"
                > ({{ props.interrotto.posizione }} livelli su {{ props.interrotto.livelli_totali }})</template></template>
            </p>

            <!--
              Chi ha già scritto qualcosa deve saperlo prima di premere «Scarta», non dopo: qui
              si chiude la sessione, non si disfa l'importazione.
            -->
            <p v-if="scartando" class="mt-2 text-sm">
              <template v-if="props.interrotto.ha_scritto">
                Scartandola chiudi questa sessione e cancelli i file caricati.
                <strong>Quello che è già entrato in archivio resta</strong> — per toglierlo serve
                l'annullamento, che arriva con la 1.10.1.
              </template>
              <template v-else>
                Scartandola cancelli i file caricati. Niente è ancora entrato in archivio, quindi
                non si perde altro.
              </template>
            </p>
          </div>

          <div class="flex flex-wrap items-center gap-2">
            <!-- La distruttiva è la più smorzata delle due, ma c'è -->
            <template v-if="scartando">
              <Button variant="ghost" size="sm" @click="scartando = false">Lascia stare</Button>
              <Button variant="destructive" size="sm" :disabled="scarto.processing" @click="scarta">
                Sì, scartala
              </Button>
            </template>
            <template v-else>
              <Button variant="ghost" @click="scartando = true">Scarta</Button>
              <Link :href="route('import.riconoscimento', props.interrotto.uuid)">
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
          </div>

          <div
            class="rounded-lg border-2 border-dashed bg-muted/40 p-8 text-center transition-colors"
            :class="trascinando ? 'border-primary bg-muted' : 'border-input'"
            @dragover.prevent="trascinando = true"
            @dragleave.prevent="trascinando = false"
            @drop.prevent="suDrop"
          >
            <UploadCloud class="mx-auto h-8 w-8 text-muted-foreground" />
            <p class="mt-3 font-medium">Trascina qui i file del tuo vecchio gestionale</p>
            <p class="mt-1 text-sm text-muted-foreground">
              Tutti insieme — ci pensiamo noi a capire cos'è ciascuno.
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
              <Button type="button" variant="ghost" size="sm" @click="togli(i)">Togli</Button>
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
              Nessun dato da importare: creo il condominio a mano.
            </p>
            <Link :href="route('condomini.create')">
              <Button variant="outline" size="sm" class="mt-1">Crea un condominio</Button>
            </Link>
          </CardContent>
        </Card>

        <!--
          Le due strade che non ci sono ancora, dette come «non ci sono ancora».

          Nel disegno erano due carte con un pulsante ciascuna — i modelli da scaricare e la
          migrazione assistita — e nessuna delle due porta da nessuna parte oggi. Lasciarle con
          l'aria di un'azione sarebbe la versione piccola del difetto che questo importatore
          esiste per non commettere: qualcosa che sembra cliccabile e non fa niente.
        -->
        <Card class="border-dashed">
          <CardContent class="space-y-2 pt-6">
            <h3 class="flex items-center gap-2 font-medium text-muted-foreground">
              <PencilRuler class="h-5 w-5 shrink-0" />
              In arrivo
            </h3>
            <p class="text-sm text-muted-foreground">
              I <strong>modelli Excel</strong> da compilare a mano, per chi non ha un export
              usabile, e la <strong>migrazione assistita</strong> — ci mandi i file e te la
              consegniamo verificata. Nessuna delle due è attiva: oggi si parte da un export
              del vecchio gestionale.
            </p>
          </CardContent>
        </Card>
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
