// composables/permissions.ts
import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";
import type { NavItem, User as BaseUser } from "@/types";
import type { Role } from "@/types/roles";  // Assuming you still need Role type if required elsewhere
import type { Permission } from "@/types/permissions";  // Assuming you still need Permission type if required elsewhere

// Extend the User interface to allow optional roles and permissions arrays
type ExtendedUser = BaseUser & {
  roles?: string[];  // Change to string array if roles are strings, not objects
  permissions?: string[];  // Change to string array if permissions are strings, not objects
};

// Type for items that can be checked for access, like NavItems or raw role/permission arrays
type AccessCheckItem = NavItem | { roles?: string[]; permissions?: string[] } | string[];

export function usePermission() {
  const page = usePage();
  // Extract auth data from page props
  const auth = computed<{ user: ExtendedUser }>(() => page.props.auth as { user: ExtendedUser });

  // Check if user has one of the specified roles
  const hasRole = (roles: string[]) => {
    const userRoles = auth.value?.user?.roles ?? [];
    return roles.some((role) => userRoles.includes(role));  // Check if any of the specified roles are present
  };

  // Check if user has one of the specified permissions
  const hasPermission = (permissions: string[]) => {
    const userPermissions = auth.value?.user?.permissions ?? [];
    return permissions.some((permission) => userPermissions.includes(permission));  // Check if any of the specified permissions are present
  };

  // Determine if access should be granted based on role or permission checks
  const canAccess = (item: AccessCheckItem): boolean => {
    if (Array.isArray(item)) {
      return hasPermission(item);
    }
  
    const roles = item.roles ?? [];  // Use "roles" instead of "role"
    const permissions = item.permissions ?? [];
  
    const hasDefinedRoles = roles.length > 0;
    const hasDefinedPermissions = permissions.length > 0;
  
    const roleCheck = hasDefinedRoles ? hasRole(roles) : false;
    const permissionCheck = hasDefinedPermissions ? hasPermission(permissions) : false;
  
    if (hasDefinedRoles && hasDefinedPermissions) {
      return roleCheck || permissionCheck;
    }
  
    if (hasDefinedRoles) return roleCheck;
    if (hasDefinedPermissions) return permissionCheck;
  
    return true;
  };
  

  return { hasRole, hasPermission, canAccess };
}
