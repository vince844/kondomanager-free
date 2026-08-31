<script setup lang="ts">
/**
 * Il «+» accanto alla tendina «Categoria fornitore», nelle due schede del fornitore.
 *
 * ## Perché sta qui e non solo nella pagina di gestione
 *
 * Il momento in cui ci si accorge che una categoria manca **non è mentre si gestiscono le
 * categorie**: è mentre si sta registrando un fornitore, con mezza scheda già compilata. Se l'unico
 * modo fosse la pagina di gestione, la scelta sarebbe fra abbandonare quello che si è scritto e
 * mettere «Altro» per non perderlo — e «Altro» è quello che poi resta lì per sempre.
 *
 * ## ⚠️ Il ricaricamento è parziale, e non è un dettaglio
 *
 * `only: ['categorie', 'flash', 'errors']` con `preserveState`: la richiesta rinfresca **solo** la
 * tendina, e il resto della scheda — i campi già compilati — non viene toccato. Un ricaricamento
 * intero qui perderebbe esattamente il lavoro che questo pulsante esiste per non far perdere.
 *
 * Per lo stesso motivo la validazione viaggia in un `errorBag` suo: senza, un nome duplicato
 * scriverebbe `errors.name`, e la scheda del fornitore ha un campo `name` tutto suo che si
 * colorerebbe di rosso per un errore che non è il suo.
 */
import { nextTick, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
  Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger,
} from '@/components/ui/dialog';
import { Plus } from 'lucide-vue-next';
import { trans } from 'laravel-vue-i18n';

const emit = defineEmits<{ (e: 'creata', nome: string): void }>();

const aperto = ref(false);

/**
 * ⚠️ **Il fuoco sul campo va messo a mano.**
 *
 * `autofocus` sull'`Input` non fa niente qui: all'apertura il dialogo porta il fuoco sul proprio
 * contenuto e sovrascrive quello del campo. Misurato a video il 30/08/2026 — si apriva il dialogo,
 * si scriveva, e non compariva niente finché non si cliccava dentro la casella. Per un pulsante che
 * esiste per far perdere meno tempo, è il difetto che lo annulla.
 *
 * Per la stessa ragione l'invio si aggancia a mano al tasto Invio (`@keydown.enter`): dentro il
 * dialogo il `submit` naturale del modulo non parte, e si scriveva il nome, si premeva Invio e non
 * succedeva niente. Misurato a video insieme al fuoco.
 */
const campoNome = ref<HTMLInputElement | { $el?: HTMLElement } | null>(null);

function metteFuocoSulNome(evento: Event) {
    evento.preventDefault();

    nextTick(() => {
        const elemento = campoNome.value as any;
        const input: HTMLElement | null = elemento?.$el ?? elemento ?? null;

        (input?.tagName === 'INPUT' ? input : input?.querySelector?.('input'))?.focus();
    });
}

const form = useForm({
  name: '',
  description: '',
});

// Riaprendo dopo un errore non si deve ritrovare il rosso della volta prima.
watch(aperto, (adesso) => {
  if (adesso) {
    form.clearErrors();
  }
});

function salva() {
  // ⚠️ **Il nome si ripulisce qui, e la stessa stringa ripulita va sia al server sia all'evento.**
  //
  // `TrimStrings` toglie gli spazi lato server: scrivendo «Vetraio » a database finisce «Vetraio».
  // Emettendo il nome grezzo, chi ci ascolta cerca «Vetraio » fra le categorie e **non lo trova**:
  // la categoria viene creata e non resta selezionata, senza nessun errore. È il difetto che rende
  // inutile il pulsante proprio nel caso in cui uno ha battuto uno spazio di troppo.
  const nome = form.name.trim();

  form
    .transform((dati) => ({
      ...dati,
      name: nome,
      description: dati.description.trim() || null,
    }))
    .post(route('admin.categorie-fornitore.store'), {
      preserveScroll: true,
      preserveState: true,

      // ⚠️ **Niente `flash`.** Chiedendolo, il messaggio di esito della *categoria* finiva nel
      // canale che la scheda del fornitore usa per il proprio: `FornitoriEdit` ha un watcher sul
      // flash che **risale in cima alla pagina** e disegna un `Alert` verde — lo stesso riquadro
      // con cui annuncia «Fornitore aggiornato», mentre il fornitore **non è stato salvato**. Chi
      // legge il verde e se ne va perde le modifiche. Il riscontro della creazione lo danno già il
      // dialogo che si chiude e la categoria che resta selezionata.
      only: ['categorie', 'errors'],
      errorBag: 'categoriaFornitore',
      onSuccess: () => {
        // Il nome è la chiave: è unico a database, e a questo punto la tendina è già stata
        // rinfrescata dal ricaricamento parziale, quindi chi ci ascolta ci trova la riga nuova.
        emit('creata', nome);
        form.reset();
        aperto.value = false;
      },
    });
}
</script>

<template>
  <Dialog v-model:open="aperto">
    <DialogTrigger as-child>
      <Button
        type="button"
        variant="outline"
        size="icon"
        class="shrink-0 bg-white dark:bg-slate-950"
        :title="trans('fornitori.categorie.quick_add')"
        :aria-label="trans('fornitori.categorie.quick_add')"
      >
        <Plus class="w-4 h-4" />
      </Button>
    </DialogTrigger>

    <DialogContent class="sm:max-w-md" @open-auto-focus="metteFuocoSulNome">
      <DialogHeader>
        <DialogTitle>{{ trans('fornitori.categorie.quick_add_title') }}</DialogTitle>
        <DialogDescription>{{ trans('fornitori.categorie.quick_add_description') }}</DialogDescription>
      </DialogHeader>

      <form class="space-y-4" @submit.prevent="salva">
        <div>
          <Label for="nuova-categoria-nome">{{ trans('fornitori.categorie.name') }}</Label>
          <Input
            id="nuova-categoria-nome"
            v-model="form.name"
            :class="{ 'border-red-500': form.errors.name }"
            :placeholder="trans('fornitori.categorie.name_placeholder')"
            ref="campoNome"
            class="mt-1 w-full"
            @keydown.enter.prevent="salva"
          />
          <p v-if="form.errors.name" class="mt-1 text-sm text-red-500">{{ form.errors.name }}</p>
        </div>

        <div>
          <Label for="nuova-categoria-descrizione">{{ trans('fornitori.categorie.description_label') }}</Label>
          <Textarea
            id="nuova-categoria-descrizione"
            v-model="form.description"
            :class="{ 'border-red-500': form.errors.description }"
            :placeholder="trans('fornitori.categorie.description_placeholder')"
            class="mt-1 w-full"
            rows="3"
          />
          <p v-if="form.errors.description" class="mt-1 text-sm text-red-500">{{ form.errors.description }}</p>
          <p v-else class="mt-1 text-xs text-slate-500">{{ trans('fornitori.categorie.description_hint') }}</p>
        </div>

        <DialogFooter>
          <Button type="button" variant="outline" @click="aperto = false">
            {{ trans('fornitori.categorie.cancel') }}
          </Button>
          <Button type="submit" :disabled="form.processing || !form.name.trim()">
            {{ trans('fornitori.categorie.save') }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
