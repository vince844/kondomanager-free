export function usePermissionsList() {
  const extractPermissionData = (permission: any) => {
    const name = typeof permission === 'string' ? permission : permission.name
    const description = typeof permission === 'object' ? permission.description : null
    
    return { name, description }
  }

  const extractRoleNames = (roles: any[]): string[] => {
    return roles.map(r => typeof r === 'string' ? r : r.name)
  }

  return {
    extractPermissionData,
    extractRoleNames
  }
}