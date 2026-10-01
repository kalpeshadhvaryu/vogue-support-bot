<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Tests;

use PHPUnit\Framework\TestCase;
use VogueHosting\SupportBot\Identity\BillingVerifier;

final class BillingVerifierTest extends TestCase
{
    public function testEmailMatchesOneCandidate(): void
    {
        $id = BillingVerifier::match('email', 'Asha@Example.com', [
            $this->candidate(3, 'other@example.com', []),
            $this->candidate(9, 'asha@example.com', []),
        ]);
        self::assertSame(9, $id);
    }

    public function testAmbiguousEmailMatchesNobody(): void
    {
        $id = BillingVerifier::match('email', 'asha@example.com', [
            $this->candidate(3, 'asha@example.com', []),
            $this->candidate(9, 'asha@example.com', []),
        ]);
        self::assertNull($id);
    }

    public function testInvoiceLast4UsesIdOrNumber(): void
    {
        self::assertTrue(BillingVerifier::invoiceLast4Matches('4321', ['INV-104321', '12']));
        self::assertFalse(BillingVerifier::invoiceLast4Matches('12', ['12']));
        self::assertFalse(BillingVerifier::invoiceLast4Matches('abcd', ['104321']));
        $id = BillingVerifier::match('invoice_last4', '4321', [
            $this->candidate(3, 'a@example.com', ['1000']),
            $this->candidate(9, 'b@example.com', ['INV-104321']),
        ]);
        self::assertSame(9, $id);
    }

    /**
     * @param list<string> $labels
     * @return array{id: int, email: string, invoice_labels: list<string>}
     */
    private function candidate(int $id, string $email, array $labels): array
    {
        return ['id' => $id, 'email' => $email, 'invoice_labels' => $labels];
    }
}
