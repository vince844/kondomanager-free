<?php

return [
    /* ------------------------------------------------------------------
     | Backend notifications
     | ------------------------------------------------------------------ */
    'success_create_user'        => 'The new user has been created successfully.',
    'error_create_user'          => 'An error occurred while creating the new user.',
    'success_update_user'        => 'The user has been updated successfully.',
    'error_update_user'          => 'An error occurred while updating the user.',
    'success_delete_user'        => 'The user has been deleted successfully.',
    'error_delete_user'          => 'An error occurred while deleting the user.',
    'success_send_user_invite'   => 'The invitation has been sent successfully.',
    'error_send_user_invite'     => 'An error occurred while sending the invitation to the user.',
    'success_delete_user_invite' => 'The invitation has been deleted successfully.',
    'error_delete_user_invite'   => 'An error occurred while deleting the invitation.',
    'success_suspend_user'       => 'The user has been suspended successfully.',
    'error_suspend_user'         => 'An error occurred while attempting to suspend the user.',
    'success_unsuspend_user'     => 'The user has been reactivated successfully.',
    'error_unsuspend_user'       => 'An error occurred while attempting to reactivate the user.',
    'success_verify_user'        => 'The user has been verified successfully.',
    'success_revoke_verify_user' => 'The user\'s verification has been revoked.',
    'error_verify_user'          => 'An error occurred while verifying the user.',
    'error_email_not_sent'       => 'The user has been created successfully, but it was not possible to send the invitation email.',

    /* ------------------------------------------------------------------
     | Front‑end strings (headings, titles, descriptions)
     | ------------------------------------------------------------------ */
    'header' => [
        'list_users_head'                    => 'Users list',
        'list_roles_head'                    => 'Roles Management',
        'list_roles_description'             => 'Define and manage access levels for each type of user.',
        'list_permissions_head'              => 'Permissions List',
        'list_permissions_description'       => 'View all permissions available within the application.',
        'list_invites_head'                  => 'Invites List',
        'list_invites_description'           => 'Monitor invites sent to new collaborators and their status.',
        'edit_user_head'                     => 'Edit user',
        'new_user_head'                      => 'Create new user',
        'new_user_title'                     => 'Create new user',
        'new_user_description'               => 'Below you can create a new user. You can assign a role, a resident record and specific permissions for this user',
        'permissions_title'                  => 'Permissions inherited from the role',
        'permissions_description_line_1'     => 'These permissions are inherited through the role',
        'permissions_description_line_2'     => 'and will be automatically assigned to the user',
        'additional_permissions_title'       => 'Additional permissions',
        'additional_permissions_description' => 'Permissions assigned directly to the user, in addition to those from the role',
    ],

    /* ------------------------------------------------------------------
     | Table
     | ------------------------------------------------------------------ */
    'table' => [
        'name'               => 'Full name',
        'email'              => 'Email address',
        'role'               => 'Role',
        'permissions'        => 'Permissions',
        'anagrafica'         => 'Resident record',
        'no_anagrafica'      => 'No resident record',
        'status'             => 'Status',
        'last_login'         => 'Last login',
        'never_logged_in'    => 'never',
        'suspended'          => 'Suspended',
        'active'             => 'Active',
        'actions'            => 'Actions',
        'filter'             => 'Filter by name...',
        'selected'           => 'selected',
        'loading'            => 'Loading...',
        'no_results'         => 'No results.',
        'clear_all_filters'  => 'Clear filters',
        'no_permissions'     => 'No permissions',
        'verified_tooltip'   => 'User verified - click to revoke verification',
        'unverified_tooltip' => 'User not verified - click to verify',
    ],

    /* ------------------------------------------------------------------
     | Labels for fields
     | ------------------------------------------------------------------ */
    'label' => [
        'name'                         => 'Full name',
        'email'                        => 'Email address',
        'role'                         => 'User role',
        'resident'                     => 'Associated resident record',
        'permissions'                  => 'Additional permissions',
        'permission_notice'            => 'Permissions from the selected role are inherited automatically',
        'permissions_assigned'         => 'User permissions',
        'permissions_assigned_to_user' => 'Permissions assigned to :name',
        'permissions_count'            => ':count permissions',
    ],

    /* ------------------------------------------------------------------
     | Placeholders
     | ------------------------------------------------------------------ */
    'placeholder' => [
        'name'        => 'Enter full name',
        'email'       => 'Enter email address',
        'role'        => 'Select user role',
        'resident'    => 'Select resident record',
        'permissions' => 'Select additional permissions',
    ],

    /* ------------------------------------------------------------------
     | Actions
     | ------------------------------------------------------------------ */
    'actions' => [
        'new_user'      => 'Create user',
        'edit_user'     => 'Edit',
        'delete_user'   => 'Delete',
        'suspend_user'  => 'Suspend',
        'activate_user' => 'Activate',
        'invite_user'   => 'Reinvite',
    ],

    /* ------------------------------------------------------------------
     | Tooltips / Hover cards
     | ------------------------------------------------------------------ */
    'tooltip' => [
        'role_line_1' => 'Select the role to assign to the user. Choose from default roles or one you created.',
        'role_line_2' => 'Permissions associated with the role will be inherited automatically.',
        'resident'    => 'Select the resident record to associate with the user. The associated resident record will be able to access the system with the credentials of the created user and view their data and related information.',
        'permissions' => 'Select specific permissions to assign to the user in addition to those inherited from the selected role.',
        'resident_drawer_desc' => 'Details of the resident record associated with this user.',
    ],

    /* ------------------------------------------------------------------
     | Dialogs
     | ------------------------------------------------------------------ */
    'dialogs' => [
        'no_users_created'         => 'No users created yet',
        'delete_user_title'        => 'Are you sure you want to delete this user?',
        'delete_user_description'  => 'This action is irreversible. It will delete the user and all associated data.',
        'invite_user_title'        => 'Are you sure you want to reinvite this user?',
        'invite_user_description'  => 'The user will receive an email with a new link to create a new password.',
    ],

    /* ------------------------------------------------------------------
     | Empty states
     | ------------------------------------------------------------------ */
    'empty_state' => [
        'inherited_permissions'   => 'No permissions inherited from the role',
        'additional_permissions'  => 'No additional permissions assigned',
        'no_assigned_permissions' => 'No permissions assigned',
    ],

    /* ------------------------------------------------------------------
     | Badges (labels/statuses)
     | ------------------------------------------------------------------ */
    'badge' => [
        'previously_direct' => 'previously assigned',
    ],

    'roles' => [
        'admin' => 'Administrator',
        'collaborator' => 'Collaborator',
        'supplier' => 'Supplier',
        'user' => 'User',
    ],

    /* ------------------------------------------------------------------
     | Layout
     | ------------------------------------------------------------------ */
    'layout' => [
        'heading_title'       => 'User Management',
        'heading_description' => 'Below is a list of registered users, roles, permissions, and invites',
    ],

    /* ------------------------------------------------------------------
     | Guides
     | ------------------------------------------------------------------ */
    'guides' => [
        'button'        => 'Guide',
        'users_title' => 'Users and Access',
        'users_desc'  => 'Manage who has access to the platform and their related records.',
        'roles_title' => 'Roles and Permissions',
        'roles_desc'  => 'Define authorization levels to protect sensitive data.',
        'invites_title' => 'Invites',
        'invites_desc'  => 'Send and track invitations for new collaborators.',
    ],
];
