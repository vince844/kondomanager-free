<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La finestra «Incolla da un foglio di calcolo» della pagina delle quote.
 *
 * Si incollano le colonne del foglio, l'unità e il valore; l'anteprima dice riga per riga che cosa
 * è stato capito, e «Applica» consegna alla pagina solo le righe riconosciute. La finestra **non
 * salva**: i valori entrano nella griglia, dove si vedono e si correggono, e partono con «Salva
 * quote» come tutti gli altri. La lettura sta in `incollaMillesimi.ts`, con le sue prove.
 *
 * Dove il foglio si può leggere in due modi la finestra **chiede**, e finché non ha la risposta
 * non mostra nessuna riga: quale colonna sono i valori (con più di due colonne piene), e se il
 * punto è il decimale o il separatore delle migliaia (quando il foglio non lo dice).
 *
 * La forma è quella delle altre finestre: ancorata in alto, il contenuto scorre dentro, il
 * riepilogo e i pulsanti fissi nel piede. Le righe da guardare stanno in un riquadro arancio
 * sopra le riconosciute, ciascuna con il suo motivo; la colonna «Adesso» mostra il valore che
 * l'unità ha in questo momento nella griglia, così chi sostituisce un millesimo già scritto lo vede.
 */
import { computed, ref, watch } from 'vue';
import { ClipboardPaste, AlertTriangle } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import {
  leggiIncolla,
  type ConvenzioneDecimale,
  type EsitoRigaIncollata,
  type UnitaPerIncolla,
} from '@/lib/gestionale/tabelle/incollaMillesimi';

const props = defineProps<{
  open: boolean;
  unita: UnitaPerIncolla[];
  /** Gli id delle unità già nella griglia: l'unità del foglio si cerca prima fra queste. */
  inTabella?: number[];
  /** Il valore che ogni unità ha adesso nella griglia, per id; vuoto se non è ancora compilato. */
  adesso?: Record<number, string | null>;
}>();

const emit = defineEmits<{
  (e: 'update:open', aperta: boolean): void;
  (e: 'applica', righe: { immobileId: number; valore: string }[]): void;
}>();

const testo = ref('');
const colonnaScelta = ref<number | null>(null);
const convenzioneScelta = ref<ConvenzioneDecimale | null>(null);

// Ogni apertura parte vuota: un foglio incollato ieri non deve rientrare per sbaglio oggi.
watch(() => props.open, (aperta) => {
  if (aperta) testo.value = '';
});

// Una risposta vale per il foglio a cui è stata data: cambiato il testo, le domande si rifanno.
watch(testo, () => {
  colonnaScelta.value = null;
  convenzioneScelta.value = null;
});

const esito = computed(() => leggiIncolla(testo.value, props.unita, {
  inTabella: props.inTabella ?? [],
  colonnaValori: colonnaScelta.value,
  convenzione: convenzioneScelta.value,
}));

const perId = computed(() => new Map(props.unita.map((u) => [u.id, u])));

/** «Appartamento 1 · Palazzina B»: in un complesso il nome da solo non dice quale. */
const nomeUnita = (id: number | null): string => {
  const u = id === null ? undefined : perId.value.get(id);
  if (!u) return '—';

  return u.palazzina?.name ? `${u.nome} · ${u.palazzina.name}` : u.nome;
};

/** Il valore dell'unità adesso nella griglia: «—» se non è in tabella, «vuoto» se non è compilato. */
const valoreAdesso = (id: number | null): string => {
  if (id === null || !(props.inTabella ?? []).includes(id)) return '—';
  const v = props.adesso?.[id];

  return v === null || v === undefined || v === '' ? 'vuoto' : String(v);
};

const motivi: Record<Exclude<EsitoRigaIncollata, 'riconosciuta'>, string> = {
  non_trovata: 'nessuna unità con questo nome o con questi dati catastali',
  ambigua: 'più unità con questo nome: usa particella/subalterno, o associa prima le unità giuste',
  doppia: 'la stessa unità è già più sopra nel foglio: vale la prima',
  valore_non_letto: 'il valore non si legge come un numero',
};

const riconosciute = computed(() => esito.value.righe.filter((r) => r.esito === 'riconosciuta'));
const daGuardare = computed(() => esito.value.righe.filter((r) => r.esito !== 'riconosciuta'));
const inAttesaDiRisposta = computed(() => esito.value.colonnaDaScegliere || esito.value.convenzioneDaScegliere);

const etichettaColonna = (c: { indice: number; intestazione: string | null }): string =>
  c.intestazione ? `«${c.intestazione}»` : `Colonna ${c.indice + 1}`;

const applica = () => {
  emit(
    'applica',
    riconosciute.value
      .filter((r) => r.immobileId !== null && r.valore !== null)
      .map((r) => ({ immobileId: r.immobileId as number, valore: r.valore as string })),
  );
  emit('update:open', false);
};
</script>

<template>
  <Dialog :open="open" @update:open="(aperta: boolean) => emit('update:open', aperta)">
    <DialogContent class="top-[5vh] flex max-h-[90vh] translate-y-0 flex-col gap-0 overflow-hidden p-0 sm:max-w-[680px]" data-test="incolla-finestra">
      <DialogHeader class="border-b border-slate-100 px-5 pt-4 pb-3 pr-12 text-left dark:border-slate-800">
        <DialogTitle>Incolla da un foglio di calcolo</DialogTitle>
        <DialogDescription>
          Copia dal foglio la colonna delle unità e quella dei valori, e incollale qui. L'unità si riconosce
          dal nome oppure dai dati catastali scritti <em>particella/subalterno</em>. I valori entrano
          nell'elenco e <strong>si salvano solo con «Salva quote»</strong>.
        </DialogDescription>
      </DialogHeader>

      <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-4 text-sm">
        <Textarea
          v-model="testo"
          rows="5"
          class="max-h-40 text-sm"
          aria-label="Le righe copiate dal foglio"
          placeholder="Appartamento 1	94,91&#10;Appartamento 2	95,97&#10;658/22	99,62"
          data-test="incolla-testo"
        />

        <!-- Più di due colonne piene: prendere l'ultima vorrebbe dire scrivere le scale nella proprietà senza dirlo. -->
        <div
          v-if="esito.colonnaDaScegliere"
          class="rounded-lg border border-sky-200 bg-sky-50 p-3 dark:border-sky-900/60 dark:bg-sky-950/30"
          data-test="incolla-scegli-colonna"
        >
          <p class="mb-2 font-medium text-slate-900 dark:text-slate-100">Il foglio ha più colonne: quale sono i valori di questa tabella?</p>
          <div class="flex flex-wrap gap-2">
            <Button
              v-for="c in esito.colonne"
              :key="c.indice"
              type="button"
              variant="outline"
              class="h-auto px-3 py-1.5 text-left text-sm"
              :data-test="`incolla-colonna-${c.indice}`"
              @click="colonnaScelta = c.indice"
            >
              <span class="font-medium">{{ etichettaColonna(c) }}</span>
              <span class="ml-2 text-xs text-slate-500 dark:text-slate-400">per esempio {{ c.esempio }}</span>
            </Button>
          </div>
        </div>

        <!-- «1.250»: milleduecentocinquanta o uno virgola venticinque? Il foglio non lo dice, e non si indovina. -->
        <div
          v-else-if="esito.convenzioneDaScegliere"
          class="rounded-lg border border-sky-200 bg-sky-50 p-3 dark:border-sky-900/60 dark:bg-sky-950/30"
          data-test="incolla-scegli-convenzione"
        >
          <p class="mb-2 font-medium text-slate-900 dark:text-slate-100">
            <template v-if="esito.dubbio">Nel foglio c'è «{{ esito.dubbio.testo }}»: il punto è il separatore delle migliaia o dei decimali?</template>
            <template v-else>Nel foglio il punto e la virgola non sono usati sempre allo stesso modo: qual è il separatore dei decimali?</template>
          </p>
          <div class="flex flex-wrap gap-2">
            <Button type="button" variant="outline" class="h-auto px-3 py-1.5 text-left text-sm" data-test="incolla-convenzione-virgola" @click="convenzioneScelta = 'virgola'">
              <span class="font-medium">Delle migliaia</span>
              <span v-if="esito.dubbio?.conLaVirgola" class="ml-2 text-xs text-slate-500 dark:text-slate-400">si legge {{ esito.dubbio.conLaVirgola }}</span>
            </Button>
            <Button type="button" variant="outline" class="h-auto px-3 py-1.5 text-left text-sm" data-test="incolla-convenzione-punto" @click="convenzioneScelta = 'punto'">
              <span class="font-medium">Dei decimali</span>
              <span v-if="esito.dubbio?.colPunto" class="ml-2 text-xs text-slate-500 dark:text-slate-400">si legge {{ esito.dubbio.colPunto }}</span>
            </Button>
          </div>
        </div>

        <template v-else>
          <!-- Le righe da guardare: non entrano nell'elenco, e ognuna dice perché. -->
          <div
            v-if="daGuardare.length > 0"
            class="rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-900/60 dark:bg-amber-950/30"
            data-test="incolla-da-guardare"
          >
            <p class="mb-1.5 flex items-center gap-2 font-medium text-amber-900 dark:text-amber-200">
              <AlertTriangle class="h-4 w-4 shrink-0" />
              {{ daGuardare.length === 1 ? 'Una riga da guardare: non entra nell\'elenco' : `${daGuardare.length} righe da guardare: non entrano nell'elenco` }}
            </p>
            <ul class="space-y-1 text-xs text-amber-900 dark:text-amber-100/90">
              <li v-for="r in daGuardare" :key="r.riga">
                <span class="tabular-nums">Riga {{ r.riga }}</span> · <strong>{{ r.chiave }}</strong>:
                {{ motivi[r.esito as Exclude<EsitoRigaIncollata, 'riconosciuta'>] }}<template v-if="r.esito === 'doppia'"> ({{ nomeUnita(r.immobileId) }})</template>.
              </li>
            </ul>
          </div>

          <div v-if="riconosciute.length > 0" class="overflow-x-auto rounded-lg border border-slate-200 dark:border-slate-800" data-test="incolla-riconosciute">
            <table class="w-full text-sm">
              <thead class="bg-slate-50 text-left text-xs font-medium text-slate-600 dark:bg-slate-900 dark:text-slate-400">
                <tr>
                  <!-- Su uno schermo stretto «Riga» e «Nel foglio» lasciano il posto ad «Adesso» e «Valore letto», che sono ciò che si decide. -->
                  <th class="hidden px-3 py-2 sm:table-cell">Riga</th>
                  <th class="hidden px-3 py-2 sm:table-cell">Nel foglio</th>
                  <th class="px-3 py-2">Unità</th>
                  <th class="px-3 py-2 text-right">Adesso</th>
                  <th class="px-3 py-2 text-right">Valore letto</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="r in riconosciute" :key="r.riga" class="border-t border-slate-100 dark:border-slate-800">
                  <td class="hidden px-3 py-1.5 tabular-nums text-slate-500 sm:table-cell dark:text-slate-400">{{ r.riga }}</td>
                  <td class="hidden px-3 py-1.5 sm:table-cell">{{ r.chiave }}</td>
                  <td class="px-3 py-1.5">{{ nomeUnita(r.immobileId) }}</td>
                  <td class="px-3 py-1.5 text-right tabular-nums text-slate-500 dark:text-slate-400">{{ valoreAdesso(r.immobileId) }}</td>
                  <td class="px-3 py-1.5 text-right font-medium tabular-nums text-slate-900 dark:text-slate-100">{{ r.valore }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <p v-if="esito.intestazioneSaltata && esito.righe.length > 0" class="text-xs text-slate-500 dark:text-slate-400">
            La prima riga è l'intestazione del foglio e non conta.
          </p>
        </template>
      </div>

      <!-- Il piede fisso: il riepilogo accanto ai pulsanti. -->
      <div class="flex flex-col gap-3 border-t border-slate-200 bg-slate-50 px-5 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800 dark:bg-slate-900/60">
        <p class="text-sm text-slate-600 dark:text-slate-300" data-test="incolla-riepilogo">
          <template v-if="inAttesaDiRisposta">Rispondi alla domanda qui sopra per vedere le righe.</template>
          <template v-else-if="esito.righe.length === 0">Niente da applicare.</template>
          <template v-else>
            <strong class="tabular-nums">{{ esito.riconosciute }}</strong> {{ esito.riconosciute === 1 ? 'riconosciuta' : 'riconosciute' }}<template v-if="esito.daGuardare > 0">
              · <strong class="tabular-nums text-amber-700 dark:text-amber-400">{{ esito.daGuardare }}</strong> da guardare</template>
          </template>
        </p>
        <div class="flex justify-end gap-2">
          <Button type="button" variant="ghost" class="h-9 px-4 text-sm" @click="emit('update:open', false)">
            Annulla
          </Button>
          <Button type="button" :disabled="esito.riconosciute === 0" class="h-9 gap-2 px-4 text-sm" data-test="incolla-applica" @click="applica">
            <ClipboardPaste class="h-4 w-4" />
            Applica {{ esito.riconosciute }} {{ esito.riconosciute === 1 ? 'valore' : 'valori' }}
          </Button>
        </div>
      </div>
    </DialogContent>
  </Dialog>
</template>
