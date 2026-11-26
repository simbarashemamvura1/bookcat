<?php

namespace App\Services;

use App\Models\BusinessAccount;
use App\Models\Organizer;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every admin action on an organizer, with its permission check and state rules in one place,
 * so the admin panel (and later an API) can never skip them.
 */
class OrganizerAdmin
{
    /**
     * Creates the login account and the organizer together.
     * Sales-assisted organizers wait for approval; pre-approved ones are live immediately.
     *
     * @param array{business_name: string, contact_phone?: ?string, bio?: ?string, origin?: string, account_name: string, account_email: string, account_password: string} $data
     */
    public function create(BusinessAccount $admin, array $data): Organizer
    {
        $this->authorize($admin, 'create_organizer');

        $preApproved = ($data['origin'] ?? 'sales_assisted') === 'pre_approved';

        if ($preApproved) {
            $this->authorize($admin, 'create_preapproved_organizer');
        }

        return DB::transaction(function () use ($admin, $data, $preApproved) {
            $account = new BusinessAccount([
                'name' => $data['account_name'],
                'email' => strtolower(trim($data['account_email'])),
                'password' => $data['account_password'],
            ]);
            $account->forceFill([
                'role' => BusinessAccount::ROLE_ORGANIZER,
                'status' => $preApproved ? 'active' : 'pending',
            ])->save();

            $organizer = $account->organizer()->make([
                'business_name' => $data['business_name'],
                'contact_phone' => $data['contact_phone'] ?? null,
                'bio' => $data['bio'] ?? null,
            ]);
            $organizer->forceFill([
                'origin' => $preApproved ? 'pre_approved' : 'sales_assisted',
                'application_status' => $preApproved ? Organizer::STATUS_APPROVED : Organizer::STATUS_PENDING,
                'slug' => $preApproved ? $this->uniqueSlug($data['business_name']) : null,
                'reviewed_by' => $preApproved ? $admin->id : null,
                'reviewed_at' => $preApproved ? now() : null,
            ])->save();

            return $organizer;
        });
    }

    public function approve(Organizer $organizer, BusinessAccount $admin): void
    {
        $this->authorize($admin, 'approve_organizer');

        DB::transaction(function () use ($organizer, $admin) {
            $locked = $this->lock($organizer);
            $this->requireStatus($locked, [Organizer::STATUS_PENDING, Organizer::STATUS_CHANGES_REQUESTED], 'approved');

            $locked->forceFill([
                'application_status' => Organizer::STATUS_APPROVED,
                'slug' => $locked->slug ?? $this->uniqueSlug($locked->business_name),
                'review_notes' => null,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ])->save();

            BusinessAccount::whereKey($locked->business_account_id)
                ->where('status', 'pending')
                ->update(['status' => 'active']);
        });
    }

    public function requestChanges(Organizer $organizer, BusinessAccount $admin, string $note): void
    {
        $this->authorize($admin, 'approve_organizer');

        DB::transaction(function () use ($organizer, $admin, $note) {
            $locked = $this->lock($organizer);
            $this->requireStatus($locked, [Organizer::STATUS_PENDING], 'sent back for changes');

            $locked->forceFill([
                'application_status' => Organizer::STATUS_CHANGES_REQUESTED,
                'review_notes' => $note,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ])->save();
        });
    }

    public function reject(Organizer $organizer, BusinessAccount $admin, string $note): void
    {
        $this->authorize($admin, 'approve_organizer');

        DB::transaction(function () use ($organizer, $admin, $note) {
            $locked = $this->lock($organizer);
            $this->requireStatus($locked, [Organizer::STATUS_PENDING, Organizer::STATUS_CHANGES_REQUESTED], 'rejected');

            $locked->forceFill([
                'application_status' => Organizer::STATUS_REJECTED,
                'review_notes' => $note,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ])->save();

            BusinessAccount::whereKey($locked->business_account_id)
                ->where('status', 'pending')
                ->update(['status' => 'rejected']);
        });
    }

    /** Admin "flag/pause". Final suspension is a Super Admin matter on the account itself. */
    public function flag(Organizer $organizer, BusinessAccount $admin, string $reason): void
    {
        $this->authorize($admin, 'suspend_organizer');

        DB::transaction(function () use ($organizer, $admin, $reason) {
            $locked = $this->lock($organizer);
            $this->requireStatus($locked, [Organizer::STATUS_APPROVED], 'flagged');

            if ($locked->flagged_at !== null) {
                throw new DomainException('This organizer is already flagged.');
            }

            $locked->forceFill([
                'flagged_by' => $admin->id,
                'flagged_at' => now(),
                'flag_reason' => $reason,
            ])->save();
        });
    }

    public function unflag(Organizer $organizer, BusinessAccount $admin): void
    {
        $this->authorize($admin, 'suspend_organizer');

        DB::transaction(function () use ($organizer) {
            $locked = $this->lock($organizer);

            if ($locked->flagged_at === null) {
                throw new DomainException('This organizer is not flagged.');
            }

            $locked->forceFill(['flagged_by' => null, 'flagged_at' => null, 'flag_reason' => null])->save();
        });
    }

    // ---------------------------------------------------------------- internals

    private function authorize(BusinessAccount $admin, string $permission): void
    {
        if (! $admin->hasPermission($permission)) {
            throw new AuthorizationException('Missing permission: '.$permission);
        }
    }

    private function lock(Organizer $organizer): Organizer
    {
        return Organizer::whereKey($organizer->id)->lockForUpdate()->firstOrFail();
    }

    /** @param string[] $allowed */
    private function requireStatus(Organizer $organizer, array $allowed, string $action): void
    {
        if (! in_array($organizer->application_status, $allowed, true)) {
            throw new DomainException('This organizer cannot be '.$action.' from its current status ('.$organizer->application_status.').');
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'organizer';
        $slug = $base;
        $i = 2;

        while (Organizer::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}