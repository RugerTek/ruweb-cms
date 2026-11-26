<?php

// Amiba chatbot connector, level 1 (App\Connectors\AmibaChat\AmibaChatConnector, docs/operations/connectors.md).
// Amiba's own labels ("Web Chat", "Site key", "Allowed origins"…) are copied from its panel (andromeda-crm,
// i18n/locales/en.json) as of 2026-11-25.
return [
    'label' => 'Amiba chatbot',
    'description' => 'Add Amiba\'s chat to your site: a floating button so visitors can write to you and your Amiba assistant answers them.',
    'requirement_handle' => 'The widget code is missing: copy it in Amiba and paste it on the detail page.',
    'action_configure' => 'Set up',

    'settings_title' => 'Widget code',
    'settings_help' => 'Paste the code Amiba gives you. With it and the connector on, the chat shows on your site\'s pages.',
    'field_handle' => 'Amiba widget code',
    'field_handle_placeholder' => '<script src="https://widget.amiba.io/loader.js" data-amiba-widget="…" async></script>',
    'field_handle_help' => 'Paste the whole snippet you copy in Amiba, or just the "Site key". We keep the key and build the code ourselves.',
    'error_handle_format' => 'That does not look like an Amiba code. Paste the "Floating widget" snippet exactly as copied, or the "Site key", which looks like 0192f7c4-1a2b-7c3d-8e4f-0123456789ab.',
    'field_hide_on_checkout' => 'Hide the chat on the checkout',
    'field_hide_on_checkout_help' => 'While customers fill in their details and pay for the order, the chat is not shown. It stays on the rest of the site.',
    'field_hide_on_checkout_no_checkout' => 'Your shop does not sell online yet: this option applies once you turn purchases on.',
    'snippet_title' => 'This is what gets added to your pages:',
    'saved' => 'Widget code saved.',

    'test_title' => 'Test with Amiba',
    'test_help' => 'We ask Amiba whether your widget exists and whether it may show on this site. Nothing changes.',
    'action_test' => 'Test',
    'test_needs_handle' => 'Save the widget code first.',
    'test_ok' => 'All good: Amiba found your widget ":title" and this site (:origin) is in its allowed origins.',
    'test_ok_any_site' => 'Amiba found your widget ":title". Its "Allowed origins" are empty, so it shows on any site; add :origin so nobody else uses it on another website.',
    'test_not_found' => 'Amiba did not find that widget. Check that you copied the whole code and that your agent\'s "Web Chat" is still connected in Amiba.',
    'test_origin_not_allowed' => 'Amiba found your widget, but this site is not in its allowed origins: the chat will not start here.',
    'test_add_this' => 'In Amiba, on the "Web Chat" node › "Widget settings" › "Allowed origins", add this line exactly:',
    'test_unreachable' => 'Amiba did not answer in time. Try again in a few minutes; if it keeps happening, it may be your server\'s connection.',
    'test_unexpected' => 'Amiba gave an unexpected answer (code :status). Try again in a few minutes.',
    'test_untitled' => 'untitled',

    'guide_title' => 'Where the code is in Amiba',
    'guide_step_open' => 'In Amiba, open the agent that will answer on your site and click the "Web Chat" node. If it is not connected yet, click "Connect Web Chat".',
    'guide_step_copy' => 'Under "Embed on your website", find "Floating widget" and click "Copy".',
    'guide_step_paste' => 'Paste it above, in "Amiba widget code", and save.',
    'guide_step_origin' => 'On the same node, under "Widget settings" › "Allowed origins", add your site\'s address (one per line):',
    'guide_step_origin_help' => 'It must match exactly: with https:// and no trailing slash. If your site also opens with or without "www", add both.',
    'guide_step_test' => 'Come back here, click "Test" and, if it goes well, turn the connector on.',
    'guide_where' => 'Chat conversations stay in your Amiba account. What the assistant answers is set up there, not here.',

    'card_live' => 'The chat shows on your site\'s public pages.',
    'card_live_not_checkout' => 'The chat shows on your site\'s public pages, except on the checkout.',
    'confirm_deactivate' => 'The chat stops showing on your site. The code stays saved so you can turn it on again.',
];
