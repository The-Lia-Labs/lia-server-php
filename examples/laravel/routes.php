<?php
// routes/api.php: Bearer sessions, no cookie authentication. Configure your exact webhook URL.
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use LiaLabs\Server\{LiaClient,LiaError,Webhooks};
Route::get('/lia/shop',function(Request $request,LiaClient $lia){
    if (!$request->bearerToken()) return response()->json(['error'=>'session_required'],401);
    try{$lia->requireSession($request->bearerToken());return response()->json($lia->listProducts())->header('Cache-Control','no-store');}
    catch(LiaError $e){return response()->json(['error'=>$e->errorCode],$e->status===401?401:503);}
});
Route::post('/lia/webhooks',function(Request $request){
    $raw=$request->getContent();if(strlen($raw)>20000)return response()->json(['error'=>'body_too_large'],413);
    try{$event=Webhooks::constructEvent((string)config('lia.webhook_secret'),$raw,(string)$request->header('Lia-Signature'));
        if(($event['test']??false)===true)return response()->json(['received'=>true,'probe'=>true]);
        // Replace with a transactional fulfillment handler, unique by mode + charge ID.
        return response()->json(['error'=>'configure_transactional_fulfillment'],503);
    }catch(LiaError $e){return response()->json(['error'=>'invalid_webhook'],401);}
});
