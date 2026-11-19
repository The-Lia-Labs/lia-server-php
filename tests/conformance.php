<?php
declare(strict_types=1);
require __DIR__.'/../src/LiaError.php'; require __DIR__.'/../src/LiaClient.php'; require __DIR__.'/../src/Webhooks.php';
use LiaLabs\Server\{LiaClient,LiaError,Webhooks};
function check(bool $condition): void {if (!$condition) throw new RuntimeException('Assertion failed');}
$base=getenv('LIA_CONFORMANCE_URL');check(is_string($base)&&str_starts_with($base,'http://127.0.0.1:'));
$lia=new LiaClient('lia_test_fixture',$base,2);
foreach(json_decode(file_get_contents(__DIR__.'/conformance.json'),true,512,JSON_THROW_ON_ERROR) as $case){
 $method=$case['operation'];$r=$lia->$method($case['params']);
 if($method==='verifySession'){check($r['valid']===true);continue;}
 if($case['text']){check(str_contains($r,'active_players'));continue;}
 check($r['echo']['method']===$case['method']&&$r['echo']['path']===$case['path']);
 check($r['echo']['body']==$case['body']&&$r['echo']['query']==$case['query']);
}
check($lia->requireSession('valid')['playerId']==='p_12345678');
foreach(['invalid','expired','wrong-mode'] as $session){try{$lia->requireSession($session);throw new RuntimeException('session accepted');}catch(LiaError $e){check($e->errorCode==='invalid_session');}}
check($lia->getCharge(['id'=>'retry-php'])['charge']['id']==='ch_fixture');
foreach(['rate-php'=>'slow_down','redirect-php'=>'http_error','invalid-php'=>'invalid_response','conflict-php'=>'idempotency_key_reused'] as $id=>$code){try{$lia->getCharge(['id'=>$id]);throw new RuntimeException('error accepted');}catch(LiaError $e){check($e->errorCode===$code&&!str_contains($e->getMessage(),'NEVER_EXPOSE'));}}
$lia->deliverCharge(['id'=>'replay-php'],'php:one');
try{$lia->getCharge(['id'=>'../pool']);throw new RuntimeException('path accepted');}catch(LiaError $e){check($e->errorCode==='invalid_path_parameter');}
try{(new LiaClient('lia_test_fixture',$base,.04))->getCharge(['id'=>'slow-php']);throw new RuntimeException('timeout accepted');}catch(LiaError $e){check($e->errorCode==='network_error');}
ob_start();var_dump($lia);$debug=ob_get_clean();check(!str_contains($debug,'fixture'));
$at=time();$raw=json_encode(['id'=>'evt_1','type'=>'charge.paid','app'=>'example','at'=>$at*1000]);$sig='t='.$at.',v1='.hash_hmac('sha256',$at.'.'.$raw,'whsec_fixture');
check(Webhooks::verify('whsec_fixture',$raw,$sig));check(!Webhooks::verify('wrong',$raw,$sig));check(!Webhooks::verify('whsec_fixture',$raw.' ',$sig));check(!Webhooks::verify('whsec_fixture',$raw,$sig,$at+301));check(Webhooks::constructEvent('whsec_fixture',$raw,$sig)['id']==='evt_1');
echo "PHP: 27 operations, sessions, retry, idempotency, deadline and signatures passed\n";
