import { h } from 'vue'

export const roleClasses: Record<string, string> = {
  amministratore: "bg-red-100 text-red-800 hover:bg-red-200",
  collaboratore: "bg-purple-100 text-purple-800 hover:bg-purple-200",
  fornitore: "bg-green-100 text-green-800 hover:bg-green-200",
  utente: "bg-blue-100 text-blue-800 hover:bg-blue-200",
}

export const defaultRoleClass = "inline-flex items-center rounded-md bg-gray-50 px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-gray-500/10 ring-inset"

export const statusClasses = {
  suspended: "inline-flex items-center rounded-md bg-red-50 px-2 py-1 text-xs font-medium text-red-700 ring-1 ring-red-600/10 ring-inset",
  active: "inline-flex items-center rounded-md bg-green-50 px-2 py-1 text-xs font-medium text-green-700 ring-1 ring-green-600/20 ring-inset",
  none: "inline-flex items-center rounded-md bg-gray-50 px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-gray-500/10 ring-inset"
}

export function useBadges() {
  const getRoleClass = (roleName: string): string => {
    return roleClasses[roleName.toLowerCase()] || defaultRoleClass
  }

  const createRoleBadges = (roleNames: string[]) => {
    return roleNames.map(roleName => ({
      label: roleName,
      class: `px-2 py-1 rounded text-xs font-medium capitalize ${getRoleClass(roleName)}`
    }))
  }

  const createUserCountBadge = (count: number) => ({
    label: `${count} utenti`,
    class: 'inline-flex items-center rounded-md bg-purple-50 px-2 py-1 text-xs font-medium text-purple-700 ring-1 ring-purple-600/20 ring-inset'
  })

  return {
    getRoleClass,
    createRoleBadges,
    createUserCountBadge
  }
}