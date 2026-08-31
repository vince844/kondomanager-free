import { h } from 'vue';
import { router } from '@inertiajs/vue3';
import DropdownAction from '@/components/documenti/DataTableRowActions.vue';
import DataTableColumnHeader from '@/components/documenti/DataTableColumnHeader.vue';
import { publishedConstants } from '@/lib/documenti/constants';
import { cellaCategorie } from '@/lib/documenti/categorie-cella';
import { usePermission } from "@/composables/permissions";
import { ShieldCheck } from 'lucide-vue-next';
import { Permission }  from "@/enums/Permission";
import { Badge } from '@/components/ui/badge';
import AnagraficheStack from '@/components/AnagraficheStack.vue';
import { trans } from 'laravel-vue-i18n';
import type { ColumnDef } from '@tanstack/vue-table';
import type { Documento } from '@/types/documenti';
import type { Building } from '@/types/buildings';

const { hasPermission,  generateRoute } = usePermission();

export const columns: ColumnDef<Documento>[] = [
  {
    accessorKey: 'name',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('documenti.table.name') }),

    cell: ({ row, table }) => {

      const documento = row.original;

      const toggleApproval = () => {
      
          router.put(route(generateRoute('documenti.toggle-approval'), { id: documento.id }), {}, {
            preserveScroll: true,
            only: ['stats', 'documenti'],
            onSuccess: () => {
              // Manually update the specific item
              documento.is_approved = !documento.is_approved;
              documento.is_published = documento.is_approved;

              // Update the row data in the table
              table.options.meta?.updateData(row.index, {
                ...documento,
                is_published: documento.is_approved
              });

            }
          });

      };

      const tooltip = documento.is_approved
        ? trans('documenti.table.approved_tooltip')
        : trans('documenti.table.unapproved_tooltip');
    
      const shieldIcon = hasPermission([Permission.APPROVE_ARCHIVE_DOCUMENTS])
        ? h('div', {
            class: 'cursor-pointer',
            title: tooltip,
            onClick: toggleApproval,
          }, [
            h(ShieldCheck, {
              class: documento.is_approved ? 'w-4 h-4 text-green-500' : 'w-4 h-4 text-red-500',
            }),
          ])
        : null;
    
      return h('div', { class: 'flex items-center space-x-2' }, [
        shieldIcon,
        h('a', {
          href: route(generateRoute('documenti.download'), { documento: documento.id }),
          class: 'hover:text-zinc-500 font-bold transition-colors duration-150',
        }, documento.name)
      ]);
    }
  },
  {
    /*
     * La data di caricamento.
     *
     * ⚠️ **La data vera, non «tre mesi fa».** La risorsa manda tutte e due: la forma umana serve
     * alle schede, dove si legge come una frase; in una colonna di tabella non si confronta a
     * occhio e non dice niente a chi cerca il verbale di una certa assemblea.
     */
    accessorKey: 'created_at_data',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('documenti.table.date') }),
    enableSorting: false,
    cell: ({ row }) => h(
      'span',
      { class: 'text-xs text-slate-500 tabular-nums whitespace-nowrap' },
      row.original.created_at_data ?? '—'
    ),
  },
  {
    /*
     * Le categorie del documento — **più d'una**, dalla 1.11.0-beta.10.
     *
     * ⚠️ **Non ordinabile, e non è una mancanza.** «Ordina per categoria» non ha una risposta
     * quando un documento sta in «Bilanci» **e** in «Verbali»: le uniche vie sarebbero inventare
     * una regola che nessuno ha chiesto («la prima in ordine alfabetico») o lasciare che la
     * libreria ordini per qualcosa di arbitrario. È la stessa ragione per cui la colonna dei
     * condomìni qui sotto non si ordina, e l'ordinamento e' stato tolto anche dal server.
     */
    id: 'categorie',
    enableSorting: false,
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('documenti.table.category') }),

    cell: ({ row }) => {
      /*
       * ⚠️ **Le decisioni stanno in `lib/documenti/categorie-cella.ts`, non qui.**
       *
       * Quante etichette mostrare, in che ordine, e cosa dire quando non ce n'è nessuna sono tre
       * decisioni, non una formattazione: dentro una funzione `cell` non si potevano provare senza
       * montare la tabella intera. È la stessa estrazione già fatta per la reidratazione dei filtri
       * (beta.62) e per la conferma di eliminazione (beta.9), e per lo stesso motivo — un difetto
       * che vive solo nel template passa sotto una suite verde.
       *
       * Qui resta il **come**: i `Badge`, le classi, il `title` che si legge passandoci sopra
       * perché l'elemento non è disabilitato.
       */
      const cella = cellaCategorie(row.original.categorie, 2);

      if (cella.vuoto) {
        // Non un vuoto, ma **uno stato**: i documenti caricati su un fornitore o un'unità non
        // hanno categoria di proposito, e chi li vede qui deve capire che è così e non un errore.
        return h('span', { class: 'text-xs italic text-slate-400' }, trans('documenti.table.no_category'));
      }

      return h('div', { class: 'flex items-center gap-1 whitespace-nowrap' }, [
        ...cella.visibili.map((categoria) =>
          h(Badge, { variant: 'outline', class: 'rounded-md', key: categoria.id }, () => categoria.name)
        ),
        cella.restanti > 0
          ? h(
              Badge,
              {
                variant: 'secondary',
                class: 'rounded-md cursor-default',
                title: cella.titolo,
              },
              () => `+${cella.restanti}`
            )
          : null,
      ]);
    },
  },
  {
    accessorKey: 'condomini',
      /**
       * ⚠️ **Non ordinabile, e non è una mancanza.** La cella contiene un **elenco** di soggetti,
       * non un valore: un'unità con «Esposito + Russo» non ha una posizione in un ordinamento
       * alfabetico finché qualcuno non decide *quale dei due* faccia da chiave.
       *
       * Finché quella decisione non è presa, l'intestazione ordinava per **quante** persone ci
       * sono nella cella — che è ciò che la libreria fa quando il valore è un array e nessuno
       * dichiara un criterio. Nessuno che clicca lì si aspetta quello: era un reperto aperto
       * della revisione della beta.52.
       *
       * Se un giorno serve, si sceglie la chiave (per esempio «il primo proprietario in ordine
       * alfabetico») e si ordina sul server. Una decisione presa, non un default ereditato.
       */
      enableSorting: false,
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('documenti.table.buildings') }),
  
    cell: ({ row }) => {
      /*
       * ⚠️ **Lo stesso cassettino delle anagrafiche, non pallini disegnati a mano.**
       *
       * Qui c'erano trenta righe di cerchietti sovrapposti con il nome dello stabile solo in un
       * `title`: da lì non si legge l'indirizzo e non ci si va. `AnagraficheStack` apre il pannello
       * con l'elenco completo, e ogni riga porta alla scheda del condominio.
       *
       * Il componente è nato per le persone, ma quello che gli serve — un nome, un indirizzo, un
       * indirizzo web — ce l'ha anche uno stabile. Ruolo e quota sono facoltativi e non si passano.
       */
      const condomini = (row.original.condomini?.full ?? []).map((stabile: any) => ({
        id: stabile.id,
        nome: stabile.nome,
        indirizzo: stabile.indirizzo,
        // ⚠️ **`route()` diretta, non `generateRoute()`.** Le rotte dei condomìni **non** hanno il
        // prefisso `admin.` — sono `condomini.show` e basta — mentre `generateRoute` lo aggiunge in
        // base al ruolo. Con il prefisso, Ziggy lancia «route is not in the route list» e la cella
        // resta **vuota**: non un errore a schermo, una colonna che sparisce.
        url: route('condomini.show', { condominio: stabile.id }),
      }));

      return h(AnagraficheStack, {
        anagrafiche: condomini,
        title: trans('documenti.table.buildings'),
        description: trans('documenti.table.buildings_desc'),
      });
    },
  },
  {
    accessorKey: 'anagrafiche',
      /**
       * ⚠️ **Non ordinabile, e non è una mancanza.** La cella contiene un **elenco** di soggetti,
       * non un valore: un'unità con «Esposito + Russo» non ha una posizione in un ordinamento
       * alfabetico finché qualcuno non decide *quale dei due* faccia da chiave.
       *
       * Finché quella decisione non è presa, l'intestazione ordinava per **quante** persone ci
       * sono nella cella — che è ciò che la libreria fa quando il valore è un array e nessuno
       * dichiara un criterio. Nessuno che clicca lì si aspetta quello: era un reperto aperto
       * della revisione della beta.52.
       *
       * Se un giorno serve, si sceglie la chiave (per esempio «il primo proprietario in ordine
       * alfabetico») e si ordina sul server. Una decisione presa, non un default ereditato.
       */
      enableSorting: false,
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('documenti.table.residents') }),
  
    cell: ({ row }) => {
      /*
       * ⚠️ **`AnagraficheStack`, non trenta righe di avatar disegnati a mano.**
       *
       * Qui c'erano gli stessi cerchietti sovrapposti del componente condiviso, riscritti a mano
       * con i `title` come unico modo di leggere i nomi: da un `title` non si copia un indirizzo,
       * non si vede chi sono davvero, e soprattutto non ci si va. Il componente apre invece il
       * pannello con l'elenco completo, e dalla 1.11.0-beta.9 ogni riga porta alla **scheda della
       * persona** — che dalla stessa beta esiste, dove prima c'era una pagina bianca.
       *
       * Ed è lo stesso pannello dell'elenco condomini, delle unità e delle categorie di fornitore:
       * chi lo ha imparato una volta lo sa usare ovunque.
       */
      const anagrafiche = (row.original.anagrafiche ?? []).map((persona: any) => ({
        ...persona,
        url: route(generateRoute('anagrafiche.show'), { anagrafica: persona.id }),
      }));

      return h(AnagraficheStack, {
        anagrafiche,
        title: trans('documenti.table.residents'),
        description: trans('documenti.table.residents_desc'),
      });
    },
  },
  {
    accessorKey: 'is_published',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('documenti.table.status') }),
    /*
     * ⚠️ **Non ordinabile dalla 1.11.0-beta.10, e il filtro è il motivo.**
     *
     * Ordinare per due soli valori mette in cima tutti i pubblici o tutti i privati, che è quello
     * che il **filtro** nella barra fa meglio e senza far sparire il resto dalla vista. Il server
     * ha già tolto `is_published` da `DocumentoIndexRequest::colonneOrdinabili()`: lasciare qui la
     * freccia avrebbe prodotto un'intestazione che si clicca e non fa niente — la richiesta parte,
     * la validazione la rifiuta, la tabella torna identica.
     */
    enableSorting: false,
    cell: ({ row }) => {

      const value = Boolean(row.getValue('is_published'));
      const stato = publishedConstants.find(p => p.value === value);
  
      if (!stato) return h('span', '–');
  
      return h('div', { class: 'flex items-center gap-2' }, [
        h(stato.icon, { class: `h-4 w-4 ${stato.colorClass}` }),
        h('span', trans(stato.label))
      ]);
    },
    filterFn: (row, id, value) =>
      value.includes(Boolean(row.getValue(id))),
  },
  {
    id: 'actions',
    enableHiding: false,
    cell: ({ row }) => {
      const documento = row.original
      return h('div', { class: 'relative' }, h(DropdownAction, { documento }))
    },
  },
]