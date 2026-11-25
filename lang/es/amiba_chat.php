<?php

// Amiba chatbot connector, level 1 (App\Connectors\AmibaChat\AmibaChatConnector, docs/operations/connectors.md).
// Amiba's own labels («Web Chat», «Clave de sitio», «Orígenes permitidos»…) are copied from its panel (andromeda-crm,
// i18n/locales/es.json) as of 2026-11-25.
return [
    'label' => 'Chatbot Amiba',
    'description' => 'Sumá a tu sitio el chat de Amiba: un botón flotante para que tus visitas te escriban y las atienda tu asistente de Amiba.',
    'requirement_handle' => 'Falta el código del widget: copialo en Amiba y pegalo en el detalle.',
    'action_configure' => 'Configurar',

    'settings_title' => 'Código del widget',
    'settings_help' => 'Pegá el código que te da Amiba. Con eso y el conector activo, el chat aparece en las páginas de tu sitio.',
    'field_handle' => 'Código del widget de Amiba',
    'field_handle_placeholder' => '<script src="https://widget.amiba.io/loader.js" data-amiba-widget="…" async></script>',
    'field_handle_help' => 'Podés pegar el fragmento completo que copiás en Amiba o solo la «Clave de sitio». Guardamos la clave y el código lo armamos nosotros.',
    'error_handle_format' => 'Eso no parece un código de Amiba. Pegá el fragmento de «Widget flotante» tal cual lo copiaste, o la «Clave de sitio», que se ve así: 0192f7c4-1a2b-7c3d-8e4f-0123456789ab.',
    'field_hide_on_checkout' => 'Ocultar el chat en el checkout',
    'field_hide_on_checkout_help' => 'Mientras tus clientes completan sus datos y pagan el pedido, el chat no se muestra. En el resto del sitio sigue visible.',
    'field_hide_on_checkout_no_checkout' => 'Tu tienda todavía no vende en línea: esta opción vale para cuando actives las compras.',
    'snippet_title' => 'Esto es lo que se agrega a tus páginas:',
    'saved' => 'Código del widget guardado.',

    'test_title' => 'Probar con Amiba',
    'test_help' => 'Le preguntamos a Amiba si tu widget existe y si permite mostrarse en este sitio. No cambia nada.',
    'action_test' => 'Probar',
    'test_needs_handle' => 'Primero guardá el código del widget.',
    'test_ok' => 'Todo bien: Amiba encontró tu widget «:title» y este sitio (:origin) está en sus orígenes permitidos.',
    'test_ok_any_site' => 'Amiba encontró tu widget «:title». Sus «Orígenes permitidos» están vacíos, así que se muestra en cualquier sitio; te conviene agregar :origin para que nadie lo use en otra web.',
    'test_not_found' => 'Amiba no encontró ese widget. Revisá que copiaste el código completo y que el «Web Chat» de tu agente siga conectado en Amiba.',
    'test_origin_not_allowed' => 'Amiba encontró tu widget, pero este sitio no está en sus orígenes permitidos: el chat no va a arrancar acá.',
    'test_add_this' => 'En Amiba, en el nodo «Web Chat» › «Configuración del widget» › «Orígenes permitidos», agregá esta línea tal cual:',
    'test_unreachable' => 'Amiba no respondió a tiempo. Probá de nuevo en unos minutos; si sigue igual, puede ser la conexión de tu servidor.',
    'test_unexpected' => 'Amiba respondió algo inesperado (código :status). Probá de nuevo en unos minutos.',
    'test_untitled' => 'sin título',

    'guide_title' => 'Dónde está el código en Amiba',
    'guide_step_open' => 'En Amiba, abrí el agente que va a atender tu sitio y tocá el nodo «Web Chat». Si todavía no está conectado, tocá «Conectar Web Chat».',
    'guide_step_copy' => 'En «Insertar en tu sitio web», buscá «Widget flotante» y tocá «Copiar».',
    'guide_step_paste' => 'Pegalo arriba, en «Código del widget de Amiba», y guardá.',
    'guide_step_origin' => 'En el mismo nodo, en «Configuración del widget» › «Orígenes permitidos», agregá la dirección de tu sitio (una por línea):',
    'guide_step_origin_help' => 'Tiene que coincidir exacta: con https:// y sin barra al final. Si tu sitio también abre con o sin «www», agregá las dos.',
    'guide_step_test' => 'Volvé acá, tocá «Probar» y, si sale bien, activá el conector.',
    'guide_where' => 'Las conversaciones del chat quedan en tu cuenta de Amiba. Lo que responde el asistente se configura allá, no acá.',

    'card_live' => 'El chat se muestra en las páginas públicas de tu sitio.',
    'card_live_not_checkout' => 'El chat se muestra en las páginas públicas de tu sitio, salvo en el checkout.',
    'confirm_deactivate' => 'El chat deja de aparecer en tu sitio. El código queda guardado para volver a activarlo.',
];
