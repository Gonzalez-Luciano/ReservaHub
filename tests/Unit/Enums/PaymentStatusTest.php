<?php

namespace Tests\Unit\Enums;

use App\Enums\PaymentStatus;
use Tests\TestCase;

class PaymentStatusTest extends TestCase
{
    public function test_only_pending_is_non_terminal(): void
    {
        $this->assertFalse(PaymentStatus::Pending->isTerminal());
        $this->assertTrue(PaymentStatus::Approved->isTerminal());
        $this->assertTrue(PaymentStatus::Rejected->isTerminal());
        $this->assertTrue(PaymentStatus::Expired->isTerminal());
    }

    public function test_pending_may_move_to_any_state_including_staying_pending(): void
    {
        foreach (PaymentStatus::cases() as $target) {
            $this->assertTrue(
                PaymentStatus::Pending->canTransitionTo($target),
                "pending → {$target->value} debería ser legal",
            );
        }
    }

    public function test_terminal_states_never_transition(): void
    {
        foreach ([PaymentStatus::Approved, PaymentStatus::Rejected, PaymentStatus::Expired] as $from) {
            foreach (PaymentStatus::cases() as $target) {
                $this->assertFalse(
                    $from->canTransitionTo($target),
                    "{$from->value} → {$target->value} no debe ser legal",
                );
            }
        }
    }

    public function test_expired_cannot_become_approved(): void
    {
        $this->assertFalse(PaymentStatus::Expired->canTransitionTo(PaymentStatus::Approved));
    }
}
