# Lia Server for PHP

Official server-only package for Lia mini apps.

```php
use LiaLabs\Server\LiaClient;
$lia = new LiaClient(getenv('LIA_SERVER_KEY'));
$player = $lia->requireSession($sessionFromLia);
$products = $lia->listProducts();
```

PHP 8.2+, cURL. Add `{"repositories":[{"type":"vcs","url":"https://github.com/The-Lia-Labs/lia-server-php"}]}` to composer.json, then run `composer require lia-labs/server:^0.1.0`. See `examples/laravel` for a container provider, server-only config and API routes. Register the provider in your Laravel app and put the config at `config/lia.php`. Run `composer test` for signature tests.

For the complete installation guide, API methods, safe retries, payment delivery and framework starters, see [Lia backend packages](https://github.com/The-Lia-Labs/lia-sdk/blob/main/backends/README.md) and [developer documentation](https://docs.lialabs.org/packages/server).

The same client covers sessions, products, charges, reconciliation, rewards, notifications, ads, seasonal leaderboards, server-only storage, durable webhook history/replay and analytics. Never ship the server key to the browser. Verify charge owner, mode, status and product before granting inventory with a persistent unique receipt. A signed webhook or `watched` flag alone is not an inventory grant. Live valuable ad rewards require provider SSV and a compatible native Lia host.
