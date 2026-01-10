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
        'status'             => 'Status',
        'suspended'          => 'Suspended',
        'active'             => 'Active',
        'actions'            => 'Actions',
        'filter'             => 'Filter by name...',
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

    /* ------------------------------------------------------------------
     | Layout
     | ------------------------------------------------------------------ */
    'layout' => [
        'heading_title'       => 'User management',
        'heading_description' => 'Below is a list of registered users, roles, permissions and invitations',
    ],
];