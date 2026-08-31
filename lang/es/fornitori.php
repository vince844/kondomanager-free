<?php

return [

    'success_create_fornitore'           => "The new supplier has been created successfully.",
    'error_create_fornitore'             => "An error occurred while creating the new supplier.",
    'success_update_fornitore'           => "The supplier has been updated successfully.",
    'error_update_fornitore'             => "An error occurred while updating the supplier.",
    'success_delete_fornitore'           => "The supplier has been deleted successfully.",     
    'error_delete_fornitore'             => "An error occurred while deleting the supplier.",
    'success_attach_anagrafica'          => "The record has been successfully associated with the supplier.",
    'error_attach_anagrafica'            => "An error occurred while associating the record with the supplier.",
    'success_detach_anagrafica'          => "The record has been successfully detached from the supplier.",
    'error_detach_anagrafica'            => "An error occurred while detaching the record from the supplier.",
    'error_delete_has_invoices'          => "Unable to delete: the supplier has invoices registered in the system. To avoid compromising the accounting, we recommend changing the \"Status\" to \"Inactive\".",

    /* ------------------------------------------------------------------
     | Headings, Titles and Descriptions
     | ------------------------------------------------------------------ */
    'header' => [
        'list_fornitori_head'           => "Suppliers list",
        'list_fornitori_title'          => "Suppliers list",
        'list_fornitori_description'    => "Manage the records of suppliers and professionals linked to your buildings.",
        'new_fornitore_head'            => "Create supplier",
        'new_fornitore_title'           => "Create supplier",
        'new_fornitore_description'     => "Enter data to register a new company or professional.",
        'edit_fornitore_head'           => "Edit supplier",
        'edit_fornitore_title'          => "Edit supplier",
        'edit_fornitore_description'    => "Update the supplier's master and tax data to keep accounting aligned.",
    ],

    /* ------------------------------------------------------------------
     | Table
     | ------------------------------------------------------------------ */
    'table' => [
        'category'                => 'Categoría',
        'name'           => 'Company Name',
        'address'        => 'Address',
        'contacts'       => 'Contacts',
        'type'           => 'Type',
        'actions'        => 'Actions',
        'click_to_view'  => 'Click to view',
        'filter_by_name' => 'Search supplier...',
        'residents'      => 'Referents',
        'representatives_title' => 'Representantes del proveedor',
        'representatives_desc'  => 'Las personas que responden por esta empresa. Abre una ficha para sus datos de contacto.',
        'residents_desc' => 'List of contacts and referents associated with this supplier.',
    ],

    /* ------------------------------------------------------------------
     | Labels and Placeholders
     | ------------------------------------------------------------------ */
    'label' => [
        'tax_code' => 'Tax Code',
        'vat_number' => 'VAT Number',
    ],

    'placeholder' => [
        'no_address' => 'Address not available',
    ],

    /* ------------------------------------------------------------------
     | Actions
     | ------------------------------------------------------------------ */
    'actions' => [
        'new_fornitore'   => 'New Supplier',
        'edit_fornitore'  => 'Edit',
        'delete_fornitore'=> 'Delete',
        'save_fornitore'  => 'Save Supplier',
        'list'            => 'List', 
    ],

    /* ------------------------------------------------------------------
     | Guides (PageHeaderGuide)
     | ------------------------------------------------------------------ */
    'guides' => [
        'portfolio_title'             => 'Suppliers Directory',
        'portfolio_desc'              => 'Quickly access tax and contact data for all registered companies and professionals.',
        'compliance_title'            => 'Tax Compliance',
        'compliance_desc'             => 'Verify VAT numbers and Tax codes for correct electronic invoicing.',
        'management_title'            => 'Quick Management',
        'management_desc'             => 'Add new suppliers or update contact details to improve operational communication.',
        'new_fornitore_guide_title'   => 'Data Entry', 
        'new_fornitore_guide_desc'    => 'Ensure you correctly enter the VAT number to enable the sending of withholding taxes.', 
        'edit_status_title'           => 'Lifecycle Status',
        'edit_status_desc'            => 'Use the "Inactive" or "Suspended" status instead of deleting the company, to keep past accounting records intact.',
        'edit_treasury_title'         => 'Bank Details',
        'edit_treasury_desc'          => 'Keep the main IBAN updated to ensure the accuracy of cash flows and wire transfers.',
        'edit_compliance_title'       => 'Withholding & Taxes',
        'edit_compliance_desc'        => 'Update tax rates to allow the system to automatically calculate net payments.',
    ],
    

    /* ------------------------------------------------------------------
     | Categorías de proveedor (1.11.0-beta.9)
     | ------------------------------------------------------------------ */
    'categorie' => [
        'success_create'      => "La categoría se ha creado correctamente.",
        'error_create'        => "Se ha producido un error al crear la categoría.",
        'success_update'      => "La categoría se ha actualizado correctamente.",
        'error_update'        => "Se ha producido un error al actualizar la categoría.",
        'success_delete'      => "La categoría se ha eliminado correctamente.",
        'error_delete'        => "Se ha producido un error al eliminar la categoría.",
        'in_uso'              => "{1} La categoría «:nome» no se ha eliminado: hay un proveedor que la usa. Cambia su categoría e inténtalo de nuevo.|[2,*] La categoría «:nome» no se ha eliminado: hay :quanti proveedores que la usan. Cambia su categoría e inténtalo de nuevo.",

        'head'                => "Categorías de proveedor",
        'title'               => "Categorías de los proveedores",
        'description'         => "Crea, renombra y elimina las categorías con las que clasificas empresas y profesionales.",
        'back'                => "Volver a proveedores",

        'new'                 => "Nueva categoría",
        'new_title'           => "Crear nueva categoría",
        'new_description'     => "Añade una categoría para clasificar empresas y profesionales.",
        'edit_title'          => "Modificar categoría: :categoria",
        'edit_description'    => "Aquí puedes cambiar el nombre y la descripción de la categoría.",

        'name'                => "Nombre",
        'name_placeholder'    => "Por ejemplo: cristalero",
        'description_label'   => "Descripción",
        'description_hint'    => "Opcional: solo sirve para recordar qué va aquí dentro.",
        'description_placeholder' => "Qué tipo de proveedores reúne esta categoría",
        'used_by'             => "Proveedores",
        'suppliers_title'     => "Proveedores de esta categoría",
        'suppliers_desc'      => "Quién está clasificado como «:categoria». Abre una ficha para cambiarle la categoría.",

        'actions'             => "Acciones",
        'edit'                => "Modificar",
        'delete'              => "Eliminar",
        'blocked_title'       => "Esta categoría no se puede eliminar",
        'blocked_intro'       => "{1} Hay un proveedor clasificado como «:categoria». Mientras sea así, eliminarla lo dejaría sin categoría.|[2,*] Hay :count proveedores clasificados como «:categoria». Mientras sea así, eliminarla los dejaría sin categoría.",
        'blocked_how'         => "Abre una ficha para cambiarle la categoría: cuando ya no la use nadie, la eliminación funciona.",
        'blocked_close'       => "Entendido",
        'delete_title'        => "¿Eliminar la categoría?",
        'delete_description'  => "Los proveedores que la usan perderían su categoría, por eso la eliminación solo funciona si no la usa nadie.",
        'save'                => "Guardar",
        'cancel'              => "Cancelar",

        'filtro_non_valido'   => "El filtro por categoría ya no es válido: esa categoría ya no existe. Aquí está la lista completa.",
        'clear_filters'       => "Borrar los filtros",
        'filter'              => "Filtrar por nombre...",
        'no_results'          => "No se ha encontrado ninguna categoría.",
        'empty'               => "Todavía no hay ninguna categoría.",

        'quick_add'           => "Crear una nueva categoría",
        'quick_add_title'     => "Nueva categoría de proveedor",
        'quick_add_description' => "La creas aquí y queda seleccionada: no pierdes lo que ya has escrito en la ficha.",
        'quick_created'       => "Categoría creada y seleccionada.",
        'manage'              => "Gestionar las categorías",

        'guides' => [
            'own_title'       => "Categorías tuyas",
            'own_desc'        => "Las categorías iniciales son un punto de partida: añade los oficios que necesitan tus comunidades y quita los que no usas.",
            'use_title'       => "Para qué sirven",
            'use_desc'        => "Clasifican empresas y profesionales en la lista de proveedores y permiten encontrarlos por tipo de trabajo.",
            'safe_title'      => "Eliminación protegida",
            'safe_desc'       => "Una categoría usada por al menos un proveedor no se elimina: antes hay que cambiar la categoría a esos proveedores.",
        ],
    ],

];