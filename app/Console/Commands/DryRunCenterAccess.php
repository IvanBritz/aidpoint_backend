<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use App\Models\User;
use App\Models\FinancialAid;

class DryRunCenterAccess extends Command
{
    protected $signature = 'access:dry-run-center {--director_id=} {--urls=*} {--check-routes}';
    protected $description = 'Dry-run access rules for all users associated with a director\'s center when subscription is expired (no DB writes).';

    public function handle(): int
    {
        $dirId = $this->option('director_id');
        $directors = User::whereHas('systemRole', function ($q) { $q->where('name', 'director'); })->get();
        if ($dirId) {
            $directors = $directors->where('id', (int)$dirId)->values();
        }
        if ($directors->isEmpty()) {
            $this->warn('No director accounts found.');
            return self::SUCCESS;
        }

        $defaultUrls = [
            '/api/user',
            '/api/subscription-status',
            '/api/my-facilities',
            '/api/beneficiary/my-document-submission',
            '/api/beneficiary/aid-requests',
            '/api/notifications',
            '/api/aid-requests/pending',
            '/api/funds/dashboard',
            '/api/subscribe',
            '/api/public/subscription-plans',
        ];
        $urls = $this->option('urls');
        if (empty($urls)) { $urls = $defaultUrls; }

        // Mirror allowlist found in EnsureActiveSubscription
        $allowPatterns = [
            'api/public/*',
            'api/subscription-plans*',
            'api/public/subscription-plans*',
            'api/my-subscriptions',
            'api/subscription-status',
            'api/user',
            'api/my-facilities',
            'api/subscribe*',
            'api/manual-subscription-activate',
            'api/cancel-pending-subscription',
            'api/subscriptions/*/receipt*',
            'api/payments/paymongo/*',
            'api/subscriptions/expire-now',
            'api/webhooks/paymongo*',
'api/webhook',
        ];

        $this->line('Dry-run: Center suspended (director subscription expired). No requests are executed; expected allow/deny is computed from rules.');
        foreach ($directors as $director) {
            $facilities = FinancialAid::where('user_id', $director->id)->pluck('id')->all();
            $users = collect([$director]);
            if (!empty($facilities)) {
                $users = $users->merge(User::whereIn('financial_aid_id', $facilities)->get());
            }

            $this->info("\nDirector #{$director->id} ({$director->email}) — facilities: ".(empty($facilities)?'none':implode(',', $facilities))." — associated users: {$users->count()}");

            foreach ($users as $u) {
                $role = strtolower(optional($u->systemRole)->name ?? '');
                $this->line("  - User #{$u->id} role={$role} status=".(string)($u->status ?? 'n/a'));

                foreach ($urls as $url) {
                    $path = ltrim(parse_url($url, PHP_URL_PATH) ?: $url, '/');
                    $allowed = $this->matchesAny($path, $allowPatterns);
                    // Directors themselves are blocked from everything except allowlist while expired; same for staff/beneficiaries
                    $expected = $allowed ? 'ALLOW' : 'DENY(403)';
                    $this->line("      » {$url} -> {$expected}");
                }
            }
        }

        if ($this->option('check-routes')) {
            $this->line("\n[Route scan] API routes gated by middleware — expected behavior while suspended:");
            $routes = collect(Route::getRoutes())->filter(function ($r) {
                $action = $r->getAction();
                $isApi = isset($action['prefix']) && str_starts_with($action['prefix'], 'api');
                $needsAuth = str_contains(implode(',', $action['middleware'] ?? []), 'auth');
                return $isApi && $needsAuth;
            });
            foreach ($routes as $r) {
                $uri = $r->uri();
                $allowed = $this->matchesAny($uri, $allowPatterns);
                $this->line(sprintf("  %s %-7s %s -> %s", implode(',', $r->methods()), '', $uri, $allowed ? 'ALLOW' : 'DENY(403)'));
            }
        }

        $this->line("\nHint: To simulate specific endpoints, pass --urls=\"/api/foo,/api/bar\"  or scan all API routes with --check-routes.");
        $this->line('This command does not modify the database and performs no HTTP requests.');
        return self::SUCCESS;
    }

    private function matchesAny(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $p = '#^' . str_replace(['*', '/'], ['.*', '\\/'], trim($pattern, '/')) . '$#i';
            if (preg_match($p, trim($path, '/'))) {
                return true;
            }
        }
        return false;
    }
}
