<?php
require __DIR__.'/../src/LiaError.php';require __DIR__.'/../src/Webhooks.php';
use LiaLabs\Server\Webhooks;
$raw='{"id":"one","type":"charge.paid","app":"game","at":1000000}';$sig='t=1000,v1='.hash_hmac('sha256','1000.'.$raw,'secret');
foreach([Webhooks::verify('secret',$raw,$sig,1000),!Webhooks::verify('wrong',$raw,$sig,1000),!Webhooks::verify('secret',$raw.' ',$sig,1000),!Webhooks::verify('secret',$raw,$sig,1301),!Webhooks::verify('secret',$raw,$sig,699),Webhooks::constructEvent('secret',$raw,$sig,1000)['id']==='one'] as $ok)if(!$ok)throw new RuntimeException('Webhook test failed');
echo "Webhook signature, raw bytes, expiry and future timestamp tests passed\n";
