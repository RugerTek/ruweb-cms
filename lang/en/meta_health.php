<?php

// Daily check of the Meta connection and the «Reconnect» email (MetaHealthCheckCommand, plan-meta-v2 track 3c).
return [
    'mail_subject' => 'Reconnect your Meta catalog — :site',
    'mail_heading' => 'The connection with Facebook and Instagram was cut',
    'mail_intro' => 'Meta no longer lets your site update your catalog: product, price and stock changes will not reach Facebook or Instagram until you connect again.',
    'mail_intro_catalog' => 'Meta no longer lets your site update the catalog “:catalog”: product, price and stock changes will not reach Facebook or Instagram until you connect again.',
    'mail_action_help' => 'Open the admin panel, click “Reconnect” and select your catalog again in the Facebook window. It takes a couple of minutes.',
    'mail_action' => 'Reconnect',
    'mail_login_help' => 'Seeing “Page not found”? Sign in first at :login, then click the button again.',
    'mail_footer' => 'We send this notice only once. The products already in your Meta catalog were not deleted.',
    'error_unreachable' => 'We could not check the connection with Meta today (:message). We will try again tomorrow; you do not need to do anything.',
    'error_unreachable_days' => 'Meta has not answered for :days days; check the server’s connection (if you are not sure how, tell whoever manages your site). The connection stays: everything updates by itself once Meta answers again.',
    'unreachable_mail_subject' => 'Meta has not answered for :days days — :site',
    'unreachable_mail_heading' => 'Meta is not answering your site',
    'unreachable_mail_intro' => 'For :days days your site has not been able to reach Meta, so product, price and stock changes may not be reaching Facebook or Instagram.',
    'unreachable_mail_intro_catalog' => 'For :days days your site has not been able to reach Meta to update the catalog “:catalog”, so product, price and stock changes may not be reaching Facebook or Instagram.',
    'unreachable_mail_action_help' => 'You do not need to reconnect: the connection stays and resumes by itself once Meta answers. Check the server’s internet connection or tell whoever manages your site.',
    'unreachable_mail_action' => 'View the Meta connection',
    'unreachable_mail_footer' => 'We send this notice only once while the problem lasts. The products already in your Meta catalog were not deleted.',
];
