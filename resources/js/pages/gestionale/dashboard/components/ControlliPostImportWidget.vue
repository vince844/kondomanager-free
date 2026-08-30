<script setup lang="ts">
/**
 * Il richiamo alla lista «Da controllare dopo l'importazione» — e, quando non c'è più niente da
 * controllare, alla **ricevuta** di quell'importazione.
 *
 * ## Le due facce, e perché non è più una sola
 *
 * ⚠️ Fino alla 1.11.0-beta.5 questo riquadro compariva solo se c'era qualcosa di aperto e spariva
 * da solo all'ultima voce chiusa. Sembrava giusto — un condominio migrato mesi fa non deve
 * portarsi in giro un richiamo che non serve — e portava via con sé l'unica strada verso il
 * **rapporto**: il documento che si allega al passaggio di consegne e si archivia. Chi chiudeva i
 * controlli non aveva più modo di tornarci, ed è successo davvero.
 *
 * Restano quindi due stati distinti, e la differenza si vede a colpo d'occhio:
 *
 * - **c'è del lavoro** → giallo, con le prime voci e il conteggio. È urgente e si legge come tale;
 * - **è tutto a posto** → neutro, una riga sola: questo condominio è arrivato da un'importazione,
 *   e la sua ricevuta è qui. Non scade, perché la domanda «dov'è il rapporto?» arriva mesi dopo.
 */
import { ClipboardCheck, ChevronRight, FileDown, CheckCircle2 } from 'lucide-vue-next';

const props = defineProps<{
  controlli: {
    totale: number;
    /** Quante si chiudono da sole quando il dato è a posto. */
    da_sole: number;
    /** Quante aspettano che l'amministratore dichiari di averle guardate. */
    da_confermare: number;
    url: string;
    prime: { titolo: string; url: string | null }[];
    lotto: { uuid: string; quando: string | null; url_rapporto: string; url_esito: string } | null;
  };
}>();
</script>

<template>
  <!-- ── C'è del lavoro: il richiamo giallo, come prima ── -->
  <div
    v-if="props.controlli.totale > 0"
    class="rounded-xl border border-amber-300 bg-amber-50/60 p-4 dark:border-amber-900/60 dark:bg-amber-950/20"
  >
    <div class="flex items-start justify-between gap-3">
      <div class="flex items-center gap-2">
        <ClipboardCheck class="h-4 w-4 text-amber-600 dark:text-amber-400" />
        <h3 class="text-sm font-semibold">Da controllare dopo l'importazione</h3>
      </div>
      <span class="shrink-0 text-sm font-semibold tabular-nums">{{ props.controlli.totale }}</span>
    </div>

    <ul class="mt-3 space-y-1.5">
      <li v-for="(v, i) in props.controlli.prime" :key="i" class="text-sm text-muted-foreground">
        · {{ v.titolo }}
      </li>
    </ul>

    <!--
      ⚠️ **Come si chiude questa card, detto qui.**

      Non ha un pulsante per chiuderla, ed è voluto: sarebbe o una bugia (nascondere senza aver
      sistemato) o una spunta collettiva su cose non fatte — cioè esattamente la todolist che si
      spunta senza lavorare, che è il difetto per cui questa lista è nata verificabile. La valvola
      c'è ed è per voce, dove è onesta e reversibile: «Metti da parte».

      Ma le voci si chiudono in **due modi diversi**, e senza dirlo la card sembra bloccata: chi
      sistema un problema, torna qui e ne trova ancora tre, non sa se ha sbagliato o se deve
      confermare da qualche parte.
    -->
    <p class="mt-3 text-xs text-muted-foreground">
      <template v-if="props.controlli.da_sole && props.controlli.da_confermare">
        {{ props.controlli.da_sole }} sparisce{{ props.controlli.da_sole > 1 ? 'ranno' : '' }} da
        {{ props.controlli.da_sole > 1 ? 'sole' : 'sola' }} appena avrai sistemato il dato;
        {{ props.controlli.da_confermare === 1 ? "l'altra riguarda" : 'le altre ' + props.controlli.da_confermare + ' riguardano' }}
        cose che non posso ricontrollare io, e {{ props.controlli.da_confermare === 1 ? 'si chiude' : 'si chiudono' }}
        quando me lo dici tu da «Vedile tutte».
      </template>
      <template v-else-if="props.controlli.da_sole">
        Spariscono da qui da sole appena avrai sistemato il dato: non c'è niente da spuntare.
      </template>
      <template v-else>
        Riguardano cose che non posso ricontrollare io — il confronto è con documenti fuori da
        Kondomanager — quindi restano finché non mi dici tu che le hai guardate, da «Vedile tutte».
      </template>
    </p>

    <div class="mt-3 flex flex-wrap items-center gap-4">
      <a :href="props.controlli.url" class="inline-flex items-center gap-1 text-sm font-medium underline">
        Vedile tutte <ChevronRight class="h-3.5 w-3.5" />
      </a>
      <a
        v-if="props.controlli.lotto"
        :href="props.controlli.lotto.url_rapporto"
        target="_blank"
        class="inline-flex items-center gap-1 text-sm text-muted-foreground underline"
      >
        <FileDown class="h-3.5 w-3.5" /> Rapporto dell'importazione
      </a>
    </div>
  </div>

  <!-- ── Niente da controllare: resta la ricevuta, e basta ── -->
  <div v-else-if="props.controlli.lotto" class="rounded-xl border bg-muted/30 p-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div class="flex items-center gap-2 text-sm">
        <CheckCircle2 class="h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
        <span>
          Questo condominio è arrivato da un'importazione<template v-if="props.controlli.lotto.quando">
            del {{ props.controlli.lotto.quando }}</template>, e non è rimasto niente da controllare.
        </span>
      </div>
      <div class="flex flex-wrap items-center gap-4">
        <a
          :href="props.controlli.lotto.url_rapporto"
          target="_blank"
          class="inline-flex items-center gap-1 text-sm font-medium underline"
        >
          <FileDown class="h-3.5 w-3.5" /> Rapporto (PDF)
        </a>
        <a :href="props.controlli.url" class="text-sm text-muted-foreground underline">
          Rivedi i controlli
        </a>
      </div>
    </div>
  </div>
</template>
