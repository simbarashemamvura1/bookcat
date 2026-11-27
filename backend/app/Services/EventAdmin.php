<?php

namespace App\Services;

use App\Models\BusinessAccount;
use App\Models\Event;
use App\Models\EventFinancialTerms;
use App\Models\FloorPlan;
use App\Models\Organizer;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Every admin action on an event, with its permission check and state rules in one place,
 * so the admin panel (and later an API) can never skip them.
 */
class EventAdmin
{
    /**
     * @param array{organizer_id: int, floor_plan_id: int, title: string, description?: ?string, category?: ?string, restrictions?: ?string, refund_policy?: ?string, starts_at: mixed, ends_at?: mixed, currency: string, is_free?: bool} $data
     */
    public function create(BusinessAccount $admin, array $data): Event
    {
        $this->authorize($admin, 'create_event_directly');

        $organizer = Organizer::find($data['organizer_id'] ?? null);

        if (! $organizer || $organizer->application_status !== Organizer::STATUS_APPROVED) {
            throw new DomainException('Pick an approved organizer.');
        }

        if ($organizer->flagged_at !== null) {
            throw new DomainException('This organizer is flagged. Remove the flag before creating events for them.');
        }

        $plan = FloorPlan::selectableBy($organizer)->find($data['floor_plan_id'] ?? null);

        if (! $plan) {
            throw new DomainException('That floor plan is not available to this organizer, or it is not ready yet.');
        }

        $currency = strtoupper((string) ($data['currency'] ?? ''));

        if (! in_array($currency, ['CAD', 'USD'], true)) {
            throw new DomainException('Choose CAD or USD.');
        }

        if (! config("bookcat.fees.{$currency}")) {
            throw new DomainException("{$currency} events are not available yet: no fee schedule is set up for that currency.");
        }

        return DB::transaction(function () use ($admin, $data, $organizer, $plan, $currency) {
            $event = new Event(Arr::only($data, ['title', 'description', 'category', 'restrictions', 'refund_policy', 'starts_at', 'ends_at']) + [
                'currency' => $currency,
                'is_free' => (bool) ($data['is_free'] ?? false),
            ]);
            $event->forceFill([
                'organizer_id' => $organizer->id,
                'created_by' => $admin->id,
                'floor_plan_id' => $plan->id,
                'slug' => Event::generateSlug($data['title']),
            ])->save();

            EventFinancialTerms::createDefaultsFor($event);
            $event->snapshotSeats();

            return $event->refresh();
        });
    }

    /**
     * One price per section, applied to every seat in it. Only while the event is still a draft.
     *
     * @param array<int, array{section: string, cents: int}> $prices
     */
    public function setSectionPrices(Event $event, BusinessAccount $admin, array $prices): void
    {
        $this->authorize($admin, 'create_event_directly');

        DB::transaction(function () use ($event, $prices) {
            $locked = $this->lock($event);

            if ($locked->review_status !== Event::REVIEW_DRAFT) {
                throw new DomainException('Prices are locked once the event has been submitted or approved.');
            }

            $sections = $locked->seats()->distinct()->pluck('section')->all();

            foreach ($prices as $row) {
                $section = (string) ($row['section'] ?? '');
                $cents = $row['cents'] ?? null;

                if (! in_array($section, $sections, true)) {
                    throw new DomainException("Unknown section: {$section}.");
                }

                if (! is_int($cents) || $cents < 0) {
                    throw new DomainException("Enter a price of 0 or more for {$section}.");
                }

                if ($locked->is_free && $cents > 0) {
                    throw new DomainException('This is a free event, so every price must be 0.');
                }
            }

            foreach ($prices as $row) {
                $locked->seats()->where('section', (string) $row['section'])->update(['price_cents' => $row['cents']]);
            }
        });
    }

    public function approve(Event $event, BusinessAccount $admin): void
    {
        $this->authorize($admin, 'approve_event');

        DB::transaction(function () use ($event, $admin) {
            $locked = $this->lock($event);
            $this->requireReview($locked, [Event::REVIEW_DRAFT, Event::REVIEW_SUBMITTED], 'approved');

            if (! $locked->seats()->exists()) {
                throw new DomainException('This event has no seats.');
            }

            if ($locked->seats()->whereNull('price_cents')->exists()) {
                throw new DomainException('Set a price for every section before approving.');
            }

            $locked->forceFill([
                'review_status' => Event::REVIEW_APPROVED,
                'review_notes' => null,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ])->save();
        });
    }

    public function reject(Event $event, BusinessAccount $admin, string $note): void
    {
        $this->authorize($admin, 'approve_event');

        DB::transaction(function () use ($event, $admin, $note) {
            $locked = $this->lock($event);
            $this->requireReview($locked, [Event::REVIEW_DRAFT, Event::REVIEW_SUBMITTED], 'rejected');

            $locked->forceFill([
                'review_status' => Event::REVIEW_REJECTED,
                'review_notes' => $note,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ])->save();
        });
    }

    /** Until the payments tables exist, an admin records that the creation fee was settled. */
    public function markCreationFeePaid(Event $event, BusinessAccount $admin): void
    {
        $this->authorize($admin, 'activate_event');

        DB::transaction(function () use ($event) {
            $locked = $this->lock($event);

            if ($locked->is_free) {
                throw new DomainException('Free events pay no creation fee.');
            }

            if ($locked->creation_fee_paid_at !== null) {
                throw new DomainException('The creation fee is already marked as paid.');
            }

            $locked->forceFill(['creation_fee_paid_at' => now()])->save();
        });
    }

    public function activate(Event $event, BusinessAccount $admin): void
    {
        $this->authorize($admin, 'activate_event');

        DB::transaction(function () use ($event, $admin) {
            $locked = $this->lock($event);

            if (! $locked->isApproved()) {
                throw new DomainException('Approve the event before activating it.');
            }

            if ($locked->isActivated()) {
                throw new DomainException('This event is already activated.');
            }

            $locked->forceFill(['activated_at' => now(), 'activated_by' => $admin->id])->save();
        });
    }

    /** Hides the event from the public even if it is still marked Public. */
    public function deactivate(Event $event, BusinessAccount $admin): void
    {
        $this->authorize($admin, 'activate_event');

        DB::transaction(function () use ($event) {
            $locked = $this->lock($event);

            if (! $locked->isActivated()) {
                throw new DomainException('This event is not activated.');
            }

            $locked->forceFill(['activated_at' => null, 'activated_by' => null])->save();
        });
    }

    public function makePublic(Event $event, BusinessAccount $admin): void
    {
        $this->authorize($admin, 'create_event_directly');

        DB::transaction(function () use ($event) {
            $locked = $this->lock($event);

            if ($locked->made_public_at !== null) {
                throw new DomainException('This event is already public.');
            }

            $missing = $this->missingForPublic($locked);

            if ($missing !== []) {
                throw new DomainException('Before this event can go public it still needs: '.implode(', ', $missing).'.');
            }

            $locked->forceFill(['made_public_at' => now()])->save();
        });
    }

    public function makePrivate(Event $event, BusinessAccount $admin): void
    {
        $this->authorize($admin, 'create_event_directly');

        DB::transaction(function () use ($event) {
            $locked = $this->lock($event);

            if ($locked->made_public_at === null) {
                throw new DomainException('This event is not public.');
            }

            if ($locked->made_bookable_at !== null) {
                throw new DomainException('Turn off Bookable first. An event cannot be bookable while it is private.');
            }

            $locked->forceFill(['made_public_at' => null])->save();
        });
    }

    public function makeBookable(Event $event, BusinessAccount $admin): void
    {
        $this->authorize($admin, 'create_event_directly');

        DB::transaction(function () use ($event) {
            $locked = $this->lock($event);

            if ($locked->made_bookable_at !== null) {
                throw new DomainException('This event is already bookable.');
            }

            if ($locked->made_public_at === null) {
                throw new DomainException('Make the event public first. Bookable requires Public.');
            }

            $missing = $this->missingForPublic($locked);

            if ($locked->floorPlan?->isReady() !== true) {
                $missing[] = 'a ready floor plan';
            }

            if ($missing !== []) {
                throw new DomainException('Before this event can be bookable it still needs: '.implode(', ', $missing).'.');
            }

            $locked->forceFill(['made_bookable_at' => now()])->save();
        });
    }

    public function makeNotBookable(Event $event, BusinessAccount $admin): void
    {
        $this->authorize($admin, 'create_event_directly');

        DB::transaction(function () use ($event) {
            $locked = $this->lock($event);

            if ($locked->made_bookable_at === null) {
                throw new DomainException('This event is not bookable.');
            }

            $locked->forceFill(['made_bookable_at' => null])->save();
        });
    }

    public function freeze(Event $event, BusinessAccount $admin): void
    {
        $this->authorize($admin, 'freeze_event_booking');

        DB::transaction(function () use ($event, $admin) {
            $locked = $this->lock($event);

            if ($locked->made_bookable_at === null) {
                throw new DomainException('Only bookable events can be frozen.');
            }

            if ($locked->sales_status !== Event::SALES_OPEN) {
                throw new DomainException('Sales are not open, so there is nothing to freeze.');
            }

            $locked->forceFill([
                'sales_status' => Event::SALES_FROZEN,
                'frozen_by' => $admin->id,
                'frozen_by_type' => 'admin',
                'frozen_at' => now(),
            ])->save();
        });
    }

    public function reactivate(Event $event, BusinessAccount $admin): void
    {
        $this->authorize($admin, 'reactivate_event');

        DB::transaction(function () use ($event) {
            $locked = $this->lock($event);

            if ($locked->sales_status !== Event::SALES_FROZEN) {
                throw new DomainException('Sales are not frozen.');
            }

            $locked->forceFill([
                'sales_status' => Event::SALES_OPEN,
                'frozen_by' => null,
                'frozen_by_type' => null,
                'frozen_at' => null,
            ])->save();
        });
    }

    // ---------------------------------------------------------------- internals

    private function authorize(BusinessAccount $admin, string $permission): void
    {
        if (! $admin->hasPermission($permission)) {
            throw new AuthorizationException('Missing permission: '.$permission);
        }
    }

    private function lock(Event $event): Event
    {
        return Event::whereKey($event->id)->lockForUpdate()->firstOrFail();
    }

    /** @param string[] $allowed */
    private function requireReview(Event $event, array $allowed, string $action): void
    {
        if (! in_array($event->review_status, $allowed, true)) {
            throw new DomainException('This event cannot be '.$action.' from its current status ('.$event->review_status.').');
        }
    }

    /** @return string[] */
    private function missingForPublic(Event $event): array
    {
        $missing = [];

        if (! $event->isApproved()) {
            $missing[] = 'approval';
        }

        if (! $event->creationFeeSettled()) {
            $missing[] = 'the creation fee marked as paid';
        }

        if (! $event->isActivated()) {
            $missing[] = 'activation';
        }

        return $missing;
    }
}