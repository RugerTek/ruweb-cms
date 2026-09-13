<?php

namespace Database\Seeders;

use App\Models\Locale;
use App\Models\Page;
use Illuminate\Database\Seeder;

/**
 * Starter contact page: the reference implementation of a code-owned public
 * form. Labels, subject options and the notification settings live in the
 * `form` section so the client edits them from the Live Editor; fields and
 * behaviour live in code. See docs/forms.md.
 */
class ContactPageSeeder extends Seeder
{
    public function run(): void
    {
        $page = Page::updateOrCreate(
            ['slug' => 'contacto', 'locale' => Locale::baseCode()],
            [
                'title' => 'Contacto',
                'template_key' => 'contact',
                'content_json' => [
                    'meta' => [
                        'description' => 'Escribinos y te respondemos a la brevedad.',
                    ],
                    'sections' => [
                        'intro' => [
                            'is_visible' => true,
                            'heading' => 'Hablemos',
                            'body' => 'Completá el formulario y nos pondremos en contacto.',
                        ],
                        'form' => [
                            'is_visible' => true,
                            'heading' => 'Envianos un mensaje',
                            'labels' => [
                                'name' => 'Nombre',
                                'email' => 'Email',
                                'phone' => 'Teléfono (opcional)',
                                'subject' => 'Motivo',
                                'message' => 'Mensaje',
                            ],
                            'button_label' => 'Enviar',
                            'success_message' => '¡Gracias! Recibimos tu mensaje y te responderemos pronto.',
                            'subject_options' => [
                                ['label' => 'Consulta general'],
                                ['label' => 'Pedido de presupuesto'],
                                ['label' => 'Soporte'],
                            ],
                            'notify_enabled' => false,
                            'notify_email' => null,
                        ],
                    ],
                ],
                'status' => 'published',
                'published_at' => now(),
                'created_by' => null,
                'updated_by' => null,
            ]
        );

        $page->source_fingerprint = $page->translatableFingerprint();
        $page->save();
    }
}
