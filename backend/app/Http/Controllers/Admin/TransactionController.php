<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Support\Wallet\Direction;
use App\Support\Wallet\TransactionStatus;
use App\Support\Wallet\TransactionType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** All customer transactions (transactions.view). Read-only in Phase 8. */
class TransactionController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::enum(TransactionType::class)],
            'status' => ['nullable', Rule::enum(TransactionStatus::class)],
            'direction' => ['nullable', Rule::enum(Direction::class)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $transactions = Transaction::query()
            ->with('user:id,name,email')
            ->when($filters['q'] ?? null, function ($query, string $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $query->where(fn ($q) => $q->where('reference', 'like', $like)
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $like)->orWhere('email', 'like', $like)));
            })
            ->when($filters['type'] ?? null, fn ($query, $v) => $query->where('type', $v))
            ->when($filters['status'] ?? null, fn ($query, $v) => $query->where('status', $v))
            ->when($filters['direction'] ?? null, fn ($query, $v) => $query->where('direction', $v))
            ->when($filters['from'] ?? null, fn ($query, $v) => $query->where('created_at', '>=', $v.' 00:00:00'))
            ->when($filters['to'] ?? null, fn ($query, $v) => $query->where('created_at', '<=', $v.' 23:59:59'))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.transactions.index', [
            'transactions' => $transactions,
            'filters' => $filters,
            'types' => TransactionType::cases(),
            'statuses' => TransactionStatus::cases(),
            'directions' => Direction::cases(),
        ]);
    }

    public function show(Transaction $transaction): View
    {
        return view('admin.transactions.show', ['transaction' => $transaction->load('user', 'wallet', 'createdBy', 'entries.createdBy')]);
    }
}
