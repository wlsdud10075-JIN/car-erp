<?php

// i18n — User management (admin/users). permission=nav.permission, role=domain.role, type=salesman.type reused.
return [
    'title' => 'User Management',
    'total' => ':count users',
    'create_btn' => 'Add User',
    'search_ph' => 'Name · email',
    'all_perm' => 'All permissions',
    'me' => '(me)',
    'no_login' => 'Never',
    'empty' => 'No users.',
    'delete_confirm' => 'Delete user :name?',
    'edit_title' => 'Edit User',
    'create_title' => 'Add User',

    'col' => [
        'name' => 'Name',
        'perm' => 'Permission',
        'role' => 'Role',
        'last_login' => 'Last Login',
    ],
    'field' => [
        'name' => 'Name',
        'name_ph' => 'John Doe',
        'email_ph' => 'user@car-erp.test',
        'phone' => 'Phone',
        'password' => 'Password',
        'password_edit_note' => '(blank = no change)',
        'password_ph' => '8+ characters',
        'sec_perm' => 'Permission Settings',
        'perm' => 'Permission',
        'role' => 'Role',
        'role_note' => 'Accessible menus depend on the role.',
        'settlement_type' => 'Settlement Type',
        'type_select' => '— Select —',
        'type_note' => 'Determines the auto-created settlement method on completion — explicit selection required to avoid omissions.',
        'manager' => 'Managers [Management] (multi-select)',
        'manager_none' => 'No [Management] users',
        'manager_note' => 'Select one or more [Management] users to oversee this salesman — every checked manager can view/edit this salesman\'s vehicles/buyers. If none selected, not picked up in any [Management] scoping.',
    ],

    'perm_opt' => [
        'super' => 'System Admin (super)',
        'admin' => 'Administrator (admin)',
        'manager' => 'Manager (manager)',
        'user' => 'User (user)',
    ],

    'saved' => 'User saved.',
    'deleted' => 'User deleted.',
    'self_delete' => 'You cannot delete your own account.',
    'no_super' => 'System admin permission cannot be granted.',
];
