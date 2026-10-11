<?php
// Private recovery checks, requiring the clean fixture's database/storage/path/port guards.
use App\Models\User;
use App\Models\Page;
use App\Services\Snapshots\SnapshotService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Tester\CommandTester;
$app=require __DIR__.'/phase-ten-bootstrap.php';
$action=$argv[1]??'';
if ($action==='grant') {
    User::where('email','phase10@example.test')->firstOrFail()->forceFill(['can_manage_custom_code'=>true])->save();
    App\Models\Setting::set('mail_enabled',true);
    App\Models\Setting::set('recaptcha_site_key','isolated-phase11-site-key');
    App\Models\Setting::set('recaptcha_secret_key','isolated-phase11-secret');
    echo "PASS explicit fixture account permission granted independently of credential scope\n";
} elseif ($action==='revoke-code-grant') {
    User::where('email','phase10@example.test')->firstOrFail()->forceFill(['can_manage_custom_code'=>false])->save();echo 'PASS fixture account grant revoked independently';
} elseif ($action==='clear-submission') {
    $profile=$argv[2];if(!in_array($profile,['full','essential'],true))throw new RuntimeException('Invalid profile');
    App\Models\ContactSubmission::where('email','phase11-'.$profile.'@visitor.test')->where('notification_status','skipped')->get()->each->delete();echo 'PASS skipped setup fixture cleared';
} elseif ($action==='recover') {
    $tester=new CommandTester(Artisan::all()['cms:user:reset-password']);
    $tester->setInputs(['Phase11-Recovered-Only-2026!']);
    if($tester->execute(['email'=>'phase10@example.test'])!==0) throw new RuntimeException('Recovery failed');
    if(User::where('email','phase10@example.test')->firstOrFail()->mcpApiKeys()->whereNull('revoked_at')->exists()) throw new RuntimeException('Credential revival');
    echo "PASS real access recovery revokes all prior keys\n";
} elseif ($action==='snapshot-roundtrip') {
    $page=Page::where('slug','phase11-landing')->firstOrFail();$before=$page->attributesToArray();
    $service=app(SnapshotService::class);$archive=$service->create('phase-eleven-recovery');$sha=hash_file('sha256',$archive);
    $page->forceFill(['title'=>'Mutación descartable anterior al restore'])->save();
    $report=$service->inspect($archive)->toArray();$accepted=[];
    foreach($report['risks'] as $risk) {if($risk['severity']==='blocking')throw new RuntimeException('Blocking archive');if($risk['severity']==='warning')$accepted[]=$risk['key'];}
    $service->restore($archive,$accepted);$restored=Page::findOrFail($page->id);
    if($restored->title!==$before['title']||$restored->content_json!==$before['content_json'])throw new RuntimeException('Snapshot content mismatch');
    foreach(['mcp_api_keys','oauth_access_tokens','editorial_preview_links','mcp_operation_receipts','editorial_operation_receipts','submission_operation_receipts','custom_code_receipts']as $table)if(DB::table($table)->count())throw new RuntimeException('Restored authority');
    if(DB::table('custom_code_snippets')->whereNotNull('active_version_id')->exists()||User::where('can_manage_custom_code',true)->exists())throw new RuntimeException('Restored executable authority');
    echo json_encode(['snapshot_sha256'=>$sha,'mutated_content_restored'=>true,'authority_excluded'=>true,'snippets_inactive'=>true,'permission_removed'=>true,'accepted_warning_keys'=>$accepted],JSON_PRETTY_PRINT);
} elseif ($action==='mail') {
    Artisan::call('queue:work',['--stop-when-empty'=>true,'--sleep'=>0]);
    $profile=$argv[2];$sub=App\Models\ContactSubmission::where('email','phase11-'.$profile.'@visitor.test')->firstOrFail();
    if($sub->notification_status!=='sent'||$sub->notification_attempts!==1)throw new RuntimeException('Queue failed');
    $logs=glob(getenv('PHASE0_STORAGE').'/logs/*.log');$found=false;foreach($logs as $log)if(str_contains(file_get_contents($log),'phase11-mail@example.test'))$found=true;
    if(!$found)throw new RuntimeException('Mail log evidence missing');
    echo json_encode(['real_database_queue_worker'=>true,'mail_log_boundary'=>true,'notification_status'=>'sent','attempts'=>1]);
} else throw new RuntimeException('Unknown action');
