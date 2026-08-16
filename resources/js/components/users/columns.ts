import { h } from 'vue'
import { router } from '@inertiajs/vue3'
import DropdownAction from '@/components/users/DataTableRowActions.vue'
import DataTableColumnHeader from '@/components/users/DataTableColumnHeader.vue'
import PermissionsDialog from '@/components/PermissionsDialog.vue'
import { ShieldCheck, UserX, ShieldX } from 'lucide-vue-next'
import { roleClasses, defaultRoleClass, statusClasses } from '@/composables/useBadges'
import { useBadges } from '@/composables/useBadges'
import { usePermissionsList } from '@/composables/usePermissionsList'
import { trans } from 'laravel-vue-i18n'
import AnagraficheStack from '@/components/AnagraficheStack.vue'
import { Badge } from '@/components/ui/badge'
import type { ColumnDef } from '@tanstack/vue-table'
import type { User } from '@/types/users'

const ROLE_I18N_KEY_BY_NAME: Record<string, string> = {
  amministratore: 'admin',
  collaboratore: 'collaborator',
  fornitore: 'supplier',
  utente: 'user',
}

function getTranslatedRoleLabel(roleValue: unknown): string {
  const rawRoleName =
    typeof roleValue === 'string'
      ? roleValue
      : (roleValue as { name?: string } | null | undefined)?.name ?? ''

  const normalizedRoleName = rawRoleName.toLowerCase().trim()
  const keySuffix =
    ROLE_I18N_KEY_BY_NAME[normalizedRoleName] ??
    normalizedRoleName.replace(/\s+/g, '_')

  const key = `users.roles.${keySuffix}`
  const translated = trans(key)

  return translated === key ? rawRoleName : translated
}

// Dialog per visualizzare i permessi dell'utente
const UserPermissionsDialog = {
  props: ['user'],
  setup(props: { user: User }) {
    const user = props.user
    const { createRoleBadges } = useBadges()
    const { extractRoleNames } = usePermissionsList()
    
    const permissions = user.permissions || []
    const roles = user.roles || []
    const roleNames = extractRoleNames(roles)
    const badges = createRoleBadges(roleNames)
    
    return () => h(PermissionsDialog, {
      permissions,
      title: 'users.label.permissions_assigned',
      subtitle: 'users.label.permissions_assigned_to_user',
      user: user.name,
      badges
    })
  }
}

export const columns: ColumnDef<User>[] = [
  {
    accessorKey: 'name',
    header: ({ column }) =>
      h(DataTableColumnHeader, { column, title: trans('users.table.name') }),
    cell: ({ row, table }) => {
      const user = row.original

      const toggleVerification = () => {
        router.put(route('utenti.toggle-verification', { user: user.id }), {}, {
          preserveScroll: true,
          only: ['users', 'flash'],
          onSuccess: () => {
            user.email_verified_at = user.email_verified_at ? null : new Date().toISOString()
            table.options.meta?.updateData(row.index, user)
          },
        })
      }

      const tooltip = user.email_verified_at
        ? trans('users.table.verified_tooltip')
        : trans('users.table.unverified_tooltip')

      return h('div', { class: 'flex items-center space-x-3' }, [
        h('div', {
          class: 'cursor-pointer shrink-0 mt-0.5 self-start',
          title: tooltip,
          onClick: toggleVerification,
        }, [
          h(ShieldCheck, {
            class: user.email_verified_at ? 'w-4 h-4 text-emerald-500' : 'w-4 h-4 text-slate-300 dark:text-slate-600',
          })
        ]),
        h('div', { class: 'flex flex-col' }, [
          h('span', { class: 'font-bold text-slate-900 dark:text-slate-100 leading-tight' }, user.name),
          h('span', { class: 'text-xs text-slate-500 dark:text-slate-400 lowercase' }, user.email),
        ]),
      ])
    },
  },
  {
    accessorKey: 'roles',
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
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('users.table.role')  }), 
    cell: ({ getValue }) => {
      const rawRoles = getValue()
      const roles = Array.isArray(rawRoles) ? rawRoles : []
      return h(
        "div",
        { class: "flex gap-2" }, 
        roles.map((role) => {
          const rawRoleName =
            typeof role === 'string'
              ? role
              : (role as { name?: string } | null | undefined)?.name ?? ''

          return (
          h("span", { 
            key: rawRoleName,
            class: `px-2 py-1 rounded text-xs font-medium capitalize ${roleClasses[rawRoleName.toLowerCase()] || defaultRoleClass}` 
          }, getTranslatedRoleLabel(role))
        )})
      )
    }
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
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('users.table.permissions')  }), 
    cell: ({ getValue, row }) => {
      const permissions = getValue() as any[]
      const user = row.original
      const total = permissions?.length || 0
      
      if (total === 0) {
        return h(Badge, { 
          variant: 'outline',
          class: 'text-slate-400 rounded dark:text-slate-500 border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-900/50 shadow-none font-normal flex items-center gap-1.5 w-fit'
        }, () => [
          h(ShieldX, { class: 'w-3 h-3' }),
          trans('users.empty_state.no_assigned_permissions')
        ])
      }
      
      return h(UserPermissionsDialog, { user })
    }
  },
  {
    accessorKey: 'anagrafica',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('users.table.anagrafica') }),
    cell: ({ row }) => {
      const anagrafica = row.original.anagrafica;
      if (!anagrafica) {
        return h(Badge, { 
          variant: 'outline',
          class: 'text-slate-400 rounded dark:text-slate-500 border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-900/50 shadow-none font-normal flex items-center gap-1.5 w-fit'
        }, () => [
          h(UserX, { class: 'w-3 h-3' }),
          trans('users.table.no_anagrafica')
        ])
      }
      return h(AnagraficheStack, { 
        anagrafiche: [anagrafica],
        title: trans('users.label.resident'),
        description: trans('users.tooltip.resident_drawer_desc')
      });
    },
  },
  {
    accessorKey: 'suspended_at',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: trans('users.table.status') }), 
    cell: ({ getValue }) => {

      const status = getValue() as string | null

      const label = status 
        ? trans('users.table.suspended') 
        : trans('users.table.active')

      const badgeClass = status ? statusClasses.suspended : statusClasses.active

      return h('div', { class: 'flex gap-2' }, [
        h('span', { class: badgeClass }, label)
      ]) 
      
    }
  },
  {
    id: 'actions',
    enableHiding: false,
    cell: ({ row }) => {
      const user = row.original
      return h('div', { class: 'relative' }, h(DropdownAction, {
        user,
      }))
    },
  }
]
