<?php
namespace App\Providers;
use Illuminate\Support\ServiceProvider;
use LiaLabs\Server\LiaClient;
final class LiaServiceProvider extends ServiceProvider {
    public function register(): void {
        $this->app->singleton(LiaClient::class,fn()=>new LiaClient((string)config('lia.server_key'),(string)config('lia.base_url')));
    }
}
