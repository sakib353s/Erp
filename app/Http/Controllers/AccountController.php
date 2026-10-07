<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\AccountGroup;
use App\Domain\Accounting\Actions\CreateAccount;
use App\Domain\Accounting\Actions\UpdateAccount;
use App\Domain\Accounting\Services\AccountService;
use App\Http\Requests\StoreAccountRequest;
use App\Http\Requests\UpdateAccountRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Chart of Accounts admin (09-03…09-05). Tree hierarchy, system
 * account protection, history-aware edit restrictions — domain rules
 * live in AccountService, not here.
 */
class AccountController extends Controller
{
    public function __construct(
        protected AccountService $accounts,
        protected CreateAccount $createAccount,
        protected UpdateAccount $updateAccount,
    ) {}

    public function tree(): View
    {
        $this->accounts->ensureDefaultGroups();

        return view('accounting.coa', [
            'rows' => $this->flatten($this->accounts->tree()),
            'groups' => AccountGroup::query()->orderBy('sort')->get(),
            'flat' => Account::query()->orderBy('code')->get(),
        ]);
    }

    /**
     * Depth-annotated flat list for the COA table (avoids recursive Blade).
     *
     * @param  array<int, array{account: Account, children: array}>  $nodes
     * @return array<int, array{account: Account, depth: int, has_children: bool}>
     */
    protected function flatten(array $nodes, int $depth = 0): array
    {
        $rows = [];

        foreach ($nodes as $node) {
            $rows[] = [
                'account' => $node['account'],
                'depth' => $depth,
                'has_children' => $node['children'] !== [],
            ];

            $rows = array_merge($rows, $this->flatten($node['children'], $depth + 1));
        }

        return $rows;
    }

    public function create(): View
    {
        $this->accounts->ensureDefaultGroups();

        return view('accounting.account-form', [
            'account' => new Account(['is_active' => true, 'type' => 'asset', 'currency' => 'BDT']),
            'mode' => 'create',
            'groups' => AccountGroup::query()->orderBy('sort')->get(),
            'parents' => Account::query()->where('is_group', true)->orderBy('code')->get(),
            'types' => Account::TYPES,
        ]);
    }

    public function store(StoreAccountRequest $request): RedirectResponse
    {
        $account = $this->createAccount->handle($request->validated(), $request);

        return redirect()
            ->route('accounting.coa')
            ->with('status', "Account {$account->code} created.");
    }

    public function edit(Account $account): View
    {
        return view('accounting.account-form', [
            'account' => $account,
            'mode' => 'edit',
            'groups' => AccountGroup::query()->orderBy('sort')->get(),
            'parents' => Account::query()
                ->where('is_group', true)
                ->where('id', '!=', $account->id)
                ->orderBy('code')
                ->get(),
            'types' => Account::TYPES,
        ]);
    }

    public function update(UpdateAccountRequest $request, Account $account): RedirectResponse
    {
        $this->updateAccount->handle($account, $request->validated(), $request);

        return redirect()
            ->route('accounting.coa')
            ->with('status', "Account {$account->code} updated.");
    }

    public function destroy(Request $request, Account $account): RedirectResponse
    {
        try {
            $this->accounts->delete($account);
            $status = "Account {$account->code} deleted.";
        } catch (\RuntimeException $e) {
            return back()->withErrors(['account' => $e->getMessage()]);
        }

        return redirect()->route('accounting.coa')->with('status', $status);
    }

    public function groups(): View
    {
        $this->accounts->ensureDefaultGroups();

        return view('accounting.account-groups', [
            'groups' => AccountGroup::query()->withCount('accounts')->orderBy('sort')->get(),
        ]);
    }
}
