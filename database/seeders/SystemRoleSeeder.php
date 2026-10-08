<?php

namespace Database\Seeders;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Permission;
use App\Domain\Foundation\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * System roles. Roles are company-scoped, so on a fresh instance this
 * seeder is a no-op until first-boot setup creates the company — at
 * that point CompanyService materialises the Administrator role with
 * the full permission set. On an already-provisioned instance this
 * seeder re-syncs the Administrator role with the current matrix
 * (deploy path), keeping the system role complete.
 */
class SystemRoleSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $company = Company::current();

        if ($company === null) {
            if ($this->command) {
                $this->command->info('No company yet (pre-setup) — Administrator role will be materialised during first-boot setup.');
            }

            return;
        }

        $role = Role::firstOrCreate(
            ['company_id' => $company->id, 'slug' => 'administrator'],
            ['name' => 'Administrator', 'description' => 'Full access to every function.', 'is_system' => true],
        );

        $role->permissions()->sync(Permission::query()->pluck('id')->all());

        $this->seedBusinessRoles($company->id);
    }

    /**
     * Structural employee roles (never faked users): Manager / Employee /
     * Technician get fixed permission bundles so first tenants have real
     * role options without inventing people.
     */
    protected function seedBusinessRoles(int $companyId): void
    {
        $all = Permission::query()->pluck('id', 'key');

        $bundles = [
            ['slug' => 'manager', 'name' => 'Manager', 'description' => 'Department manager: approvals, HR oversight, masters view, reports.', 'keys' => [
                'dashboard.view', 'approvals.view', 'approvals.decide', 'approvals.comment',
                'settings.view', 'employees.view', 'audit.view', 'documents.view',
                'masters.view', 'branches.view', 'roles.view', 'users.view', 'search.view',
                // HRM (§10): a manager runs their team's attendance and approves leave
                'attendance.view', 'attendance.manage', 'attendance.report',
                'leave.view', 'leave.approve', 'leave.balance',
                // Purchase (§03): a manager signs orders off but does not raise
                // the ones they will approve — self-approval is refused in code.
                'purchase.orders.view', 'purchase.orders.approve', 'purchase.orders.cancel',
                'purchase.receipts.view', 'purchase.bills.view', 'purchase.bills.approve',
                'purchase.payments.view', 'purchase.payments.create',
                'purchase.returns.view', 'purchase.returns.approve', 'purchase.returns.cancel',
                'suppliers.view', 'inventory.stock.view',
                // Stock the desk promised away is a manager's business too,
                // and so is the decision to destroy value (§04-48): a manager
                // approves write-offs, and the maker-checker rule still applies.
                'inventory.reservations.view', 'inventory.reservations.manage',
                'inventory.reports.view',
                // §04-55/56: deciding what to buy is a manager's call — and the
                // purchase module's own create key is what drafts the order, so
                // this desk never gains the power to raise paperwork alone.
                'inventory.reorder.view', 'inventory.reorder.suggest',
                // §04-59: what the goods travel in is a cost decision, so the
                // desk that declares and retires packaging types is this one.
                'inventory.packaging', 'inventory.packaging.manage',
                'inventory.damage.create', 'inventory.loss.create',
                'inventory.writeoffs.create', 'inventory.writeoffs.approve',
                'inventory.counts.view', 'inventory.counts.create', 'inventory.counts.post',
                // §04-38: an expired shelf is a value decision, so the desk that
                // judges writes and loss also reads the dates — and corrects one.
                'inventory.batch.view', 'inventory.batch.manage',
                // §04-12: the catalogue is maintained in bulk by the desk that
                // owns masters, and taking a copy of it out is part of that job.
                'inventory.products.import', 'inventory.products.export',
                // §04-13/52/53: printing a shelf label is part of maintaining the
                // catalogue the labels point at, and the sheet desk is the same job.
                'inventory.products.print', 'inventory.labels',
                // §08: a manager sees where the money is and may record what
                // moved through it — receipts, payments, transfers — but opening
                // or closing a bank account is a decision above a department.
                'cash.view', 'cash.receipts.create', 'cash.payments.create',
                'cash.transfers', 'bank.view',
                // §08-08: checking the bank is what a manager's signature is for.
                'bank.reconcile', 'wallets.reconcile',
                // §08-13/14: the cheque register is money in the same sense the
                // cash book is — a manager records cheques taken and written, and
                // says which ones a bank has honoured, because that is the moment
                // the ledger moves; printing the record of one we issued is part
                // of writing it. The keys stay three separate jobs; the
                // maker-checker rule lives in ChequeService, not in a hidden
                // button, so a manager holding all of them still answers for what
                // they cleared.
                'cheques.view', 'cheques.manage', 'cheques.clear', 'cheques.print',
                // §08-15…§08-18: a department manager records what the department
                // spends and signs off what it spends above the limit. The
                // maker-checker rule is in ExpenseService, so holding the
                // approval key still cannot approve their own entry — and the
                // category mapping stays with whoever configures the books.
                'expenses.view', 'expenses.create', 'expenses.approve', 'expenses.recurring',
                // §08-21: the float is a chart-of-accounts act to declare, a
                // custodian's to spend and a manager's to sign for. The manager
                // carries all four because a small company's manager is both the
                // person who opens the tin and the second pair of eyes on what
                // came out of it — and the service still refuses them their own
                // request.
                'pettycash.funds', 'pettycash.spend', 'pettycash.approve', 'pettycash.replenish',
                // §08-10: the manager records what the bank took and writes the
                // tariff rules that post it automatically — a bank charge is a
                // fact from outside, not a payment anybody here authorised.
                'bank.charges', 'bank.charges.rules',
                // §08-20/§08-22: a manager answers for what the company spent and
                // for where its money is, so both report keys are theirs.
                'expenses.reports', 'cash.reports',
                // §08-05: the manager also answers for a counted difference —
                // counting the drawer is the cash desk's, signing off what is
                // missing from it is a second pair of eyes, and the service
                // refuses the counter their own count.
                'cash.counts', 'cash.counts.approve',
                // §04-28: stock leaving the building on a transfer is the same
                // kind of decision as stock being written off, so it is the same
                // desk that signs it off — and never the person who raised it.
                'inventory.transfers.approve',
                // Seeing the register is part of the create key it always was;
                // the maker-checker rule lives in the service, not in a hidden
                // button, so a manager holding both still cannot clear their own.
                'inventory.transfers.create',
                // §12-07/12-08: seeing the branches beside each other is the
                // manager's view of the company — and it still refuses anybody
                // whose scope is one branch, however senior. Moving stock from
                // one branch to another is the same transfer the inventory desk
                // raises, so the manager holds this key and the branch desk
                // writes through the same engine, approval threshold included.
                'branches.compare', 'branches.transfer',
                // §04-44: picking and putaway are warehouse work, and the module's
                // own keys cover it — a bin is a place, so the layout and the
                // instructions that use it share one pair of keys.
                'warehouses.view', 'warehouses.update',
                // §13: a manager is exactly the person who lives in the reports,
                // so the centre and the six families they answer for come with
                // the role. Not the tax family — filing is the accountant's — and
                // not the marketing or custom-schedule keys.
                'reports.view', 'reports.sales', 'reports.purchase', 'reports.inventory',
                'reports.customers', 'reports.suppliers', 'reports.finance', 'reports.hr',
                // §15: a department manager decides the display and document
                // settings of their own outlet — the receipt footer, the label
                // geometry, the accent — and sees the desk. Not the security,
                // audit or workflow groups: those are company policy and the
                // branch screen refuses them anyway, and not `settings.update`,
                // which is what the company's own values are written with.
                'settings.general', 'settings.localization', 'settings.labels',
                'settings.barcode', 'settings.branch',
                // §12: a manager runs the office as well as the department — the
                // notice board they publish to, their own work, and everybody's
                // work, because assigning it is the job. Projects come with it:
                // a project with nobody accountable is a folder.
                'business.notices.view', 'business.notices.create',
                'tasks.view_own', 'tasks.view_all', 'tasks.manage',
                // §12-03/04/09/10: the registers are the office's memory —
                // which licence runs out when, what was signed with whom, what
                // cover is in force. A manager both reads and keeps them; the
                // employee bundle deliberately does not, because a contract's
                // value and a licence's number are not everybody's business.
                'business.records.view', 'business.records.manage',
                // §12-11: the diary is a manager's as well — calling a meeting,
                // minuting it and turning what was decided into work somebody
                // owns. The employee bundle gets the reading half only.
                'business.meetings.view', 'business.meetings.manage',
                // §12-14: the register of what the company owns, and the
                // permission to write things off — a manager's call, because
                // disposal and depreciation touch the books.
                'business.assets.view', 'business.assets.manage',
                // §12-15: filing the electricity bill and paying it are one job
                // at a manager's level — including the second signature when the
                // amount crosses the company's own approval limit.
                'business.utilities.view', 'business.utilities.manage',
                // §12-16: the manager runs the gate — booking people in,
                // admitting them and keeping the register honest.
                'business.visitors.view', 'business.visitors.manage',
                // §11: the marketing desk is a manager's job end to end —
                // writing to customers is the part of this system that cannot be
                // recalled once it has gone out.
                'marketing.campaigns.view', 'marketing.campaigns.manage',
            ]],
            ['slug' => 'employee', 'name' => 'Employee', 'description' => 'Staff account: own portal access only — own leave, own payslips.', 'keys' => [
                'dashboard.view', 'employees.view', 'documents.view', 'search.view',
                // Own leave only: the leave screen self-scopes when the user
                // cannot approve, so leave.view here never exposes the company.
                'leave.view', 'leave.request',
                // §12: everybody reads the notices addressed to them and sees
                // their own tasks. Not `tasks.view_all` — that is the line
                // between “my work” and “the company's work”.
                'business.notices.view', 'tasks.view_own',
                // §12-11: your own meetings, their minutes, and the action
                // items you owe — not the office's diary.
                'business.meetings.view',
                // §12-14: everybody can see what the company has and where it
                // is — that is how a laptop gets handed back — but not write it
                // off. The employee bundle gets the reading half.
                'business.assets.view',
                // §12-15: everybody may see what the premises cost — the
                // reading half — but nobody pays a bill without the manage key.
                'business.utilities.view',
                // §12-16: anybody may look up who is expected and who is in —
                // that is what a front desk is for — but the gate's decisions
                // are not everybody's to make.
                'business.visitors.view',
                // §11: everybody may read what marketing has sent and what it
                // cost — but writing to a customer list is not everybody's call.
                'marketing.campaigns.view',
            ]],
            ['slug' => 'technician', 'name' => 'Technician', 'description' => 'Service technician (portal-capable).', 'keys' => [
                'dashboard.view', 'documents.view', 'documents.upload', 'search.view',
                'portal.technician.access',
            ]],
            ['slug' => 'buyer', 'name' => 'Buyer (procurement)', 'description' => 'Procurement officer: keeps suppliers and raises purchase orders. Cannot approve them.', 'keys' => [
                'dashboard.view', 'search.view', 'documents.view',
                'suppliers.view', 'suppliers.create', 'suppliers.edit',
                'purchase.orders.view', 'purchase.orders.create',
                'purchase.receipts.view', 'purchase.receipts.create',
                'purchase.bills.view', 'purchase.bills.create',
                'purchase.returns.view', 'purchase.returns.create',
                'inventory.products.view', 'inventory.stock.view',
                // §04-38: a buyer has to see what is about to expire to buy it again.
                'inventory.batch.view',
                // §04-55/56: this is the buyer's actual job — the desk says what
                // is short and why, and turning a proposal into a purchase order
                // is the same act as raising one by hand.
                'inventory.reorder.view', 'inventory.reorder.suggest',
            ]],
            ['slug' => 'storekeeper', 'name' => 'Store keeper', 'description' => 'Receiving desk: books goods in against approved orders, posts them to stock and records what breaks.', 'keys' => [
                'dashboard.view', 'search.view',
                'suppliers.view',
                'purchase.orders.view', 'purchase.receipts.view',
                'purchase.receipts.create', 'purchase.receipts.post', 'purchase.bills.view',
                'purchase.returns.view', 'purchase.returns.create',
                'inventory.stock.view', 'inventory.ledger.view', 'inventory.transfers.receive',
                'inventory.reservations.view', 'inventory.reservations.manage',
                'inventory.reports.view', 'inventory.valuation.view',
                // §04-46/04-47: seeing the damage is the storekeeper's job, so
                // recording it is too. Destroying the value is not: disposal and
                // write-off approval stay with a manager (§04-48).
                'inventory.damage.create', 'inventory.loss.create',
                'inventory.writeoffs.create',
                // §08-21: the storekeeper keeps the tin — pays out of it and
                // puts money back into it. Declaring the float (which creates a
                // ledger account) and approving what the custodian asked for are
                // deliberately not here: they are the manager's two acts.
                'pettycash.spend', 'pettycash.replenish',
                // §04-31: the storekeeper counts the shelves; posting the
                // difference into stock is the manager's signature. §08-05 is the
                // same shape for cash: this desk counts the till, and a shortage
                // big enough to matter waits for somebody else.
                'inventory.counts.view', 'inventory.counts.create', 'cash.counts',
                // §04-55: the storekeeper is the person who notices the shelf is
                // empty, so the desk is readable here — proposing a purchase and
                // drafting one stay with the buyer (inventory.reorder.suggest).
                'inventory.reorder.view',
                // §04-59/60: this desk packs the orders, so it reads which types
                // exist and what is left of them; declaring and retiring a type
                // changes what everybody can use, so that stays with a manager.
                'inventory.packaging',
                // §04-13/52/53: the labels that go on the boxes arrive at this desk:
                // it prints them and the barcodes they carry.
                'inventory.labels', 'inventory.products.print',
                // §04-38: the label on the shelf is read at this desk, so the
                // register and its date corrections belong here first.
                'inventory.batch.view', 'inventory.batch.manage',
                // §04-44: goods arrive at this desk and have to be put away, and
                // the same person walks the pick for an order. Bins and the map
                // belong to the warehouse module, so the keys are the warehouse's
                // own — the physical layout *is* the warehouse.
                'warehouses.view', 'warehouses.update',
            ]],
        ];

        foreach ($bundles as $bundle) {
            $role = Role::firstOrCreate(
                ['company_id' => $companyId, 'slug' => $bundle['slug']],
                [
                    'name' => $bundle['name'],
                    'description' => $bundle['description'],
                    'is_system' => true,
                ],
            );

            $ids = array_values(array_filter(array_map(fn ($k) => $all[$k] ?? null, $bundle['keys'])));
            $role->permissions()->sync($ids);
        }
    }
}
