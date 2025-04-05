
import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";

export function usePermission() {
    const page = usePage();
    const auth = computed(() => page.props.auth);

    // Check if the user has at least one of the roles in the array
    const hasRole = (roles) => {
        const userRoles = auth.value?.user?.roles ?? [];
        return roles.some(role => userRoles.includes(role));
    };

    // Check if the user has a specific permission
    const hasPermission = (permissions) => {
        const userPermissions = auth.value?.user?.permissions ?? [];
        return permissions.some(permission => userPermissions.includes(permission));
    };

    return { hasRole, hasPermission };
}
