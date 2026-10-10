<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CustomCode\CustomCodeOperations;
use App\Services\Snapshots\SnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class SnapshotCustomCodeTest extends TestCase
{
    use RefreshDatabase;

    private function snippet(User $actor): array
    {
        $ops=app(CustomCodeOperations::class);
        $record=$ops->save($actor,['name'=>'Snapshot fixture','type'=>'javascript','code'=>'window.snapshotFixture=true;','position'=>'body_end','sort_order'=>0,'rules'=>['global'=>true,'include'=>[],'exclude'=>[]],'operation_key'=>'save-snapshot']);
        return $ops->activate($actor,$record['id'],['version_id'=>$record['versions'][0]['id'],'expected_revision'=>$record['revision'],'operation_key'=>'activate-snapshot']);
    }

    public function test_snapshot_preserves_code_but_never_activates_or_grants_permission_and_excludes_receipts(): void
    {
        Storage::fake('public');
        $user=User::factory()->create(['role'=>'admin','can_manage_custom_code'=>true]);$snippet=$this->snippet($user);
        $archive=app(SnapshotService::class)->create('custom-code-snapshot');
        $zip=new ZipArchive;$zip->open($archive);$manifest=json_decode($zip->getFromName('manifest.json'),true);$zip->close();
        $this->assertNotContains('custom_code_receipts',array_column($manifest['database']['tables'],'name'));
        app(SnapshotService::class)->restore($archive);
        $this->assertDatabaseHas('custom_code_snippets',['id'=>$snippet['id'],'state'=>'inactive','active_version_id'=>null]);
        $this->assertDatabaseCount('custom_code_versions',1);$this->assertDatabaseCount('custom_code_receipts',0);
        $this->assertFalse($user->fresh()->can_manage_custom_code);
        $this->assertDatabaseHas('custom_code_events',['snippet_id'=>$snippet['id'],'action'=>'restore_inactive','user_id'=>null]);
        $payload=json_decode(DB::table('custom_code_versions')->first()->payload,true);$this->assertSame('window.snapshotFixture=true;',$payload['code']);
    }

    public function test_legacy_archive_without_code_tables_still_disables_destination_code_and_clears_receipts(): void
    {
        Storage::fake('public');$user=User::factory()->create(['role'=>'admin']);
        $archive=app(SnapshotService::class)->create('legacy-custom-code');
        $zip=new ZipArchive;$zip->open($archive);$manifest=json_decode($zip->getFromName('manifest.json'),true);
        $manifest['database']['tables']=array_values(array_filter($manifest['database']['tables'],fn($entry)=>!str_starts_with($entry['name'],'custom_code_')));
        $json=json_encode($manifest);
        $checksums=json_decode($zip->getFromName('checksums.json'),true);$checksums['manifest.json']=hash('sha256',$json);
        $zip->addFromString('manifest.json',$json);$zip->addFromString('checksums.json',json_encode($checksums));$zip->close();
        $user->update(['can_manage_custom_code'=>true]);$snippet=$this->snippet($user);
        app(SnapshotService::class)->restore($archive);
        $this->assertDatabaseHas('custom_code_snippets',['id'=>$snippet['id'],'state'=>'inactive','active_version_id'=>null]);
        $this->assertDatabaseCount('custom_code_versions',1);$this->assertDatabaseCount('custom_code_receipts',0);
        $this->assertFalse($user->fresh()->can_manage_custom_code);
    }
}
