<script setup lang="ts">
/**
 * Riepilogo dei rifiuti di validazione, in testa a un modulo.
 *
 * Nasce dalla segnalazione di un amministratore sulla scheda fornitore: «il pulsante Salva
 * modifiche non riporta alcun effetto, non compare né il messaggio di successo né un errore».
 * Il rifiuto c'era — un 422 — ma riguardava un campo senza `<InputError>`, e Inertia rimonta la
 * stessa pagina senza che nulla cambi a schermo.
 *
 * È la stessa classe della Coda 51 in roadmap: 210 chiavi validate su 675 non hanno dove
 * comparire. Un `<InputError>` per campo è la correzione puntuale; questo riquadro è la rete
 * sotto, perché copre anche le chiavi che nessuno ha ancora collegato a un campo — comprese
 * quelle dei campi che si aggiungeranno domani.
 */
import { computed } from 'vue';
import { TriangleAlert } from 'lucide-vue-next';

const props = withDefaults(defineProps<{
  /** `form.errors` di Inertia. */
  errors: Record<string, string>;
  /** Nome leggibile per chiave, così l'elenco non parla di `giorni_scadenza`. */
  labels?: Record<string, string>;
  titolo?: string;
}>(), {
  labels: () => ({}),
  titolo: 'Il salvataggio è stato rifiutato',
});

/** Minuscolo e senza accenti, per confrontare un'etichetta con l'inizio di un messaggio. */
const piatto = (t: string) =>
  t.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();

const voci = computed(() =>
  Object.entries(props.errors ?? {})
    .filter(([, messaggio]) => Boolean(messaggio))
    .map(([chiave, messaggio]) => {
      const etichetta = props.labels[chiave] ?? null;

      // Molti messaggi di Laravel cominciano già col nome del campo (`:attribute`), e ripeterlo
      // darebbe «Codice ATECO: codice ateco non può essere più lungo di 20 caratteri».
      // Quando il messaggio si presenta da solo, l'etichetta si tace.
      const siPresentaDaSolo = etichetta !== null && piatto(messaggio).startsWith(piatto(etichetta));

      return { chiave, etichetta: siPresentaDaSolo ? null : etichetta, messaggio };
    }),
);
</script>

<template>
  <div
    v-if="voci.length"
    role="alert"
    aria-live="assertive"
    tabindex="-1"
    class="overflow-hidden rounded-xl border border-rose-200/80 bg-white shadow-sm dark:border-rose-900/40 dark:bg-slate-950"
  >
    <div class="flex">
      <!-- Filo verticale invece del fondo rosa pieno: segnala senza gridare, e resta in famiglia
           con le schede chiare del resto della pagina. -->
      <div class="w-1 shrink-0 bg-rose-500/80 dark:bg-rose-500/60" aria-hidden="true"></div>

      <div class="flex flex-1 items-start gap-3 p-4">
        <span class="mt-px flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-rose-50 dark:bg-rose-950/50">
          <TriangleAlert class="h-4 w-4 text-rose-600 dark:text-rose-400" />
        </span>

        <div class="min-w-0 flex-1">
          <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">
              {{ titolo }}
            </p>
            <span class="rounded-full bg-rose-50 px-2 py-0.5 text-[11px] font-medium text-rose-700 dark:bg-rose-950/50 dark:text-rose-300">
              {{ voci.length === 1 ? '1 campo da correggere' : `${voci.length} campi da correggere` }}
            </span>
          </div>

          <ul class="mt-2.5 space-y-1.5">
            <li
              v-for="voce in voci"
              :key="voce.chiave"
              class="flex gap-2 text-sm leading-snug text-slate-600 dark:text-slate-300"
            >
              <!-- Il pallino si centra sulla **prima riga** del testo, non su un margine fisso:
                   il contenitore è alto quanto l'interlinea (`leading-snug` = 1.375em), così
                   l'allineamento regge anche quando il messaggio va a capo. -->
              <span class="flex h-[1.375em] w-1 shrink-0 items-center" aria-hidden="true">
                <span class="h-1 w-1 rounded-full bg-rose-400 dark:bg-rose-500"></span>
              </span>
              <span class="min-w-0">
                <span v-if="voce.etichetta" class="font-medium text-slate-900 dark:text-slate-100">{{ voce.etichetta }}:</span>
                {{ voce.messaggio }}
              </span>
            </li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</template>
