<?php

namespace Tests\Unit;

use App\Support\CompositionBlocks;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CompositionBlocksTest extends TestCase
{
    private function accordion(array $params = []): array
    {
        return ['id' => 'faq-one', 'type' => 'interactive', 'component' => 'accordion', 'params' => $params ?: ['items' => [['question' => 'Question', 'answer' => 'Answer']]]];
    }

    public function test_order_identity_and_visibility_survive_normalization(): void
    {
        $blocks = [
            ['id' => 'body', 'type' => 'html', 'is_visible' => false, 'html' => '<p onclick="bad()">Body</p>'],
            $this->accordion(),
        ];
        $normal = (new CompositionBlocks)->normalize($blocks);
        $this->assertSame(['body', 'faq-one'], array_column($normal, 'id'));
        $this->assertFalse($normal[0]['is_visible']);
        $this->assertSame('<p>Body</p>', $normal[0]['html']);
        $this->assertSame(1, $normal[1]['version']);
        $this->assertSame($normal, (new CompositionBlocks)->normalize($normal));
    }

    public function test_duplicate_ids_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        (new CompositionBlocks)->normalize([$this->accordion(), $this->accordion()]);
    }

    public function test_unknown_nested_parameters_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        (new CompositionBlocks)->normalize([$this->accordion(['items' => [['question' => 'Q', 'answer' => 'A', 'script' => 'bad()']]])]);
    }

    public function test_unregistered_component_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        (new CompositionBlocks)->normalize([array_replace($this->accordion(), ['component' => 'arbitrary-script'])]);
    }

    public function test_wrong_component_type_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        (new CompositionBlocks)->normalize([array_replace($this->accordion(), ['type' => 'structured'])]);
    }

    public function test_interactive_parameters_are_validated(): void
    {
        $this->expectException(ValidationException::class);
        (new CompositionBlocks)->normalize([$this->accordion(['items' => [['question' => 'Q']], 'initially_open' => -1])]);
    }

    public function test_unsupported_block_version_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        (new CompositionBlocks)->normalize([array_replace($this->accordion(), ['version' => 2])]);
    }

    public function test_list_size_and_order_contract_are_enforced(): void
    {
        $this->expectException(ValidationException::class);
        (new CompositionBlocks)->normalize(['named' => $this->accordion()]);
    }

    public function test_media_discovery_includes_hidden_blocks_and_structured_media(): void
    {
        $this->assertSame([12, 13], CompositionBlocks::mediaIds([
            ['type' => 'html', 'is_visible' => false, 'html' => '<img data-media-id="12"><img data-media-id="12"><img data-media-id="bad">'],
            ['type' => 'structured', 'params' => ['image_id' => 13]],
        ]));
    }
}
