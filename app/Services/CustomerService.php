<?php

namespace App\Services;

use App\Domains\Customer\Models\Customer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CustomerService
{
    public function paginate(int $workspaceId, ?string $search): LengthAwarePaginator
    {
        return Customer::where('workspace_id', $workspaceId)
            ->when($search, fn ($q, $s) => $q->search($s))
            ->withCount('conversations')
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();
    }

    public function search(int $workspaceId, string $query): Collection
    {
        $op = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        return Customer::where('workspace_id', $workspaceId)
            ->where(fn ($q) => $q
                ->where('name', $op, "%{$query}%")
                ->orWhere('email', $op, "%{$query}%")
            )
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'email']);
    }

    public function update(Customer $customer, array $validated): void
    {
        $customer->update($validated);
    }
}
