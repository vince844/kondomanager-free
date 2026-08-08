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
import { UploadCloud, FileSpreadsheet, PencilRuler, Sparkles, Lock, LoaderCircle } from 'lucide-vue-next';
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
  dimensione_massima_mb: number;
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
              {{ props.formati.join(', ').toUpperCase() }} · massimo {{ props.dimensione_massima_mb }} MB per file
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
            <Sparkles class="h-5 w-5 text-muted-foreground" />
            <h3 class="font-medium">Parto da zero</h3>
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
            <PencilRuler class="h-5 w-5 text-muted-foreground" />
            <h3 class="font-medium text-muted-foreground">In arrivo</h3>
            <p class="text-sm text-muted-foreground">
              I <strong>modelli Excel</strong> da compilare a mano, per chi non ha un export
              usabile, e la <strong>migrazione assistita</strong> — ci mandi i file e te la
              consegniamo verificata. Nessuna delle due è attiva: oggi si parte da un export
              del vecchio gestionale.
            </p>
          </CardContent>
        </Card>
      </div>

      <p class="flex items-center gap-2 border-t pt-4 text-xs text-muted-foreground">
        <Lock class="h-3.5 w-3.5" />
        Niente viene scritto in Kondomanager finché non confermi. Puoi ricaricare i file quante volte vuoi.
      </p>
    </div>

    <ImportGuide v-model:open="mostraGuida" />
  </AppLayout>
</template>
