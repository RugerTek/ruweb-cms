<?php

// Daily check of the Meta connection and the «Volvé a conectar» email (MetaHealthCheckCommand, plan-meta-v2 track 3c).
return [
    'mail_subject' => 'Volvé a conectar tu catálogo de Meta — :site',
    'mail_heading' => 'Se cortó la conexión con Facebook e Instagram',
    'mail_intro' => 'Meta ya no deja que tu sitio actualice tu catálogo: los cambios de productos, precios y stock no van a llegar a Facebook ni a Instagram hasta que vuelvas a conectar.',
    'mail_intro_catalog' => 'Meta ya no deja que tu sitio actualice el catálogo «:catalog»: los cambios de productos, precios y stock no van a llegar a Facebook ni a Instagram hasta que vuelvas a conectar.',
    'mail_action_help' => 'Entrá al panel, tocá «Volver a conectar» y en la ventana de Facebook marcá de nuevo tu catálogo. Tarda un par de minutos.',
    'mail_action' => 'Volver a conectar',
    'mail_login_help' => '¿Te aparece «Página no encontrada»? Primero iniciá sesión en :login y después volvé a tocar el botón.',
    'mail_footer' => 'Te mandamos este aviso una sola vez. Los productos que ya estaban en tu catálogo de Meta no se borraron.',
    'error_unreachable' => 'No pudimos comprobar la conexión con Meta hoy (:message). Lo volvemos a probar mañana; no tenés que hacer nada.',
    'error_unreachable_days' => 'Meta no responde desde hace :days días; revisá la conexión del servidor (si no sabés cómo, avisale a quien administra tu sitio). La conexión sigue: cuando Meta vuelva a responder, todo se actualiza solo.',
    'unreachable_mail_subject' => 'Meta no responde desde hace :days días — :site',
    'unreachable_mail_heading' => 'Meta no responde a tu sitio',
    'unreachable_mail_intro' => 'Hace :days días que tu sitio no logra comunicarse con Meta, así que los cambios de productos, precios y stock pueden no estar llegando a Facebook ni a Instagram.',
    'unreachable_mail_intro_catalog' => 'Hace :days días que tu sitio no logra comunicarse con Meta para actualizar el catálogo «:catalog», así que los cambios de productos, precios y stock pueden no estar llegando a Facebook ni a Instagram.',
    'unreachable_mail_action_help' => 'No hace falta volver a conectar: la conexión sigue y se retoma sola cuando Meta responda. Revisá la conexión a internet del servidor o avisale a quien administra tu sitio.',
    'unreachable_mail_action' => 'Ver la conexión con Meta',
    'unreachable_mail_footer' => 'Te mandamos este aviso una sola vez mientras dure el problema. Los productos que ya estaban en tu catálogo de Meta no se borraron.',
];
