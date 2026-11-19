<?php
// Run locally with: php -S 127.0.0.1:3001 server.php
require __DIR__.'/vendor/autoload.php';
use LiaLabs\Server\{LiaClient,LiaError,Webhooks};
header('Content-Type: application/json');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
function reply(int $status,array $value): never {http_response_code($status);echo json_encode($value,JSON_THROW_ON_ERROR);exit;}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$method=$_SERVER['REQUEST_METHOD'];
if($method==='GET'&&$path==='/health')reply(200,['ok'=>true]);
try{
 if($method==='POST'&&$path==='/webhooks/lia'){
  $stream=fopen('php://input','rb');$raw=stream_get_contents($stream,20001);if(strlen($raw)>20000)reply(413,['error'=>'body_too_large']);
  $event=Webhooks::constructEvent(getenv('LIA_WEBHOOK_SECRET')?:'',$raw,$_SERVER['HTTP_LIA_SIGNATURE']??'');
  if(($event['test']??false)===true)reply(200,['received'=>true,'probe'=>true]);
  reply(503,['error'=>'configure_transactional_fulfillment']);
 }
 $lia=new LiaClient(getenv('LIA_SERVER_KEY')?:'',getenv('LIA_API_BASE_URL')?:'https://api.lialabs.org/api/apps/v1');
 if(!preg_match('/^Bearer (\S+)$/D',$_SERVER['HTTP_AUTHORIZATION']??'',$m))reply(401,['error'=>'session_required']);
 $player=$lia->requireSession($m[1]);
 if($method==='GET'&&$path==='/shop')reply(200,$lia->listProducts());
 reply(404,['error'=>'not_found']);
}catch(LiaError $e){reply($e->status>=400&&$e->status<600?$e->status:503,['error'=>$e->errorCode]);}
