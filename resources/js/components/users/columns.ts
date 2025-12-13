import { h } from 'vue'
import { router } from '@inertiajs/vue3'
import DropdownAction from '@/components/users/DataTableRowActions.vue'
import DataTableColumnHeader from '@/components/users/DataTableColumnHeader.vue'
import PermissionsDialog from '@/components/PermissionsDialog.vue'
import { ShieldCheck } from 'lucide-vue-next'
import { roleClasses, defaultRoleClass, statusClasses } from '@/composables/useBadges'
import { useBadges } from '@/composables/useBadges'
import { usePermissionsList } from '@/composables/usePermissionsList'
import type { ColumnDef } from '@tanstack/vue-table'
import type { User } from '@/types/users'

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
      title: 'Permessi utente',
      subtitle: `Permessi assegnati a ${user.name}`,
      badges
    })
  }
}

export const columns: ColumnDef<User>[] = [
  {
    accessorKey: 'name',
    header: ({ column }) =>
      h(DataTableColumnHeader, { column, title: 'Nome e cognome' }),
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
        ? 'Utente verificato - clicca per revocare verifica'
        : 'Utente non verificato - clicca per verificare'

      return h('div', { class: 'flex items-center space-x-2' }, [
        h('div', {
          class: 'cursor-pointer',
          title: tooltip,
          onClick: toggleVerification,
        }, [
          h(ShieldCheck, {
            class: user.email_verified_at ? 'w-4 h-4 text-green-500' : 'w-4 h-4 text-red-500',
          })
        ]),
        h('span', { class: 'font-bold' }, user.name),
      ])
    },
  },
  {
    accessorKey: 'email',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Indirizzo email' }), 
    cell: ({ row }) => h('div', { class: 'lowercase' }, row.getValue('email')),
  },
  {
    accessorKey: 'roles',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Ruolo' }), 
    cell: ({ getValue }) => {
      const roles = getValue() as string[]
      return h(
        "div",
        { class: "flex gap-2" }, 
        roles.map((role) =>
          h("span", { 
            key: role,
            class: `px-2 py-1 rounded text-xs font-medium capitalize ${roleClasses[role.toLowerCase()] || defaultRoleClass}` 
          }, role)
        )
      )
    }
  },
  {
    accessorKey: 'permissions',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Permessi' }), 
    cell: ({ getValue, row }) => {
      const permissions = getValue() as any[]
      const user = row.original
      const total = permissions?.length || 0
      
      if (total === 0) {
        return h('span', { 
          class: statusClasses.none
        }, 'Nessuno')
      }
      
      return h(UserPermissionsDialog, { user })
    }
  },
  {
    accessorKey: 'suspended_at',
    header: ({ column }) => h(DataTableColumnHeader, { column, title: 'Stato' }), 
    cell: ({ getValue }) => {
      const status = getValue() as string | null
      const label = status ? 'Sospeso' : 'Attivo'
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