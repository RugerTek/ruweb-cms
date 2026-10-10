<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\User;
use App\Services\CustomCode\CustomCodeOperations;
use App\Support\CustomCodeDestinations;
use App\Support\CustomCodeRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomCodeRenderingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['es', 'en'] as $code) \App\Models\Locale::create(['code'=>$code, 'name'=>$code, 'is_base'=>$code==='es', 'is_active'=>true, 'is_public'=>true]);
    }

    private function payload(array $rules): array
    {
        return ['name' => 'Safe local fixture', 'type' => 'javascript', 'code' => 'window.phaseEight = (window.phaseEight || 0) + 1;', 'position' => 'body_end', 'sort_order' => 3, 'rules' => $rules, 'operation_key' => 'create'];
    }

    public function test_public_canvas_exact_locale_groups_exclusion_and_missing_reference_fail_closed(): void
    {
        $actor = User::factory()->create(['role' => 'admin', 'can_manage_custom_code' => true]);
        $page = Page::create(['title'=>'Fixture', 'slug'=>'fixture-code', 'template_key' => 'empty', 'status' => 'published', 'published_at' => now()->subHour(), 'locale' => 'es']);
        $english = Page::create(['title'=>'English', 'slug'=>'fixture-code', 'template_key' => 'empty', 'status' => 'published', 'published_at' => now()->subHour(), 'locale' => 'en', 'translation_group_id' => $page->translation_group_id]);
        $ref = app(CustomCodeDestinations::class)->forModel($page);
        $ops = app(CustomCodeOperations::class);
        $record = $ops->save($actor, $this->payload(['global' => false, 'include' => [$ref], 'exclude' => []]));
        $record = $ops->activate($actor, $record['id'], ['version_id' => $record['versions'][0]['id'], 'expected_revision' => $record['revision'], 'operation_key' => 'activate']);
        $renderer = app(CustomCodeRenderer::class);
        $this->assertStringContainsString('phaseEight', $renderer->render($ref, 'body_end'));
        $this->assertSame('', $renderer->render(app(CustomCodeDestinations::class)->forModel($english), 'body_end'));
        $this->get($page->url())->assertOk()->assertSee('window.phaseEight', false);
        $this->get('/login')->assertOk()->assertDontSee('window.phaseEight', false);
        $this->get('/missing-custom-code-page')->assertNotFound()->assertDontSee('window.phaseEight', false);
        $this->assertSame('', $renderer->render($ref, 'body_end', true));
        $group = $ref; $group['group'] = true;
        $record = $ops->save($actor, array_replace($this->payload(['global' => true, 'include' => [$group], 'exclude' => [$group]]), ['expected_revision' => $record['revision'], 'operation_key' => 'exclude']), $record['id']);
        $record = $ops->activate($actor, $record['id'], ['version_id' => $record['versions'][0]['id'], 'expected_revision' => $record['revision'], 'operation_key' => 'activate-excluded']);
        $this->assertSame('', $renderer->render(app(CustomCodeDestinations::class)->forModel($english), 'body_end'));
        $page->delete();
        $evaluation = $renderer->evaluate(app(CustomCodeDestinations::class)->forModel($english));
        $this->assertFalse($evaluation['snippets'][0]['applies']);
        $this->assertSame('deleted_destination', $evaluation['snippets'][0]['diagnostics'][0]['cause']);
    }

    public function test_validation_rechecks_exact_versions_and_cli_recovery_needs_no_frontend(): void
    {
        $actor = User::factory()->create(['role' => 'admin', 'can_manage_custom_code' => true]);
        $ops = app(CustomCodeOperations::class);
        $input = $this->payload(['global' => true, 'include' => [], 'exclude' => []]);
        $record = $ops->save($actor, $input);
        DB::table('custom_code_versions')->where('id', $record['versions'][0]['id'])->update(['payload' => json_encode(array_replace($record['versions'][0]['payload'], ['code' => 'changed']))]);
        try { $ops->activate($actor, $record['id'], ['version_id' => $record['versions'][0]['id'], 'expected_revision' => $record['revision'], 'operation_key' => 'tampered']); $this->fail('Tampered activation accepted'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(409, $error->getStatusCode()); }
        $this->assertSame('draft', $ops->get($actor, $record['id'])['state']);
        try {
            $this->artisan('cms:custom-code disable')->assertExitCode(0);
            $this->assertFalse(app(CustomCodeRenderer::class)->enabled());
            $this->artisan('cms:custom-code enable')->assertExitCode(0);
            $this->assertTrue(app(CustomCodeRenderer::class)->enabled());
            config(['cms.custom_code.enabled' => false]);
            $this->artisan('cms:custom-code enable')->assertExitCode(0);
            $this->assertFalse(app(CustomCodeRenderer::class)->enabled());
        } finally { @unlink(storage_path('app/private/custom-code.disabled')); }
    }

    public function test_executable_preview_requires_explicit_ack_and_is_separate_from_ordinary_preview(): void
    {
        $actor = User::factory()->create(['role' => 'admin', 'can_manage_custom_code' => true]);
        $page = Page::create(['title'=>'Draft', 'slug'=>'draft-code', 'template_key'=>'default']);
        $ops = app(CustomCodeOperations::class); $record = $ops->save($actor, $this->payload(['global' => true, 'include' => [], 'exclude' => []]));
        $data = ['version_id' => $record['versions'][0]['id'], 'expected_revision' => $record['revision'], 'target_ref' => json_encode(app(CustomCodeDestinations::class)->forModel($page))];
        $this->actingAs($actor)->postJson(route('admin.custom-code.test-preview', $record['id']), $data)->assertUnprocessable();
        $this->post(route('admin.custom-code.test-preview', $record['id']), $data + ['acknowledgement' => 'execute_browser_code'])->assertOk()->assertSee('sandbox="allow-scripts"', false)->assertSee('window.phaseEight');
        $this->assertSame('draft', $ops->get($actor, $record['id'])['state']);
    }
    public function test_executable_preview_can_read_old_exact_version_but_rejects_other_snippet_version(): void
    {
        $actor=User::factory()->create(['role'=>'admin','can_manage_custom_code'=>true]);
        $page=Page::create(['title'=>'Test','slug'=>'old-preview','template_key'=>'default']);
        $ops=app(CustomCodeOperations::class);$payload=$this->payload(['global'=>true,'include'=>[],'exclude'=>[]]);
        $record=$ops->save($actor,$payload);$first=$record['versions'][0]['id'];
        foreach(range(1,21) as $i) $record=$ops->save($actor,array_replace($payload,['expected_revision'=>$record['revision'],'operation_key'=>'old-'.$i]),$record['id']);
        $input=['version_id'=>$first,'expected_revision'=>$record['revision'],'target_ref'=>json_encode(app(CustomCodeDestinations::class)->forModel($page)),'acknowledgement'=>'execute_browser_code'];
        $this->actingAs($actor)->post(route('admin.custom-code.test-preview',$record['id']),$input)->assertOk()->assertSee('window.phaseEight');
        $other=$ops->save($actor,array_replace($payload,['operation_key'=>'other']));
        $this->post(route('admin.custom-code.test-preview',$record['id']),array_replace($input,['version_id'=>$other['versions'][0]['id']]))->assertNotFound();
    }

}
