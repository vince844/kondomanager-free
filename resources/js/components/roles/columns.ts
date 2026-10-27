import { h } from 'vue'
import DropdownAction from '@/components/roles/DataTableRowActions.vue'
import DataTableColumnHeader from '@/components/roles/DataTableColumnHeader.vue'
import PermissionsDialog from '@/components/PermissionsDialog.vue'
import { statusClasses } from '@/composables/useBadges'
import { useBadges } from '@/composables/useBadges'
import type { ColumnDef } from '@tanstack/vue-table'
import type { Role } from '@/types/roles'

/**
 * Il pulsante con il numero dei permessi e la finestra che li elenca, per il ruolo della riga.
 *
 * ⚠️ **Il ruolo si legge dalle props a ogni disegno, non una volta sola nel `setup`.** Quando l'elenco cambia
 * sotto la tabella (un ruolo eliminato o creato) una cella può ricevere un altro ruolo, e una copia fatta
 * all'inizio mostrava ancora i permessi e gli utenti di quello di prima (decisione 135.1). Le righe hanno anche
 * l'id per chiave (`DataTable.vue`), ma la cella non deve dipendere da quello.
 */
const RolePermissionsDialog = {
  props: ['role'],
  setup(props: { role: Role }) {
    const { createUserCountBadge } = useBadges()

    return () => h(PermissionsDialog, {
      permissions: props.role.permissions || [],
      title: 'Permessi del ruolo',
      entityName: props.role.name,
      entityDescription: props.role.description,
      badges: props.role.users_count !== undefined ? [createUserCountBadge(props.role.users_count)] : [],
    })
  }
}

export const columns: ColumnDef<Role>[] = [
  {
    accessorKey: 'name',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Ruolo' }), 
    cell: ({ row }) => h('div', { class: 'capitalize font-bold' }, row.getValue('name')),
  },
  {
    accessorKey: 'description',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Descrizione' }), 
    cell: ({ row }) => h('div', row.getValue('description')),
  },
  {
    accessorKey: 'permissions',
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
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Permessi' }), 
    cell: ({ getValue, row }) => {
      const permissions = getValue() as any[]
      const role = row.original
      const total = permissions?.length || 0
      
      if (total === 0) {
        return h('span', { 
          class: statusClasses.none
        }, 'Nessuno')
      }
      
      return h(RolePermissionsDialog, { role })
    }
  },
  {
    id: 'actions',
    enableHiding: false,
    cell: ({ row }) => {
      const role = row.original
      return h('div', { class: 'relative' }, h(DropdownAction, {
        role,
      }))
    },
  }
]